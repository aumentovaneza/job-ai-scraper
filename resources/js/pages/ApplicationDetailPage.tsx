import { useState } from 'react';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import { Link, useParams } from 'react-router-dom';
import { AppNav } from '@/components/AppNav';
import { MatchScoreBadge } from '@/components/MatchScoreBadge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useAddContact, useAddNote, useApplication } from '@/hooks/useApplications';
import { apiMessage } from '@/lib/apiError';
import { formatSalary } from '@/lib/jobFormat';
import type { ApplicationEvent } from '@/types/applications';

export default function ApplicationDetailPage() {
    const { id } = useParams<{ id: string }>();
    const { data: application, isLoading, isError } = useApplication(id ?? '');

    return (
        <div className="min-h-screen bg-background">
            <AppNav />
            <div className="mx-auto max-w-4xl space-y-6 p-6">
                <Button variant="ghost" size="sm" asChild className="-ml-2 w-fit">
                    <Link to="/applications">
                        <ArrowLeft /> Back to pipeline
                    </Link>
                </Button>

                {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}
                {isError && (
                    <p className="text-sm text-destructive">Could not load this application. Try refreshing.</p>
                )}

                {application && (
                    <>
                        <header className="space-y-2">
                            <div className="flex flex-wrap items-center gap-3">
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    {application.job_posting?.title ?? 'Untitled role'}
                                </h1>
                                {application.current_stage && (
                                    <Badge variant="secondary">{application.current_stage.name}</Badge>
                                )}
                                <MatchScoreBadge matchScore={application.job_posting?.match_score ?? null} />
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {[
                                    application.job_posting?.company ?? 'Unknown company',
                                    application.job_posting?.location,
                                    application.job_posting ? formatSalary(application.job_posting) : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                            {application.job_posting?.apply_url && (
                                <Button variant="outline" size="sm" asChild className="w-fit">
                                    <a href={application.job_posting.apply_url} target="_blank" rel="noreferrer">
                                        <ExternalLink /> View posting
                                    </a>
                                </Button>
                            )}
                        </header>

                        <MatchReasoning matchScore={application.job_posting?.match_score ?? null} />

                        <div className="grid gap-6 md:grid-cols-2">
                            <Timeline events={application.events ?? []} />
                            <div className="space-y-6">
                                <NotesCard applicationId={application.id} />
                                <ContactsCard applicationId={application.id} contacts={application.contacts ?? []} />
                                <FollowUpsCard followUps={application.follow_ups ?? []} />
                                <CoverLetterCard />
                            </div>
                        </div>

                        <JdSnapshot jd={application.job_posting?.jd_text ?? null} />
                    </>
                )}
            </div>
        </div>
    );
}

function MatchReasoning({ matchScore }: { matchScore: ApplicationDetailMatch }) {
    if (!matchScore || matchScore.score == null) return null;
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Why this match</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
                {matchScore.reasoning && <p className="text-muted-foreground">{matchScore.reasoning}</p>}
                {matchScore.strengths && matchScore.strengths.length > 0 && (
                    <ChipRow label="Strengths" items={matchScore.strengths} tone="emerald" />
                )}
                {matchScore.gaps && matchScore.gaps.length > 0 && (
                    <ChipRow label="Gaps" items={matchScore.gaps} tone="rose" />
                )}
            </CardContent>
        </Card>
    );
}

type ApplicationDetailMatch = { score: number | null; reasoning: string | null; strengths: string[] | null; gaps: string[] | null } | null;

function ChipRow({ label, items, tone }: { label: string; items: string[]; tone: 'emerald' | 'rose' }) {
    const toneClass =
        tone === 'emerald'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-400'
            : 'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-400';
    return (
        <div>
            <p className="mb-1 text-xs font-medium text-muted-foreground">{label}</p>
            <div className="flex flex-wrap gap-1.5">
                {items.map((item, i) => (
                    <span key={i} className={`rounded-md border px-2 py-0.5 text-xs ${toneClass}`}>
                        {item}
                    </span>
                ))}
            </div>
        </div>
    );
}

function Timeline({ events }: { events: ApplicationEvent[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Timeline</CardTitle>
            </CardHeader>
            <CardContent>
                {events.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No activity yet.</p>
                ) : (
                    <ol className="space-y-3">
                        {events.map((event) => (
                            <li key={event.id} className="flex flex-col gap-0.5 border-l-2 border-muted pl-3">
                                <span className="text-sm">{describeEvent(event)}</span>
                                <span className="text-xs text-muted-foreground">
                                    {formatDateTime(event.occurred_at)}
                                    {event.actor !== 'user' ? ` · ${event.actor}` : ''}
                                </span>
                            </li>
                        ))}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}

function NotesCard({ applicationId }: { applicationId: number }) {
    const [body, setBody] = useState('');
    const addNote = useAddNote(applicationId);
    const [error, setError] = useState<string | null>(null);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (!body.trim()) return;
        setError(null);
        addNote.mutate(body.trim(), {
            onSuccess: () => setBody(''),
            onError: (err) => setError(apiMessage(err, 'Could not save the note.')),
        });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Add a note</CardTitle>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-2">
                    <Textarea
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        placeholder="Recruiter call notes, next steps…"
                        rows={3}
                    />
                    {error && <p className="text-sm text-destructive">{error}</p>}
                    <Button type="submit" size="sm" disabled={addNote.isPending || !body.trim()}>
                        {addNote.isPending ? 'Saving…' : 'Add note'}
                    </Button>
                    <p className="text-xs text-muted-foreground">Notes show up in the timeline.</p>
                </form>
            </CardContent>
        </Card>
    );
}

function ContactsCard({
    applicationId,
    contacts,
}: {
    applicationId: number;
    contacts: { id: number; name: string; role: string | null; email: string | null }[];
}) {
    const addContact = useAddContact(applicationId);
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState({ name: '', role: '', email: '' });
    const [error, setError] = useState<string | null>(null);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (!form.name.trim()) return;
        setError(null);
        addContact.mutate(
            { name: form.name.trim(), role: form.role || undefined, email: form.email || undefined },
            {
                onSuccess: () => {
                    setForm({ name: '', role: '', email: '' });
                    setOpen(false);
                },
                onError: (err) => setError(apiMessage(err, 'Could not save the contact.')),
            }
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Contacts</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
                {contacts.length === 0 && <p className="text-sm text-muted-foreground">No contacts yet.</p>}
                {contacts.map((c) => (
                    <div key={c.id} className="text-sm">
                        <p className="font-medium">
                            {c.name}
                            {c.role ? <span className="text-muted-foreground"> · {c.role}</span> : null}
                        </p>
                        {c.email && <p className="text-xs text-muted-foreground">{c.email}</p>}
                    </div>
                ))}

                {open ? (
                    <form onSubmit={submit} className="space-y-2 border-t pt-3">
                        <div className="space-y-1">
                            <Label htmlFor="contact-name">Name</Label>
                            <Input
                                id="contact-name"
                                value={form.name}
                                onChange={(e) => setForm({ ...form, name: e.target.value })}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="contact-role">Role</Label>
                            <Input
                                id="contact-role"
                                value={form.role}
                                onChange={(e) => setForm({ ...form, role: e.target.value })}
                                placeholder="Recruiter, Hiring manager…"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="contact-email">Email</Label>
                            <Input
                                id="contact-email"
                                type="email"
                                value={form.email}
                                onChange={(e) => setForm({ ...form, email: e.target.value })}
                            />
                        </div>
                        {error && <p className="text-sm text-destructive">{error}</p>}
                        <div className="flex gap-2">
                            <Button type="submit" size="sm" disabled={addContact.isPending || !form.name.trim()}>
                                {addContact.isPending ? 'Saving…' : 'Save contact'}
                            </Button>
                            <Button type="button" size="sm" variant="ghost" onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                        </div>
                    </form>
                ) : (
                    <Button type="button" size="sm" variant="outline" onClick={() => setOpen(true)}>
                        Add contact
                    </Button>
                )}
            </CardContent>
        </Card>
    );
}

function FollowUpsCard({ followUps }: { followUps: { id: number; status: string; due_at: string | null; draft_content: string | null }[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Follow-ups</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
                {followUps.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No follow-ups drafted. If this application goes quiet, one will be suggested here.
                    </p>
                ) : (
                    followUps.map((f) => (
                        <div key={f.id} className="rounded-md border p-2 text-sm">
                            <div className="flex items-center justify-between">
                                <Badge variant="outline">{f.status}</Badge>
                                <span className="text-xs text-muted-foreground">{formatDateTime(f.due_at)}</span>
                            </div>
                            {f.draft_content && (
                                <p className="mt-2 whitespace-pre-wrap text-muted-foreground">{f.draft_content}</p>
                            )}
                        </div>
                    ))
                )}
            </CardContent>
        </Card>
    );
}

function CoverLetterCard() {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Cover letter</CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-sm text-muted-foreground">Tailored cover letters arrive in Phase 5.</p>
            </CardContent>
        </Card>
    );
}

function JdSnapshot({ jd }: { jd: string | null }) {
    if (!jd) return null;
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Job description</CardTitle>
            </CardHeader>
            <CardContent>
                <p className="whitespace-pre-wrap text-sm text-muted-foreground">{jd}</p>
            </CardContent>
        </Card>
    );
}

function describeEvent(event: ApplicationEvent): string {
    switch (event.type) {
        case 'created':
            return `Started tracking${event.to_stage ? ` · ${event.to_stage.name}` : ''}`;
        case 'stage_changed':
            return `Moved ${event.from_stage?.name ?? '—'} → ${event.to_stage?.name ?? '—'}`;
        case 'note':
            return `Note: ${String(event.metadata?.body ?? '')}`;
        case 'contact_added':
            return `Added contact ${String(event.metadata?.name ?? '')}`;
        case 'follow_up_drafted':
            return 'Follow-up drafted';
        default:
            return event.type;
    }
}

function formatDateTime(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}
