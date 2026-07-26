import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Application, ApplicationEvent, ApplicationStage, Contact } from '@/types/applications';

export const APPLICATIONS_KEY = ['applications'] as const;
export const STAGES_KEY = ['application-stages'] as const;
export const applicationKey = (id: number | string) => ['application', Number(id)] as const;

async function csrf() {
    await api.get('/sanctum/csrf-cookie');
}

/** Every application for the current user (the Kanban board data). */
export function useApplications() {
    return useQuery({
        queryKey: APPLICATIONS_KEY,
        queryFn: async () => {
            const { data } = await api.get<{ data: Application[] }>('/api/applications');
            return data.data;
        },
    });
}

/** The user's ordered pipeline stages (the Kanban columns). */
export function useApplicationStages() {
    return useQuery({
        queryKey: STAGES_KEY,
        queryFn: async () => {
            const { data } = await api.get<{ data: ApplicationStage[] }>('/api/application-stages');
            return data.data;
        },
    });
}

/** A single application with its timeline, contacts and follow-ups. */
export function useApplication(id: number | string) {
    return useQuery({
        queryKey: applicationKey(id),
        queryFn: async () => {
            const { data } = await api.get<{ data: Application }>(`/api/applications/${id}`);
            return data.data;
        },
    });
}

export function useCreateApplication() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (payload: { job_posting_id: number; stage_id?: number }) => {
            await csrf();
            const { data } = await api.post<{ data: Application }>('/api/applications', payload);
            return data.data;
        },
        onSuccess: () => void qc.invalidateQueries({ queryKey: APPLICATIONS_KEY }),
    });
}

/**
 * Move an application to another stage with an optimistic update: the card jumps
 * columns immediately and rolls back if the request fails (T-43).
 */
export function useMoveApplication() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ id, targetStageId }: { id: number; targetStageId: number }) => {
            await csrf();
            const { data } = await api.patch<{ data: Application }>(`/api/applications/${id}/move`, {
                target_stage_id: targetStageId,
            });
            return data.data;
        },
        onMutate: async ({ id, targetStageId }) => {
            await qc.cancelQueries({ queryKey: APPLICATIONS_KEY });
            const previous = qc.getQueryData<Application[]>(APPLICATIONS_KEY);
            qc.setQueryData<Application[]>(APPLICATIONS_KEY, (old) =>
                old?.map((a) => (a.id === id ? { ...a, current_stage_id: targetStageId } : a))
            );
            return { previous };
        },
        onError: (_err, _vars, context) => {
            if (context?.previous) {
                qc.setQueryData(APPLICATIONS_KEY, context.previous);
            }
        },
        onSettled: (_data, _err, vars) => {
            void qc.invalidateQueries({ queryKey: APPLICATIONS_KEY });
            void qc.invalidateQueries({ queryKey: applicationKey(vars.id) });
        },
    });
}

export function useAddNote(applicationId: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (body: string) => {
            await csrf();
            const { data } = await api.post<{ data: ApplicationEvent }>(
                `/api/applications/${applicationId}/notes`,
                { body }
            );
            return data.data;
        },
        onSuccess: () => void qc.invalidateQueries({ queryKey: applicationKey(applicationId) }),
    });
}

export function useAddContact(applicationId: number) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (payload: {
            name: string;
            role?: string;
            email?: string;
            linkedin_url?: string;
            notes?: string;
        }) => {
            await csrf();
            const { data } = await api.post<{ data: Contact }>(
                `/api/applications/${applicationId}/contacts`,
                payload
            );
            return data.data;
        },
        onSuccess: () => void qc.invalidateQueries({ queryKey: applicationKey(applicationId) }),
    });
}
