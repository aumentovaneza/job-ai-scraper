import { type FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, ExternalLink, MapPin, SearchX, Rss } from 'lucide-react';
import { Link, useNavigate } from 'react-router-dom';
import { AppNav } from '@/components/AppNav';
import { EmptyState } from '@/components/EmptyState';
import { ErrorState } from '@/components/ErrorState';
import { JobDetailDialog } from '@/components/JobDetailDialog';
import { MatchScoreBadge } from '@/components/MatchScoreBadge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { useApplications, useCreateApplication } from '@/hooks/useApplications';
import { useKeyboardShortcuts } from '@/hooks/useKeyboardShortcuts';
import { api } from '@/lib/api';
import { formatDate, formatSalary } from '@/lib/jobFormat';
import { cn } from '@/lib/utils';
import type { JobPosting, Paginated, RemoteType } from '@/types/jobs';

const PER_PAGE = 20;

type ScoreStatus = 'scored' | 'unscored' | '';

interface Filters {
    q: string;
    remoteType: RemoteType | '';
    salaryMin: string;
    postedAfter: string;
    scoreStatus: ScoreStatus;
    scoreMin: string;
    scoreMax: string;
}

const EMPTY_FILTERS: Filters = {
    q: '',
    remoteType: '',
    salaryMin: '',
    postedAfter: '',
    scoreStatus: '',
    scoreMin: '',
    scoreMax: '',
};

const REMOTE_BADGE_VARIANT: Record<RemoteType, 'default' | 'secondary' | 'outline'> = {
    remote: 'default',
    hybrid: 'secondary',
    onsite: 'outline',
};

