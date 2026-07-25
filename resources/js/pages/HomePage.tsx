import { Link } from 'react-router-dom';
import { AppNav } from '@/components/AppNav';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useAuthStore } from '@/store/useAuthStore';

/**
 * Landing page for the authenticated SPA shell. Quick links out to the
 * scraping pipeline screens (job feed, job sources) and account settings;
 * more will land as later phases (matching, tracker, letters) ship.
 */
export default function HomePage() {
    const user = useAuthStore((s) => s.user);

    return (
        <div className="min-h-screen bg-background">
            <AppNav />
            <div className="mx-auto max-w-5xl space-y-8 p-8">
                <div className="space-y-2">
                    <h1 className="text-3xl font-semibold tracking-tight">JobScope</h1>
                    <p className="text-muted-foreground">
                        Signed in as {user?.name} ({user?.email}){user?.is_admin ? ' · admin' : ''}
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Link to="/jobs">
                        <Card className="h-full transition-colors hover:bg-accent">
                            <CardHeader>
                                <CardTitle>Job feed</CardTitle>
                                <CardDescription>
                                    Browse jobs scraped from your sources, filter and search.
                                </CardDescription>
                            </CardHeader>
                        </Card>
                    </Link>
                    <Link to="/sources">
                        <Card className="h-full transition-colors hover:bg-accent">
                            <CardHeader>
                                <CardTitle>Job sources</CardTitle>
                                <CardDescription>
                                    Manage ATS feeds, career pages, and RSS feeds the scraper pulls from.
                                </CardDescription>
                            </CardHeader>
                        </Card>
                    </Link>
                    <Link to="/settings">
                        <Card className="h-full transition-colors hover:bg-accent">
                            <CardHeader>
                                <CardTitle>Settings</CardTitle>
                                <CardDescription>
                                    Manage your Anthropic key, resume, targets, and AI spend caps.
                                </CardDescription>
                            </CardHeader>
                        </Card>
                    </Link>
                </div>
            </div>
        </div>
    );
}
