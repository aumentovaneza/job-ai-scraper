<?php

namespace App\Http\Controllers;

use App\Http\Requests\JobIndexRequest;
use App\Models\JobSource;
use App\Services\JobSearchService;
use Illuminate\Http\JsonResponse;

class JobPostingController extends Controller
{
    public function __construct(private readonly JobSearchService $search) {}

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

        $jobs = $this->search->search($filters)
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return response()->json($jobs);
    }
}
