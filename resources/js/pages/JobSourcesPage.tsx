import { type Dispatch, type FormEvent, type SetStateAction, useState } from 'react';
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
import { Textarea } from '@/components/ui/textarea';
import { api } from '@/lib/api';
import { JSON_API_PRESETS } from '@/lib/jsonApiPresets';
import type {
    AtsProvider,
    JobSource,
    JobSourceConfig,
    JobSourceType,
    NormalizedJob,
    Paginated,
    TimezoneOverlap,
} from '@/types/jobs';

const JOB_SOURCES_KEY = ['job-sources'] as const;

const TYPE_LABELS: Record<JobSourceType, string> = {
    ats_feed: 'ATS feed',
    career_page: 'Career page',
    rss: 'RSS feed',
    json_api: 'JSON API',
};

const PROVIDERS: AtsProvider[] = ['greenhouse', 'lever', 'workable', 'ashby'];

/** Mappable NormalizedJob targets — mirrors JsonApiScraper::ALLOWED_TARGETS. */
const FIELD_MAP_TARGETS = [
    'title',
    'company',
    'location',
    'remote_type',
    'salary',
    'salary_min',
    'salary_max',
    'salary_currency',
    'jd_text',
    'jd_html_snapshot',
    'apply_url',
    'source_url',
    'posted_at',
    'tags',
] as const;

const TIMEZONE_OVERLAP_LABELS: Record<TimezoneOverlap, string> = {
    any: 'Any (async-friendly)',
    partial: 'Partial overlap',
    strict: 'Strict overlap',
};

interface FieldMapRow {
    target: string;
    path: string;
}

interface FormState {
    type: JobSourceType;
    url: string;
    provider: AtsProvider;
    boardToken: string;
    cronSchedule: string;
    active: boolean;
    hiresInternationally: boolean;
    timezoneOverlap: '' | TimezoneOverlap;
    // json_api
    itemsPath: string;
    headersText: string;
    fieldMap: FieldMapRow[];
}

const EMPTY_FORM: FormState = {
    type: 'ats_feed',
    url: '',
    provider: 'greenhouse',
    boardToken: '',
    cronSchedule: '0 * * * *',
    active: true,
    hiresInternationally: true,
    timezoneOverlap: '',
    itemsPath: '',
    headersText: '',
    fieldMap: [{ target: 'title', path: '' }],
};

interface JobSourcePayload {
    type: JobSourceType;
    url: string;
    config: JobSourceConfig | null;
    cron_schedule: string | null;
    active: boolean;
    hires_internationally: boolean;
    timezone_overlap: TimezoneOverlap | null;
}

/** Parse a "Key: Value" per-line textarea into a headers map. */
function parseHeaders(text: string): Record<string, string> {
    const headers: Record<string, string> = {};
    for (const line of text.split('\n')) {
        const idx = line.indexOf(':');
        if (idx === -1) continue;
        const key = line.slice(0, idx).trim();
        const value = line.slice(idx + 1).trim();
        if (key) headers[key] = value;
    }
    return headers;
}

function headersToText(headers: Record<string, string> | undefined): string {
    if (!headers) return '';
    return Object.entries(headers)
        .map(([k, v]) => `${k}: ${v}`)
        .join('\n');
}

function rowsToFieldMap(rows: FieldMapRow[]): Record<string, string> {
    const map: Record<string, string> = {};
    for (const { target, path } of rows) {
        if (target && path.trim()) map[target] = path.trim();
    }
    return map;
}

function fieldMapToRows(map: Record<string, string | string[]> | undefined): FieldMapRow[] {
    if (!map) return [{ target: 'title', path: '' }];
    const rows = Object.entries(map).map(([target, value]) => ({
        target,
        path: Array.isArray(value) ? value.join(', ') : value,
    }));
    return rows.length ? rows : [{ target: 'title', path: '' }];
}

