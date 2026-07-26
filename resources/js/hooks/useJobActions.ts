import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

async function csrf() {
    await api.get('/sanctum/csrf-cookie');
}

/**
 * Re-score a posting for the current user. The score is computed by a
 * background job, so success only means "started" — the new score appears on
 * the next fetch. We invalidate the jobs list immediately and again after a
 * short delay to pick the score up once the worker has run.
 */
export function useRescoreJob() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (jobId: number) => {
            await csrf();
            const { data } = await api.post<{ message: string }>(`/api/jobs/${jobId}/rescore`);
            return data;
        },
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: ['jobs'] });
            setTimeout(() => void qc.invalidateQueries({ queryKey: ['jobs'] }), 5000);
        },
    });
}

/**
 * Re-run the shared AI enrichment for a posting (funded by the caller's key).
 * Also asynchronous; enrichment fans out a fresh score afterwards.
 */
export function useEnrichJob() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (jobId: number) => {
            await csrf();
            const { data } = await api.post<{ message: string }>(`/api/jobs/${jobId}/enrich`);
            return data;
        },
        onSuccess: () => {
            void qc.invalidateQueries({ queryKey: ['jobs'] });
            setTimeout(() => void qc.invalidateQueries({ queryKey: ['jobs'] }), 5000);
        },
    });
}
