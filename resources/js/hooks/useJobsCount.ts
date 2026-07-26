import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { JobPosting, Paginated } from '@/types/jobs';

export const JOBS_COUNT_KEY = ['jobs-count'] as const;

/**
 * Total number of scraped jobs for the current user, for the dashboard tile.
 * Requests a single row (`per_page=1`) so only the paginator `total` is read.
 */
export function useJobsCount() {
    return useQuery({
        queryKey: JOBS_COUNT_KEY,
        queryFn: async () => {
            const { data } = await api.get<Paginated<JobPosting>>('/api/jobs', {
                params: { per_page: 1 },
            });
            return data.total;
        },
    });
}
