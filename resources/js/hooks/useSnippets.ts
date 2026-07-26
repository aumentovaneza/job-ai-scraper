import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Snippet } from '@/types/coverLetters';

export const SNIPPETS_KEY = ['snippets'] as const;

async function csrf() {
    await api.get('/sanctum/csrf-cookie');
}

/** The user's reusable letter building blocks (T-54), in display order. */
export function useSnippets() {
    return useQuery({
        queryKey: SNIPPETS_KEY,
        queryFn: async () => {
            const { data } = await api.get<{ data: Snippet[] }>('/api/snippets');
            return data.data;
        },
    });
}

export function useCreateSnippet() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (payload: { label: string; content_md: string; position?: number }) => {
            await csrf();
            const { data } = await api.post<{ data: Snippet }>('/api/snippets', payload);
            return data.data;
        },
        onSuccess: () => void qc.invalidateQueries({ queryKey: SNIPPETS_KEY }),
    });
}

export function useUpdateSnippet() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({
            id,
            ...payload
        }: {
            id: number;
            label?: string;
            content_md?: string;
            position?: number;
        }) => {
            await csrf();
            const { data } = await api.patch<{ data: Snippet }>(`/api/snippets/${id}`, payload);
            return data.data;
        },
        onSuccess: () => void qc.invalidateQueries({ queryKey: SNIPPETS_KEY }),
    });
}

export function useDeleteSnippet() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (id: number) => {
            await csrf();
            await api.delete(`/api/snippets/${id}`);
        },
        onSuccess: () => void qc.invalidateQueries({ queryKey: SNIPPETS_KEY }),
    });
}
