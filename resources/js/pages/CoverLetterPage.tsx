import { type ChangeEvent, type FormEvent, useEffect, useRef, useState } from 'react';
import {
    ArrowLeft,
    Check,
    ClipboardCopy,
    Copy,
    Download,
    Sparkles,
    Wand2,
    X,
} from 'lucide-react';
import { Link, useParams } from 'react-router-dom';
import { AppNav } from '@/components/AppNav';
import { EmptyState } from '@/components/EmptyState';
import { ErrorState } from '@/components/ErrorState';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { useApplication } from '@/hooks/useApplications';
import {
    type GenerateLetterPayload,
    useCoverLetter,
    useGenerateLetter,
    useRegenerateParagraph,
    useSetActiveVersion,
    useUpdateVersionContent,
} from '@/hooks/useCoverLetters';
import { useSnippets } from '@/hooks/useSnippets';
import { friendlyApiMessage } from '@/lib/apiError';
import { downloadFile } from '@/lib/download';
import {
    copyPlainText,
    copyRichText,
    humanizeVariant,
    joinParagraphs,
    renderInlineHtml,
    splitParagraphs,
} from '@/lib/markdown';
import { cn } from '@/lib/utils';
import type { CoverLetterGenerationParams, CoverLetterVersion } from '@/types/coverLetters';

const VARIANT_OPTIONS = [
    { value: 'story_led', label: 'Story-led' },
    { value: 'results_led', label: 'Results-led' },
    { value: 'culture_led', label: 'Culture-led' },
] as const;

const EXPORT_FORMATS = ['docx', 'pdf', 'txt', 'md'] as const;

/**
 * Cover-letter editor (T-53/T-54/T-55), reached from the application detail
 * page. Drafts 3 angled variants (T-52), lets the user pick/edit one with a
 * Markdown + live-preview split pane, regenerate individual paragraphs,
 * insert saved snippets, and export/copy the result.
 */
