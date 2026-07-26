/**
 * Shared types for the cover-letter generator (T-52 backend, T-53/T-54/T-55
 * frontend). Mirrors the Laravel API contract exactly — see PLAN.md Phase 5.
 */

export type VariantLabel = 'story_led' | 'results_led' | 'culture_led' | 'custom';

/**
 * Recorded alongside each version so a draft is always regenerable and the
 * editor can show the hallucination-guard note (PLAN.md §7): whether real,
 * scraped company facts were available when this version was drafted.
 */
export interface CoverLetterGenerationParams {
    prompt_version?: string;
    variant?: string;
    length_hint?: string;
    tone?: string;
    custom_instructions?: string | null;
    company_context_used?: boolean;
    model?: string;
    [key: string]: unknown;
}

export interface CoverLetterVersion {
    id: number;
    cover_letter_id: number;
    content_md: string;
    variant_label: VariantLabel;
    generation_params: CoverLetterGenerationParams | null;
    was_sent: boolean;
    parent_version_id: number | null;
    created_at: string;
}

export interface CoverLetter {
    id: number;
    application_id: number;
    active_version_id: number | null;
    // Eager-loaded on show/generate, newest first.
    versions?: CoverLetterVersion[];
}

export interface Snippet {
    id: number;
    label: string;
    content_md: string;
    position: number;
}
