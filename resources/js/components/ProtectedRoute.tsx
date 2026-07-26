import type { ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import { Skeleton } from '@/components/ui/skeleton';
import { useAuthStore } from '@/store/useAuthStore';

/**
 * Gate for authenticated-only routes. While the initial /me check is in flight
 * it shows a lightweight app-shell skeleton (T-72); unauthenticated users are
 * redirected to /login.
 */
export function ProtectedRoute({ children }: { children: ReactNode }) {
    const status = useAuthStore((s) => s.status);

    if (status === 'idle' || status === 'loading') {
        return (
            <div className="min-h-screen bg-background">
                <div className="border-b">
                    <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-3">
                        <div className="flex gap-2">
                            {Array.from({ length: 5 }).map((_, i) => (
                                <Skeleton key={i} className="h-8 w-16" />
                            ))}
                        </div>
                        <Skeleton className="h-8 w-20" />
                    </div>
                </div>
                <div className="mx-auto max-w-5xl space-y-4 p-6">
                    <Skeleton className="h-8 w-48" />
                    <Skeleton className="h-4 w-72" />
                    <div className="grid gap-4 pt-4 sm:grid-cols-3">
                        {Array.from({ length: 3 }).map((_, i) => (
                            <Skeleton key={i} className="h-28 w-full" />
                        ))}
                    </div>
                </div>
            </div>
        );
    }

    if (status === 'unauthenticated') {
        return <Navigate to="/login" replace />;
    }

    return <>{children}</>;
}
