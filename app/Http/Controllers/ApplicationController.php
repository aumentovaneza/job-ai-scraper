<?php

namespace App\Http\Controllers;

use App\Http\Requests\MoveApplicationRequest;
use App\Http\Requests\StoreApplicationNoteRequest;
use App\Http\Requests\StoreApplicationRequest;
use App\Http\Requests\StoreContactRequest;
use App\Models\Application;
use App\Models\ApplicationStage;
use App\Models\JobPosting;
use App\Services\ApplicationService;
use Illuminate\Http\JsonResponse;

/**
 * The application tracker API (T-41). Reads project the current pipeline state;
 * every write goes through ApplicationService so it appends to the event log and
 * keeps current_stage_id in sync (T-42).
 *
 * All actions are user-scoped: the BelongsToUser global scope hides other users'
 * rows (route binding 404s on a foreign id) and ApplicationPolicy gates each
 * action.
 */
class ApplicationController extends Controller
{
    public function __construct(private readonly ApplicationService $applications) {}

    /**
     * Every application for the current user, with the data the Kanban board
     * needs: the posting, its current stage, and the user's fit score.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Application::class);

        $applications = Application::query()
            ->with(['jobPosting.matchScore', 'currentStage'])
            ->latest('updated_at')
            ->get();

        return response()->json(['data' => $applications]);
    }

    public function show(Application $application): JsonResponse
    {
        $this->authorize('view', $application);

        $application->load([
            'jobPosting.matchScore',
            'currentStage',
            'contacts',
            'followUps' => fn ($q) => $q->latest('due_at'),
            'events' => fn ($q) => $q->orderBy('occurred_at')->orderBy('id'),
            'events.fromStage:id,name',
            'events.toStage:id,name',
        ]);

        return response()->json(['data' => $application]);
    }

    /**
     * Start tracking a job. Optionally lands in a specific stage; otherwise the
     * user's first stage (default "Saved").
     */
    public function store(StoreApplicationRequest $request): JsonResponse
    {
        $job = JobPosting::findOrFail($request->validated('job_posting_id'));

        $stage = $request->filled('stage_id')
            ? ApplicationStage::findOrFail($request->validated('stage_id'))
            : null;

        $application = $this->applications->createFromJob($request->user(), $job, $stage);

        return response()->json([
            'data' => $application->load(['jobPosting.matchScore', 'currentStage']),
        ], 201);
    }

    /**
     * Move an application to another stage (the Kanban drag target).
     */
    public function move(MoveApplicationRequest $request, Application $application): JsonResponse
    {
        $this->authorize('update', $application);

        $target = ApplicationStage::findOrFail($request->validated('target_stage_id'));

        $this->applications->moveToStage($application, $target);

        return response()->json([
            'data' => $application->fresh(['jobPosting.matchScore', 'currentStage']),
        ]);
    }

    public function storeNote(StoreApplicationNoteRequest $request, Application $application): JsonResponse
    {
        $this->authorize('update', $application);

        $event = $this->applications->addNote($application, $request->validated('body'));

        return response()->json(['data' => $event], 201);
    }

    public function storeContact(StoreContactRequest $request, Application $application): JsonResponse
    {
        $this->authorize('update', $application);

        $contact = $this->applications->addContact($application, $request->validated());

        return response()->json(['data' => $contact], 201);
    }
}
