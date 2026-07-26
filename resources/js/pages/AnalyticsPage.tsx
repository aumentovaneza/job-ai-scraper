import { BarChart3 } from 'lucide-react';
import { AppNav } from '@/components/AppNav';
import { EmptyState } from '@/components/EmptyState';
import { ErrorState } from '@/components/ErrorState';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useAnalytics } from '@/hooks/useAnalytics';
import { humanizeVariant, renderInlineHtml, splitParagraphs } from '@/lib/markdown';
import type {
    AnalyticsOverview,
    GapTally,
    SourceBreakdown,
    StageDwell,
    VariantBreakdown,
} from '@/types/analytics';

/** Format a 0–1 rate as a whole percentage, or an em dash when there's no data. */
function pct(rate: number | null): string {
    return rate === null ? '—' : `${Math.round(rate * 100)}%`;
}

/**
 * Insights dashboard (T-61): headline conversion tiles, the breakdowns that
 * explain them (by source, by cover-letter angle, time in each stage, recurring
 * gaps), and the weekly Claude-written narrative. All numbers come live from the
 * application tracker via GET /api/analytics.
 */
export default function AnalyticsPage() {
    const { data, isLoading, isError, refetch } = useAnalytics();
    const overview = data?.data;
    const summary = data?.summary;
    const hasData = (overview?.totals.applied ?? 0) > 0;

    return (
        <div className="min-h-screen bg-background">
            <AppNav />
            <div className="mx-auto max-w-5xl space-y-6 p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Insights</h1>
                    <p className="text-sm text-muted-foreground">
                        What's working across your applications — and what to change next.
                    </p>
                </div>

                {isLoading && <AnalyticsSkeleton />}
                {isError && (
                    <ErrorState message="Could not load your insights." onRetry={() => void refetch()} />
                )}

                {overview && !isLoading && !isError && (
                    <>
                        {!hasData && (
                            <EmptyState
                                icon={BarChart3}
                                title="No insights yet"
                                description="Once you start tracking applications, your response rates, conversion, and weekly summary will appear here."
                            />
                        )}

                        {hasData && (
                            <>
                                {summary?.summary_md && <NarrativeCard markdown={summary.summary_md} />}
                                <TileRow overview={overview} />
                                <div className="grid gap-6 md:grid-cols-2">
                                    <SourceCard rows={overview.response_rate_by_source} />
                                    <VariantCard rows={overview.response_rate_by_variant} />
                                    <StageCard rows={overview.time_in_stage} />
                                    <GapsCard rows={overview.top_gaps} />
                                </div>
                            </>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}

/** Skeleton mirroring the narrative card, stat tiles, and breakdown grid so the layout doesn't jump on load. */
function AnalyticsSkeleton() {
    return (
        <div className="space-y-6">
            <Skeleton className="h-32 w-full" />
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Skeleton className="h-20 w-full" />
                <Skeleton className="h-20 w-full" />
                <Skeleton className="h-20 w-full" />
                <Skeleton className="h-20 w-full" />
            </div>
            <div className="grid gap-6 md:grid-cols-2">
                <Skeleton className="h-40 w-full" />
                <Skeleton className="h-40 w-full" />
                <Skeleton className="h-40 w-full" />
                <Skeleton className="h-40 w-full" />
            </div>
        </div>
    );
}

function NarrativeCard({ markdown }: { markdown: string }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">This week's read</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm leading-relaxed text-foreground/90">
                {splitParagraphs(markdown).map((para, i) => (
                    // eslint-disable-next-line react/no-array-index-key
                    <p key={i} dangerouslySetInnerHTML={{ __html: renderInlineHtml(para) }} />
                ))}
            </CardContent>
        </Card>
    );
}

function StatTile({ label, value, hint }: { label: string; value: string; hint?: string }) {
    return (
        <Card>
            <CardContent className="p-4">
                <div className="text-2xl font-semibold tracking-tight">{value}</div>
                <div className="mt-1 text-xs font-medium text-muted-foreground">{label}</div>
                {hint && <div className="mt-0.5 text-xs text-muted-foreground/70">{hint}</div>}
            </CardContent>
        </Card>
    );
}

function TileRow({ overview }: { overview: AnalyticsOverview }) {
    const t = overview.totals;
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label="Applications" value={String(t.applied)} hint={`${t.in_progress} in progress`} />
            <StatTile
                label="Response rate"
                value={pct(t.response_rate)}
                hint={`${t.responded} of ${t.applied} replied`}
            />
            <StatTile
                label="Interview → offer"
                value={pct(t.interview_to_offer_rate)}
                hint={`${t.offers} ${t.offers === 1 ? 'offer' : 'offers'}`}
            />
            <StatTile
                label="Outcomes"
                value={`${t.won} / ${t.rejected}`}
                hint="won / rejected"
            />
        </div>
    );
}

/** A labeled proportion bar with a right-aligned rate. */
function RateBar({ label, rate, caption }: { label: string; rate: number | null; caption: string }) {
    const width = rate === null ? 0 : Math.round(rate * 100);
    return (
        <div className="space-y-1">
            <div className="flex items-center justify-between gap-3 text-sm">
                <span className="truncate">{label}</span>
                <span className="shrink-0 tabular-nums text-muted-foreground">
                    {pct(rate)} <span className="text-muted-foreground/60">· {caption}</span>
                </span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-muted">
                <div className="h-full rounded-full bg-primary" style={{ width: `${width}%` }} />
            </div>
        </div>
    );
}

function SourceCard({ rows }: { rows: SourceBreakdown[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Response rate by source</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {rows.length === 0 && <p className="text-sm text-muted-foreground">No data yet.</p>}
                {rows.map((row) => (
                    <RateBar
                        key={row.source_id ?? 'direct'}
                        label={row.label}
                        rate={row.response_rate}
                        caption={`${row.responded}/${row.applied}`}
                    />
                ))}
            </CardContent>
        </Card>
    );
}

function VariantCard({ rows }: { rows: VariantBreakdown[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Response rate by letter angle</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {rows.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        No sent letters yet — mark a cover-letter version sent to compare angles.
                    </p>
                )}
                {rows.map((row) => (
                    <RateBar
                        key={row.variant}
                        label={humanizeVariant(row.variant)}
                        rate={row.response_rate}
                        caption={`${row.responded}/${row.sent} sent`}
                    />
                ))}
            </CardContent>
        </Card>
    );
}

function StageCard({ rows }: { rows: StageDwell[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Time in each stage</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
                {rows.length === 0 && <p className="text-sm text-muted-foreground">No data yet.</p>}
                {rows.map((row) => (
                    <div key={row.stage_id} className="flex items-center justify-between text-sm">
                        <span className="truncate">{row.name}</span>
                        <span className="shrink-0 tabular-nums text-muted-foreground">
                            {row.avg_days} {row.avg_days === 1 ? 'day' : 'days'} avg
                            <span className="text-muted-foreground/60"> · n={row.samples}</span>
                        </span>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

function GapsCard({ rows }: { rows: GapTally[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Recurring gaps on rejections</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-wrap gap-2">
                {rows.length === 0 && (
                    <p className="text-sm text-muted-foreground">No rejections with scored gaps yet.</p>
                )}
                {rows.map((row) => (
                    <Badge key={row.gap} variant="secondary" className="font-normal">
                        {row.gap}
                        <span className="ml-1.5 text-muted-foreground">×{row.count}</span>
                    </Badge>
                ))}
            </CardContent>
        </Card>
    );
}
