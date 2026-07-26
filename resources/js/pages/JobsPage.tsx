import { type FormEvent, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, ExternalLink, MapPin } from 'lucide-react';
import { AppNav } from '@/components/AppNav';
import { JobDetailDialog } from '@/components/JobDetailDialog';
import { MatchScoreBadge } from '@/components/MatchScoreBadge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
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
    const [filters, setFilters] = useState<Filters>(EMPTY_FILTERS);
    const [appliedFilters, setAppliedFilters] = useState<Filters>(EMPTY_FILTERS);
    const [page, setPage] = useState(1);
    const [selectedJob, setSelectedJob] = useState<JobPosting | null>(null);

    const { data, isLoading, isError, isFetching } = useQuery({
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

    const jobs = data?.data ?? [];
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

    return (
        <div className="min-h-screen bg-background">
            <AppNav />
            <div className="mx-auto max-w-5xl space-y-6 p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Job feed</h1>
                    <p className="text-sm text-muted-foreground">
                        Jobs scraped from your sources, deduped and searchable.
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
                    {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}
                    {isError && (
                        <p className="text-sm text-destructive">Could not load jobs. Try refreshing the page.</p>
                    )}
                    {!isLoading && !isError && jobs.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            {hasFilters
                                ? 'No jobs match these filters.'
                                : 'No jobs yet — add a job source and wait for the next scrape.'}
                        </p>
                    )}

                    {!isLoading &&
                        !isError &&
                        jobs.map((job) => {
                            const salary = formatSalary(job);
                            const posted = formatDate(job.posted_at);

                            return (
                                <Card
                                    key={job.id}
                                    className={cn(
                                        'cursor-pointer transition-colors hover:bg-accent/40',
                                        isFetching && 'opacity-60'
                                    )}
                                    onClick={() => setSelectedJob(job)}
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
