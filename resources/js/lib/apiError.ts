import { AxiosError } from 'axios';

/**
 * Pull a human-facing message out of an API error, falling back to a caller-
 * supplied default. TanStack Query's per-call `onError` types the error as
 * `Error`, so callers pass that through and this narrows to the axios shape.
 */
export function apiMessage(error: unknown, fallback: string): string {
    if (error instanceof AxiosError) {
        const data = error.response?.data as { message?: string } | undefined;
        if (data?.message) return data.message;
    }
    return fallback;
}

/**
 * User-friendly error surfacing (T-71), tuned for AI/Claude-backed actions.
 *
 * The backend already returns readable messages for provider failures — an
 * invalid/rejected key (422) and an exhausted spend cap (402) both arrive with a
 * `message` we trust. This adds the cases with no response body — the network
 * dropped, or a long AI generation timed out — and a friendlier line for rate
 * limits, so callers can show one helpful sentence for any failure.
 */
export function friendlyApiMessage(error: unknown, fallback: string): string {
    if (error instanceof AxiosError) {
        // No response at all: connection refused, DNS failure, CORS, or timeout.
        if (!error.response) {
            if (error.code === 'ECONNABORTED' || /timeout/i.test(error.message)) {
                return 'That took too long and timed out. This can happen with longer AI generations — try again.';
            }
            return 'Could not reach the server. Check your connection and try again.';
        }

        const message = (error.response.data as { message?: string } | undefined)?.message;

        switch (error.response.status) {
            case 401:
            case 403:
                // Provider auth failures carry a specific message; prefer it.
                return message ?? 'Your AI key was rejected. Update it in Settings and try again.';
            case 402:
                return message ?? 'This would exceed your AI spend cap. Raise the cap in Settings to continue.';
            case 429:
                return message ?? 'The AI provider is rate-limiting requests right now. Wait a moment and retry.';
            case 504:
                return message ?? 'The AI request timed out. Try again — longer letters sometimes need a retry.';
            default:
                if (message) return message;
        }
    }
    return fallback;
}
