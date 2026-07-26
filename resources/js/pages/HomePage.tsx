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
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useAnalytics } from '@/hooks/useAnalytics';
import { useAuthStore } from '@/store/useAuthStore';

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
 * every major screen.
 */
export default function HomePage() {
    const user = useAuthStore((s) => s.user);
    const { data, isLoading } = useAnalytics();
    const totals = data?.data.totals;
    const hasData = (totals?.applied ?? 0) > 0;

    return (
        <div className="min-h-screen bg-background">
            <AppNav />
            <div className="mx-auto max-w-5xl space-y-8 p-8">
                <div className="space-y-1">
                    <h1 className="text-3xl font-semibold tracking-tight">
                        {user?.name ? `Welcome back, ${user.name.split(' ')[0]}` : 'JobScope'}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {user?.email}
                        {user?.is_admin ? ' · admin' : ''}
                    </p>
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
                    {QUICK_LINKS.map(({ to, label, description, icon: Icon }) => (
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
                                    <CardDescription>{description}</CardDescription>
                                </CardHeader>
                            </Card>
                        </Link>
                    ))}
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
