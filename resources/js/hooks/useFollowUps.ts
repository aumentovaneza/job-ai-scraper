import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { FollowUp, FollowUpStatus } from '@/types/applications';

export const FOLLOW_UPS_KEY = ['follow-ups'] as const;

/** A follow-up as returned by the inbox endpoint, with its application + job. */
export interface InboxFollowUp extends FollowUp {
    application?: {
        id: number;
        job_posting_id: number;
        job_posting?: { id: number; title: string; company: string };
    };
}

async function csrf() {
    await api.get('/sanctum/csrf-cookie');
}

/** Active (pending/drafted) follow-ups awaiting the user's review. */
export function useFollowUps() {
    return useQuery({
        queryKey: FOLLOW_UPS_KEY,
        queryFn: async () => {
            const { data } = await api.get<{ data: InboxFollowUp[] }>('/api/follow-ups');
            return data.data;
        },
    });
}

export function useUpdateFollowUp() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, status }: { id: number; status: FollowUpStatus }) => {
            await csrf();
            const { data } = await api.patch<{ data: FollowUp }>(`/api/follow-ups/${id}`, { status });
            return data.data;
        },
        onSuccess: () => void qc.invalidateQueries({ queryKey: FOLLOW_UPS_KEY }),
    });
}
