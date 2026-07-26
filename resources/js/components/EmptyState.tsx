import type { ComponentType, ReactNode } from 'react';
import type { LucideProps } from 'lucide-react';
import { cn } from '@/lib/utils';

interface EmptyStateProps {
    /** Optional lucide icon shown in a muted circle above the title. */
    icon?: ComponentType<LucideProps>;
    title: string;
    description?: ReactNode;
    /** Optional call-to-action (usually a <Button> or a linked button). */
    action?: ReactNode;
    className?: string;
}

/**
 * Shared empty state (T-70) for lists/views with no data yet. Renders a dashed
 * card with an optional icon, a short title, a guiding description, and an
 * optional action so first-run screens explain what to do next.
 */
export function EmptyState({ icon: Icon, title, description, action, className }: EmptyStateProps) {
    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-10 text-center',
                className
            )}
        >
            {Icon && (
                <div className="flex size-11 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <Icon className="size-5" />
                </div>
            )}
            <div className="space-y-1">
                <h3 className="text-sm font-medium">{title}</h3>
                {description && (
                    <div className="mx-auto max-w-sm text-sm text-muted-foreground">{description}</div>
                )}
            </div>
            {action}
        </div>
    );
}
