import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Lightweight new-york-style label. shadcn's upstream version wraps
 * @radix-ui/react-label, which isn't installed in this project — this is a
 * plain <label> with the same visual API (peer-disabled affordance) so it
 * doesn't require adding a new dependency.
 */
function Label({ className, ...props }: React.ComponentProps<'label'>) {
    return (
        <label
            data-slot="label"
            className={cn(
                'flex items-center gap-2 text-sm leading-none font-medium select-none peer-disabled:cursor-not-allowed peer-disabled:opacity-50',
                className
            )}
            {...props}
        />
    );
}

export { Label };
