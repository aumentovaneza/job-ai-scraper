import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { type AiProvider, useSetProvider } from '@/hooks/useProfile';

const OPTIONS: { value: AiProvider; label: string }[] = [
    { value: 'anthropic', label: 'Claude' },
    { value: 'openai', label: 'ChatGPT' },
];

/**
 * Segmented control for the user's active BYOK provider. Switching providers
 * doesn't discard either key — /api/ai-provider just flips which one is
 * active, and onboarding.key_verified reflects that provider going forward.
 */
export function ProviderToggle({ value }: { value: AiProvider }) {
    const setProvider = useSetProvider();

    return (
        <div className="inline-flex rounded-md border p-1" role="group" aria-label="AI provider">
            {OPTIONS.map((option) => {
                const active = option.value === value;
                return (
                    <Button
                        key={option.value}
                        type="button"
                        size="sm"
                        variant={active ? 'default' : 'ghost'}
                        className={cn('shadow-none', !active && 'text-muted-foreground')}
                        disabled={setProvider.isPending}
                        onClick={() => {
                            if (!active) setProvider.mutate(option.value);
                        }}
                    >
                        {option.label}
                    </Button>
                );
            })}
        </div>
    );
}
