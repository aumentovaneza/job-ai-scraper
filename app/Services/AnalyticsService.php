<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\ApplicationStage;
use App\Models\CoverLetter;
use App\Models\JobSourceHit;
use App\Models\MatchScore;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Closes the loop (T-60, PLAN.md §5 Phase 6): reads the event-sourced application
 * tracker and the AI ledger to surface what's actually working — response rate by
 * job source and by cover-letter angle, how long applications sit in each stage,
 * the interview→offer conversion, and the gaps that recur across rejected apps.
 *
 * All metrics are derived from the append-only `application_events` log plus the
 * stage flags (`is_terminal` / `is_success`, see DefaultStages). Runs both on the
 * request path (AnalyticsController) and off it (the weekly digest worker), so it
 * always scopes explicitly by user_id and bypasses the BelongsToUser global scope
 * rather than trusting an authenticated context.
 *
 * Pipeline semantics are inferred from stage ordering + flags so they stay correct
 * when a user renames/reorders their stages:
 *   - the lowest-position stage is the pre-application bucket ("Saved");
 *   - the next stage is where an application counts as "applied";
 *   - reaching any non-terminal stage beyond "applied" (or a success stage) counts
 *     as a response — the employer engaged rather than ghosting;
 *   - the deepest non-terminal stage is treated as the "offer" bar;
 *   - a success terminal stage is a win, a non-success terminal stage is a loss.
 */
class AnalyticsService
{
    /** Below this many applied applications there isn't enough signal to feed back. */
    private const MIN_PRIORS_SAMPLE = 3;

    /**
     * The full analytics payload for the insights dashboard (T-61) and the weekly
     * digest email (T-63).
     *
     * @return array<string, mixed>
     */
    public function overviewFor(User $user): array
    {
        $stages = $this->stageMeta($user);
        $applications = $this->applicationsFor($user);
        $eventsByApplication = $this->eventsByApplication($user);

        $rows = $applications
            ->map(fn (Application $application) => $this->deriveRow(
                $application,
                $eventsByApplication->get($application->id, collect()),
                $stages,
            ))
            ->filter(fn (array $row) => $row['applied'])
            ->values();

        return [
            'generated_at' => now()->toIso8601String(),
            'totals' => $this->totals($rows),
            'response_rate_by_source' => $this->responseRateBySource($user, $rows),
            'response_rate_by_variant' => $this->responseRateByVariant($rows),
            'time_in_stage' => $this->timeInStage($rows, $stages),
            'top_gaps' => $this->topGaps($user, $rows),
        ];
    }

    /**
     * User-specific priors distilled from history for the letter-generation
     * feedback loop (T-62): which angle earns the most responses and the gaps
     * that keep coming up. Returns null until there's enough history to trust.
     *
     * @return array{best_variant: ?array, variant_stats: list<array>, common_gaps: list<array>}|null
     */
    public function priorsFor(User $user): ?array
    {
        $stages = $this->stageMeta($user);
        $eventsByApplication = $this->eventsByApplication($user);

        $rows = $this->applicationsFor($user)
            ->map(fn (Application $application) => $this->deriveRow(
                $application,
                $eventsByApplication->get($application->id, collect()),
                $stages,
            ))
            ->filter(fn (array $row) => $row['applied'])
            ->values();

        if ($rows->count() < self::MIN_PRIORS_SAMPLE) {
            return null;
        }

        $variantStats = $this->responseRateByVariant($rows);

        // Best angle = highest response rate among variants with at least two
        // samples, so a single lucky letter doesn't skew the recommendation.
        $best = collect($variantStats)
            ->filter(fn (array $v) => $v['sent'] >= 2)
            ->sortByDesc('response_rate')
            ->first();

        $gaps = $this->topGaps($user, $rows, onlyRejected: false, limit: 5);

        if ($best === null && $gaps === []) {
            return null;
        }

        return [
            'best_variant' => $best,
            'variant_stats' => $variantStats,
            'common_gaps' => $gaps,
        ];
    }

