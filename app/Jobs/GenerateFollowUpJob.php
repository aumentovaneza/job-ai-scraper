<?php

namespace App\Jobs;

use App\Exceptions\Ai\AiBudgetExceededException;
use App\Exceptions\Ai\AiException;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\FollowUp;
use App\Models\Profile;
use App\Models\User;
use App\Services\Ai\AiClientFactory;
use App\Services\Ai\Prompt;
use App\Services\ApplicationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Drafts a follow-up nudge for one stale application (T-45). Dispatched by
 * DraftFollowUpsCommand for applications that have gone quiet; the job
 * re-verifies staleness so a race (e.g. the user just moved the card) doesn't
 * produce a spurious draft.
 *
 * Calls Claude with `follow_up.v1` using the user's own key, stores the draft as
 * a `pending` FollowUp for the user to review in the inbox, and logs a
 * `follow_up_drafted` event (actor = claude) on the application timeline.
 *
 * Runs in an unauthenticated worker, so all lookups bypass the BelongsToUser
 * scope and user_id is set explicitly.
 */
class GenerateFollowUpJob implements ShouldQueue
{
    use Queueable;

    private const PROMPT_VERSION = 'follow_up.v1';

    public int $tries = 3;

    public function __construct(
        public readonly int $applicationId,
        public readonly int $staleDays = 7,
    ) {
        $this->onQueue('ai');
    }

    public function handle(AiClientFactory $factory, ApplicationService $applications): void
    {
        $application = Application::withoutGlobalScopes()->find($this->applicationId);

        if ($application === null || $application->applied_at === null) {
            return;
        }

        // Don't nudge terminal applications (accepted/rejected/withdrawn).
        $stage = $application->currentStage()->withoutGlobalScopes()->first();
        if ($stage !== null && $stage->is_terminal) {
            return;
        }

        // Skip if a follow-up is already awaiting the user's action.
        $hasActive = FollowUp::withoutGlobalScopes()
            ->where('application_id', $application->id)
            ->whereIn('status', FollowUp::ACTIVE_STATUSES)
            ->exists();
        if ($hasActive) {
            return;
        }

        $lastActivity = $this->lastActivityAt($application);
        $daysStale = (int) $lastActivity->diffInDays(now());

        // Re-verify staleness now that we're running (defends against a race).
        if ($daysStale < $this->staleDays) {
            return;
        }

        $user = User::find($application->user_id);
        if ($user === null) {
            return;
        }

        $profile = Profile::withoutGlobalScopes()->where('user_id', $user->id)->first();
        $contact = $application->contacts()->withoutGlobalScopes()->first();

        $content = Prompt::render(self::PROMPT_VERSION, [
            'job_title' => $application->jobPosting?->title ?? 'the role',
            'company' => $application->jobPosting?->company ?? 'the company',
            'current_stage' => $stage?->name ?? 'Applied',
            'days_stale' => (string) $daysStale,
            'contact' => $contact?->name ?? 'not provided',
            'candidate_headline' => $profile?->headline ?? 'not provided',
        ]);

        try {
            $response = $factory->forUser($user)->messages(
                [
                    'max_tokens' => 512,
                    'messages' => [['role' => 'user', 'content' => $content]],
                ],
                purpose: 'follow_up',
                referenceType: Application::class,
                referenceId: $application->id,
                promptVersion: self::PROMPT_VERSION,
            );
        } catch (AiBudgetExceededException|AiException $e) {
            // Ledger already recorded the failure; one user's budget stops here only.
            Log::warning('Follow-up drafting failed', [
                'application_id' => $application->id,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        $draft = trim($response->text);
        if ($draft === '') {
            return;
        }

        FollowUp::create([
            'user_id' => $user->id,
            'application_id' => $application->id,
            'due_at' => now(),
            'kind' => 'nudge',
            'status' => 'pending',
            'draft_content' => $draft,
        ]);

        $applications->record(
            $application,
            ApplicationEvent::TYPE_FOLLOW_UP_DRAFTED,
            actor: 'claude',
        );
    }

    /**
     * Timestamp of the last stage-affecting event (created/stage_changed), used
     * as the "no stage change since" clock. Falls back to applied_at.
     */
    private function lastActivityAt(Application $application): Carbon
    {
        $last = ApplicationEvent::withoutGlobalScopes()
            ->where('application_id', $application->id)
            ->whereIn('type', [ApplicationEvent::TYPE_CREATED, ApplicationEvent::TYPE_STAGE_CHANGED])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('occurred_at');

        return $last ?? $application->applied_at ?? $application->created_at;
    }
}
