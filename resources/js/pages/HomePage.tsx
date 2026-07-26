import { Link } from 'react-router-dom';
import {
    BellRing,
    Briefcase,
    ChevronRight,
    KanbanSquare,
    LineChart,
    type LucideIcon,
    Rss,
    Settings,
} from 'lucide-react';
import { AppNav } from '@/components/AppNav';
import { Badge } from '@/components/ui/badge';
import { Card, CardDescription, CardTitle } from '@/components/ui/card';
import { useAnalytics } from '@/hooks/useAnalytics';
import { useApplications } from '@/hooks/useApplications';
import { useFollowUps } from '@/hooks/useFollowUps';
import { useJobSourcesCount } from '@/hooks/useJobSourcesCount';
import { useJobsCount } from '@/hooks/useJobsCount';
import { useAiUsage } from '@/hooks/useProfile';
import { useAuthStore } from '@/store/useAuthStore';

/** Format cents as a dollar string, mirroring the Settings page. */
function dollars(cents: number): string {
    return `$${(cents / 100).toFixed(2)}`;
}

/** Format a 0–1 rate as a whole percentage, mirroring the Insights page. */
function pct(rate: number | null | undefined): string {
    return rate == null ? '—' : `${Math.round(rate * 100)}%`;
}

interface StatCardProps {
    to: string;
    icon: LucideIcon;
    title: string;
    description: string;
    /** The headline number/string; omit for a navigation-only card. */
    stat?: string | number;
    statLabel?: string;
    loading?: boolean;
}

/**
 * A single dashboard tile: a tinted icon, a live headline stat, and a link out
 * to the matching screen. Shows an em dash while its query is loading so a slow
 * (or failed) endpoint never blanks the whole page.
 */
function StatCard({ to, icon: Icon, title, description, stat, statLabel, loading }: StatCardProps) {
    return (
        <Link to={to} className="group">
            <Card className="h-full p-5 transition-colors hover:border-primary/40 hover:bg-accent">
                <div className="flex items-start justify-between">
                    <div className="flex size-10 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                        <Icon className="size-5" />
                    </div>
                    <ChevronRight className="size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                </div>
                {stat !== undefined && (
                    <div className="mt-4 flex items-baseline gap-2">
                        <span className="text-2xl font-semibold tabular-nums">
                            {loading ? <span className="text-muted-foreground/40">—</span> : stat}
                        </span>
                        {statLabel && <span className="text-sm text-muted-foreground">{statLabel}</span>}
                    </div>
                )}
                <CardTitle className={stat === undefined ? 'mt-4 text-base' : 'mt-3 text-base'}>
                    {title}
                </CardTitle>
                <CardDescription className="mt-1">{description}</CardDescription>
            </Card>
        </Link>
    );
}

/**
 * Landing dashboard for the authenticated SPA shell: a live overview tile per
 * pipeline screen (job feed, pipeline, insights, follow-ups, sources, settings)
 * with real counts pulled from the existing API, each linking to its section.
 */
export default function HomePage() {
    const user = useAuthStore((s) => s.user);

    const jobs = useJobsCount();
    const applications = useApplications();
    const analytics = useAnalytics();
    const followUps = useFollowUps();
    const sources = useJobSourcesCount();
    const usage = useAiUsage();

    return (
        <div className="min-h-screen bg-background">
            <AppNav />
            <div className="mx-auto max-w-5xl space-y-8 p-8">
                <div className="space-y-2">
                    <h1 className="text-3xl font-semibold tracking-tight">JobScope</h1>
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="text-muted-foreground">Welcome back, {user?.name ?? 'there'}</p>
                        {user?.is_admin && <Badge variant="secondary">Admin</Badge>}
                    </div>
                    {user?.email && <p className="text-sm text-muted-foreground/70">{user.email}</p>}
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <StatCard
                        to="/jobs"
                        icon={Briefcase}
                        title="Job feed"
                        description="Browse jobs scraped from your sources, filter and search."
                        stat={jobs.data ?? 0}
                        statLabel="jobs"
                        loading={jobs.isLoading}
                    />
                    <StatCard
                        to="/applications"
                        icon={KanbanSquare}
                        title="Pipeline"
                        description="Track applications through every stage of your search."
                        stat={applications.data?.length ?? 0}
                        statLabel="in pipeline"
                        loading={applications.isLoading}
                    />
                    <StatCard
                        to="/insights"
                        icon={LineChart}
                        title="Insights"
                        description="Conversion analytics and your weekly narrative."
                        stat={pct(analytics.data?.data.totals.response_rate)}
                        statLabel="response rate"
                        loading={analytics.isLoading}
                    />
                    <StatCard
                        to="/follow-ups"
                        icon={BellRing}
                        title="Follow-ups"
                        description="Drafted and pending nudges awaiting your review."
                        stat={followUps.data?.length ?? 0}
                        statLabel="pending"
                        loading={followUps.isLoading}
                    />
                    <StatCard
                        to="/sources"
                        icon={Rss}
                        title="Job sources"
                        description="Manage ATS feeds, career pages, and RSS feeds the scraper pulls from."
                        stat={sources.data ?? 0}
                        statLabel="sources"
                        loading={sources.isLoading}
                    />
                    <StatCard
                        to="/settings"
                        icon={Settings}
                        title="Settings"
                        description="Manage your Anthropic key, resume, targets, and AI spend caps."
                        stat={usage.data ? dollars(usage.data.week.spent_cents) : undefined}
                        statLabel="AI spend this week"
                        loading={usage.isLoading}
                    />
                </div>
            </div>
        </div>
    );
}