    /**
     * Stage flags/positions keyed by id, plus the derived thresholds used to
     * classify how far an application progressed.
     *
     * @return array{by_id: array<int, array{name: string, position: int, is_terminal: bool, is_success: bool}>, applied_position: int, offer_position: ?int}
     */
    private function stageMeta(User $user): array
    {
        $stages = ApplicationStage::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->orderBy('position')
            ->get();

        $byId = [];
        foreach ($stages as $stage) {
            $byId[$stage->id] = [
                'name' => $stage->name,
                'position' => (int) $stage->position,
                'is_terminal' => (bool) $stage->is_terminal,
                'is_success' => (bool) $stage->is_success,
            ];
        }

        $positions = $stages->pluck('position')->map(fn ($p) => (int) $p);
        $minPosition = $positions->min() ?? 0;

        // "Applied" is the first stage after the pre-application bucket.
        $appliedPosition = $positions->filter(fn (int $p) => $p > $minPosition)->min() ?? $minPosition;

        // The "offer" bar is the deepest non-terminal stage in the funnel.
        $offerPosition = $stages
            ->reject(fn (ApplicationStage $s) => $s->is_terminal)
            ->pluck('position')
            ->map(fn ($p) => (int) $p)
            ->max();

        return [
            'by_id' => $byId,
            'applied_position' => $appliedPosition,
            'offer_position' => $offerPosition,
        ];
    }

    /** @return Collection<int, Application> */
    private function applicationsFor(User $user): Collection
    {
        return Application::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->with([
                'coverLetter.versions',
                'jobPosting',
            ])
            ->get();
    }

    /**
     * All stage-affecting events (created / stage_changed) grouped by application,
     * ordered oldest-first — the raw material for progression + dwell time.
     *
     * @return Collection<int, Collection<int, ApplicationEvent>>
     */
    private function eventsByApplication(User $user): Collection
    {
        return ApplicationEvent::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereIn('type', [ApplicationEvent::TYPE_CREATED, ApplicationEvent::TYPE_STAGE_CHANGED])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->groupBy('application_id');
    }

    /**
     * Reduce one application to the boolean/positional facts every metric needs.
     *
     * @param  Collection<int, ApplicationEvent>  $events
     * @param  array{by_id: array, applied_position: int, offer_position: ?int}  $stages
     * @return array<string, mixed>
     */
    private function deriveRow(Application $application, Collection $events, array $stages): array
    {
        $byId = $stages['by_id'];

        // Every stage the application has ever entered (from the event log), plus
        // its current stage in case a projection ever drifts from the log.
        $visitedStageIds = $events
            ->pluck('to_stage_id')
            ->filter()
            ->push($application->current_stage_id)
            ->filter()
            ->unique()
            ->values();

        $visitedMeta = $visitedStageIds
            ->map(fn ($id) => $byId[$id] ?? null)
            ->filter();

        $deepestFunnelPosition = $visitedMeta
            ->reject(fn (array $m) => $m['is_terminal'])
            ->pluck('position')
            ->max();

        $won = $visitedMeta->contains(fn (array $m) => $m['is_terminal'] && $m['is_success']);

        $responded = $won
            || ($deepestFunnelPosition !== null && $deepestFunnelPosition > $stages['applied_position']);

        $reachedOffer = $won
            || ($stages['offer_position'] !== null
                && $deepestFunnelPosition !== null
                && $deepestFunnelPosition >= $stages['offer_position']);

        $currentStage = $application->current_stage_id !== null
            ? ($byId[$application->current_stage_id] ?? null)
            : null;
        $rejected = $currentStage !== null && $currentStage['is_terminal'] && ! $currentStage['is_success'];

        return [
            'application_id' => $application->id,
            'applied' => $application->applied_at !== null,
            'responded' => $responded,
            'reached_offer' => $reachedOffer,
            'won' => $won,
            'rejected' => $rejected,
            'in_progress' => $currentStage === null || ! $currentStage['is_terminal'],
            'job_posting_id' => $application->job_posting_id,
            'variant' => $this->chosenVariant($application),
            'stage_durations' => $this->stageDurations($events, $byId),
        ];
    }

