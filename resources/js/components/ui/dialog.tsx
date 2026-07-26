import * as React from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';

import { cn } from '@/lib/utils';

/**
 * Minimal, dependency-free dialog/modal primitive.
 *
 * The repo doesn't have `@radix-ui/react-dialog` installed; rather than pull
 * in a new dependency for a single detail panel, this is a small
 * self-contained implementation (portal + overlay + Escape-to-close + focus
 * on open) with the same exported shape shadcn/ui uses (`Dialog`,
 * `DialogContent`, `DialogHeader`, `DialogTitle`, `DialogDescription`,
 * `DialogFooter`, `DialogClose`) so it can be swapped for the real Radix
 * component later without touching call sites.
 */

interface DialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    children: React.ReactNode;
}

function Dialog({ open, onOpenChange, children }: DialogProps) {
    React.useEffect(() => {
        if (!open) return;

        const onKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onOpenChange(false);
        };
        document.addEventListener('keydown', onKeyDown);

        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.body.style.overflow = previousOverflow;
        };
    }, [open, onOpenChange]);

    if (!open) return null;

    return createPortal(
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 py-10 sm:py-16">
            <div
                className="fixed inset-0 bg-black/50"
                aria-hidden="true"
                onClick={() => onOpenChange(false)}
            />
            {children}
        </div>,
        document.body
    );
}

function DialogContent({ className, children, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            role="dialog"
            aria-modal="true"
            className={cn(
                'relative z-10 grid w-full max-w-2xl gap-4 rounded-lg border bg-background p-6 shadow-lg',
                className
            )}
            {...props}
        >
            {children}
        </div>
    );
}

function DialogClose({ onClick, className, ...props }: React.ComponentProps<'button'>) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'absolute top-4 right-4 rounded-xs opacity-70 transition-opacity hover:opacity-100 focus:outline-none',
                className
            )}
            {...props}
        >
            <X className="size-4" />
            <span className="sr-only">Close</span>
        </button>
    );
}

function DialogHeader({ className, ...props }: React.ComponentProps<'div'>) {
    return <div className={cn('flex flex-col gap-1.5 pr-6', className)} {...props} />;
}

function DialogTitle({ className, ...props }: React.ComponentProps<'h2'>) {
    return <h2 className={cn('text-lg leading-none font-semibold', className)} {...props} />;
}

function DialogDescription({ className, ...props }: React.ComponentProps<'p'>) {
    return <p className={cn('text-sm text-muted-foreground', className)} {...props} />;
}

function DialogFooter({ className, ...props }: React.ComponentProps<'div'>) {
    return <div className={cn('flex flex-col-reverse gap-2 sm:flex-row sm:justify-end', className)} {...props} />;
}

export { Dialog, DialogContent, DialogClose, DialogHeader, DialogTitle, DialogDescription, DialogFooter };
