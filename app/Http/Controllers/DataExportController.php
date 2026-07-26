<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\CoverLetter;
use App\Models\JobPosting;
use App\Models\Snippet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

/**
 * Personal-backup export (T-75): a single JSON dump of everything the
 * authenticated user owns — applications (with their event log, contacts,
 * follow-ups and cover letter), cover letters, the job postings they've
 * tracked, and their letter snippets. Invite-only, single-user-facing
 * feature — no pagination, just the whole account.
 *
 * Every model queried here carries the BelongsToUser global scope except
 * JobPosting, which is the shared catalog table; that one is filtered
 * explicitly to the postings referenced by this user's applications.
 */
class DataExportController extends Controller
{
    public function export(Request $request): Response
    {
        $user = $request->user();

        $applications = Application::query()
            ->with([
                'currentStage',
                'jobPosting',
                'events' => fn ($q) => $q->orderBy('occurred_at')->orderBy('id'),
                'events.fromStage:id,name',
                'events.toStage:id,name',
                'contacts',
                'followUps',
                'coverLetter.versions',
            ])
            ->get();

        $coverLetters = CoverLetter::query()
            ->with('versions')
            ->get();

        $jobPostingIds = $applications->pluck('job_posting_id')->filter()->unique()->values();
        $jobs = JobPosting::query()
            ->whereIn('id', $jobPostingIds)
            ->get();

        $snippets = Snippet::query()
            ->orderBy('position')
            ->get();

        $payload = $this->buildPayload($user, $applications, $coverLetters, $jobs, $snippets);

        $filename = 'jobscope-export-'.now()->format('Y-m-d').'.json';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return response($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Assemble the export body. Kept separate from export() for readability.
     *
     * @param  Collection<int, Application>  $applications
     * @param  Collection<int, CoverLetter>  $coverLetters
     * @param  Collection<int, JobPosting>  $jobs
     * @param  Collection<int, Snippet>  $snippets
     * @return array<string, mixed>
     */
    private function buildPayload(
        User $user,
        Collection $applications,
        Collection $coverLetters,
        Collection $jobs,
        Collection $snippets,
    ): array {
        return [
            'exported_at' => now()->toISOString(),
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'applications' => $applications,
            'cover_letters' => $coverLetters,
            'jobs' => $jobs,
            'snippets' => $snippets,
        ];
    }
}
