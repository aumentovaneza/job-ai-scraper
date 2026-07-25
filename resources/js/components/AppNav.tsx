import { Link, useLocation, useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { useAuthStore } from '@/store/useAuthStore';

const links = [
    { to: '/', label: 'Home' },
    { to: '/jobs', label: 'Jobs' },
    { to: '/sources', label: 'Sources' },
] as const;

/**
 * Shared header/nav for authenticated pages. Lets the user reach the job
 * feed and job sources screens from anywhere, plus sign out.
 */
export function AppNav() {
    const location = useLocation();
    const navigate = useNavigate();
    const user = useAuthStore((s) => s.user);
    const logout = useAuthStore((s) => s.logout);

    async function handleLogout() {
        await logout();
        navigate('/login', { replace: true });
    }

    return (
        <header className="border-b">
            <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-3">
                <nav className="flex items-center gap-1">
                    {links.map((link) => (
                        <Button
                            key={link.to}
                            variant={location.pathname === link.to ? 'secondary' : 'ghost'}
                            size="sm"
                            asChild
                        >
                            <Link to={link.to}>{link.label}</Link>
                        </Button>
                    ))}
                    {user?.is_admin && (
                        <Button
                            variant={location.pathname === '/admin/invitations' ? 'secondary' : 'ghost'}
                            size="sm"
                            asChild
                        >
                            <Link to="/admin/invitations">Invitations</Link>
                        </Button>
                    )}
                </nav>
                <Button variant="outline" size="sm" onClick={handleLogout}>
                    Sign out
                </Button>
            </div>
        </header>
    );
}
