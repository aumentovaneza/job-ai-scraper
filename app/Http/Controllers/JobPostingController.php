<?php

namespace App\Http\Controllers;

use App\Http\Requests\JobIndexRequest;
use App\Jobs\EnrichJobJob;
use App\Jobs\MatchJobToProfileJob;
use App\Models\JobPosting;
use App\Models\JobSource;
use App\Models\User;
use App\Services\Ai\AiKeyService;
use App\Services\JobSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobPostingController extends Controller
{
    public function __construct(
        private readonly JobSearchService $search,
        private readonly AiKeyService $keys,
    ) {}

    /**
     * Paginated feed over the shared job catalog with keyword search + filters
     * (T-06). JobPostings are not user-scoped, but the optional `source_id`
     * filter is validated against the caller's own JobSources so one user can't
     * filter by another user's source id.
     */
    public function index(JobIndexRequest $request): JsonResponse
    {
        $filters = $request->safe()->except('per_page');

        // Resolve source_id through the (user-scoped) JobSource model. If it
        // isn't the caller's, drop the filter rather than leaking or erroring.
        if (! empty($filters['source_id']) && ! JobSource::whereKey($filters['source_id'])->exists()) {
            unset($filters['source_id']);
        }

        // Attach the caller's own fit score to each posting (T-32/T-33). The
        // MatchScore BelongsToUser scope keeps this to the current user's rows.
        // Passing the user also lets the search downrank low-acceptability sources.
        $jobs = $this->search->search($filters, $request->user())
            ->with('matchScore')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return response()->json($jobs);
    }

    /**
     * Re-score one posting for the current user on demand. Only ever writes the
     * caller's own MatchScore and spends the caller's BYOK budget, so any
     * authenticated user may trigger it against the shared catalog. Forces a
     * fresh score even when the cached inputs are unchanged.
     */
    public function rescore(Request $request, JobPosting $jobPosting): JsonResponse
    {
        if ($error = $this->guardScoringReady($request->user())) {
            return $error;
        }

        MatchJobToProfileJob::dispatch($request->user()->id, $jobPosting->id, force: true);

        return response()->json(['message' => 'Scoring started.'], 202);
    }

    /**
     * Re-enrich one posting on demand. Enrichment is the shared canonical record
     * (cached for every user), funded here by the caller's BYOK key; it fans out
     * a fresh score to everyone who tracks the posting.
     */
    public function enrich(Request $request, JobPosting $jobPosting): JsonResponse
    {
        if ($error = $this->guardScoringReady($request->user())) {
            return $error;
        }

        EnrichJobJob::dispatch($jobPosting->id, $request->user()->id, force: true);

        return response()->json(['message' => 'Enrichment started.'], 202);
    }

    /**
     * Fail fast (mirrors ProfileController::extractVoice) so we don't enqueue a
     * job that would silently bail: the user needs a resume to score against and
     * a verified key to fund the Claude call.
     */
    private function guardScoringReady(User $user): ?JsonResponse
    {
        $profile = $user->profile()->firstOrCreate([]);

        if (blank($profile->resume_text)) {
            return response()->json(['message' => 'Upload a resume before scoring jobs.'], 422);
        }

        $provider = $this->keys->activeProvider($user);

        if (! $this->keys->isVerified($user, $provider)) {
            return response()->json([
                'message' => "Add and verify your {$provider->label()} key first.",
            ], 422);
        }

        return null;
    }
}
