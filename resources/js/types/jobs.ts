/**
 * Shared types for the scraping pipeline (T-21 job sources, T-27 job feed).
 * Mirrors the Laravel API contract exactly — see PLAN.md Phase 2.
 */

/** Shape returned by Laravel's default paginator (`->paginate()`). */
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page?: number;
    per_page: number;
    total: number;
}

export type JobSourceType = 'ats_feed' | 'career_page' | 'rss' | 'json_api';
export type AtsProvider = 'greenhouse' | 'lever' | 'workable' | 'ashby';
export type RemoteType = 'remote' | 'hybrid' | 'onsite';
export type TimezoneOverlap = 'any' | 'partial' | 'strict';

/** A field_map value: a single dot-path or a list of candidate paths. */
export type FieldMapValue = string | string[];

export interface JobSourceConfig {
    provider?: AtsProvider;
    board_token?: string;
    /** json_api: dot-path to the array of items (null/omitted = body is the array). */
    items_path?: string | null;
    /** json_api: request headers to merge (e.g. a User-Agent). */
    headers?: Record<string, string>;
    /** json_api: target NormalizedJob field => source dot-path(s). */
    field_map?: Record<string, FieldMapValue>;
}

export interface JobSource {
    id: number;
    type: JobSourceType;
    url: string;
    config: JobSourceConfig | null;
    cron_schedule: string | null;
    active: boolean;
    hires_internationally: boolean;
    timezone_overlap: TimezoneOverlap | null;
    last_scraped_at: string | null;
    created_at: string;
}

export interface NormalizedJob {
    title: string;
    company: string;
    location: string | null;
    remote_type: RemoteType | null;
    salary_min: number | null;
    salary_max: number | null;
    salary_currency: string | null;
    jd_text: string | null;
    apply_url: string | null;
    posted_at: string | null;
    source_url: string | null;
    tags: string[];
}

export interface JobPosting {
    id: number;
    title: string;
    company: string;
    location: string | null;
    remote_type: RemoteType | null;
    salary_min: number | null;
    salary_max: number | null;
    salary_currency: string | null;
    jd_text: string | null;
    apply_url: string | null;
    posted_at: string | null;
    tags: string[] | null;
    first_seen_at: string | null;
    last_seen_at: string | null;
    created_at: string;
}
