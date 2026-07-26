<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFollowUpRequest;
use App\Models\FollowUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The follow-up review inbox (T-45). Drafts are generated off the request path
 * by GenerateFollowUpJob; here the user lists them and marks each sent or
 * dismissed. All actions are user-scoped via the BelongsToUser scope +
 * FollowUpPolicy.
 */
class FollowUpController extends Controller
{
    /**
     * The user's follow-ups, newest first. Defaults to the active ones (pending
     * / drafted) so the inbox shows only what still needs a decision; pass
     * `?status=all` to include sent/dismissed history.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FollowUp::class);

        $followUps = FollowUp::query()
            ->with(['application:id,job_posting_id', 'application.jobPosting:id,title,company'])
            ->when(
                $request->query('status') !== 'all',
                fn ($q) => $q->whereIn('status', FollowUp::ACTIVE_STATUSES),
            )
            ->latest('due_at')
            ->latest('id')
            ->get();

        return response()->json(['data' => $followUps]);
    }

    public function update(UpdateFollowUpRequest $request, FollowUp $followUp): JsonResponse
    {
        $this->authorize('update', $followUp);

        $followUp->update($request->validated());

        return response()->json(['data' => $followUp]);
    }
}
