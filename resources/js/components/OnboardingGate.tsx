import type { ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import { useProfile } from '@/hooks/useProfile';

/**
 * Keeps unfinished accounts out of the main app until onboarding is complete
 * (T-12). Wraps authenticated app pages; redirects to /onboarding when the
 * server reports a missing key, resume, or targets. Sits inside <ProtectedRoute>.
 */
export function OnboardingGate({ children }: { children: ReactNode }) {
    const { data, isLoading } = useProfile();

    if (isLoading || !data) {
        return (
            <div className="flex min-h-screen items-center justify-center text-muted-foreground">
                Loading…
            </div>
        );
    }

    if (!data.onboarding.complete) {
        return <Navigate to="/onboarding" replace />;
    }

    return <>{children}</>;
}
