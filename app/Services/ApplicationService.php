<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\ApplicationStage;
use App\Models\Contact;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Event-sourced writes for the application tracker (T-42, PLAN.md §4).
 *
 * Every state change appends a row to `application_events` — the append-only log
 * is the source of truth for the timeline. `applications.current_stage_id` is a
 * denormalized projection kept in sync inside the same transaction so the Kanban
 * board can query it directly; it is fully rebuildable from the event log via
 * {@see self::rebuildCurrentStage()}.
 *
 * Callers pass already-scoped models (resolved through the BelongsToUser scope in
 * the controller), so these methods trust that the models belong to the user;
 * `user_id` is stamped from the application to stay correct even off the request
 * path (e.g. the follow-up worker).
 */
class ApplicationService
{
    /**
     * Start tracking a job. Lands in the given stage, or the user's first stage
     * (lowest position — the default "Saved") when none is supplied. Appends a
     * `created` event.
     *
     * @throws RuntimeException when the user has no pipeline stages configured.
     */
    public function createFromJob(User $user, JobPosting $job, ?ApplicationStage $stage = null): Application
    {
        $stage ??= $this->firstStage($user->id);

        if ($stage === null) {
            throw new RuntimeException('User has no application stages configured.');
        }

        return DB::transaction(function () use ($user, $job, $stage): Application {
            $initial = $this->firstStage($user->id);
            // Created straight into a non-initial stage means the user is logging
            // an application they already submitted, so stamp applied_at now.
            $appliedAt = ($initial !== null && $stage->id !== $initial->id) ? now() : null;

            $application = Application::create([
                'user_id' => $user->id,
                'job_posting_id' => $job->id,
                'current_stage_id' => $stage->id,
                'applied_at' => $appliedAt,
            ]);

            $this->record($application, ApplicationEvent::TYPE_CREATED, toStage: $stage);

            return $application;
        });
    }

    /**
     * Move an application to a new stage. No-op (returns unchanged) when it is
     * already in the target stage. Sets applied_at the first time the app leaves
     * its initial stage.
     */
    public function moveToStage(Application $application, ApplicationStage $target): Application
    {
        if ($application->current_stage_id === $target->id) {
            return $application;
        }

        return DB::transaction(function () use ($application, $target): Application {
            $from = $application->current_stage_id !== null
                ? ApplicationStage::withoutGlobalScopes()->find($application->current_stage_id)
                : null;

            $this->record($application, ApplicationEvent::TYPE_STAGE_CHANGED, fromStage: $from, toStage: $target);

            $initial = $this->firstStage($application->user_id);
            $attributes = ['current_stage_id' => $target->id];

            if ($application->applied_at === null && ($initial === null || $target->id !== $initial->id)) {
                $attributes['applied_at'] = now();
            }

            $application->update($attributes);

            return $application;
        });
    }

    /**
     * Append a free-text note to the timeline.
     */
    public function addNote(Application $application, string $body): ApplicationEvent
    {
        return $this->record($application, ApplicationEvent::TYPE_NOTE, metadata: ['body' => $body]);
    }

    /**
     * Attach a contact to the application and log it on the timeline.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addContact(Application $application, array $attributes): Contact
    {
        return DB::transaction(function () use ($application, $attributes): Contact {
            $contact = $application->contacts()->create([
                'user_id' => $application->user_id,
                ...$attributes,
            ]);

            $this->record($application, ApplicationEvent::TYPE_CONTACT_ADDED, metadata: [
                'contact_id' => $contact->id,
                'name' => $contact->name,
            ]);

            return $contact;
        });
    }

    /**
     * Append an event to the log. Kept public so system writers (e.g. the
     * follow-up worker, T-45) can log to the same timeline.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        Application $application,
        string $type,
        ?ApplicationStage $fromStage = null,
        ?ApplicationStage $toStage = null,
        ?array $metadata = null,
        string $actor = 'user',
    ): ApplicationEvent {
        return ApplicationEvent::create([
            'user_id' => $application->user_id,
            'application_id' => $application->id,
            'type' => $type,
            'from_stage_id' => $fromStage?->id,
            'to_stage_id' => $toStage?->id,
            'actor' => $actor,
            'occurred_at' => now(),
            'metadata' => $metadata,
        ]);
    }

    /**
     * Recompute current_stage_id from the event log — the projection is only a
     * cache. Returns the value it set. Used to prove (and repair) that the
     * denormalized column is derivable from events (T-42).
     */
    public function rebuildCurrentStage(Application $application): ?int
    {
        $last = ApplicationEvent::withoutGlobalScopes()
            ->where('application_id', $application->id)
            ->whereIn('type', [ApplicationEvent::TYPE_CREATED, ApplicationEvent::TYPE_STAGE_CHANGED])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        $stageId = $last?->to_stage_id;

        $application->update(['current_stage_id' => $stageId]);

        return $stageId;
    }

    /**
     * The user's first pipeline stage (lowest position), or null if none.
     * Bypasses the global scope so it works off the request path too.
     */
    private function firstStage(int $userId): ?ApplicationStage
    {
        return ApplicationStage::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }
}
