import { useEffect } from 'react';
import { Route, Routes } from 'react-router-dom';
import { ProtectedRoute } from '@/components/ProtectedRoute';
import HomePage from '@/pages/HomePage';
import LoginPage from '@/pages/LoginPage';
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
            <Route
                path="/"
                element={
                    <ProtectedRoute>
                        <HomePage />
                    </ProtectedRoute>
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
