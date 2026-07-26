import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { AnalyticsResponse } from '@/types/analytics';

export const ANALYTICS_KEY = ['analytics'] as const;

/** Live conversion analytics + the latest weekly narrative for the current user. */
export function useAnalytics() {
    return useQuery({
        queryKey: ANALYTICS_KEY,
        queryFn: async () => {
            const { data } = await api.get<AnalyticsResponse>('/api/analytics');
            return data;
        },
    });
}
