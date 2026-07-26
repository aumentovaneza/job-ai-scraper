import { type FormEvent, useState } from 'react';
import { Pencil, Plus, Trash2, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { useCreateSnippet, useDeleteSnippet, useSnippets, useUpdateSnippet } from '@/hooks/useSnippets';
import { apiMessage } from '@/lib/apiError';
import type { Snippet } from '@/types/coverLetters';

/**
 * Snippet library management (T-54): reusable letter building blocks (a
 * standard closing, a referral mention paragraph, …) the user inserts into
 * the cover-letter editor. Lives on the settings page alongside the other
 * account-level panels.
 */
export function SnippetsPanel() {
    const { data: snippets, isLoading, isError } = useSnippets();
    const [creating, setCreating] = useState(false);

    return (
        <section className="space-y-3">
            <div className="flex items-center justify-between">
                <h2 className="font-medium">Snippets</h2>
                {!creating && (
                    <Button type="button" size="sm" variant="outline" onClick={() => setCreating(true)}>
                        <Plus /> New snippet
                    </Button>
                )}
            </div>
            <p className="text-xs text-muted-foreground">
                Reusable blocks — a standard closing, a referral mention — you can insert into any cover letter
                while editing.
            </p>

            {isLoading && (
                <div className="space-y-2">
                    <Skeleton className="h-14 w-full" />
                    <Skeleton className="h-14 w-full" />
                </div>
            )}
            {isError && <p className="text-sm text-destructive">Could not load your snippets.</p>}

            {creating && <SnippetForm onDone={() => setCreating(false)} />}

            {!isLoading && !isError && (snippets?.length ?? 0) === 0 && !creating && (
                <p className="text-sm text-muted-foreground">No snippets yet.</p>
            )}

            <div className="space-y-2">
                {snippets?.map((snippet) => (
                    <SnippetRow key={snippet.id} snippet={snippet} />
                ))}
            </div>
        </section>
    );
}

function SnippetRow({ snippet }: { snippet: Snippet }) {
    const [editing, setEditing] = useState(false);
    const deleteSnippet = useDeleteSnippet();

    if (editing) {
        return <SnippetForm snippet={snippet} onDone={() => setEditing(false)} />;
    }

    return (
        <div className="rounded-md border p-3">
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm font-medium">{snippet.label}</p>
                <div className="flex shrink-0 gap-1">
                    <Button type="button" size="icon" variant="ghost" onClick={() => setEditing(true)}>
                        <Pencil className="size-4" />
                        <span className="sr-only">Edit</span>
                    </Button>
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        onClick={() => deleteSnippet.mutate(snippet.id)}
                        disabled={deleteSnippet.isPending}
                    >
                        <Trash2 className="size-4" />
                        <span className="sr-only">Delete</span>
                    </Button>
                </div>
            </div>
            <p className="mt-1 line-clamp-2 whitespace-pre-wrap text-xs text-muted-foreground">
                {snippet.content_md}
            </p>
        </div>
    );
}

function SnippetForm({ snippet, onDone }: { snippet?: Snippet; onDone: () => void }) {
    const create = useCreateSnippet();
    const update = useUpdateSnippet();
    const [label, setLabel] = useState(snippet?.label ?? '');
    const [contentMd, setContentMd] = useState(snippet?.content_md ?? '');
    const [error, setError] = useState<string | null>(null);
    const pending = create.isPending || update.isPending;

    function submit(e: FormEvent) {
        e.preventDefault();
        if (!label.trim() || !contentMd.trim()) return;
        setError(null);

        const onError = (err: unknown) => setError(apiMessage(err, 'Could not save this snippet.'));

        if (snippet) {
            update.mutate(
                { id: snippet.id, label: label.trim(), content_md: contentMd },
                { onSuccess: onDone, onError }
            );
        } else {
            create.mutate({ label: label.trim(), content_md: contentMd }, { onSuccess: onDone, onError });
        }
    }

    return (
        <form onSubmit={submit} className="space-y-2 rounded-md border p-3">
            <div className="flex items-center justify-between">
                <Label htmlFor="snippet-label">Label</Label>
                <Button type="button" size="icon" variant="ghost" onClick={onDone}>
                    <X className="size-4" />
                    <span className="sr-only">Cancel</span>
                </Button>
            </div>
            <Input
                id="snippet-label"
                value={label}
                onChange={(e) => setLabel(e.target.value)}
                placeholder="Standard closing"
                required
            />
            <Label htmlFor="snippet-content">Content (Markdown)</Label>
            <Textarea
                id="snippet-content"
                value={contentMd}
                onChange={(e) => setContentMd(e.target.value)}
                rows={4}
                placeholder="Thank you for your time and consideration…"
                required
            />
            {error && <p className="text-sm text-destructive">{error}</p>}
            <Button type="submit" size="sm" disabled={pending || !label.trim() || !contentMd.trim()}>
                {pending ? 'Saving…' : 'Save snippet'}
            </Button>
        </form>
    );
}
