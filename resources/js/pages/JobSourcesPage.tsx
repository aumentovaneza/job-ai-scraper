import { type FormEvent, useState } from 'react';
import { AxiosError } from 'axios';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Play, Plus, Trash2, X } from 'lucide-react';
import { AppNav } from '@/components/AppNav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { api } from '@/lib/api';
import type { AtsProvider, JobSource, JobSourceType, NormalizedJob, Paginated } from '@/types/jobs';

const JOB_SOURCES_KEY = ['job-sources'] as const;

const TYPE_LABELS: Record<JobSourceType, string> = {
    ats_feed: 'ATS feed',
    career_page: 'Career page',
    rss: 'RSS feed',
};

const PROVIDERS: AtsProvider[] = ['greenhouse', 'lever', 'workable', 'ashby'];

interface FormState {
    type: JobSourceType;
    url: string;
    provider: AtsProvider;
    boardToken: string;
    cronSchedule: string;
    active: boolean;
}

const EMPTY_FORM: FormState = {
    type: 'ats_feed',
    url: '',
    provider: 'greenhouse',
    boardToken: '',
    cronSchedule: '0 * * * *',
    active: true,
};

interface JobSourcePayload {
    type: JobSourceType;
    url: string;
    config: { provider: AtsProvider; board_token: string } | null;
    cron_schedule: string | null;
    active: boolean;
}

function toPayload(form: FormState): JobSourcePayload {
    return {
        type: form.type,
        url: form.url,
        config: form.type === 'ats_feed' ? { provider: form.provider, board_token: form.boardToken } : null,
        cron_schedule: form.cronSchedule.trim() ? form.cronSchedule.trim() : null,
        active: form.active,
    };
}

function formStateFromSource(source: JobSource): FormState {
    return {
        type: source.type,
        url: source.url,
        provider: source.config?.provider ?? 'greenhouse',
        boardToken: source.config?.board_token ?? '',
        cronSchedule: source.cron_schedule ?? '',
        active: source.active,
    };
}

