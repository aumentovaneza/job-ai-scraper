import type { ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuthStore } from '@/store/useAuthStore';

/**
 * Gate for authenticated-only routes. While the initial /me check is in
 * flight it shows a placeholder; unauthenticated users are redirected to
 * /login. Real loading skeletons land in T-72.
 */
export function ProtectedRoute({ children }: { children: ReactNode }) {
    const status = useAuthStore((s) => s.status);

    if (status === 'idle' || status === 'loading') {
        return (
            <div className="flex min-h-screen items-center justify-center text-muted-foreground">
                Loading…
            </div>
        );
    }

    if (status === 'unauthenticated') {
        return <Navigate to="/login" replace />;
    }

    return <>{children}</>;
}
