import type { FieldMapValue } from '@/types/jobs';

/**
 * One-click templates for the generic json_api source type. Selecting a preset
 * pre-fills the URL, items_path, headers and field_map into the (still fully
 * editable) form. The backend stays a single generic code path — these are
 * purely a frontend convenience.
 *
 * HN Algolia is intentionally excluded: its "Who is Hiring" hits are freeform
 * comment prose with no title/company keys, which a flat field-map can't parse.
 */
export interface JsonApiPreset {
    key: string;
    label: string;
    url: string;
    itemsPath: string;
    headers: Record<string, string>;
    fieldMap: Record<string, FieldMapValue>;
}

const BROWSER_UA = 'Mozilla/5.0 (compatible; JobScraper/1.0)';

export const JSON_API_PRESETS: JsonApiPreset[] = [
    {
        key: 'remoteok',
        label: 'RemoteOK',
        url: 'https://remoteok.com/api',
        itemsPath: '', // top-level array; item[0] is a legal notice (auto-skipped)
        headers: { 'User-Agent': BROWSER_UA },
        fieldMap: {
            title: 'position',
            company: 'company',
            location: 'location',
            remote_type: 'location',
            salary_min: 'salary_min',
            salary_max: 'salary_max',
            jd_text: 'description',
            apply_url: 'url',
            posted_at: 'date',
            tags: 'tags',
        },
    },
    {
        key: 'remotive',
        label: 'Remotive',
        url: 'https://remotive.com/api/remote-jobs',
        itemsPath: 'jobs',
        headers: {},
        fieldMap: {
            title: 'title',
            company: 'company_name',
            location: 'candidate_required_location',
            remote_type: 'candidate_required_location',
            salary: 'salary',
            jd_text: 'description',
            apply_url: 'url',
            posted_at: 'publication_date',
            tags: 'tags',
        },
    },
    {
        key: 'himalayas',
        label: 'Himalayas',
        url: 'https://himalayas.app/jobs/api',
        itemsPath: 'jobs',
        headers: {},
        fieldMap: {
            title: 'title',
            company: 'companyName',
            location: 'locationRestrictions',
            salary_min: 'minSalary',
            salary_max: 'maxSalary',
            jd_text: 'description',
            apply_url: 'applicationLink',
            posted_at: 'pubDate',
            tags: 'categories',
        },
    },
    {
        key: 'arbeitnow',
        label: 'Arbeitnow',
        url: 'https://www.arbeitnow.com/api/job-board-api',
        itemsPath: 'data',
        headers: {},
        fieldMap: {
            title: 'title',
            company: 'company_name',
            location: 'location',
            remote_type: 'remote',
            jd_text: 'description',
            apply_url: 'url',
            posted_at: 'created_at',
            tags: 'tags',
        },
    },
];
