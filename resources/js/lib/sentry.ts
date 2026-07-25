import * as Sentry from '@sentry/react';

/**
 * Error monitoring for the SPA (T-07). No-ops unless VITE_SENTRY_DSN is set, so
 * local dev stays quiet and only real environments report.
 */
export function initSentry(): void {
    const dsn = import.meta.env.VITE_SENTRY_DSN;

    if (!dsn) {
        return;
    }

    Sentry.init({
        dsn,
        // Conservative default; tune per environment.
        tracesSampleRate: 0.2,
    });
}
