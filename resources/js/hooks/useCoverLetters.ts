import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { CoverLetter, CoverLetterVersion } from '@/types/coverLetters';

export const coverLetterKey = (applicationId: number | string) => ['cover-letter', Number(applicationId)] as const;

async function csrf() {
    await api.get('/sanctum/csrf-cookie');
}

export interface GenerateLetterPayload {
    length_hint?: 'short' | 'medium' | 'long';
    tone?: string;
    custom_instructions?: string | null;
    variants?: string[];
}

/**
 * The application's cover letter with its versions (newest first), or `null`
 * if the user hasn't drafted one yet. Generation (T-52) runs off the request
 * path, so pass `refetchInterval` while a draft is in flight to poll GET
 * .../cover-letter until the variants land.
 */
export function useCoverLetter(applicationId: number | string, refetchInterval: number | false = false) {
    return useQuery({
        queryKey: coverLetterKey(applicationId),
        queryFn: async () => {
            const { data } = await api.get<{ data: CoverLetter | null }>(
                `/api/applications/${applicationId}/cover-letter`
            );
            return data.data;
        },
        enabled: applicationId !== '',
        refetchInterval,
    });
}

/** Kicks off (or redraws) the 3-variant letter draft; returns the 202 payload including any soft-cap nudge. */
export function useGenerateLetter(applicationId: number | string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (payload: GenerateLetterPayload) => {
            await csrf();
            const { data } = await api.post<{ data: CoverLetter; nudge?: string }>(
                `/api/applications/${applicationId}/cover-letter/generate`,
                payload
            );
            return data;
        },
        onSuccess: ({ data }) => qc.setQueryData(coverLetterKey(applicationId), data),
    });
}

export function useSetActiveVersion(applicationId: number | string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ coverLetterId, versionId }: { coverLetterId: number; versionId: number }) => {
            await csrf();
            const { data } = await api.patch<{ data: CoverLetter }>(
                `/api/cover-letters/${coverLetterId}/active-version`,
                { active_version_id: versionId }
            );
            return data.data;
        },
        onSuccess: (coverLetter) => {
            qc.setQueryData<CoverLetter | null>(coverLetterKey(applicationId), (old) =>
                old ? { ...old, active_version_id: coverLetter.active_version_id } : coverLetter
            );
        },
    });
}

/** The editor autosave target: persists edits to one version's Markdown body. */
export function useUpdateVersionContent(applicationId: number | string) {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async ({ versionId, content_md }: { versionId: number; content_md: string }) => {
            await csrf();
            const { data } = await api.patch<{ data: CoverLetterVersion }>(
                `/api/cover-letter-versions/${versionId}`,
                { content_md }
            );
            return data.data;
        },
        onSuccess: (version) => {
            qc.setQueryData<CoverLetter | null>(coverLetterKey(applicationId), (old) =>
                old
                    ? { ...old, versions: old.versions?.map((v) => (v.id === version.id ? version : v)) }
                    : old
            );
        },
    });
}

/**
 * Rewrites a single paragraph in the same voice/context (a small, synchronous
 * Claude call). Returns just the replacement text — the caller splices it
 * back into content_md and saves via useUpdateVersionContent.
 */
export function useRegenerateParagraph(coverLetterId: number | null | undefined) {
    return useMutation({
        mutationFn: async (payload: { version_id: number; paragraph: string; instructions?: string }) => {
            if (!coverLetterId) throw new Error('No cover letter to regenerate a paragraph for yet.');
            await csrf();
            const { data } = await api.post<{ data: { text: string }; nudge?: string }>(
                `/api/cover-letters/${coverLetterId}/regenerate-paragraph`,
                payload
            );
            return data;
        },
    });
}