export default function JobsPage() {
    const navigate = useNavigate();
    const [filters, setFilters] = useState<Filters>(EMPTY_FILTERS);
    const [appliedFilters, setAppliedFilters] = useState<Filters>(EMPTY_FILTERS);
    const [page, setPage] = useState(1);
    const [selectedJob, setSelectedJob] = useState<JobPosting | null>(null);
    // Keyboard-navigation cursor (T-73): index of the highlighted card in the feed.
    const [cursor, setCursor] = useState(0);

    const { data, isLoading, isError, isFetching, refetch } = useQuery({
        queryKey: ['jobs', appliedFilters, page],
        queryFn: async () => {
            const params: Record<string, string | number> = { per_page: PER_PAGE, page };
            if (appliedFilters.q) params.q = appliedFilters.q;
            if (appliedFilters.remoteType) params.remote_type = appliedFilters.remoteType;
            if (appliedFilters.salaryMin) params.salary_min = appliedFilters.salaryMin;
            if (appliedFilters.postedAfter) params.posted_after = appliedFilters.postedAfter;
            if (appliedFilters.scoreStatus) params.score_status = appliedFilters.scoreStatus;
            if (appliedFilters.scoreMin) params.score_min = appliedFilters.scoreMin;
            if (appliedFilters.scoreMax) params.score_max = appliedFilters.scoreMax;
            const { data } = await api.get<Paginated<JobPosting>>('/api/jobs', { params });
            return data;
        },
        placeholderData: keepPreviousData,
    });

    // Applications the user already tracks, so `s` (save) and `l` (open letter)
    // map onto real pipeline actions per job.
    const { data: applications } = useApplications();
    const createApplication = useCreateApplication();

    function applyFilters(e: FormEvent) {
        e.preventDefault();
        setPage(1);
        setAppliedFilters(filters);
    }

    function resetFilters() {
        setFilters(EMPTY_FILTERS);
        setAppliedFilters(EMPTY_FILTERS);
        setPage(1);
    }

    const jobs = useMemo(() => data?.data ?? [], [data]);
    const total = data?.total ?? 0;
    const lastPage = data?.last_page ?? (total > 0 ? Math.ceil(total / PER_PAGE) : 1);
    const hasFilters =
        appliedFilters.q !== '' ||
        appliedFilters.remoteType !== '' ||
        appliedFilters.salaryMin !== '' ||
        appliedFilters.postedAfter !== '' ||
        appliedFilters.scoreStatus !== '' ||
        appliedFilters.scoreMin !== '' ||
        appliedFilters.scoreMax !== '';

    // Keep the cursor in range as the result set changes (new page/filters).
    useEffect(() => {
        setCursor((c) => (jobs.length === 0 ? 0 : Math.min(c, jobs.length - 1)));
    }, [jobs]);

    const cardRefs = useRef<(HTMLDivElement | null)[]>([]);

    function moveCursor(delta: number) {
        if (jobs.length === 0) return;
        setCursor((c) => {
            const next = Math.max(0, Math.min(jobs.length - 1, c + delta));
            cardRefs.current[next]?.scrollIntoView({ block: 'nearest' });
            return next;
        });
    }

    /** Add the cursor's job to the pipeline, or jump to it if already tracked. */
    function saveJob(job: JobPosting | undefined) {
        if (!job) return;
        const existing = applications?.find((a) => a.job_posting_id === job.id);
        if (existing) {
            navigate(`/applications/${existing.id}`);
            return;
        }
        createApplication.mutate(
            { job_posting_id: job.id },
            { onSuccess: (application) => navigate(`/applications/${application.id}`) }
        );
    }

    /** Open the tracked application's cover-letter editor for this job, if any. */
    function openLetter(job: JobPosting | undefined) {
        if (!job) return;
        const existing = applications?.find((a) => a.job_posting_id === job.id);
        if (existing) navigate(`/applications/${existing.id}/letter`);
    }

    // Feed shortcuts (T-73). Suspended while the detail dialog owns the keyboard.
    useKeyboardShortcuts(
        {
            j: () => moveCursor(1),
            k: () => moveCursor(-1),
            o: () => setSelectedJob(jobs[cursor] ?? null),
            Enter: () => setSelectedJob(jobs[cursor] ?? null),
            a: () => {
                const url = jobs[cursor]?.apply_url;
                if (url) window.open(url, '_blank', 'noopener,noreferrer');
            },
            s: () => saveJob(jobs[cursor]),
            l: () => openLetter(jobs[cursor]),
        },
        { enabled: selectedJob == null && jobs.length > 0 }
    );

    return (
        <div className="min-h-screen bg-background">
            <AppNav />
            <div className="mx-auto max-w-5xl space-y-6 p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Job feed</h1>
                    <p className="text-sm text-muted-foreground">
                        Jobs scraped from your sources, deduped and searchable.
                    </p>
                    <p className="mt-1 hidden text-xs text-muted-foreground/70 sm:block">
                        Shortcuts: <kbd className="font-mono">j</kbd>/<kbd className="font-mono">k</kbd> move ·{' '}
                        <kbd className="font-mono">o</kbd> open · <kbd className="font-mono">a</kbd> apply ·{' '}
                        <kbd className="font-mono">s</kbd> save · <kbd className="font-mono">l</kbd> letter
                    </p>
                </div>

                <Card>
                    <CardContent className="pt-6">
                        <form onSubmit={applyFilters} className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div className="space-y-1.5 lg:col-span-2">
                                <Label htmlFor="q">Search</Label>
                                <Input
                                    id="q"
                                    value={filters.q}
                                    onChange={(e) => setFilters((f) => ({ ...f, q: e.target.value }))}
                                    placeholder="Title, company, keywords…"
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="remote_type">Remote type</Label>
                                <Select
                                    id="remote_type"
                                    value={filters.remoteType}
                                    onChange={(e) =>
                                        setFilters((f) => ({ ...f, remoteType: e.target.value as RemoteType | '' }))
                                    }
                                >
                                    <option value="">Any</option>
                                    <option value="remote">Remote</option>
                                    <option value="hybrid">Hybrid</option>
                                    <option value="onsite">Onsite</option>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="salary_min">Min salary</Label>
                                <Input
                                    id="salary_min"
                                    type="number"
                                    min={0}
                                    value={filters.salaryMin}
                                    onChange={(e) => setFilters((f) => ({ ...f, salaryMin: e.target.value }))}
                                    placeholder="e.g. 100000"
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="posted_after">Posted after</Label>
                                <Input
                                    id="posted_after"
                                    type="date"
                                    value={filters.postedAfter}
                                    onChange={(e) => setFilters((f) => ({ ...f, postedAfter: e.target.value }))}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="score_status">Match score</Label>
                                <Select
                                    id="score_status"
                                    value={filters.scoreStatus}
                                    onChange={(e) =>
                                        setFilters((f) => ({ ...f, scoreStatus: e.target.value as ScoreStatus }))
                                    }
                                >
                                    <option value="">Any</option>
                                    <option value="scored">Scored</option>
                                    <option value="unscored">Not scored</option>
                                </Select>
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="score_min">Min score</Label>
                                <Input
                                    id="score_min"
                                    type="number"
                                    min={0}
                                    max={100}
                                    value={filters.scoreMin}
                                    disabled={filters.scoreStatus === 'unscored'}
                                    onChange={(e) => setFilters((f) => ({ ...f, scoreMin: e.target.value }))}
                                    placeholder="0"
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="score_max">Max score</Label>
                                <Input
                                    id="score_max"
                                    type="number"
                                    min={0}
                                    max={100}
                                    value={filters.scoreMax}
                                    disabled={filters.scoreStatus === 'unscored'}
                                    onChange={(e) => setFilters((f) => ({ ...f, scoreMax: e.target.value }))}
                                    placeholder="100"
                                />
                            </div>
                            <div className="flex items-end gap-2 lg:col-span-4">
                                <Button type="submit">Apply filters</Button>
                                {hasFilters && (
                                    <Button type="button" variant="outline" onClick={resetFilters}>
                                        Clear
                                    </Button>
                                )}
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <div className="space-y-3">
                    {isLoading && <JobsSkeleton />}
                    {isError && (
                        <ErrorState message="Could not load jobs." onRetry={() => void refetch()} />
                    )}
                    {!isLoading && !isError && jobs.length === 0 && (
                        <EmptyState
                            icon={hasFilters ? SearchX : Rss}
                            title={hasFilters ? 'No jobs match these filters' : 'No jobs yet'}
                            description={
                                hasFilters
                                    ? 'Try widening your search or clearing the filters.'
                                    : 'Add a job source and jobs will show up here after the next scrape.'
                            }
                            action={
                                hasFilters ? (
                                    <Button variant="outline" onClick={resetFilters}>
                                        Clear filters
                                    </Button>
                                ) : (
                                    <Button asChild>
                                        <Link to="/sources">Add a job source</Link>
                                    </Button>
                                )
                            }
                        />
                    )}

                    {!isLoading &&
                        !isError &&
                        jobs.map((job, index) => {
                            const salary = formatSalary(job);
                            const posted = formatDate(job.posted_at);

                            return (
                                <Card
                                    key={job.id}
                                    ref={(el) => {
                                        cardRefs.current[index] = el;
                                    }}
                                    className={cn(
                                        'cursor-pointer transition-colors hover:bg-accent/40',
                                        index === cursor && 'ring-2 ring-primary ring-offset-2 ring-offset-background',
                                        isFetching && 'opacity-60'
                                    )}
                                    onClick={() => {
                                        setCursor(index);
                                        setSelectedJob(job);
                                    }}
                                >
                                    <CardContent className="flex items-start justify-between gap-4 pt-6">
                                        <div className="min-w-0 space-y-1.5">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h2 className="font-medium">{job.title}</h2>
                                                {job.remote_type && (
                                                    <Badge variant={REMOTE_BADGE_VARIANT[job.remote_type]}>
                                                        {job.remote_type}
                                                    </Badge>
                                                )}
                                                <MatchScoreBadge matchScore={job.match_score} />
                                            </div>
                                            <p className="text-sm text-muted-foreground">{job.company}</p>
                                            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                                {job.location && (
                                                    <span className="inline-flex items-center gap-1">
                                                        <MapPin className="size-3.5" />
                                                        {job.location}
                                                    </span>
                                                )}
                                                {salary && <span>{salary}</span>}
                                                {posted && <span>Posted {posted}</span>}
                                            </div>
                                        </div>
                                        {job.apply_url && (
                                            <Button variant="outline" size="sm" asChild onClick={(e) => e.stopPropagation()}>
                                                <a href={job.apply_url} target="_blank" rel="noreferrer">
                                                    Apply <ExternalLink />
                                                </a>
                                            </Button>
                                        )}
                                    </CardContent>
                                </Card>
                            );
                        })}
                </div>

                {!isLoading && !isError && jobs.length > 0 && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            Page {data?.current_page ?? page} of {lastPage} · {total} job(s)
                        </p>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setPage((p) => Math.max(1, p - 1))}
                                disabled={page <= 1 || isFetching}
                            >
                                <ChevronLeft /> Prev
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                                disabled={page >= lastPage || isFetching}
                            >
                                Next <ChevronRight />
                            </Button>
                        </div>
                    </div>
                )}
            </div>

            <JobDetailDialog job={selectedJob} open={selectedJob != null} onOpenChange={(open) => !open && setSelectedJob(null)} />
        </div>
    );
}

/** Loading placeholder for the feed: a handful of job-card-shaped skeletons. */
function JobsSkeleton() {
    return (
        <div className="space-y-3">
            {Array.from({ length: 5 }).map((_, i) => (
                <Card key={i}>
                    <CardContent className="flex items-start justify-between gap-4 pt-6">
                        <div className="w-full space-y-2">
                            <Skeleton className="h-5 w-1/2" />
                            <Skeleton className="h-4 w-1/3" />
                            <Skeleton className="h-3 w-2/3" />
                        </div>
                        <Skeleton className="h-8 w-20 shrink-0" />
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}