    /**
     * The cover-letter angle the user actually went with for this application: the
     * most recent version they marked sent, else the active version.
     */
    private function chosenVariant(Application $application): ?string
    {
        $coverLetter = $application->coverLetter;
        if (! $coverLetter instanceof CoverLetter) {
            return null;
        }

        $versions = $coverLetter->versions;

        $sent = $versions
            ->where('was_sent', true)
            ->sortByDesc('id')
            ->first();
        if ($sent !== null) {
            return $sent->variant_label;
        }

        $active = $versions->firstWhere('id', $coverLetter->active_version_id);

        return $active?->variant_label;
    }

    /**
     * Days spent in each stage, derived from consecutive stage-affecting events:
     * the gap between entering a stage and the next transition. The stage the
     * application currently sits in is measured up to now (unless it's terminal).
     *
     * @param  Collection<int, ApplicationEvent>  $events
     * @param  array<int, array>  $byId
     * @return list<array{stage_id: int, days: float}>
     */
    private function stageDurations(Collection $events, array $byId): array
    {
        $ordered = $events->filter(fn (ApplicationEvent $e) => $e->to_stage_id !== null)->values();
        $durations = [];

        for ($i = 0; $i < $ordered->count(); $i++) {
            $event = $ordered[$i];
            $stageId = $event->to_stage_id;
            $meta = $byId[$stageId] ?? null;
            if ($meta === null) {
                continue;
            }

            $enteredAt = $event->occurred_at ?? $event->created_at;
            if ($enteredAt === null) {
                continue;
            }

            $next = $ordered->get($i + 1);
            if ($next !== null) {
                $leftAt = $next->occurred_at ?? $next->created_at;
            } elseif ($meta['is_terminal']) {
                // Terminal stages have no meaningful dwell time.
                continue;
            } else {
                $leftAt = now();
            }

            if ($leftAt === null) {
                continue;
            }

            $durations[] = [
                'stage_id' => $stageId,
                'days' => max(0.0, $enteredAt->floatDiffInDays($leftAt)),
            ];
        }

        return $durations;
    }

    /**
     * @param  Collection<int, array>  $rows
     * @return array<string, mixed>
     */
    private function totals(Collection $rows): array
    {
        $applied = $rows->count();
        $responded = $rows->where('responded', true)->count();
        $offers = $rows->where('reached_offer', true)->count();

        return [
            'applied' => $applied,
            'responded' => $responded,
            'response_rate' => $this->rate($responded, $applied),
            'in_progress' => $rows->where('in_progress', true)->count(),
            'offers' => $offers,
            'won' => $rows->where('won', true)->count(),
            'rejected' => $rows->where('rejected', true)->count(),
            'interview_to_offer_rate' => $this->rate($offers, $responded),
        ];
    }

    /**
     * Response rate grouped by the user's own job source that surfaced each job.
     * Applications whose job came from no tracked source bucket as "Direct".
     *
     * @param  Collection<int, array>  $rows
     * @return list<array{source_id: ?int, label: string, applied: int, responded: int, response_rate: ?float}>
     */
    private function responseRateBySource(User $user, Collection $rows): array
    {
        $jobIds = $rows->pluck('job_posting_id')->filter()->unique()->values();

        // Map each job posting to the user's earliest source hit for it.
        $sourceByJob = [];
        $sourceLabels = [];
        if ($jobIds->isNotEmpty()) {
            $hits = JobSourceHit::query()
                ->whereIn('job_posting_id', $jobIds)
                ->whereHas('jobSource', fn ($q) => $q->withoutGlobalScopes()->where('user_id', $user->id))
                ->with(['jobSource' => fn ($q) => $q->withoutGlobalScopes()])
                ->orderBy('id')
                ->get();

            foreach ($hits as $hit) {
                if (! isset($sourceByJob[$hit->job_posting_id])) {
                    $sourceByJob[$hit->job_posting_id] = $hit->job_source_id;
                    $sourceLabels[$hit->job_source_id] = $this->sourceLabel($hit->jobSource);
                }
            }
        }

        $buckets = [];
        foreach ($rows as $row) {
            $sourceId = $sourceByJob[$row['job_posting_id']] ?? null;
            $key = $sourceId ?? 'direct';

            $buckets[$key] ??= [
                'source_id' => $sourceId,
                'label' => $sourceId !== null ? ($sourceLabels[$sourceId] ?? 'Source') : 'Direct',
                'applied' => 0,
                'responded' => 0,
            ];
            $buckets[$key]['applied']++;
            $buckets[$key]['responded'] += $row['responded'] ? 1 : 0;
        }

        return collect($buckets)
            ->map(fn (array $b) => [
                ...$b,
                'response_rate' => $this->rate($b['responded'], $b['applied']),
            ])
            ->sortByDesc('applied')
            ->values()
            ->all();
    }

