import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * Loading placeholder (T-72). A muted, pulsing block sized by the caller via
 * className — compose several to mirror the shape of the content that's loading.
 */
function Skeleton({ className, ...props }: ComponentProps<'div'>) {
    return (
        <div
            data-slot="skeleton"
            className={cn('animate-pulse rounded-md bg-muted', className)}
            {...props}
        />
    );
}

export { Skeleton };
