import { useMemo, useState } from 'react';
import {
    DndContext,
    PointerSensor,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
    type DragEndEvent,
} from '@dnd-kit/core';
import { GripVertical, LayoutList } from 'lucide-react';
import { Link } from 'react-router-dom';
import { AppNav } from '@/components/AppNav';
import { EmptyState } from '@/components/EmptyState';
import { ErrorState } from '@/components/ErrorState';
import { MatchScoreBadge } from '@/components/MatchScoreBadge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useApplications, useApplicationStages, useMoveApplication } from '@/hooks/useApplications';
import { cn } from '@/lib/utils';
import type { Application, ApplicationStage } from '@/types/applications';

const STAGE_PREFIX = 'stage-';
const APP_PREFIX = 'app-';

/**
 * Kanban board for the application pipeline (T-43). Columns are the user's
 * stages; cards are draggable between them. A move updates the card optimistically
 * (useMoveApplication) and rolls back on error, surfacing a message inline.
 */
export default function ApplicationsPage() {
    const stagesQuery = useApplicationStages();
    const appsQuery = useApplications();
    const move = useMoveApplication();
    const [moveError, setMoveError] = useState<string | null>(null);

    // Require an 8px drag before activating, so clicking a card's link still
    // navigates instead of starting a drag.
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 8 } }));

    const stages = useMemo(
        () => [...(stagesQuery.data ?? [])].sort((a, b) => a.position - b.position),
        [stagesQuery.data]
    );

    const byStage = useMemo(() => {
        const map = new Map<number, Application[]>();
        for (const stage of stages) map.set(stage.id, []);
        for (const app of appsQuery.data ?? []) {
            if (app.current_stage_id != null && map.has(app.current_stage_id)) {
                map.get(app.current_stage_id)!.push(app);
            }
        }
        return map;
    }, [stages, appsQuery.data]);

    function handleDragEnd(event: DragEndEvent) {
        const { active, over } = event;
        if (!over) return;

        const appId = Number(String(active.id).replace(APP_PREFIX, ''));
        const targetStageId = Number(String(over.id).replace(STAGE_PREFIX, ''));
        const app = (appsQuery.data ?? []).find((a) => a.id === appId);
        if (!app || app.current_stage_id === targetStageId) return;

        setMoveError(null);
        move.mutate(
            { id: appId, targetStageId },
            { onError: () => setMoveError('Could not move that application. It has been put back.') }
        );
    }

    const isLoading = stagesQuery.isLoading || appsQuery.isLoading;
    const isError = stagesQuery.isError || appsQuery.isError;
    const totalApps = appsQuery.data?.length ?? 0;

    return (
        <div className="min-h-screen bg-background">
            <AppNav />
            <div className="mx-auto max-w-7xl space-y-6 p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Pipeline</h1>
                    <p className="text-sm text-muted-foreground">
                        Drag applications between stages to track their progress.
                    </p>
                </div>

                {moveError && <p className="text-sm text-destructive">{moveError}</p>}

                {isLoading && <KanbanSkeleton />}
                {isError && (
                    <ErrorState
                        message="Could not load your pipeline."
                        onRetry={() => {
                            void stagesQuery.refetch();
                            void appsQuery.refetch();
                        }}
                    />
                )}

                {!isLoading && !isError && totalApps === 0 && (
                    <EmptyState
                        icon={LayoutList}
                        title="No applications yet"
                        description={
                            <>
                                Open a job from the feed and start tracking it — moves between stages will show up
                                here.
                            </>
                        }
                        action={
                            <Button asChild>
                                <Link to="/jobs">Browse jobs</Link>
                            </Button>
                        }
                    />
                )}

                {!isLoading && !isError && stages.length > 0 && (
                    <DndContext sensors={sensors} onDragEnd={handleDragEnd}>
                        <div className={cn('flex gap-4 overflow-x-auto pb-4', appsQuery.isFetching && 'opacity-60')}>
                            {stages.map((stage) => (
                                <StageColumn key={stage.id} stage={stage} applications={byStage.get(stage.id) ?? []} />
                            ))}
                        </div>
                    </DndContext>
                )}
            </div>
        </div>
    );
}

function KanbanSkeleton() {
    return (
        <div className="flex gap-4 overflow-x-auto pb-4">
            {Array.from({ length: 4 }).map((_, i) => (
                <div key={i} className="flex w-72 shrink-0 flex-col gap-2">
                    <Skeleton className="mb-1 h-4 w-24" />
                    <div className="flex flex-1 flex-col gap-2 rounded-lg border border-dashed p-2">
                        <Skeleton className="h-16 w-full" />
                        <Skeleton className="h-16 w-full" />
                        <Skeleton className="h-16 w-full" />
                    </div>
                </div>
            ))}
        </div>
    );
}

function StageColumn({ stage, applications }: { stage: ApplicationStage; applications: Application[] }) {
    const { setNodeRef, isOver } = useDroppable({ id: `${STAGE_PREFIX}${stage.id}` });

    return (
        <div className="flex w-72 shrink-0 flex-col">
            <div className="mb-2 flex items-center justify-between px-1">
                <span className="text-sm font-medium">{stage.name}</span>
                <span className="text-xs text-muted-foreground">{applications.length}</span>
            </div>
            <div
                ref={setNodeRef}
                className={cn(
                    'flex min-h-24 flex-1 flex-col gap-2 rounded-lg border border-dashed p-2 transition-colors',
                    isOver ? 'border-primary bg-primary/5' : 'border-border bg-muted/30'
                )}
            >
                {applications.map((app) => (
                    <ApplicationCard key={app.id} application={app} />
                ))}
                {applications.length === 0 && (
                    <p className="px-1 py-2 text-xs text-muted-foreground">Nothing here yet.</p>
                )}
            </div>
        </div>
    );
}

function ApplicationCard({ application }: { application: Application }) {
    const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
        id: `${APP_PREFIX}${application.id}`,
    });

    const style = transform
        ? { transform: `translate3d(${transform.x}px, ${transform.y}px, 0)` }
        : undefined;

    const job = application.job_posting;

    return (
        <div
            ref={setNodeRef}
            style={style}
            className={cn(
                'rounded-md border bg-card p-3 shadow-sm',
                isDragging && 'opacity-50 shadow-md'
            )}
        >
            <div className="flex items-start gap-2">
                <button
                    type="button"
                    className="mt-0.5 cursor-grab touch-none text-muted-foreground active:cursor-grabbing"
                    aria-label="Drag application"
                    {...listeners}
                    {...attributes}
                >
                    <GripVertical className="size-4" />
                </button>
                <Link to={`/applications/${application.id}`} className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{job?.title ?? 'Untitled role'}</p>
                    <p className="truncate text-xs text-muted-foreground">{job?.company ?? 'Unknown company'}</p>
                    <div className="mt-2">
                        <MatchScoreBadge matchScore={job?.match_score ?? null} />
                    </div>
                </Link>
            </div>
        </div>
    );
}
