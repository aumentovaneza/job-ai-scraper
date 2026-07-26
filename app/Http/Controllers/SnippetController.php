<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSnippetRequest;
use App\Http\Requests\UpdateSnippetRequest;
use App\Models\Snippet;
use Illuminate\Http\JsonResponse;

/**
 * The reusable snippet library (T-54): standard closings, referral paragraphs,
 * etc. the user inserts into the letter editor. All actions are user-scoped via
 * the BelongsToUser global scope + SnippetPolicy.
 */
class SnippetController extends Controller
{
    /**
     * The user's snippets in display order.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Snippet::class);

        $snippets = Snippet::query()
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $snippets]);
    }

    public function store(StoreSnippetRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Snippet::class);

        $snippet = Snippet::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $snippet], 201);
    }

    public function update(UpdateSnippetRequest $request, Snippet $snippet): JsonResponse
    {
        $this->authorize('update', $snippet);

        $snippet->update($request->validated());

        return response()->json(['data' => $snippet]);
    }

    public function destroy(Snippet $snippet): JsonResponse
    {
        $this->authorize('delete', $snippet);

        $snippet->delete();

        return response()->json(['data' => null]);
    }
}
