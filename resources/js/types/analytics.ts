/** Conversion analytics + weekly narrative for the insights dashboard (T-60/T-61). */

export interface AnalyticsTotals {
    applied: number;
    responded: number;
    response_rate: number | null;
    in_progress: number;
    offers: number;
    won: number;
    rejected: number;
    interview_to_offer_rate: number | null;
}

export interface SourceBreakdown {
    source_id: number | null;
    label: string;
    applied: number;
    responded: number;
    response_rate: number | null;
}

export interface VariantBreakdown {
    variant: string;
    sent: number;
    responded: number;
    response_rate: number | null;
}

export interface StageDwell {
    stage_id: number;
    name: string;
    samples: number;
    avg_days: number;
    median_days: number;
}

export interface GapTally {
    gap: string;
    count: number;
}

export interface AnalyticsOverview {
    generated_at: string;
    totals: AnalyticsTotals;
    response_rate_by_source: SourceBreakdown[];
    response_rate_by_variant: VariantBreakdown[];
    time_in_stage: StageDwell[];
    top_gaps: GapTally[];
}

export interface InsightSummary {
    id: number;
    summary_md: string | null;
    period_start: string | null;
    period_end: string | null;
    generated_at: string;
}

export interface AnalyticsResponse {
    data: AnalyticsOverview;
    summary: InsightSummary | null;
}
