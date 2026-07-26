import { cn } from '@/lib/utils';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { JobMatchScore } from '@/types/jobs';

export type MatchBand = 'strong' | 'medium' | 'weak';

export function matchBand(score: number): MatchBand {
    if (score >= 75) return 'strong';
    if (score >= 50) return 'medium';
    return 'weak';
}

export function matchBandLabel(band: MatchBand): string {
    switch (band) {
        case 'strong':
            return 'Strong match';
        case 'medium':
            return 'Medium match';
        case 'weak':
            return 'Weak match';
    }
}

const BAND_CLASSES: Record<MatchBand, string> = {
    strong: 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-400',
    medium: 'border-transparent bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-400',
    weak: 'border-transparent bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-400',
};

interface MatchScoreBadgeProps {
    matchScore: JobMatchScore | null;
    className?: string;
}

/**
 * Compact score badge for job cards (T-33). Shows a numeric score colored by
 * band, with a hover tooltip carrying the band label + a truncated one-line
 * reasoning. Renders a subtle "Not scored yet" pill when the job hasn't been
 * scored for the current user.
 */
export function MatchScoreBadge({ matchScore, className }: MatchScoreBadgeProps) {
    if (matchScore == null || matchScore.score == null) {
        return (
            <span
                className={cn(
                    'inline-flex w-fit shrink-0 items-center rounded-md border px-2 py-0.5 text-xs font-medium text-muted-foreground',
                    className
                )}
            >
                Not scored yet
            </span>
        );
    }

    const band = matchBand(matchScore.score);
    const reasoning = matchScore.reasoning?.trim();

    return (
        <Tooltip className={className}>
            <TooltipTrigger>
                <span
                    className={cn(
                        'inline-flex w-fit shrink-0 cursor-default items-center rounded-md border px-2 py-0.5 text-xs font-semibold',
                        BAND_CLASSES[band]
                    )}
                >
                    {matchScore.score}
                </span>
            </TooltipTrigger>
            <TooltipContent>
                <p className="font-semibold">
                    {matchBandLabel(band)} · {matchScore.score}/100
                </p>
                {reasoning && <p className="mt-1 line-clamp-3 text-popover-foreground/80">{reasoning}</p>}
            </TooltipContent>
        </Tooltip>
    );
}
