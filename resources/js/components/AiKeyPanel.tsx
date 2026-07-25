import { type FormEvent, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type AiProvider, type KeyStatus, useDeleteKey, useStoreKey } from '@/hooks/useProfile';

const PROVIDER_COPY: Record<AiProvider, { label: string; placeholder: string }> = {
    anthropic: { label: 'Anthropic API key', placeholder: 'sk-ant-…' },
    openai: { label: 'OpenAI API key', placeholder: 'sk-…' },
};

/**
 * BYOK key management, shared by the onboarding wizard (T-12) and settings
 * (T-15). Pasting a key verifies it inline with a 1-token ping; a stored key is
 * shown masked with its verification state and can be replaced or removed.
 * Provider-agnostic: works for either Anthropic or OpenAI depending on `provider`.
 */
export function AiKeyPanel({ provider, status }: { provider: AiProvider; status: KeyStatus }) {
    const storeKey = useStoreKey();
    const deleteKey = useDeleteKey();
    const copy = PROVIDER_COPY[provider];

    const [key, setKey] = useState('');
    const [editing, setEditing] = useState(!status.has_key);
    const [message, setMessage] = useState<string | null>(null);
    const [ok, setOk] = useState<boolean | null>(null);

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setMessage(null);
        try {
            const result = await storeKey.mutateAsync({ provider, key: key.trim() });
            setKey('');
            setOk(result.verified);
            setMessage(result.message);
            if (result.verified) {
                setEditing(false);
            }
        } catch {
            setOk(false);
            setMessage('Could not save the key. Please try again.');
        }
    }

    async function handleRemove() {
        await deleteKey.mutateAsync({ provider });
        setEditing(true);
        setOk(null);
        setMessage(null);
    }

    if (status.has_key && !editing) {
        return (
            <div className="space-y-3">
                <div className="flex items-center justify-between rounded-md border p-3">
                    <div className="space-y-1">
                        <p className="font-mono text-sm">{status.masked}</p>
                        <p className="text-xs text-muted-foreground">
                            {status.verified_at ? (
                                <span className="text-emerald-600">
                                    Verified {new Date(status.verified_at).toLocaleDateString()}
                                </span>
                            ) : (
                                <span className="text-destructive">Not verified</span>
                            )}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
                            Replace
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={handleRemove}
                            disabled={deleteKey.isPending}
                        >
                            Remove
                        </Button>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <form onSubmit={handleSubmit} className="space-y-3">
            <div className="space-y-1.5">
                <Label htmlFor="ai-key">{copy.label}</Label>
                <Input
                    id="ai-key"
                    type="password"
                    autoComplete="off"
                    placeholder={copy.placeholder}
                    value={key}
                    onChange={(e) => setKey(e.target.value)}
                    required
                />
                <p className="text-xs text-muted-foreground">
                    Stored encrypted, never shown again, and only used with your account.
                </p>
            </div>

            {message && (
                <p className={`text-sm ${ok ? 'text-emerald-600' : 'text-destructive'}`}>{message}</p>
            )}

            <div className="flex gap-2">
                <Button type="submit" disabled={storeKey.isPending || key.trim().length < 20}>
                    {storeKey.isPending ? 'Verifying…' : 'Save & verify'}
                </Button>
                {status.has_key && (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => {
                            setEditing(false);
                            setKey('');
                            setMessage(null);
                        }}
                    >
                        Cancel
                    </Button>
                )}
            </div>
        </form>
    );
}
