import { RefreshCw, TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface ErrorStateProps {
    title?: string;
    message?: string;
    /** When provided, renders a "Try again" button (usually a query's refetch). */
    onRetry?: () => void;
    className?: string;
}

/**
 * Shared inline error state (T-71) for failed data fetches. Keeps the surface
 * calm — an icon, a friendly message, and an optional retry — instead of the
 * bare red sentence pages used before.
 */
export function ErrorState({
    title = 'Something went wrong',
    message = 'We could not load this. Try again in a moment.',
    onRetry,
    className,
}: ErrorStateProps) {
    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-10 text-center',
                className
            )}
        >
            <div className="flex size-11 items-center justify-center rounded-full bg-destructive/10 text-destructive">
                <TriangleAlert className="size-5" />
            </div>
            <div className="space-y-1">
                <h3 className="text-sm font-medium">{title}</h3>
                <p className="mx-auto max-w-sm text-sm text-muted-foreground">{message}</p>
            </div>
            {onRetry && (
                <Button variant="outline" size="sm" onClick={onRetry}>
                    <RefreshCw /> Try again
                </Button>
            )}
        </div>
    );
}
