import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

/**
 * Data layer for BYOK + profile + settings (Phase 1). The onboarding wizard
 * (T-12) and the settings page (T-15) both read the same /api/profile payload
 * and share these mutations, so they live together here.
 */

export interface KeyStatus {
    has_key: boolean;
    masked: string | null;
    verified_at: string | null;
}

export interface ProfileData {
    headline: string | null;
    summary: string | null;
    target_roles: string[];
    target_locations: string[];
    target_comp_min_cents: number | null;
    resume_chars: number;
    has_voice_profile: boolean;
    updated_at: string | null;
}

export interface SettingsData {
    timezone: string;
    daily_ai_spend_cap_cents: number | null;
    weekly_ai_spend_cap_cents: number | null;
}

export interface OnboardingStatus {
    key_verified: boolean;
    resume: boolean;
    targets: boolean;
    complete: boolean;
}

export interface ProfilePayload {
    profile: ProfileData;
    key: KeyStatus;
    settings: SettingsData;
    onboarding: OnboardingStatus;
}

export interface UsageWindow {
    spent_cents: number;
    cap_cents: number | null;
}

export interface AiUsage {
    currency: string;
    day: UsageWindow;
    week: UsageWindow;
}

export const PROFILE_KEY = ['profile'] as const;
export const USAGE_KEY = ['ai-usage'] as const;

/** Sanctum requires a fresh XSRF cookie before any stateful request. */
async function csrf(): Promise<void> {
    await api.get('/sanctum/csrf-cookie');
}

export function useProfile() {
    return useQuery({
        queryKey: PROFILE_KEY,
        queryFn: async () => {
            const { data } = await api.get<ProfilePayload>('/api/profile');
            return data;
        },
    });
}

export function useAiUsage() {
    return useQuery({
        queryKey: USAGE_KEY,
        queryFn: async () => {
            const { data } = await api.get<AiUsage>('/api/ai/usage');
            return data;
        },
    });
}

export function useStoreKey() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (key: string) => {
            await csrf();
            const { data } = await api.post<{ verified: boolean; key: KeyStatus; message: string }>(
                '/api/anthropic-key',
                { key }
            );
            return data;
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: PROFILE_KEY }),
    });
}

export function useDeleteKey() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async () => {
            await csrf();
            await api.delete('/api/anthropic-key');
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: PROFILE_KEY }),
    });
}

export interface ProfileUpdate {
    headline?: string | null;
    summary?: string | null;
    target_roles?: string[];
    target_locations?: string[];
    target_comp_min_cents?: number | null;
}

export function useUpdateProfile() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (payload: ProfileUpdate) => {
            await csrf();
            const { data } = await api.put<ProfilePayload>('/api/profile', payload);
            return data;
        },
        onSuccess: (data) => qc.setQueryData(PROFILE_KEY, data),
    });
}

export function useUploadResume() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (file: File) => {
            await csrf();
            const form = new FormData();
            form.append('resume', file);
            const { data } = await api.post<ProfilePayload>('/api/profile/resume', form);
            return data;
        },
        onSuccess: (data) => qc.setQueryData(PROFILE_KEY, data),
    });
}

export function useExtractVoice() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (writingSample: string | null) => {
            await csrf();
            await api.post('/api/profile/voice', { writing_sample: writingSample || null });
        },
        onSuccess: () => qc.invalidateQueries({ queryKey: PROFILE_KEY }),
    });
}

export function useUpdateSettings() {
    const qc = useQueryClient();
    return useMutation({
        mutationFn: async (payload: Partial<SettingsData>) => {
            await csrf();
            const { data } = await api.put<{ settings: SettingsData }>('/api/settings', payload);
            return data.settings;
        },
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: PROFILE_KEY });
            qc.invalidateQueries({ queryKey: USAGE_KEY });
        },
    });
}
