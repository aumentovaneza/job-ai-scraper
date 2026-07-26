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
