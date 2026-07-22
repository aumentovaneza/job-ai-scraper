import { QueryClient } from '@tanstack/react-query';

/**
 * App-wide TanStack Query client. Server state lives here; client-only UI
 * state lives in Zustand stores (see resources/js/store).
 */
export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            retry: 1,
            refetchOnWindowFocus: false,
        },
    },
});