    private function sourceLabel($jobSource): string
    {
        if ($jobSource === null) {
            return 'Source';
        }

        $type = ucwords(str_replace('_', ' ', (string) $jobSource->type));
        $host = $jobSource->url ? parse_url($jobSource->url, PHP_URL_HOST) : null;

        return $host ? "{$type} · {$host}" : $type;
    }

    /**
     * Response rate grouped by the cover-letter angle the user sent (T-60): does
     * story-led out-convert results-led for this user? Applications with no chosen
     * letter are excluded from the denominator.
     *
     * @param  Collection<int, array>  $rows
     * @return list<array{variant: string, sent: int, responded: int, response_rate: ?float}>
     */
    private function responseRateByVariant(Collection $rows): array
    {
        return $rows
            ->filter(fn (array $r) => $r['variant'] !== null && $r['variant'] !== '')
            ->groupBy('variant')
            ->map(fn (Collection $group, string $variant) => [
                'variant' => $variant,
                'sent' => $group->count(),
                'responded' => $group->where('responded', true)->count(),
                'response_rate' => $this->rate($group->where('responded', true)->count(), $group->count()),
            ])
            ->sortByDesc('sent')
            ->values()
            ->all();
    }

    /**
     * Average and median days applications dwell in each stage.
     *
     * @param  Collection<int, array>  $rows
     * @param  array{by_id: array}  $stages
     * @return list<array{stage_id: int, name: string, samples: int, avg_days: float, median_days: float}>
     */
    private function timeInStage(Collection $rows, array $stages): array
    {
        $byStage = [];
        foreach ($rows as $row) {
            foreach ($row['stage_durations'] as $duration) {
                $byStage[$duration['stage_id']][] = $duration['days'];
            }
        }

        $result = [];
        foreach ($stages['by_id'] as $stageId => $meta) {
            $samples = $byStage[$stageId] ?? [];
            if ($samples === []) {
                continue;
            }

            $result[] = [
                'stage_id' => $stageId,
                'name' => $meta['name'],
                'position' => $meta['position'],
                'samples' => count($samples),
                'avg_days' => round(array_sum($samples) / count($samples), 1),
                'median_days' => round($this->median($samples), 1),
            ];
        }

        usort($result, fn ($a, $b) => $a['position'] <=> $b['position']);

        return array_map(fn (array $r) => array_diff_key($r, ['position' => null]), $result);
    }