export default function CoverLetterPage() {
    const { id } = useParams<{ id: string }>();
    const applicationId = id ?? '';

    const [dialogOpen, setDialogOpen] = useState(false);
    const [polling, setPolling] = useState(false);
    const [requestedVariantCount, setRequestedVariantCount] = useState(0);
    const [nudge, setNudge] = useState<string | null>(null);
    const [selectedVersionId, setSelectedVersionId] = useState<number | null>(null);

    const { data: application, isLoading: applicationLoading, isError: applicationError } = useApplication(applicationId);
    const {
        data: coverLetter,
        isLoading: letterLoading,
        isError: letterError,
    } = useCoverLetter(applicationId, polling ? 3000 : false);

    const versions = coverLetter?.versions ?? [];

    // Stop polling once every requested variant has landed, or after 90s so a
    // stuck job doesn't poll forever.
    useEffect(() => {
        if (!polling) return;
        if (versions.length >= requestedVariantCount) setPolling(false);
    }, [polling, versions.length, requestedVariantCount]);

    useEffect(() => {
        if (!polling) return;
        const timeout = window.setTimeout(() => setPolling(false), 90_000);
        return () => window.clearTimeout(timeout);
    }, [polling]);

    // Keep a valid selection: default to the active version, then the newest.
    useEffect(() => {
        if (versions.length === 0) return;
        if (selectedVersionId && versions.some((v) => v.id === selectedVersionId)) return;
        setSelectedVersionId(coverLetter?.active_version_id ?? versions[0].id);
    }, [versions, selectedVersionId, coverLetter?.active_version_id]);

    const selectedVersion = versions.find((v) => v.id === selectedVersionId) ?? null;
    const isLoading = applicationLoading || letterLoading;
    const isError = applicationError || letterError;
    const stillGenerating = polling && versions.length < requestedVariantCount;

    return (
        <div className="min-h-screen bg-background">
            <AppNav />
            <div className="mx-auto max-w-6xl space-y-6 p-6">
                <Button variant="ghost" size="sm" asChild className="-ml-2 w-fit">
                    <Link to={`/applications/${applicationId}`}>
                        <ArrowLeft /> Back to application
                    </Link>
                </Button>

                <header className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">Cover letter</h1>
                    {application && (
                        <p className="text-sm text-muted-foreground">
                            {application.job_posting?.title ?? 'Untitled role'}
                            {application.job_posting?.company ? ` · ${application.job_posting.company}` : ''}
                        </p>
                    )}
                </header>

                {nudge && (
                    <div className="flex items-center justify-between gap-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-400">
                        <span>{nudge}</span>
                        <Button size="icon" variant="ghost" onClick={() => setNudge(null)}>
                            <X className="size-4" />
                            <span className="sr-only">Dismiss</span>
                        </Button>
                    </div>
                )}

                {isLoading && (
                    <div className="grid gap-6 md:grid-cols-3">
                        <div className="space-y-4 md:col-span-2">
                            <Skeleton className="h-9 w-40" />
                            <Skeleton className="h-72 w-full" />
                        </div>
                        <Skeleton className="h-40 w-full" />
                    </div>
                )}
                {isError && <ErrorState message="Could not load this cover letter." />}

                {!isLoading && !isError && (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <VariantSwitcher
                                versions={versions}
                                activeVersionId={coverLetter?.active_version_id ?? null}
                                selectedVersionId={selectedVersionId}
                                onSelect={setSelectedVersionId}
                            />
                            <Button size="sm" onClick={() => setDialogOpen(true)}>
                                <Sparkles /> {coverLetter ? 'Draft another' : 'Draft letter'}
                            </Button>
                        </div>

                        {stillGenerating && (
                            <p className="text-sm text-muted-foreground">
                                Drafting your letter variants… this can take a minute. This page will update
                                automatically.
                            </p>
                        )}

                        {coverLetter && selectedVersion ? (
                            <LetterEditor
                                applicationId={applicationId}
                                coverLetterId={coverLetter.id}
                                activeVersionId={coverLetter.active_version_id}
                                version={selectedVersion}
                                onNudge={setNudge}
                            />
                        ) : (
                            !stillGenerating && (
                                <EmptyState
                                    icon={Sparkles}
                                    title="No letter drafted yet"
                                    description="Generate variants grounded in your resume and this job's scraped company context, then edit and export the one you like."
                                    action={
                                        <Button size="sm" onClick={() => setDialogOpen(true)}>
                                            <Sparkles /> Draft letter
                                        </Button>
                                    }
                                />
                            )
                        )}
                    </>
                )}

                <DraftLetterDialog
                    applicationId={applicationId}
                    open={dialogOpen}
                    onOpenChange={setDialogOpen}
                    onGenerated={(count, generateNudge) => {
                        setRequestedVariantCount(count);
                        setPolling(true);
                        if (generateNudge) setNudge(generateNudge);
                    }}
                />
            </div>
        </div>
    );
}

function VariantSwitcher({
    versions,
    activeVersionId,
    selectedVersionId,
    onSelect,
}: {
    versions: CoverLetterVersion[];
    activeVersionId: number | null;
    selectedVersionId: number | null;
    onSelect: (id: number) => void;
}) {
    if (versions.length === 0) return null;

    return (
        <div className="flex flex-wrap gap-2">
            {versions.map((version) => (
                <button
                    key={version.id}
                    type="button"
                    onClick={() => onSelect(version.id)}
                    className={cn(
                        'flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm transition-colors',
                        version.id === selectedVersionId ? 'border-primary bg-primary/5' : 'hover:bg-accent'
                    )}
                >
                    {humanizeVariant(version.variant_label)}
                    {version.id === activeVersionId && (
                        <Badge variant="secondary" className="text-[10px]">
                            Active
                        </Badge>
                    )}
                </button>
            ))}
        </div>
    );
}

