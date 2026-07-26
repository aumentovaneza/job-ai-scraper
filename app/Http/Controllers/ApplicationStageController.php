<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReorderApplicationStagesRequest;
use App\Http\Requests\StoreApplicationStageRequest;
use App\Http\Requests\UpdateApplicationStageRequest;
use App\Models\ApplicationStage;
use App\Support\DefaultStages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRUD + reordering for a user's pipeline stages (T-40). The default pipeline is
 * seeded at account creation (DefaultStages) and lazily on first read here, so
 * every logged-in user always has a pipeline; this endpoint lets them rename,
 * add, remove, and reorder those stages.
 *
 * All actions are user-scoped: the BelongsToUser global scope hides other users'
 * rows (route binding 404s on a foreign id) and ApplicationStagePolicy gates
 * each mutation.
 */
class ApplicationStageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ApplicationStage::class);

        // Guarantee every logged-in user has a pipeline: seed the defaults on
        // first read for accounts that never got one (created before the stages
        // feature, or outside the invite/owner seeding paths). Idempotent.
        if (! ApplicationStage::query()->exists()) {
            DefaultStages::seedFor($request->user());
        }

        $stages = ApplicationStage::query()
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $stages]);
    }

    public function store(StoreApplicationStageRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Default a new stage to the end of the pipeline when no position given.
        $data['position'] ??= (int) ApplicationStage::max('position') + 1;

        $stage = ApplicationStage::create($data);

        return response()->json(['data' => $stage], 201);
    }

    public function update(UpdateApplicationStageRequest $request, ApplicationStage $applicationStage): JsonResponse
    {
        $this->authorize('update', $applicationStage);

        $applicationStage->update($request->validated());

        return response()->json(['data' => $applicationStage]);
    }

    /**
     * Delete a stage. Applications currently in it have current_stage_id nulled
     * (nullOnDelete FK) rather than being deleted, so the app itself survives.
     */
    public function destroy(ApplicationStage $applicationStage): JsonResponse
    {
        $this->authorize('delete', $applicationStage);

        $applicationStage->delete();

        return response()->json(status: 204);
    }

    /**
     * Persist a new ordering. Accepts the full ordered list of stage ids and
     * rewrites `position` to match, so the pipeline order is authoritative.
     */
    public function reorder(ReorderApplicationStagesRequest $request): JsonResponse
    {
        $this->authorize('create', ApplicationStage::class);

        $ids = $request->validated('stage_ids');

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $position => $id) {
                ApplicationStage::whereKey($id)->update(['position' => $position]);
            }
        });

        $stages = ApplicationStage::query()->orderBy('position')->orderBy('id')->get();

        return response()->json(['data' => $stages]);
    }
}
