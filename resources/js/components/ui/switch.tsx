import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Lightweight new-york-style switch. shadcn's upstream version wraps
 * @radix-ui/react-switch, which isn't installed in this project — this is a
 * plain accessible toggle button (role="switch") with the same visual API,
 * so it doesn't require adding a new dependency.
 */
interface SwitchProps extends Omit<React.ComponentProps<'button'>, 'onChange' | 'value'> {
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
}

function Switch({ className, checked, onCheckedChange, disabled, ...props }: SwitchProps) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            data-slot="switch"
            data-state={checked ? 'checked' : 'unchecked'}
            disabled={disabled}
            onClick={() => onCheckedChange(!checked)}
            className={cn(
                'inline-flex h-5 w-9 shrink-0 items-center rounded-full border border-transparent shadow-xs outline-none transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50',
                checked ? 'bg-primary' : 'bg-input',
                className
            )}
            {...props}
        >
            <span
                data-slot="switch-thumb"
                data-state={checked ? 'checked' : 'unchecked'}
                className={cn(
                    'pointer-events-none block size-4 rounded-full bg-background shadow-lg ring-0 transition-transform',
                    checked ? 'translate-x-4' : 'translate-x-0.5'
                )}
            />
        </button>
    );
}

export { Switch };
