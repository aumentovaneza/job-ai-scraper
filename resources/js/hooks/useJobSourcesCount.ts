import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { JobSource, Paginated } from '@/types/jobs';

export const JOB_SOURCES_COUNT_KEY = ['job-sources-count'] as const;

/**
 * Total number of configured job sources for the current user, for the
 * dashboard tile. Requests a single row (`per_page=1`) so only the paginator
 * `total` is read.
 */
export function useJobSourcesCount() {
    return useQuery({
        queryKey: JOB_SOURCES_COUNT_KEY,
        queryFn: async () => {
            const { data } = await api.get<Paginated<JobSource>>('/api/job-sources', {
                params: { per_page: 1 },
            });
            return data.total;
        },
    });
}
