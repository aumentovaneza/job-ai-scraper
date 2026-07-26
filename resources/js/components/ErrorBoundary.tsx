import { Component, type ErrorInfo, type ReactNode } from 'react';
import * as Sentry from '@sentry/react';
import { RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';

interface ErrorBoundaryProps {
    children: ReactNode;
}

interface ErrorBoundaryState {
    hasError: boolean;
}

/**
 * Top-level render error boundary (T-71). Catches JavaScript errors thrown while
 * rendering the app, reports them to Sentry (a no-op unless a DSN is set), and
 * shows a calm recovery screen instead of a blank page. React only reports
 * render/lifecycle errors here — async/event-handler errors are surfaced inline
 * by each view via friendlyApiMessage / ErrorState.
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
    state: ErrorBoundaryState = { hasError: false };

    static getDerivedStateFromError(): ErrorBoundaryState {
        return { hasError: true };
    }

    componentDidCatch(error: Error, info: ErrorInfo): void {
        Sentry.captureException(error, { extra: { componentStack: info.componentStack } });
    }

    render(): ReactNode {
        if (!this.state.hasError) {
            return this.props.children;
        }

        return (
            <div className="flex min-h-screen flex-col items-center justify-center gap-4 p-8 text-center">
                <div className="space-y-1.5">
                    <h1 className="text-lg font-semibold tracking-tight">Something broke on this page</h1>
                    <p className="max-w-sm text-sm text-muted-foreground">
                        The error has been logged. Reloading usually clears it — if it keeps happening, try
                        again in a little while.
                    </p>
                </div>
                <Button onClick={() => window.location.reload()}>
                    <RefreshCw /> Reload
                </Button>
            </div>
        );
    }
}