function toPayload(form: FormState): JobSourcePayload {
    let config: JobSourceConfig | null = null;
    if (form.type === 'ats_feed') {
        config = { provider: form.provider, board_token: form.boardToken };
    } else if (form.type === 'json_api') {
        config = {
            items_path: form.itemsPath.trim() ? form.itemsPath.trim() : null,
            headers: parseHeaders(form.headersText),
            field_map: rowsToFieldMap(form.fieldMap),
        };
    }

    return {
        type: form.type,
        url: form.url,
        config,
        cron_schedule: form.cronSchedule.trim() ? form.cronSchedule.trim() : null,
        active: form.active,
        hires_internationally: form.hiresInternationally,
        timezone_overlap: form.timezoneOverlap ? form.timezoneOverlap : null,
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
        hiresInternationally: source.hires_internationally ?? true,
        timezoneOverlap: source.timezone_overlap ?? '',
        itemsPath: source.config?.items_path ?? '',
        headersText: headersToText(source.config?.headers),
        fieldMap: fieldMapToRows(source.config?.field_map),
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

interface JsonApiConfigFieldsProps {
    form: FormState;
    setForm: Dispatch<SetStateAction<FormState>>;
}

/** Config editor for the generic json_api source type: preset, items_path,
 * headers, and the target→path field map. */
function JsonApiConfigFields({ form, setForm }: JsonApiConfigFieldsProps) {
    function applyPreset(key: string) {
        const preset = JSON_API_PRESETS.find((p) => p.key === key);
        if (!preset) return;
        setForm((f) => ({
            ...f,
            url: preset.url,
            itemsPath: preset.itemsPath,
            headersText: headersToText(preset.headers),
            fieldMap: fieldMapToRows(preset.fieldMap),
        }));
    }

    function updateRow(index: number, patch: Partial<FieldMapRow>) {
        setForm((f) => ({
            ...f,
            fieldMap: f.fieldMap.map((row, i) => (i === index ? { ...row, ...patch } : row)),
        }));
    }

    function addRow() {
        setForm((f) => ({ ...f, fieldMap: [...f.fieldMap, { target: 'title', path: '' }] }));
    }

    function removeRow(index: number) {
        setForm((f) => ({ ...f, fieldMap: f.fieldMap.filter((_, i) => i !== index) }));
    }

    return (
        <div className="space-y-4 rounded-md border border-dashed p-4 sm:col-span-2">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="preset">Preset</Label>
                    <Select
                        id="preset"
                        value=""
                        onChange={(e) => {
                            if (e.target.value) applyPreset(e.target.value);
                        }}
                    >
                        <option value="">Load a preset…</option>
                        {JSON_API_PRESETS.map((p) => (
                            <option key={p.key} value={p.key}>
                                {p.label}
                            </option>
                        ))}
                    </Select>
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="items_path">Items path</Label>
                    <Input
                        id="items_path"
                        value={form.itemsPath}
                        onChange={(e) => setForm((f) => ({ ...f, itemsPath: e.target.value }))}
                        placeholder="jobs  (blank = the body is the array)"
                    />
                </div>
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="headers">Request headers</Label>
                <Textarea
                    id="headers"
                    value={form.headersText}
                    onChange={(e) => setForm((f) => ({ ...f, headersText: e.target.value }))}
                    placeholder="User-Agent: Mozilla/5.0 (compatible; JobScraper/1.0)"
                    rows={2}
                    className="font-mono text-xs"
                />
                <p className="text-xs text-muted-foreground">One "Header: value" per line. Some feeds (RemoteOK) require a User-Agent.</p>
            </div>

            <div className="space-y-2">
                <Label>Field map</Label>
                <p className="text-xs text-muted-foreground">
                    Map each posting field to a dot-path in the source item (e.g. <code>company_name</code>,{' '}
                    <code>location.name</code>). Title and company are required.
                </p>
                {form.fieldMap.map((row, i) => (
                    <div key={i} className="flex items-center gap-2">
                        <Select
                            className="max-w-[10rem]"
                            value={row.target}
                            onChange={(e) => updateRow(i, { target: e.target.value })}
                        >
                            {FIELD_MAP_TARGETS.map((t) => (
                                <option key={t} value={t}>
                                    {t}
                                </option>
                            ))}
                        </Select>
                        <Input
                            value={row.path}
                            onChange={(e) => updateRow(i, { path: e.target.value })}
                            placeholder="source path"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            onClick={() => removeRow(i)}
                            title="Remove"
                        >
                            <X />
                        </Button>
                    </div>
                ))}
                <Button type="button" variant="outline" size="sm" onClick={addRow}>
                    <Plus /> Add field
                </Button>
            </div>
        </div>
    );
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
                                            <option value="json_api">JSON API (RemoteOK/Remotive/Himalayas/Arbeitnow…)</option>
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
                                                {form.type === 'rss'
                                                    ? 'RSS feed URL'
                                                    : form.type === 'json_api'
                                                      ? 'JSON API endpoint URL'
                                                      : 'Career page URL'}
                                            </Label>
                                            <Input
                                                id="url"
                                                type="url"
                                                value={form.url}
                                                onChange={(e) => setForm((f) => ({ ...f, url: e.target.value }))}
                                                placeholder={
                                                    form.type === 'json_api'
                                                        ? 'https://remoteok.com/api'
                                                        : 'https://example.com/careers'
                                                }
                                                required
                                            />
                                        </div>
                                    )}

                                    {form.type === 'json_api' && (
                                        <JsonApiConfigFields form={form} setForm={setForm} />
                                    )}

                                    <div className="space-y-1.5">
                                        <Label htmlFor="hires_internationally">Hires internationally</Label>
                                        <div>
                                            <Switch
                                                id="hires_internationally"
                                                checked={form.hiresInternationally}
                                                onCheckedChange={(hiresInternationally) =>
                                                    setForm((f) => ({ ...f, hiresInternationally }))
                                                }
                                            />
                                        </div>
                                    </div>

                                    <div className="space-y-1.5">
                                        <Label htmlFor="timezone_overlap">Timezone overlap</Label>
                                        <Select
                                            id="timezone_overlap"
                                            value={form.timezoneOverlap}
                                            onChange={(e) =>
                                                setForm((f) => ({
                                                    ...f,
                                                    timezoneOverlap: e.target.value as '' | TimezoneOverlap,
                                                }))
                                            }
                                        >
                                            <option value="">Unset</option>
                                            {(Object.keys(TIMEZONE_OVERLAP_LABELS) as TimezoneOverlap[]).map((tz) => (
                                                <option key={tz} value={tz}>
                                                    {TIMEZONE_OVERLAP_LABELS[tz]}
                                                </option>
                                            ))}
                                        </Select>
                                    </div>

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
                                                    {job.salary_min || job.salary_max
                                                        ? ` · ${[job.salary_min, job.salary_max]
                                                              .filter(Boolean)
                                                              .join('–')} ${job.salary_currency ?? ''}`.trimEnd()
                                                        : ''}
                                                </p>
                                                {job.tags && job.tags.length > 0 && (
                                                    <div className="mt-1 flex flex-wrap gap-1">
                                                        {job.tags.slice(0, 6).map((tag) => (
                                                            <Badge key={tag} variant="outline" className="text-xs">
                                                                {tag}
                                                            </Badge>
                                                        ))}
                                                    </div>
                                                )}
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