function formatDateTime(iso: string | null): string {
    if (!iso) return 'Never';
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

interface TestResult {
    sourceId: number;
    count: number;
    jobs: NormalizedJob[];
}

export default function JobSourcesPage() {
    const queryClient = useQueryClient();
    const [formOpen, setFormOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [form, setForm] = useState<FormState>(EMPTY_FORM);
    const [formError, setFormError] = useState<string | null>(null);
    const [testResult, setTestResult] = useState<TestResult | null>(null);
    const [testError, setTestError] = useState<string | null>(null);

    const { data, isLoading, isError } = useQuery({
        queryKey: JOB_SOURCES_KEY,
        queryFn: async () => {
            const { data } = await api.get<Paginated<JobSource>>('/api/job-sources');
            return data;
        },
    });

    const createSource = useMutation({
        mutationFn: async (payload: JobSourcePayload) => {
            await api.get('/sanctum/csrf-cookie');
            const { data } = await api.post<{ data: JobSource }>('/api/job-sources', payload);
            return data.data;
        },
        onSuccess: () => {
            closeForm();
            void queryClient.invalidateQueries({ queryKey: JOB_SOURCES_KEY });
        },
        onError: (err: AxiosError<{ message?: string }>) => {
            setFormError(err.response?.data?.message ?? 'Could not save source.');
        },
    });

    const updateSource = useMutation({
        mutationFn: async ({ id, payload }: { id: number; payload: JobSourcePayload }) => {
            await api.get('/sanctum/csrf-cookie');
            const { data } = await api.patch<{ data: JobSource }>(`/api/job-sources/${id}`, payload);
            return data.data;
        },
        onSuccess: () => {
            closeForm();
            void queryClient.invalidateQueries({ queryKey: JOB_SOURCES_KEY });
        },
        onError: (err: AxiosError<{ message?: string }>) => {
            setFormError(err.response?.data?.message ?? 'Could not save source.');
        },
    });

    const deleteSource = useMutation({
        mutationFn: async (id: number) => {
            await api.get('/sanctum/csrf-cookie');
            await api.delete(`/api/job-sources/${id}`);
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: JOB_SOURCES_KEY }),
    });

    const toggleActive = useMutation({
        mutationFn: async ({ id, active }: { id: number; active: boolean }) => {
            await api.get('/sanctum/csrf-cookie');
            const { data } = await api.patch<{ data: JobSource }>(`/api/job-sources/${id}`, { active });
            return data.data;
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: JOB_SOURCES_KEY }),
    });

    const testScrape = useMutation({
        mutationFn: async (id: number) => {
            await api.get('/sanctum/csrf-cookie');
            const { data } = await api.post<{ count: number; jobs: NormalizedJob[] }>(
                `/api/job-sources/${id}/test-scrape`
            );
            return { sourceId: id, ...data };
        },
        onSuccess: (result) => {
            setTestError(null);
            setTestResult(result);
        },
        onError: (err: AxiosError<{ message?: string }>) => {
            setTestResult(null);
            setTestError(err.response?.data?.message ?? 'Test scrape failed.');
        },
    });

    function openCreateForm() {
        setForm(EMPTY_FORM);
        setEditingId(null);
        setFormError(null);
        setFormOpen(true);
    }

    function openEditForm(source: JobSource) {
        setForm(formStateFromSource(source));
        setEditingId(source.id);
        setFormError(null);
        setFormOpen(true);
    }

    function closeForm() {
        setFormOpen(false);
        setEditingId(null);
        setFormError(null);
    }

    function handleSubmit(e: FormEvent) {
        e.preventDefault();
        const payload = toPayload(form);
        if (editingId !== null) {
            updateSource.mutate({ id: editingId, payload });
        } else {
            createSource.mutate(payload);
        }
    }

    function handleDelete(id: number) {
        if (window.confirm('Delete this job source? This cannot be undone.')) {
            if (testResult?.sourceId === id) setTestResult(null);
            deleteSource.mutate(id);
        }
    }

    const isSaving = createSource.isPending || updateSource.isPending;
    const sources = data?.data ?? [];

    return (
        <div className="min-h-screen bg-background">
            <AppNav />
            <div className="mx-auto max-w-5xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Job sources</h1>
                        <p className="text-sm text-muted-foreground">
                            ATS feeds, career pages, and RSS feeds the scraper pulls from.
                        </p>
                    </div>
                    {!formOpen && (
                        <Button onClick={openCreateForm}>
                            <Plus /> Add source
                        </Button>
                    )}
                </div>

                {formOpen && (
                    <Card>
                        <CardHeader>
                            <CardTitle>{editingId !== null ? 'Edit source' : 'New source'}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="type">Type</Label>
                                        <Select
                                            id="type"
                                            value={form.type}
                                            onChange={(e) =>
                                                setForm((f) => ({ ...f, type: e.target.value as JobSourceType }))
                                            }
                                        >
                                            <option value="ats_feed">ATS feed (Greenhouse/Lever/Workable/Ashby)</option>
                                            <option value="career_page">Company career page</option>
                                            <option value="rss">RSS feed</option>
                                        </Select>
                                    </div>

                                    <div className="flex items-end justify-between">
                                        <div className="space-y-1.5">
                                            <Label htmlFor="active">Active</Label>
                                            <div>
                                                <Switch
                                                    id="active"
                                                    checked={form.active}
                                                    onCheckedChange={(active) => setForm((f) => ({ ...f, active }))}
                                                />
                                            </div>
                                        </div>
                                    </div>

                                    {form.type === 'ats_feed' ? (
                                        <>
                                            <div className="space-y-1.5">
                                                <Label htmlFor="provider">Provider</Label>
                                                <Select
                                                    id="provider"
                                                    value={form.provider}
                                                    onChange={(e) =>
                                                        setForm((f) => ({
                                                            ...f,
                                                            provider: e.target.value as AtsProvider,
                                                        }))
                                                    }
                                                >
                                                    {PROVIDERS.map((p) => (
                                                        <option key={p} value={p}>
                                                            {p[0].toUpperCase() + p.slice(1)}
                                                        </option>
                                                    ))}
                                                </Select>
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label htmlFor="board_token">Board token</Label>
                                                <Input
                                                    id="board_token"
                                                    value={form.boardToken}
                                                    onChange={(e) =>
                                                        setForm((f) => ({ ...f, boardToken: e.target.value }))
                                                    }
                                                    placeholder="e.g. acme-inc"
                                                    required
                                                />
                                            </div>
                                            <div className="space-y-1.5 sm:col-span-2">
                                                <Label htmlFor="url">Board URL (optional, for reference)</Label>
                                                <Input
                                                    id="url"
                                                    type="url"
                                                    value={form.url}
                                                    onChange={(e) => setForm((f) => ({ ...f, url: e.target.value }))}
                                                    placeholder="https://boards.greenhouse.io/acme-inc"
                                                />
                                            </div>
                                        </>
                                    ) : (
                                        <div className="space-y-1.5 sm:col-span-2">
                                            <Label htmlFor="url">
                                                {form.type === 'rss' ? 'RSS feed URL' : 'Career page URL'}
                                            </Label>
                                            <Input
                                                id="url"
                                                type="url"
                                                value={form.url}
                                                onChange={(e) => setForm((f) => ({ ...f, url: e.target.value }))}
                                                placeholder="https://example.com/careers"
                                                required
                                            />
                                        </div>
                                    )}

                                    <div className="space-y-1.5 sm:col-span-2">
                                        <Label htmlFor="cron_schedule">Cron schedule</Label>
                                        <Input
                                            id="cron_schedule"
                                            value={form.cronSchedule}
                                            onChange={(e) => setForm((f) => ({ ...f, cronSchedule: e.target.value }))}
                                            placeholder="0 * * * * (every hour)"
                                        />
                                    </div>
                                </div>

                                {formError && <p className="text-sm text-destructive">{formError}</p>}

                                <div className="flex items-center gap-2">
                                    <Button type="submit" disabled={isSaving}>
                                        {isSaving ? 'Saving…' : editingId !== null ? 'Save changes' : 'Create source'}
                                    </Button>
                                    <Button type="button" variant="outline" onClick={closeForm}>
                                        Cancel
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardContent className="pt-6">
                        {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}
                        {isError && (
                            <p className="text-sm text-destructive">Could not load job sources. Try refreshing.</p>
                        )}
                        {!isLoading && !isError && sources.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No job sources yet. Add an ATS feed, career page, or RSS feed to start scraping.
                            </p>
                        )}
                        {!isLoading && !isError && sources.length > 0 && (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Source</TableHead>
                                        <TableHead>Schedule</TableHead>
                                        <TableHead>Active</TableHead>
                                        <TableHead>Last scraped</TableHead>
                                        <TableHead className="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {sources.map((source) => (
                                        <TableRow key={source.id}>
                                            <TableCell>
                                                <Badge variant="outline">{TYPE_LABELS[source.type]}</Badge>
                                            </TableCell>
                                            <TableCell className="max-w-xs">
                                                {source.type === 'ats_feed' ? (
                                                    <div className="space-y-0.5">
                                                        <p className="font-medium">
                                                            {source.config?.provider ?? '—'} / {source.config?.board_token ?? '—'}
                                                        </p>
                                                        {source.url && (
                                                            <p className="truncate text-xs text-muted-foreground">{source.url}</p>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <p className="truncate">{source.url}</p>
                                                )}
                                            </TableCell>
                                            <TableCell className="font-mono text-xs">
                                                {source.cron_schedule ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                <Switch
                                                    checked={source.active}
                                                    disabled={toggleActive.isPending}
                                                    onCheckedChange={(active) =>
                                                        toggleActive.mutate({ id: source.id, active })
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground">
                                                {formatDateTime(source.last_scraped_at)}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center justify-end gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => testScrape.mutate(source.id)}
                                                        disabled={testScrape.isPending}
                                                        title="Test scrape"
                                                    >
                                                        <Play />
                                                        {testScrape.isPending && testScrape.variables === source.id
                                                            ? 'Testing…'
                                                            : 'Test'}
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() => openEditForm(source)}
                                                        title="Edit"
                                                    >
                                                        <Pencil />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        onClick={() => handleDelete(source.id)}
                                                        disabled={deleteSource.isPending}
                                                        title="Delete"
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                {(testResult || testError) && (
                    <Card>
                        <CardHeader className="flex-row items-center justify-between space-y-0">
                            <CardTitle>
                                {testError ? 'Test scrape failed' : `Test scrape: ${testResult?.count} job(s) found`}
                            </CardTitle>
                            <Button
                                variant="ghost"
                                size="icon"
                                onClick={() => {
                                    setTestResult(null);
                                    setTestError(null);
                                }}
                            >
                                <X />
                            </Button>
                        </CardHeader>
                        <CardContent>
                            {testError && <p className="text-sm text-destructive">{testError}</p>}
                            {testResult && testResult.jobs.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    The scrape ran successfully but found no jobs. Nothing was saved (this is a dry
                                    run).
                                </p>
                            )}
                            {testResult && testResult.jobs.length > 0 && (
                                <ul className="divide-y">
                                    {testResult.jobs.map((job, i) => (
                                        <li key={i} className="flex items-center justify-between gap-4 py-2">
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">{job.title}</p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {job.company}
                                                    {job.location ? ` · ${job.location}` : ''}
                                                </p>
                                            </div>
                                            {job.remote_type && (
                                                <Badge variant="secondary" className="shrink-0">
                                                    {job.remote_type}
                                                </Badge>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </div>
    );
}
