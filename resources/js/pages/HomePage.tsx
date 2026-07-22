import { useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { useAuthStore } from '@/store/useAuthStore';

/**
 * Placeholder landing page for the authenticated SPA shell. Real pages (feed,
 * tracker, settings) replace this in later phases.
 */
export default function HomePage() {
    const navigate = useNavigate();
    const { user, logout } = useAuthStore();

    async function handleLogout() {
        await logout();
        navigate('/login', { replace: true });
    }

    return (
        <div className="mx-auto flex min-h-screen max-w-2xl flex-col items-center justify-center gap-6 p-8 text-center">
            <div className="space-y-2">
                <h1 className="text-3xl font-semibold tracking-tight">JobScope</h1>
                <p className="text-muted-foreground">
                    Signed in as {user?.name} ({user?.email}){user?.is_admin ? ' · admin' : ''}
                </p>
            </div>

            <Button variant="outline" onClick={handleLogout}>
                Sign out
            </Button>
        </div>
    );
}
