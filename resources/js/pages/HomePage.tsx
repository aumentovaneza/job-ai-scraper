import {
    ArrowRight,
    BarChart3,
    Briefcase,
    Inbox,
    LayoutList,
    Rss,
    Settings,
    type LucideProps,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { Link } from 'react-router-dom';
import { AppNav } from '@/components/AppNav';
import { EmptyState } from '@/components/EmptyState';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useAiUsage } from '@/hooks/useProfile';
import { useAnalytics } from '@/hooks/useAnalytics';
import { useApplications } from '@/hooks/useApplications';
import { useFollowUps } from '@/hooks/useFollowUps';
import { useJobSourcesCount } from '@/hooks/useJobSourcesCount';
import { useJobsCount } from '@/hooks/useJobsCount';
import { useAuthStore } from '@/store/useAuthStore';

/** Format cents as a dollar string, mirroring the Settings page. */
function dollars(cents: number): string {
    return `$${(cents / 100).toFixed(2)}`;
}

/** Format a 0–1 rate as a whole percentage, or an em dash when there's no data. */
function pct(rate: number | null | undefined): string {
    return rate == null ? '—' : `${Math.round(rate * 100)}%`;
}

const QUICK_LINKS: {
    to: string;
    label: string;
    description: string;
    icon: ComponentType<LucideProps>;
}[] = [
    {
        to: '/jobs',
        label: 'Job feed',
        description: 'Browse scraped jobs, see your match scores, and track the good ones.',
        icon: Briefcase,
    },
    {
        to: '/applications',
        label: 'Pipeline',
        description: 'Drag applications between stages and open any one for detail.',
        icon: LayoutList,
    },
    {
        to: '/insights',
        label: 'Insights',
        description: "What's working across your applications, with a weekly read.",
        icon: BarChart3,
    },
    {
        to: '/follow-ups',
        label: 'Follow-ups',
        description: 'Review AI-drafted nudges for applications that have gone quiet.',
        icon: Inbox,
    },
    {
        to: '/sources',
        label: 'Job sources',
        description: 'Manage the ATS feeds, career pages, and APIs the scraper pulls from.',
        icon: Rss,
    },
    {
        to: '/settings',
        label: 'Settings',
        description: 'Your Anthropic key, resume, targets, spend caps, and data export.',
        icon: Settings,
    },
];

/**
 * Dashboard for the authenticated SPA (T-70 polish). Opens with a headline
 * stat row read live from the analytics endpoint, then quick-link cards into
 * every major screen — each showing its own live count pulled from the API.
 */
export default function HomePage() {
    const user = useAuthStore((s) => s.user);
    const { data, isLoading } = useAnalytics();
    const totals = data?.data.totals;
    const hasData = (totals?.applied ?? 0) > 0;

    const jobs = useJobsCount();
    const applications = useApplications();
    const followUps = useFollowUps();
    const sources = useJobSourcesCount();
    const usage = useAiUsage();

    /** Per-section headline stat shown on each quick-link card, keyed by route. */
    const cardStats: Record<string, { value: string | number; label: string; loading: boolean }> = {
        '/jobs': { value: jobs.data ?? 0, label: 'jobs', loading: jobs.isLoading },
        '/applications': {
            value: applications.data?.length ?? 0,
            label: 'in pipeline',
            loading: applications.isLoading,
        },
        '/insights': { value: pct(totals?.response_rate), label: 'response rate', loading: isLoading },
        '/follow-ups': {
            value: followUps.data?.length ?? 0,
            label: 'pending',
            loading: followUps.isLoading,
        },
        '/sources': { value: sources.data ?? 0, label: 'sources', loading: sources.isLoading },
        '/settings': {
            value: usage.data ? dollars(usage.data.week.spent_cents) : '—',
            label: 'AI spend this week',
            loading: usage.isLoading,
        },
    };

    return (
        <div className="min-h-screen bg-background">
            <AppNav />
            <div className="mx-auto max-w-5xl space-y-8 p-8">
                <div className="space-y-1">
                    <h1 className="text-3xl font-semibold tracking-tight">
                        {user?.name ? `Welcome back, ${user.name.split(' ')[0]}` : 'JobScope'}
                    </h1>
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="text-sm text-muted-foreground">{user?.email}</p>
                        {user?.is_admin && <Badge variant="secondary">Admin</Badge>}
                    </div>
                </div>

                {isLoading ? (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {Array.from({ length: 4 }).map((_, i) => (
                            <Skeleton key={i} className="h-20 w-full" />
                        ))}
                    </div>
                ) : hasData ? (
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <StatTile label="Applications" value={String(totals?.applied ?? 0)} />
                        <StatTile label="In progress" value={String(totals?.in_progress ?? 0)} />
                        <StatTile label="Response rate" value={pct(totals?.response_rate)} />
                        <StatTile
                            label="Offers"
                            value={String(totals?.offers ?? 0)}
                            hint={`${totals?.won ?? 0} won`}
                        />
                    </div>
                ) : (
                    <EmptyState
                        icon={Briefcase}
                        title="No applications yet"
                        description="Browse the job feed and track a role to start building your pipeline — your stats will show up here."
                        action={
                            <Link
                                to="/jobs"
                                className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                            >
                                Browse jobs <ArrowRight className="size-4" />
                            </Link>
                        }
                    />
                )}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {QUICK_LINKS.map(({ to, label, description, icon: Icon }) => {
                        const stat = cardStats[to];
                        return (
                            <Link key={to} to={to} className="group">
                                <Card className="h-full transition-colors group-hover:bg-accent">
                                    <CardHeader>
                                        <div className="mb-1 flex size-9 items-center justify-center rounded-md bg-muted text-muted-foreground">
                                            <Icon className="size-5" />
                                        </div>
                                        <CardTitle className="flex items-center justify-between">
                                            {label}
                                            <ArrowRight className="size-4 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                                        </CardTitle>
                                        {stat && (
                                            <div className="flex items-baseline gap-1.5">
                                                <span className="text-xl font-semibold tabular-nums">
                                                    {stat.loading ? (
                                                        <span className="text-muted-foreground/40">—</span>
                                                    ) : (
                                                        stat.value
                                                    )}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {stat.label}
                                                </span>
                                            </div>
                                        )}
                                        <CardDescription>{description}</CardDescription>
                                    </CardHeader>
                                </Card>
                            </Link>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

function StatTile({ label, value, hint }: { label: string; value: string; hint?: string }) {
    return (
        <Card>
            <CardContent className="p-4">
                <div className="text-2xl font-semibold tracking-tight tabular-nums">{value}</div>
                <div className="mt-1 text-xs font-medium text-muted-foreground">{label}</div>
                {hint && <div className="mt-0.5 text-xs text-muted-foreground/70">{hint}</div>}
            </CardContent>
        </Card>
    );
}
