import { ExternalLink, MapPin, TriangleAlert } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { matchBand, matchBandLabel } from '@/components/MatchScoreBadge';
import { formatDate, formatSalary, formatSalaryBand } from '@/lib/jobFormat';
import { cn } from '@/lib/utils';
import type { JobPosting, RemoteType } from '@/types/jobs';

const REMOTE_BADGE_VARIANT: Record<RemoteType, 'default' | 'secondary' | 'outline'> = {
    remote: 'default',
    hybrid: 'secondary',
    onsite: 'outline',
};

const SCORE_TEXT_CLASSES: Record<'strong' | 'medium' | 'weak', string> = {
    strong: 'text-emerald-600 dark:text-emerald-400',
    medium: 'text-amber-600 dark:text-amber-400',
    weak: 'text-rose-600 dark:text-rose-400',
};

interface JobDetailDialogProps {
    job: JobPosting | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/**
 * Full job detail panel (T-33). Shows the scraped posting plus, when
 * available, the AI enrichment (skills, red flags, one-line summary) and the
 * current user's match score with full reasoning and strengths/gaps chips.
 * Both `enrichment` and `match_score` are nullable on the API until their
 * background jobs (T-31 / T-32) have run for this job/user.
 */
export function JobDetailDialog({ job, open, onOpenChange }: JobDetailDialogProps) {
    if (!job) return null;

    const salary = formatSalary(job);
    const posted = formatDate(job.posted_at);
    const enrichment = job.enrichment;
    const match = job.match_score;
    const enrichmentSalary = enrichment ? formatSalaryBand(enrichment.salary_band) : null;
    const band = match?.score != null ? matchBand(match.score) : null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] overflow-y-auto">
                <DialogClose onClick={() => onOpenChange(false)} />
                <DialogHeader>
                    <div className="flex flex-wrap items-center gap-2">
                        <DialogTitle>{job.title}</DialogTitle>
                        {job.remote_type && <Badge variant={REMOTE_BADGE_VARIANT[job.remote_type]}>{job.remote_type}</Badge>}
                        {enrichment?.seniority && <Badge variant="outline">{enrichment.seniority}</Badge>}
                    </div>
                    <DialogDescription className="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <span>{job.company}</span>
                        {job.location && (
                            <span className="inline-flex items-center gap-1">
                                <MapPin className="size-3.5" />
                                {job.location}
                            </span>
                        )}
                        {salary && <span>{salary}</span>}
                        {posted && <span>Posted {posted}</span>}
                    </DialogDescription>
                </DialogHeader>

                {enrichment?.one_line_summary && (
                    <p className="rounded-md bg-muted px-3 py-2 text-sm">{enrichment.one_line_summary}</p>
                )}

                {/* Match section */}
                <section className="space-y-3 rounded-lg border p-4">
                    <h3 className="text-sm font-semibold">Your match</h3>
                    {!match || match.score == null ? (
                        <p className="text-sm text-muted-foreground">This job hasn&apos;t been scored for you yet.</p>
                    ) : (
                        <div className="space-y-3">
                            <div className="flex items-baseline gap-3">
                                <span className={cn('text-3xl font-bold tabular-nums', band && SCORE_TEXT_CLASSES[band])}>
                                    {match.score}
                                </span>
                                <span className="text-sm text-muted-foreground">
                                    / 100 · {band && matchBandLabel(band)}
                                </span>
                            </div>
                            {match.reasoning && <p className="text-sm text-foreground/90">{match.reasoning}</p>}
                            {match.strengths && match.strengths.length > 0 && (
                                <div className="space-y-1.5">
                                    <p className="text-xs font-medium text-muted-foreground">Strengths</p>
                                    <div className="flex flex-wrap gap-1.5">
                                        {match.strengths.map((s) => (
                                            <Badge
                                                key={s}
                                                variant="outline"
                                                className="border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-400"
                                            >
                                                {s}
                                            </Badge>
                                        ))}
                                    </div>
                                </div>
                            )}
                            {match.gaps && match.gaps.length > 0 && (
                                <div className="space-y-1.5">
                                    <p className="text-xs font-medium text-muted-foreground">Gaps</p>
                                    <div className="flex flex-wrap gap-1.5">
                                        {match.gaps.map((g) => (
                                            <Badge
                                                key={g}
                                                variant="outline"
                                                className="border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-500/10 dark:text-rose-400"
                                            >
                                                {g}
                                            </Badge>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </section>

                {/* Enrichment section */}
                {!enrichment ? (
                    <p className="text-sm text-muted-foreground">Enrichment pending.</p>
                ) : (
                    <section className="space-y-3">
                        {enrichmentSalary && (
                            <p className="text-sm">
                                <span className="text-muted-foreground">Estimated salary: </span>
                                {enrichmentSalary}
                            </p>
                        )}
                        {enrichment.required_skills && enrichment.required_skills.length > 0 && (
                            <div className="space-y-1.5">
                                <p className="text-xs font-medium text-muted-foreground">Required skills</p>
                                <div className="flex flex-wrap gap-1.5">
                                    {enrichment.required_skills.map((skill) => (
                                        <Badge key={skill} variant="secondary">
                                            {skill}
                                        </Badge>
                                    ))}
                                </div>
                            </div>
                        )}
                        {enrichment.nice_to_have_skills && enrichment.nice_to_have_skills.length > 0 && (
                            <div className="space-y-1.5">
                                <p className="text-xs font-medium text-muted-foreground">Nice to have</p>
                                <div className="flex flex-wrap gap-1.5">
                                    {enrichment.nice_to_have_skills.map((skill) => (
                                        <Badge key={skill} variant="outline">
                                            {skill}
                                        </Badge>
                                    ))}
                                </div>
                            </div>
                        )}
                        {enrichment.red_flags && enrichment.red_flags.length > 0 && (
                            <div className="space-y-1.5">
                                <p className="inline-flex items-center gap-1 text-xs font-medium text-amber-700 dark:text-amber-400">
                                    <TriangleAlert className="size-3.5" /> Red flags
                                </p>
                                <div className="flex flex-wrap gap-1.5">
                                    {enrichment.red_flags.map((flag) => (
                                        <Badge
                                            key={flag}
                                            variant="outline"
                                            className="border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-500/10 dark:text-amber-400"
                                        >
                                            {flag}
                                        </Badge>
                                    ))}
                                </div>
                            </div>
                        )}
                    </section>
                )}

                {job.jd_text && (
                    <section className="space-y-1.5">
                        <p className="text-xs font-medium text-muted-foreground">Full description</p>
                        <p className="max-h-64 overflow-y-auto rounded-md border bg-muted/40 p-3 text-sm whitespace-pre-wrap text-foreground/90">
                            {job.jd_text}
                        </p>
                    </section>
                )}

                {job.apply_url && (
                    <div className="flex justify-end">
                        <Button asChild>
                            <a href={job.apply_url} target="_blank" rel="noreferrer">
                                Apply <ExternalLink />
                            </a>
                        </Button>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