function DraftLetterDialog({
    applicationId,
    open,
    onOpenChange,
    onGenerated,
}: {
    applicationId: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onGenerated: (variantsRequested: number, nudge?: string) => void;
}) {
    const generate = useGenerateLetter(applicationId);
    const [lengthHint, setLengthHint] = useState<NonNullable<GenerateLetterPayload['length_hint']>>('medium');
    const [tone, setTone] = useState('professional');
    const [customInstructions, setCustomInstructions] = useState('');
    const [variants, setVariants] = useState<Record<string, boolean>>({
        story_led: true,
        results_led: true,
        culture_led: true,
    });
    const [error, setError] = useState<string | null>(null);

    const selectedVariants = VARIANT_OPTIONS.filter((v) => variants[v.value]).map((v) => v.value);

    function submit(e: FormEvent) {
        e.preventDefault();
        if (selectedVariants.length === 0) {
            setError('Pick at least one variant.');
            return;
        }
        setError(null);
        generate.mutate(
            {
                length_hint: lengthHint,
                tone: tone.trim() || undefined,
                custom_instructions: customInstructions.trim() || null,
                variants: selectedVariants,
            },
            {
                onSuccess: (result) => {
                    onOpenChange(false);
                    onGenerated(selectedVariants.length, result.nudge);
                },
                onError: (err) => setError(friendlyApiMessage(err, 'Could not start the draft. Try again.')),
            }
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogClose onClick={() => onOpenChange(false)} />
                <DialogHeader>
                    <DialogTitle>Draft letter</DialogTitle>
                    <DialogDescription>
                        Claude drafts each selected angle from your resume and this job's scraped company
                        context.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="length-hint">Length</Label>
                            <Select
                                id="length-hint"
                                value={lengthHint}
                                onChange={(e) =>
                                    setLengthHint(e.target.value as NonNullable<GenerateLetterPayload['length_hint']>)
                                }
                            >
                                <option value="short">Short</option>
                                <option value="medium">Medium</option>
                                <option value="long">Long</option>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="tone">Tone</Label>
                            <Input
                                id="tone"
                                value={tone}
                                onChange={(e) => setTone(e.target.value)}
                                placeholder="professional"
                            />
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="custom-instructions">Custom instructions (optional)</Label>
                        <Textarea
                            id="custom-instructions"
                            rows={3}
                            value={customInstructions}
                            onChange={(e) => setCustomInstructions(e.target.value)}
                            placeholder="Mention my open-source work, keep it under 300 words…"
                        />
                    </div>
                    <div className="space-y-2">
                        <Label>Variants</Label>
                        {VARIANT_OPTIONS.map((v) => (
                            <label
                                key={v.value}
                                className="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    checked={variants[v.value]}
                                    onChange={(e) =>
                                        setVariants((prev) => ({ ...prev, [v.value]: e.target.checked }))
                                    }
                                    className="size-4 rounded border"
                                />
                                {v.label}
                            </label>
                        ))}
                    </div>
                    {error && <p className="text-sm text-destructive">{error}</p>}
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={generate.isPending}>
                            {generate.isPending ? 'Starting…' : 'Draft letter'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function LetterEditor({
    applicationId,
    coverLetterId,
    activeVersionId,
    version,
    onNudge,
}: {
    applicationId: string;
    coverLetterId: number;
    activeVersionId: number | null;
    version: CoverLetterVersion;
    onNudge: (message: string) => void;
}) {
    const updateVersion = useUpdateVersionContent(applicationId);
    const setActiveVersion = useSetActiveVersion(applicationId);
    const regenerateParagraph = useRegenerateParagraph(coverLetterId);
    const { data: snippets } = useSnippets();

    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const [content, setContent] = useState(version.content_md);
    const [savedContent, setSavedContent] = useState(version.content_md);
    const [snippetSelectValue, setSnippetSelectValue] = useState('');
    const [regenIndex, setRegenIndex] = useState<number | null>(null);
    const [regenInstructions, setRegenInstructions] = useState('');
    const [regenError, setRegenError] = useState<string | null>(null);
    const [copyState, setCopyState] = useState<'idle' | 'rich' | 'plain'>('idle');
    const [downloading, setDownloading] = useState<string | null>(null);

    // Switching the selected variant/version resets the editor to that
    // version's saved content.
    useEffect(() => {
        setContent(version.content_md);
        setSavedContent(version.content_md);
        setRegenIndex(null);
        setRegenError(null);
    }, [version.id, version.content_md]);

    // Debounced autosave: persists edits ~800ms after the user stops typing.
    useEffect(() => {
        if (content === savedContent) return;
        const timeout = window.setTimeout(() => {
            updateVersion.mutate(
                { versionId: version.id, content_md: content },
                { onSuccess: () => setSavedContent(content) }
            );
        }, 800);
        return () => window.clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [content, savedContent, version.id]);

    const paragraphs = splitParagraphs(content);
    const dirty = content !== savedContent;
    const savingLabel = updateVersion.isPending ? 'Saving…' : dirty ? 'Unsaved changes' : 'Saved';

    function insertAtCursor(text: string) {
        const el = textareaRef.current;
        const pos = el ? el.selectionStart : content.length;
        const next = content.slice(0, pos) + text + content.slice(pos);
        setContent(next);
        requestAnimationFrame(() => {
            if (!el) return;
            const newPos = pos + text.length;
            el.focus();
            el.setSelectionRange(newPos, newPos);
        });
    }

    function handleInsertSnippet(e: ChangeEvent<HTMLSelectElement>) {
        const snippetId = Number(e.target.value);
        const snippet = snippets?.find((s) => s.id === snippetId);
        if (snippet) insertAtCursor(snippet.content_md.trim());
        setSnippetSelectValue('');
    }

    function submitRegenerate(index: number) {
        const paragraph = paragraphs[index];
        setRegenError(null);
        regenerateParagraph.mutate(
            { version_id: version.id, paragraph, instructions: regenInstructions.trim() || undefined },
            {
                onSuccess: (result) => {
                    const next = [...paragraphs];
                    next[index] = result.data.text;
                    // Splice back in and let the autosave effect above persist it.
                    setContent(joinParagraphs(next));
                    setRegenIndex(null);
                    setRegenInstructions('');
                    if (result.nudge) onNudge(result.nudge);
                },
                onError: (err) => setRegenError(friendlyApiMessage(err, 'Could not regenerate this paragraph.')),
            }
        );
    }

    async function ensureSaved(): Promise<void> {
        if (content === savedContent) return;
        await updateVersion.mutateAsync({ versionId: version.id, content_md: content });
        setSavedContent(content);
    }

    async function handleCopyRich() {
        await copyRichText(content);
        setCopyState('rich');
        window.setTimeout(() => setCopyState('idle'), 1500);
    }

    async function handleCopyPlain() {
        await copyPlainText(content);
        setCopyState('plain');
        window.setTimeout(() => setCopyState('idle'), 1500);
    }

    async function handleDownload(format: (typeof EXPORT_FORMATS)[number]) {
        setDownloading(format);
        try {
            await ensureSaved();
            await downloadFile(`/api/cover-letter-versions/${version.id}/export?format=${format}`, `cover-letter.${format}`);
        } catch {
            setRegenError(null);
        } finally {
            setDownloading(null);
        }
    }

    const isActive = version.id === activeVersionId;

    return (
        <div className="grid gap-6 md:grid-cols-3">
            <div className="space-y-4 md:col-span-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-sm text-muted-foreground">{savingLabel}</span>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setActiveVersion.mutate({ coverLetterId, versionId: version.id })}
                            disabled={setActiveVersion.isPending || isActive}
                        >
                            {isActive ? (
                                <>
                                    <Check /> Active
                                </>
                            ) : (
                                'Set as active'
                            )}
                        </Button>
                        <Select
                            value={snippetSelectValue}
                            onChange={handleInsertSnippet}
                            className="h-8 w-44 text-xs"
                            disabled={!snippets || snippets.length === 0}
                        >
                            <option value="">Insert snippet…</option>
                            {snippets?.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.label}
                                </option>
                            ))}
                        </Select>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="letter-markdown">Markdown</Label>
                        <Textarea
                            id="letter-markdown"
                            ref={textareaRef}
                            value={content}
                            onChange={(e) => setContent(e.target.value)}
                            rows={18}
                            className="font-mono text-xs"
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label>Preview</Label>
                        <div className="h-full max-h-[420px] min-h-[200px] overflow-y-auto rounded-md border bg-muted/30 p-3 text-sm">
                            {paragraphs.length === 0 ? (
                                <p className="text-muted-foreground">Nothing to preview yet.</p>
                            ) : (
                                paragraphs.map((p, i) => (
                                    <p
                                        key={i}
                                        className="mb-3 leading-relaxed last:mb-0"
                                        dangerouslySetInnerHTML={{ __html: renderInlineHtml(p) }}
                                    />
                                ))
                            )}
                        </div>
                    </div>
                </div>

                <div className="space-y-2 border-t pt-4">
                    <Label>Paragraphs</Label>
                    <p className="text-xs text-muted-foreground">
                        Regenerate a single paragraph in the same voice, optionally with instructions.
                    </p>
                    {regenError && <p className="text-sm text-destructive">{regenError}</p>}
                    <div className="space-y-2">
                        {paragraphs.map((p, i) => (
                            <div key={i} className="rounded-md border p-3">
                                <div className="flex items-start justify-between gap-3">
                                    <p className="line-clamp-2 text-sm text-foreground/90">{p}</p>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        className="shrink-0"
                                        onClick={() => {
                                            setRegenIndex(regenIndex === i ? null : i);
                                            setRegenInstructions('');
                                            setRegenError(null);
                                        }}
                                    >
                                        <Wand2 className="size-4" /> Regenerate
                                    </Button>
                                </div>
                                {regenIndex === i && (
                                    <div className="mt-2 space-y-2 border-t pt-2">
                                        <Textarea
                                            value={regenInstructions}
                                            onChange={(e) => setRegenInstructions(e.target.value)}
                                            placeholder="Optional instructions — make it punchier, mention X…"
                                            rows={2}
                                        />
                                        <div className="flex gap-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                onClick={() => submitRegenerate(i)}
                                                disabled={regenerateParagraph.isPending}
                                            >
                                                {regenerateParagraph.isPending ? 'Regenerating…' : 'Regenerate'}
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => setRegenIndex(null)}
                                            >
                                                Cancel
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2 border-t pt-4">
                    <Button size="sm" variant="outline" onClick={handleCopyRich}>
                        {copyState === 'rich' ? <Check /> : <ClipboardCopy />}
                        Copy rich text
                    </Button>
                    <Button size="sm" variant="outline" onClick={handleCopyPlain}>
                        {copyState === 'plain' ? <Check /> : <Copy />}
                        Copy plain text
                    </Button>
                    <span className="mx-1 h-5 w-px bg-border" aria-hidden="true" />
                    {EXPORT_FORMATS.map((format) => (
                        <Button
                            key={format}
                            size="sm"
                            variant="ghost"
                            onClick={() => handleDownload(format)}
                            disabled={downloading === format}
                        >
                            <Download /> {downloading === format ? 'Preparing…' : `.${format}`}
                        </Button>
                    ))}
                </div>
            </div>

            <div className="space-y-4">
                <CompanyContextNote params={version.generation_params} />
            </div>
        </div>
    );
}

function CompanyContextNote({ params }: { params: CoverLetterGenerationParams | null }) {
    const used = params?.company_context_used;

    return (
        <div className="space-y-2 rounded-lg border p-4 text-sm">
            <h3 className="font-medium">Company context</h3>
            {used === true && (
                <p className="text-muted-foreground">
                    This draft is grounded in facts scraped from the company's homepage, about, and careers
                    pages — verify any specific claims before sending.
                </p>
            )}
            {used === false && (
                <p className="text-amber-700 dark:text-amber-400">
                    No scraped company context was available when this draft was written — review it closely
                    for anything that sounds like a guess about the company.
                </p>
            )}
            {used === undefined && (
                <p className="text-muted-foreground">Company-context usage isn't recorded for this version.</p>
            )}
            <div className="space-y-1 border-t pt-2 text-xs text-muted-foreground">
                {params?.tone && <p>Tone: {params.tone}</p>}
                {params?.length_hint && <p>Length: {params.length_hint}</p>}
                {params?.custom_instructions && params.custom_instructions !== 'none' && (
                    <p>Instructions: {params.custom_instructions}</p>
                )}
            </div>
        </div>
    );
}
