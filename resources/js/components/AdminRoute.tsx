import type { ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import { useAuthStore } from '@/store/useAuthStore';

/**
 * Gate for admin-only routes (e.g. the invite panel). Layers on top of the same
 * session check as ProtectedRoute, then additionally requires `is_admin`.
 * Non-admins are bounced to the app home rather than the login screen.
 */
export function AdminRoute({ children }: { children: ReactNode }) {
    const status = useAuthStore((s) => s.status);
    const user = useAuthStore((s) => s.user);

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

    if (!user?.is_admin) {
        return <Navigate to="/" replace />;
    }

    return <>{children}</>;
}
