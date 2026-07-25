import { useEffect } from 'react';
import { Route, Routes } from 'react-router-dom';
import { AdminRoute } from '@/components/AdminRoute';
import { ProtectedRoute } from '@/components/ProtectedRoute';
import AcceptInvitePage from '@/pages/AcceptInvitePage';
import HomePage from '@/pages/HomePage';
import JobSourcesPage from '@/pages/JobSourcesPage';
import JobsPage from '@/pages/JobsPage';
import LoginPage from '@/pages/LoginPage';
import InvitationsPage from '@/pages/admin/InvitationsPage';
import { useAuthStore } from '@/store/useAuthStore';

/**
 * Root shell + router. On first load it hydrates the session via GET /api/me;
 * authenticated-only routes are wrapped in <ProtectedRoute>.
 */
export default function App() {
    const status = useAuthStore((s) => s.status);
    const fetchMe = useAuthStore((s) => s.fetchMe);

    useEffect(() => {
        if (status === 'idle') {
            void fetchMe();
        }
    }, [status, fetchMe]);

    return (
        <Routes>
            <Route path="/login" element={<LoginPage />} />
            <Route path="/invite/:token" element={<AcceptInvitePage />} />
            <Route
                path="/"
                element={
                    <ProtectedRoute>
                        <HomePage />
                    </ProtectedRoute>
                }
            />
            <Route
                path="/jobs"
                element={
                    <ProtectedRoute>
                        <JobsPage />
                    </ProtectedRoute>
                }
            />
            <Route
                path="/sources"
                element={
                    <ProtectedRoute>
                        <JobSourcesPage />
                    </ProtectedRoute>
                }
            />
            <Route
                path="/admin/invitations"
                element={
                    <AdminRoute>
                        <InvitationsPage />
                    </AdminRoute>
                }
            />
            <Route
                path="*"
                element={
                    <ProtectedRoute>
                        <div className="flex min-h-screen items-center justify-center text-muted-foreground">
                            Not found
                        </div>
                    </ProtectedRoute>
                }
            />
        </Routes>
    );
}
