/**
 * Shared types for the application tracker (T-40 stages, T-41/T-42 applications
 * + event log). Mirrors the Laravel API contract exactly — see PLAN.md Phase 4.
 */

import type { JobPosting } from '@/types/jobs';

export interface ApplicationStage {
    id: number;
    name: string;
    position: number;
    is_terminal: boolean;
    is_success: boolean;
}

export type EventActor = 'user' | 'system' | 'claude';

export type ApplicationEventType =
    | 'created'
    | 'stage_changed'
    | 'note'
    | 'contact_added'
    | 'follow_up_drafted';

/** Minimal stage reference eager-loaded onto events for the timeline. */
export interface StageRef {
    id: number;
    name: string;
}

export interface ApplicationEvent {
    id: number;
    type: ApplicationEventType;
    from_stage_id: number | null;
    to_stage_id: number | null;
    from_stage?: StageRef | null;
    to_stage?: StageRef | null;
    actor: EventActor;
    occurred_at: string | null;
    metadata: Record<string, unknown> | null;
}

export interface Contact {
    id: number;
    name: string;
    role: string | null;
    email: string | null;
    linkedin_url: string | null;
    notes: string | null;
    created_at: string;
}

export type FollowUpStatus = 'pending' | 'drafted' | 'sent' | 'dismissed';

export interface FollowUp {
    id: number;
    due_at: string | null;
    kind: string | null;
    status: FollowUpStatus;
    draft_content: string | null;
    created_at: string;
}

export interface Application {
    id: number;
    job_posting_id: number;
    current_stage_id: number | null;
    applied_at: string | null;
    created_at: string;
    updated_at: string;
    // Eager-loaded on index/show; optional so the type is reusable.
    job_posting?: JobPosting;
    current_stage?: ApplicationStage | null;
    events?: ApplicationEvent[];
    contacts?: Contact[];
    follow_ups?: FollowUp[];
}