    /**
     * The gaps Claude flagged most often across the user's match scores — by
     * default only for rejected applications, so it reads as "what kept costing
     * you offers" (T-60). Used verbatim by the digest and the letter feedback loop.
     *
     * @param  Collection<int, array>  $rows
     * @return list<array{gap: string, count: int}>
     */
    private function topGaps(User $user, Collection $rows, bool $onlyRejected = true, int $limit = 8): array
    {
        $jobIds = $rows
            ->when($onlyRejected, fn (Collection $r) => $r->where('rejected', true))
            ->pluck('job_posting_id')
            ->filter()
            ->unique()
            ->values();

        if ($jobIds->isEmpty()) {
            return [];
        }

        $gapLists = MatchScore::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereIn('job_posting_id', $jobIds)
            ->pluck('gaps');

        $tally = [];
        foreach ($gapLists as $gaps) {
            foreach ((array) $gaps as $gap) {
                $gap = trim((string) $gap);
                if ($gap === '') {
                    continue;
                }
                // Tally case-insensitively but keep the first-seen surface form.
                $normalized = mb_strtolower($gap);
                $tally[$normalized] ??= ['gap' => $gap, 'count' => 0];
                $tally[$normalized]['count']++;
            }
        }

        return collect($tally)
            ->sortByDesc('count')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Render an analytics overview as a compact, human-readable stats block for
     * the weekly-summary prompt (T-61). Percentages are pre-computed so the model
     * never has to do arithmetic — it only narrates.
     *
     * @param  array<string, mixed>  $overview
     */
    public function statsBlock(array $overview): string
    {
        $totals = $overview['totals'];
        $lines = [];

        $lines[] = 'Overall:';
        $lines[] = "- Applications: {$totals['applied']}";
        $lines[] = '- Response rate: '.$this->pct($totals['response_rate'])
            ." ({$totals['responded']} of {$totals['applied']} got a reply)";
        $lines[] = "- In progress: {$totals['in_progress']}; offers: {$totals['offers']}; "
            ."won: {$totals['won']}; rejected: {$totals['rejected']}";
        $lines[] = '- Interview→offer rate: '.$this->pct($totals['interview_to_offer_rate']);

        if ($overview['response_rate_by_source'] !== []) {
            $lines[] = '';
            $lines[] = 'Response rate by job source:';
            foreach ($overview['response_rate_by_source'] as $s) {
                $lines[] = "- {$s['label']}: ".$this->pct($s['response_rate'])
                    ." ({$s['responded']}/{$s['applied']})";
            }
        }

        if ($overview['response_rate_by_variant'] !== []) {
            $lines[] = '';
            $lines[] = 'Response rate by cover-letter angle:';
            foreach ($overview['response_rate_by_variant'] as $v) {
                $label = $this->variantLabel($v['variant']);
                $lines[] = "- {$label}: ".$this->pct($v['response_rate'])
                    ." ({$v['responded']}/{$v['sent']} sent)";
            }
        }

        if ($overview['time_in_stage'] !== []) {
            $lines[] = '';
            $lines[] = 'Average days spent in each stage:';
            foreach ($overview['time_in_stage'] as $t) {
                $lines[] = "- {$t['name']}: {$t['avg_days']} days avg "
                    ."(median {$t['median_days']}, n={$t['samples']})";
            }
        }

        if ($overview['top_gaps'] !== []) {
            $lines[] = '';
            $lines[] = 'Gaps most often flagged on rejected applications:';
            foreach ($overview['top_gaps'] as $g) {
                $lines[] = "- {$g['gap']} (×{$g['count']})";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * A short "based on this user's history" preamble injected into letter
     * generation (T-62). Returns an empty string when priors are too thin to help.
     *
     * @param  array{best_variant: ?array, variant_stats: list<array>, common_gaps: list<array>}  $priors
     */
    public function priorsPreamble(array $priors): string
    {
        $parts = [];

        $best = $priors['best_variant'] ?? null;
        if ($best !== null && $best['response_rate'] !== null) {
            $label = $this->variantLabel($best['variant']);
            $parts[] = "For this candidate, {$label} cover letters have earned the best "
                .'response rate so far ('.$this->pct($best['response_rate'])
                ." across {$best['sent']} sent). Lean into that angle where it fits the role.";
        }

        $gaps = $priors['common_gaps'] ?? [];
        if ($gaps !== []) {
            $names = array_map(fn (array $g) => $g['gap'], array_slice($gaps, 0, 3));
            $parts[] = 'Recruiters have repeatedly flagged these gaps for this candidate: '
                .implode('; ', $names).'. Where the résumé offers honest evidence to '
                .'counter them, address them proactively — never fabricate.';
        }

        return $parts === [] ? '' : implode(' ', $parts);
    }

    private function variantLabel(?string $variant): string
    {
        if ($variant === null || $variant === '') {
            return 'Cover letter';
        }

        return ucwords(str_replace('_', ' ', $variant));
    }

    private function pct(?float $rate): string
    {
        return $rate === null ? 'n/a' : round($rate * 100).'%';
    }

    private function rate(int $numerator, int $denominator): ?float
    {
        if ($denominator === 0) {
            return null;
        }

        return round($numerator / $denominator, 4);
    }

    /** @param  list<float>  $values */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        $mid = intdiv($count, 2);

        return $count % 2 === 0
            ? ($values[$mid - 1] + $values[$mid]) / 2
            : $values[$mid];
    }
}
