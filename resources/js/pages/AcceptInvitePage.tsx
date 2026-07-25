import { type FormEvent, useEffect, useState } from 'react';
import { Navigate, useNavigate, useParams } from 'react-router-dom';
import { AxiosError } from 'axios';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { api } from '@/lib/api';
import { useAuthStore } from '@/store/useAuthStore';

type TokenState =
    | { status: 'loading' }
    | { status: 'valid'; email: string }
    | { status: 'invalid'; message: string };

/**
 * Public invite-acceptance page (T-05/T-09). Resolves the token to show the
 * invited email, then lets the invitee set a name + password, which creates
 * their account and signs them into the SPA.
 */
export default function AcceptInvitePage() {
    const { token = '' } = useParams();
    const navigate = useNavigate();
    const { status: authStatus, fetchMe } = useAuthStore();

    const [token_, setToken] = useState<TokenState>({ status: 'loading' });
    const [name, setName] = useState('');
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        let active = true;
        api.get(`/api/invitations/${token}`)
            .then(({ data }) => active && setToken({ status: 'valid', email: data.email }))
            .catch((err: AxiosError<{ message?: string }>) =>
                active &&
                setToken({
                    status: 'invalid',
                    message: err.response?.data?.message ?? 'This invite link is not valid.',
                })
            );
        return () => {
            active = false;
        };
    }, [token]);

    // Already signed in — nothing to accept.
    if (authStatus === 'authenticated') {
        return <Navigate to="/" replace />;
    }

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setError(null);
        setSubmitting(true);
        try {
            await api.get('/sanctum/csrf-cookie');
            await api.post(`/api/invitations/${token}/accept`, {
                name,
                password,
                password_confirmation: passwordConfirmation,
            });
            await fetchMe();
            navigate('/', { replace: true });
        } catch (err) {
            const axiosErr = err as AxiosError<{ message?: string }>;
            setError(axiosErr.response?.data?.message ?? 'Could not accept the invitation.');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="flex min-h-screen items-center justify-center p-8">
            <div className="w-full max-w-sm space-y-5">
                <div className="space-y-1 text-center">
                    <h1 className="text-2xl font-semibold tracking-tight">Join JobScope</h1>
                    {token_.status === 'valid' && (
                        <p className="text-sm text-muted-foreground">
                            Setting up the account for {token_.email}
                        </p>
                    )}
                </div>

                {token_.status === 'loading' && (
                    <p className="text-center text-sm text-muted-foreground">Checking your invite…</p>
                )}

                {token_.status === 'invalid' && (
                    <p className="text-center text-sm text-destructive">{token_.message}</p>
                )}

                {token_.status === 'valid' && (
                    <form onSubmit={handleSubmit} className="space-y-3">
                        <Input
                            type="text"
                            placeholder="Your name"
                            autoComplete="name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            required
                        />
                        <Input
                            type="password"
                            placeholder="Choose a password"
                            autoComplete="new-password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            required
                        />
                        <Input
                            type="password"
                            placeholder="Confirm password"
                            autoComplete="new-password"
                            value={passwordConfirmation}
                            onChange={(e) => setPasswordConfirmation(e.target.value)}
                            required
                        />

                        {error && <p className="text-sm text-destructive">{error}</p>}

                        <Button type="submit" className="w-full" disabled={submitting}>
                            {submitting ? 'Creating account…' : 'Create account'}
                        </Button>
                    </form>
                )}
            </div>
        </div>
    );
}
