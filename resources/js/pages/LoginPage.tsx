import { type FormEvent, useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useAuthStore } from '@/store/useAuthStore';

export default function LoginPage() {
    const navigate = useNavigate();
    const { status, login } = useAuthStore();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    // Already signed in — bounce to the app.
    if (status === 'authenticated') {
        return <Navigate to="/" replace />;
    }

    async function handleSubmit(e: FormEvent) {
        e.preventDefault();
        setError(null);
        setSubmitting(true);
        try {
            await login(email, password);
            navigate('/', { replace: true });
        } catch {
            setError('Those credentials do not match our records.');
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="flex min-h-screen items-center justify-center p-8">
            <form onSubmit={handleSubmit} className="w-full max-w-sm space-y-5">
                <div className="space-y-1 text-center">
                    <h1 className="text-2xl font-semibold tracking-tight">Sign in to JobScope</h1>
                    <p className="text-sm text-muted-foreground">Invite-only. Use the account you were invited with.</p>
                </div>

                <div className="space-y-3">
                    <Input
                        type="email"
                        placeholder="you@example.com"
                        autoComplete="email"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        required
                    />
                    <Input
                        type="password"
                        placeholder="Password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        required
                    />
                </div>

                {error && <p className="text-sm text-destructive">{error}</p>}

                <Button type="submit" className="w-full" disabled={submitting}>
                    {submitting ? 'Signing in…' : 'Sign in'}
                </Button>
            </form>
        </div>
    );
}
