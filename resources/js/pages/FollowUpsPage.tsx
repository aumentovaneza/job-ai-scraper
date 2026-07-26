import { useState } from 'react';
import { Check, Copy, Inbox, X } from 'lucide-react';
import { Link } from 'react-router-dom';
import { AppNav } from '@/components/AppNav';
import { EmptyState } from '@/components/EmptyState';
import { ErrorState } from '@/components/ErrorState';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useFollowUps, useUpdateFollowUp, type InboxFollowUp } from '@/hooks/useFollowUps';

/**
 * Follow-up review inbox (T-45). Lists AI-drafted nudges for applications that
 * have gone quiet; the user copies a draft, then marks it sent or dismisses it.
 * Nothing is auto-sent — human review always (PLAN.md §7).
 */
export default function FollowUpsPage() {
    const { data: followUps, isLoading, isError, refetch } = useFollowUps();

    return (
        <div className="min-h-screen bg-background">
            <AppNav />
            <div className="mx-auto max-w-3xl space-y-6 p-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Follow-ups</h1>
                    <p className="text-sm text-muted-foreground">
                        Suggested nudges for applications that have gone quiet. Review, copy, and send them
                        yourself — nothing is sent automatically.
                    </p>
                </div>

                {isLoading && (
                    <div className="space-y-4">
                        <Skeleton className="h-32 w-full" />
                        <Skeleton className="h-32 w-full" />
                        <Skeleton className="h-32 w-full" />
                    </div>
                )}
                {isError && (
                    <ErrorState message="Could not load your follow-ups." onRetry={() => void refetch()} />
                )}

                {!isLoading && !isError && (followUps?.length ?? 0) === 0 && (
                    <EmptyState
                        icon={Inbox}
                        title="No follow-ups to review"
                        description="Drafts appear here when an application sits without a stage change for a while."
                    />
                )}

                <div className="space-y-4">
                    {followUps?.map((followUp) => (
                        <FollowUpCard key={followUp.id} followUp={followUp} />
                    ))}
                </div>
            </div>
        </div>
    );
}

function FollowUpCard({ followUp }: { followUp: InboxFollowUp }) {
    const update = useUpdateFollowUp();
    const [copied, setCopied] = useState(false);
    const job = followUp.application?.job_posting;

    async function copy() {
        if (!followUp.draft_content) return;
        await navigator.clipboard.writeText(followUp.draft_content);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    }

    return (
        <Card>
            <CardHeader>
                <div className="flex items-start justify-between gap-3">
                    <CardTitle className="text-base">
                        {followUp.application ? (
                            <Link
                                to={`/applications/${followUp.application.id}`}
                                className="hover:underline underline-offset-4"
                            >
                                {job?.title ?? 'Application'}
                            </Link>
                        ) : (
                            (job?.title ?? 'Application')
                        )}
                        {job?.company && <span className="text-muted-foreground"> · {job.company}</span>}
                    </CardTitle>
                    <Badge variant="outline">{followUp.status}</Badge>
                </div>
            </CardHeader>
            <CardContent className="space-y-3">
                {followUp.draft_content && (
                    <p className="whitespace-pre-wrap rounded-md border bg-muted/40 p-3 text-sm text-foreground/90">
                        {followUp.draft_content}
                    </p>
                )}
                <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" onClick={copy} disabled={!followUp.draft_content}>
                        {copied ? <Check /> : <Copy />}
                        {copied ? 'Copied' : 'Copy'}
                    </Button>
                    <Button
                        size="sm"
                        onClick={() => update.mutate({ id: followUp.id, status: 'sent' })}
                        disabled={update.isPending}
                    >
                        <Check /> Mark sent
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => update.mutate({ id: followUp.id, status: 'dismissed' })}
                        disabled={update.isPending}
                    >
                        <X /> Dismiss
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}
