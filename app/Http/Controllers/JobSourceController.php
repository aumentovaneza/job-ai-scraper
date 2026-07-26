<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreJobSourceRequest;
use App\Http\Requests\UpdateJobSourceRequest;
use App\Models\JobSource;
use App\Services\Ats\AtsFeedScraper;
use App\Services\CareerPageScraper;
use App\Services\JsonApi\JsonApiScraper;
use App\Support\NormalizedJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CRUD for a user's job sources plus a synchronous "test scrape" (T-21).
 *
 * Every action is user-scoped: the BelongsToUser global scope hides other
 * users' rows (route binding 404s on a foreign id) and JobSourcePolicy gates
 * each mutation. Test-scrape runs the real scraper inline but persists nothing —
 * it just shows what *would* land, so the user can validate a source before
 * arming its schedule.
 */
class JobSourceController extends Controller
{
    public function __construct(
        private readonly AtsFeedScraper $atsScraper,
        private readonly CareerPageScraper $careerScraper,
        private readonly JsonApiScraper $jsonApiScraper,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', JobSource::class);

        $sources = JobSource::query()
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return response()->json($sources);
    }

    public function store(StoreJobSourceRequest $request): JsonResponse
    {
        $source = JobSource::create($request->validated());

        return response()->json(['data' => $source], 201);
    }

    public function update(UpdateJobSourceRequest $request, JobSource $jobSource): JsonResponse
    {
        $jobSource->update($request->validated());

        return response()->json(['data' => $jobSource]);
    }

    public function destroy(JobSource $jobSource): JsonResponse
    {
        $this->authorize('delete', $jobSource);

        $jobSource->delete();

        return response()->json(status: 204);
    }

    /**
     * Fetch + normalize the source's postings synchronously without persisting.
     * Returns what a scheduled scrape would ingest.
     */
    public function testScrape(JobSource $jobSource): JsonResponse
    {
        $this->authorize('view', $jobSource);

        try {
            $postings = match ($jobSource->type) {
                'ats_feed' => $this->atsScraper->scrape($jobSource),
                'career_page' => $this->careerScrape($jobSource),
                'json_api' => $this->jsonApiScraper->scrape($jobSource, JsonApiScraper::PREVIEW_TIMEOUT),
                default => throw new \RuntimeException("Test scrape is not supported for '{$jobSource->type}' sources yet."),
            };
        } catch (Throwable $e) {
            Log::warning('Test scrape failed', ['job_source_id' => $jobSource->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $jobs = array_map(fn ($posting) => $posting->toArray(), $postings);

        return response()->json([
            'count' => count($jobs),
            'jobs' => $jobs,
        ]);
    }

    /**
     * @return array<int, NormalizedJob>
     */
    protected function careerScrape(JobSource $source): array
    {
        if (! $this->careerScraper->configured()) {
            throw new \RuntimeException('Firecrawl is not configured, so career pages cannot be test-scraped.');
        }

        return $this->careerScraper->scrape($source);
    }
}
