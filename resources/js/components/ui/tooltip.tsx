import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Minimal, dependency-free tooltip primitive.
 *
 * The repo doesn't have `@radix-ui/react-tooltip` installed and this task
 * shouldn't add a new runtime dependency for a single hover popover, so this
 * is a small CSS-only implementation (`group`/`group-hover` + `peer-focus`)
 * rather than a Radix-backed one. The exported names (`Tooltip`,
 * `TooltipTrigger`, `TooltipContent`, `TooltipProvider`) match shadcn/ui's
 * API shape so call sites read the same way and this can be swapped for the
 * real Radix component later without touching consumers.
 */

function TooltipProvider({ children }: { children: React.ReactNode }) {
    return <>{children}</>;
}

function Tooltip({ children, className }: { children: React.ReactNode; className?: string }) {
    return <span className={cn('group/tooltip relative inline-flex', className)}>{children}</span>;
}

function TooltipTrigger({ children, className, ...props }: React.ComponentProps<'span'>) {
    return (
        <span tabIndex={0} className={cn('inline-flex outline-none', className)} {...props}>
            {children}
        </span>
    );
}

function TooltipContent({ children, className, side = 'top' }: { children: React.ReactNode; className?: string; side?: 'top' | 'bottom' }) {
    const sideClasses = side === 'top' ? 'bottom-full mb-2' : 'top-full mt-2';

    return (
        <span
            role="tooltip"
            className={cn(
                'pointer-events-none invisible absolute left-1/2 z-50 w-max max-w-64 -translate-x-1/2 rounded-md bg-popover px-3 py-1.5 text-xs text-popover-foreground opacity-0 shadow-md ring-1 ring-border transition-opacity duration-150 group-hover/tooltip:visible group-hover/tooltip:opacity-100 group-focus-within/tooltip:visible group-focus-within/tooltip:opacity-100',
                sideClasses,
                className
            )}
        >
            {children}
        </span>
    );
}

export { Tooltip, TooltipTrigger, TooltipContent, TooltipProvider };
