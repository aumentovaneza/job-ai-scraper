import type { JobPosting, SalaryBand } from '@/types/jobs';

/** Formats a job's top-level scraped salary range, e.g. "USD 100,000–140,000". */
export function formatSalary(job: JobPosting): string | null {
    if (job.salary_min == null && job.salary_max == null) return null;
    const currency = job.salary_currency ? `${job.salary_currency} ` : '';
    const fmt = (n: number) => n.toLocaleString();
    if (job.salary_min != null && job.salary_max != null) {
        return `${currency}${fmt(job.salary_min)}–${fmt(job.salary_max)}`;
    }
    const only = job.salary_min ?? job.salary_max;
    return only != null ? `${currency}${fmt(only)}+` : null;
}

/** Formats an AI-extracted salary band (enrichment.salary_band), e.g. "USD 100,000–140,000 / year". */
export function formatSalaryBand(band: SalaryBand | undefined): string | null {
    if (!band || (band.min == null && band.max == null)) return null;
    const currency = band.currency ? `${band.currency} ` : '';
    const fmt = (n: number) => n.toLocaleString();
    const period = band.period ? ` / ${band.period}` : '';
    if (band.min != null && band.max != null) {
        return `${currency}${fmt(band.min)}–${fmt(band.max)}${period}`;
    }
    const only = band.min ?? band.max;
    return only != null ? `${currency}${fmt(only)}+${period}` : null;
}

export function formatDate(iso: string | null | undefined): string | null {
    if (!iso) return null;
    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}
