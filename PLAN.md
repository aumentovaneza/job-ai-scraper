# JobScope — AI Job Scraper & Application Tracker

**Working name:** JobScope (rename anytime)
**Stack:** Laravel 13 + PHP 8.3, React 18 + Vite 8, PostgreSQL + pgvector, Redis, Tailwind 4, Firecrawl API, Anthropic Claude API (BYOK), Voyage AI embeddings, Postgres full-text search
**Deploy target:** Laravel Cloud, Singapore region (`ap-southeast-1`)
**Usage model:** Invite-only, small user base (owner + 2 test users initially). Proper per-user data isolation and per-user BYOK from day one. No public signup, no Stripe, no consumer-scale legal in MVP.

---

## 1. Vision & non-goals

**What this is:** a personal job discovery, matching, application-drafting, and pipeline-tracking tool for one job seeker at a time. Scrapes job sources on a schedule, enriches with Claude, scores against the user's resume, generates specific cover letters per job, and tracks every application through interview stages so the user can see what's actually working.

**What this is NOT:**
- Not a job board
- Not an auto-applier (human review always)
- Not a recruiter tool
- Not a team/company product in MVP (single-user, single-tenant per account)
- Not a scraper for sites that prohibit scraping in their ToS (Indeed, LinkedIn, Glassdoor — we route around them via ATS feeds and company career pages)

**Success criteria for MVP:**
- User signs up, adds their Claude API key, uploads a resume
- User adds ≥3 job sources; system scrapes them on schedule
- Jobs appear enriched with Claude within 5 min of scrape
- User can generate 2-3 cover letter variants per job
- User can move a job through Saved → Applied → Interview → Offer stages
- User can see conversion analytics after 20+ applications

---

## 2. Tech stack (finalized)

| Layer | Choice | Why |
|------|--------|-----|
| Framework | Laravel 13.8 | Already scaffolded |
| PHP | 8.3 | Already installed |
| DB | PostgreSQL (Neon-powered on Laravel Cloud) with pgvector extension | JSONB for scrape payloads, pgvector native on Cloud, no self-hosting |
| Queue | Redis + Horizon (Laravel Cloud managed Redis) | Horizon dashboard is essential for scraping ops |
| Cache | Redis | Same instance, separate DB |
| Search | PostgreSQL full-text search (`tsvector`) + pgvector | No managed Meilisearch on Laravel Cloud; Postgres FTS is plenty for personal-use scale and removes one moving part |
| Frontend | React 18 + TypeScript + Vite 8 | User preference; SPA over Inertia because we'll add a Chrome ext later |
| Styling | Tailwind 4 (already installed) + shadcn/ui | Fast build, clean defaults |
| Client state | Zustand | Small footprint |
| Server state | TanStack Query v5 | Non-negotiable |
| Auth | Sanctum (SPA) | Standard for React + Laravel |
| Scraping | Firecrawl `/extract` with schema | Structured output, not raw HTML parsing |
| LLM | Anthropic Claude via user's API key (BYOK) | Business model |
| Embeddings | Voyage AI (`voyage-3-lite`), key in `.env` (not BYOK) | Cheap enough to eat as owner cost; single project-level key |
| Errors | Sentry | Both Laravel + React |
| Deploy | Laravel Cloud (`ap-southeast-1` Singapore) | Managed Postgres+Redis+scheduler+queues, low APAC latency |
| Storage | Laravel Cloud Object Storage (S3-compatible) | For resumes, letter exports |

---

## 3. Architecture overview

```
Browser (React SPA)
      │
      ▼
Laravel API (Sanctum-authed)
      │
      ├── HTTP → Firecrawl API (scraping)
      ├── HTTP → user's Anthropic key (enrichment, letters)
      ├── HTTP → Voyage AI (embeddings)
      │
      ├── Redis queues (Horizon)
      │     ├── ScrapeSourceJob
      │     ├── EnrichJobJob
      │     ├── DedupeJobJob
      │     ├── MatchJobToProfileJob
      │     └── GenerateLetterJob
      │
      └── PostgreSQL (pgvector + tsvector FTS)
```

Every external API call is queued. Nothing scraping/AI-related happens in a web request. Frontend polls or opens SSE for progress.

---

## 4. Data model (v1)

Rough shape — refine during T-04:

```
users
  id, email, password, name
  encrypted_anthropic_key, anthropic_key_verified_at
  daily_ai_spend_cap_cents, weekly_ai_spend_cap_cents
  timezone, created_at

profiles                      -- one per user, matching context
  id, user_id, headline, summary
  resume_file_id, resume_text (extracted)
  voice_profile jsonb         -- writing style extracted from samples
  target_roles jsonb, target_locations jsonb, target_comp_min_cents

job_sources                   -- what to scrape
  id, user_id, type (ats_feed|career_page|rss|firecrawl_search)
  url, config jsonb, cron_schedule, active, last_scraped_at

jobs                          -- deduped canonical job records
  id, source_hash (dedup key), title, company, location
  remote_type (remote|hybrid|onsite), salary_min, salary_max, salary_currency
  jd_text, jd_html_snapshot, apply_url
  posted_at, first_seen_at, last_seen_at
  raw_extract jsonb           -- full Firecrawl payload
  enrichment jsonb            -- Claude's structured analysis
  embedding vector(1024)      -- pgvector

job_source_hits               -- many-to-many jobs ↔ sources (same job on multiple boards)
  job_id, source_id, source_url, first_seen_at

match_scores                  -- per user × job
  id, user_id, job_id, score (0-100)
  reasoning, strengths jsonb, gaps jsonb
  computed_at, prompt_version

applications                  -- user's actual applications
  id, user_id, job_id, current_stage_id
  applied_at, source (which JobSource led to it)
  resume_version_id, active_letter_version_id
  created_at

application_stages            -- per-user ordered stages
  id, user_id, name, position, is_terminal, is_success
  -- Default seeds: Saved, Applied, Recruiter Reply, Phone Screen,
  --                Technical, Onsite, Offer, Accepted, Rejected, Withdrawn

application_events            -- event log (source of truth for timeline)
  id, application_id, type, from_stage_id, to_stage_id
  actor (user|system|claude), occurred_at, metadata jsonb

contacts                      -- recruiter/HM/referrer per app
  id, application_id, name, role, email, linkedin_url, notes

cover_letters                 -- one per application
  id, application_id, active_version_id

cover_letter_versions         -- many per letter
  id, letter_id, content_md, generation_params jsonb
  variant_label (story_led|results_led|culture_led|custom)
  parent_version_id, was_sent, created_at

follow_ups                    -- scheduled nudges
  id, application_id, due_at, kind, status (pending|drafted|sent|dismissed)
  draft_content

ai_calls                      -- audit log of every Claude/Voyage call
  id, user_id, provider, model, endpoint
  input_tokens, output_tokens, cost_cents
  purpose (enrich|match|letter|analysis), reference_type, reference_id
  status, error, created_at
```

---

## 5. Phased roadmap

Each task below is scoped to be ~1 Conductor session. Task IDs are stable — reference them in commit messages and PR titles (e.g. `T-14: add Firecrawl extract wrapper`).

**Dependencies are hard: don't start a task until its deps are merged.**
**Parallel-safe means the task touches non-overlapping files with its siblings.**

---

### Phase 0 — Foundation (blocks everything)

**Goal:** repo is ready for parallel feature work.

| ID | Task | Depends | Parallel-safe with |
|----|------|---------|--------------------|
| T-01 | Local dev: Docker Compose with Postgres 16 + pgvector image + Redis; matching config in `.env.example`; migration to `CREATE EXTENSION IF NOT EXISTS vector`. Prod Postgres/Redis are managed by Laravel Cloud, no infra work needed there | — | T-02, T-03 |
| T-02 | Install Horizon; publish config; add dashboard route behind auth gate. Confirm Horizon runs on Laravel Cloud queue workers (documented in Cloud dashboard) | — | T-01, T-03 |
| T-03 | Install React 18 + TypeScript + shadcn/ui + TanStack Query + Zustand in `resources/js`; wire Vite; create `<App />` shell with placeholder router | — | T-01, T-02 |
| T-04 | Author all Phase 1-5 migrations from the data model in section 4; run and verify; add model stubs (empty classes, fillable arrays, relationships only). Include a `tsvector` generated column on `jobs` (title + jd_text) with GIN index for FTS | T-01 | — |
| T-05 | Install Sanctum for SPA auth; scaffold `/login`, `/logout`, `/me` endpoints. No public `/register` — invited users hit `/invite/{token}` to set their password. React auth store + protected routes. Add `is_admin` flag on users; only admin can issue invites | T-03, T-04 | T-06 |
| T-06 | `JobSearchService`: Postgres FTS via `plainto_tsquery` against the `tsvector` column, with filters (source, remote_type, salary_min, posted_at). Later Phase 3 will layer pgvector semantic search on top | T-04 | T-05 |
| T-07 | Add Sentry (Laravel + React); add structured logging with request IDs; add health-check endpoint at `/up` (Laravel Cloud uses this for liveness) | T-05 | T-08 |
| T-08 | Set up Pest for tests; configure GitHub Actions CI (composer install, migrate, test); add Pint for formatting | — | T-07 |
| T-09 | Seed admin/owner user via `DatabaseSeeder` reading `OWNER_EMAIL`, `OWNER_PASSWORD` from `.env`; add `invitations` table + admin UI to invite the 2 test users (email + token link, expires in 7d). Setup on Laravel Cloud is `php artisan migrate --seed` and then invite from the admin panel | T-04, T-05 | — |

**End of Phase 0 acceptance:** blank React SPA at `/`, owner login works, invite flow can add a second user who lands with an empty (isolated) account, Horizon dashboard reachable, tests + CI green.

---

### Phase 1 — BYOK & profile setup

**Goal:** user can onboard, add their Claude key safely, upload a resume.

| ID | Task | Depends | Parallel-safe with |
|----|------|---------|--------------------|
| T-10 | `AnthropicKeyService`: encrypt with Laravel `Crypt`, store on `users.encrypted_anthropic_key`; `verify()` method that pings Claude with a 1-token request; on failure, mark invalid and notify user | T-05 | T-11, T-12 |
| T-11 | `AnthropicClient` service wrapping the API: takes user-scoped key at construction, handles retry/backoff, records every call to `ai_calls`, enforces daily/weekly spend cap; throws `AiBudgetExceededException` when over | T-10 | — |
| T-12 | Invited user onboarding wizard (React): 3 steps after they accept the invite — (1) paste Anthropic API key + verify inline, (2) upload resume, (3) set target roles/locations/comp. Owner reuses the same flow initially. Standalone Settings page for later edits | T-05 | T-13 |
| T-13 | Resume ingestion: accept PDF/docx, extract text (use `smalot/pdfparser` for PDF, `phpoffice/phpword` for docx), store in `profiles.resume_text` | T-04 | T-12 |
| T-14 | Voice profile extraction: on demand, run Claude over resume text + any pasted writing sample; store extracted style in `profiles.voice_profile` jsonb | T-11, T-13 | — |
| T-15 | Settings page: manage API key (rotate/remove), spend caps, timezone; a live "AI spend this week: $X.XX / $Y cap" indicator | T-11 | — |

**End of Phase 1 acceptance:** user completes signup, key is verified, resume text is stored, spend caps are enforceable.

---

### Phase 2 — Scraping pipeline

**Goal:** user adds sources; jobs land in DB, deduped, indexed.

| ID | Task | Depends | Parallel-safe with |
|----|------|---------|--------------------|
| T-20 | `FirecrawlClient`: HTTP wrapper for `/scrape` and `/extract` endpoints; timeout, retry, logging; env-based API key | T-07 | T-21, T-22 |
| T-21 | Job sources UI (React): CRUD for `job_sources`; source types: ATS feed (Greenhouse/Lever/Workable JSON URL), company career page (Firecrawl-scraped), RSS; test-scrape button that runs synchronously and shows what would land | T-05, T-20 | T-22 |
| T-22 | `ScrapeAtsFeedJob`: for Greenhouse/Lever/Workable/Ashby — direct JSON fetch, no Firecrawl needed; upsert into `jobs` | T-04 | T-23 |
| T-23 | `ScrapeCareerPageJob`: uses Firecrawl `/extract` with structured schema (title, company, location, salary, jd, apply_url); upsert into `jobs` | T-20 | T-22 |
| T-24 | `DedupeJobJob`: on any new job, compute `source_hash = sha256(company + normalized_title + location)`; check DB; if hit, add to `job_source_hits` instead of creating new; if miss, create and mark for embedding | T-04 | — |
| T-25 | Voyage embedding job: compute embedding for `title + jd_text[:2000]`; store in `jobs.embedding`; second dedup pass with cosine similarity > 0.92 → merge | T-24 | — |
| T-26 | Scheduler wiring: `ScrapeSourcesCommand` reads `job_sources.cron_schedule`, dispatches per-source jobs; register in `bootstrap/app.php` | T-22, T-23 | — |
| T-27 | Job feed UI (React): list view with filters (source, remote type, salary min, posted_at, search box hitting `JobSearchService` Postgres FTS); virtualized scroll | T-06, T-24 | — |

**End of Phase 2 acceptance:** add a Greenhouse feed URL, wait for scheduler, jobs appear in the feed with dedup working.

---

### Phase 3 — AI enrichment & matching

**Goal:** every job is scored against the user, with reasoning.

| ID | Task | Depends | Parallel-safe with |
|----|------|---------|--------------------|
| T-30 | Prompt library: `resources/prompts/` folder with versioned Markdown prompt files loaded via a `Prompt::load('enrich_job.v1')` helper; all prompts have a version number stored on each call | T-11 | — |
| T-31 | `EnrichJobJob`: for each new job, call Claude with `enrich_job.v1` prompt; extract required_skills, nice_to_have_skills, seniority, remote_type, salary_band, red_flags, one_line_summary; store on `jobs.enrichment` jsonb | T-11, T-30 | T-32 |
| T-32 | `MatchJobToProfileJob`: for each (user, job) pair after enrichment, call Claude with `match_score.v1` prompt using profile + enrichment; store 0-100 score, reasoning, strengths, gaps in `match_scores` | T-14, T-30, T-31 | — |
| T-33 | Match reasoning UI: on any job card, show score badge with tooltip; on detail view, show full reasoning + strengths/gaps as chips | T-27, T-32 | — |
| T-34 | Cache hits: hash of `(jd_text, profile_version, prompt_version)` — if seen, return cached result, skip Claude call | T-32 | — |

**End of Phase 3 acceptance:** every job in the feed has a score badge; clicking one shows Claude's reasoning; second run of same job doesn't hit Claude.

---

### Phase 4 — Application tracker

**Goal:** move jobs through stages; timeline is real.

| ID | Task | Depends | Parallel-safe with |
|----|------|---------|--------------------|
| T-40 | Seed default `application_stages` on user creation (Saved, Applied, Recruiter Reply, Phone Screen, Technical, Onsite, Offer, Accepted, Rejected, Withdrawn); stages CRUD API for customization | T-05 | T-41 |
| T-41 | Applications API: `POST /applications` (from a job), `PATCH /applications/{id}/move` (with target_stage_id), `POST /applications/{id}/notes`, `POST /applications/{id}/contacts` | T-40 | T-42 |
| T-42 | Event-sourced writes: every state change appends to `application_events`; `current_stage_id` is derived (denormalized for query speed but rebuildable) | T-40 | T-41 |
| T-43 | Kanban board (React): drag between columns using `@dnd-kit/core`; optimistic update, revert on API error | T-41, T-42 | T-44 |
| T-44 | Application detail page: JD snapshot, timeline (from events), notes, contacts, documents, active cover letter, follow-up widget | T-42 | T-43 |
| T-45 | Follow-up engine: nightly command scans applications with no stage change > N days; drafts follow-up via Claude; queues as `follow_ups` pending; user reviews in a dedicated inbox and copies to clipboard | T-11, T-44 | — |

**End of Phase 4 acceptance:** save a job → apply → mark interview → mark offer, all reflected in a timeline you can audit.

---

### Phase 5 — Cover letter generator (the moat)

**Goal:** high-quality, per-job letters that learn from what converts.

| ID | Task | Depends | Parallel-safe with |
|----|------|---------|--------------------|
| T-50 | `CompanyContextService`: when generating a letter, fetch company homepage + `/about` + `/careers` via Firecrawl `/scrape`; cache per company for 30 days; return distilled facts as text | T-20 | T-51 |
| T-51 | Prompts: `letter_story_led.v1`, `letter_results_led.v1`, `letter_culture_led.v1` in prompt library; all take (jd, resume_text, voice_profile, company_context, length_hint, tone, custom_instructions) | T-14, T-30 | T-50 |
| T-52 | `GenerateLetterJob`: fans out to 3 variants in parallel Claude calls; creates a `cover_letter` if none, then 3 `cover_letter_versions` with `variant_label` set; user picks one to set active | T-11, T-42, T-51 | — |
| T-53 | Letter editor UI (React): TipTap or Lexical rich text editor; sidebar shows company_context Claude used (so user can veto claims); "regenerate this paragraph" via selection + prompt | T-52 | T-54 |
| T-54 | Snippet library: user saves reusable blocks (standard closing, referral mention paragraph); slash-command insertion in the editor | T-53 | — |
| T-55 | Letter export: copy as rich text, plain text, download as .docx (use `docx` skill) or .pdf | T-53 | — |
| T-56 | Regeneration caps: soft (5/day nudges "try editing"), hard (tied to user's daily spend cap) | T-11, T-52 | — |

**End of Phase 5 acceptance:** open a saved job, click "Draft letter," see 3 variants with different angles, edit one, export as docx.

---

### Phase 6 — Analytics & feedback loop

**Goal:** close the loop — surface what's working, feed insights back into generation.

| ID | Task | Depends | Parallel-safe with |
|----|------|---------|--------------------|
| T-60 | Analytics queries: response rate per source, response rate per letter variant, time-in-stage distribution, interview→offer rate, top gaps flagged across rejected apps | T-42, T-52 | T-61 |
| T-61 | Insights dashboard (React): 4-6 tiles + one narrative summary generated by Claude weekly ("Your last 30 applications: story-led letters got 3x response rate...") | T-60 | — |
| T-62 | Feedback loop: user-specific priors extracted from history are injected into the letter-generation prompt as a "based on this user's history" preamble | T-52, T-60 | — |
| T-63 | Weekly digest email: top new matches + insights summary; use Mailtrap in dev, Resend in prod | T-32, T-61 | — |

**End of Phase 6 acceptance:** dashboard shows real numbers after seeding 30 test applications; new letter generations reference user's historical patterns.

---

### Phase 7 — Polish & launch

| ID | Task | Depends |
|----|------|---------|
| T-70 | Empty states everywhere (first-run, no jobs yet, no applications yet, no key set) |
| T-71 | Error boundaries + user-friendly Claude error surfacing (invalid key, over quota, timeout) |
| T-72 | Loading skeletons for all async views |
| T-73 | Keyboard shortcuts (`j`/`k` navigate feed, `s` save, `a` apply, `l` open letter) |
| T-74 | Deploy to Laravel Cloud (`ap-southeast-1`): connect GitHub repo, add Postgres+Redis resources, set env vars (Anthropic key, Firecrawl key, Voyage key, owner seed), run `migrate --seed`, smoke test end-to-end |
| T-75 | Data export button (JSON dump of all your applications, letters, jobs) — useful for backups even in personal use |

_Deferred while invite-only: public signup, consumer-scale legal/ToS/privacy policy, Stripe, marketing site. **Still shipped**: proper login, invited-user onboarding, per-user data isolation, per-user BYOK, admin invite panel, password reset (needed since test users will forget passwords)._

---

## 6. Suggested Conductor parallelization

Wave 1 (Phase 0 foundation, day 1-3): T-01, T-02, T-03 in parallel; T-04 after T-01; T-08 anytime.
Wave 2 (Phase 0 finish): T-05, T-06, T-07 in parallel after T-04.
Wave 3 (Phase 1 + 2 kickoff): T-10, T-20, T-21 in parallel. T-13 in parallel with T-10.
Wave 4: T-11 → T-12/T-14/T-15 in parallel. T-22/T-23/T-24 in parallel.
Wave 5: T-25, T-26, T-27 in parallel after their deps.
Wave 6: T-30 (blocks all AI work) → T-31/T-32 sequential → T-33/T-34 parallel.
Wave 7: Phase 4 tasks — T-40 first, then T-41/T-42 parallel, then T-43/T-44 parallel, then T-45.
Wave 8: Phase 5 tasks — T-50/T-51 parallel, then T-52, then T-53/T-54/T-55 parallel, T-56 anytime after T-52.
Wave 9: Phase 6 + Phase 7 polish.

Rule of thumb: any two tasks in the same "parallel-safe with" cell can run as concurrent Conductor sessions. Do NOT parallelize tasks that share migration files, model files, or the same React route.

---

## 7. Guardrails & limitations (bake into product from day 1)

**Legal:**
- Do NOT scrape Indeed, LinkedIn, Glassdoor, ZipRecruiter — their ToS prohibits it. Route users toward ATS feeds (Greenhouse, Lever, Workable, Ashby) and company career pages.
- ToS clause: users are responsible for scraping only sources they have permission to scrape.

**Multi-user data isolation (non-negotiable from day 1):**
- Every user-owned model gets a `user_id` column and a `BelongsToUser` global scope that automatically filters queries to `auth()->id()`. Add it to: `profiles`, `job_sources`, `applications`, `application_stages`, `application_events`, `contacts`, `cover_letters`, `cover_letter_versions`, `follow_ups`, `match_scores`, `ai_calls`, `invitations`.
- `jobs` is the one exception — it's a shared canonical record (same job on Greenhouse is the same job for both users). But `match_scores` and everything downstream IS user-scoped, so users still only see their own scores/enrichments/applications.
- All API endpoints validate ownership via Policy classes; no raw `Model::find($id)` without going through the scope.
- Feature tests for every endpoint MUST include a "user B cannot access user A's data" case. This is a checklist item in every task's acceptance criteria — no exceptions.
- Job source URL isolation: if two users add the same Greenhouse feed, we scrape it once and fan out `match_scores` per user; but `job_sources` rows are still per-user for CRUD.

**BYOK:**
- Anthropic keys encrypted at rest with Laravel `Crypt`; never returned via API even to owner (mask as `sk-ant-...abc123`).
- Never log the key. Middleware scrubs it from Sentry payloads.
- Verify on entry with a 1-token ping; re-verify weekly; surface breakage in UI.
- Every AI call recorded in `ai_calls` with cost estimate, scoped to the user whose key was used. Daily + weekly spend caps enforced pre-call, per-user. Hard stop when exceeded.
- One user's key exhaustion never blocks another user's work.

**Scraping hygiene:**
- Async only. No scrape in a web request.
- Cache aggressively: same JD → skip re-enrichment; same URL scraped in last 6h → serve from cache.
- Circuit breaker per source: after 3 consecutive failures, pause and alert user.

**AI hygiene:**
- All prompts versioned in `resources/prompts/`. Never inline prompts in code.
- Every Claude output surfaced to the user must be regenerable from stored `generation_params`.
- Hallucination guard: company facts used in letters come from Firecrawl-scraped pages, not Claude's memory. Show source panel in editor.
- Confidence over certainty: match scores show reasoning, not just a number.

**Product boundaries:**
- No auto-apply.
- No auto-send email/letters.
- No promises about interview conversion rates in marketing copy.

**Privacy (invite-only means lighter but not zero):**
- Resume and application data encrypted at rest.
- One-click data export (JSON) per user — their data only.
- Admin can revoke a user (soft-delete → hard-delete after 7d grace period), which cascades to all their user-scoped data.
- No cross-user analytics visibility. Insights dashboards are strictly per-user, even for the admin/owner viewing their own account.

---

## 8. Post-MVP roadmap (not for Conductor yet — track as backlog)

- Chrome extension: save any job from any page → drops into the tracker
- Gmail read-only integration: auto-detect stage transitions from recruiter emails
- Semantic search across saved jobs ("show me remote React roles that mention platform teams")
- Interview prep pack per job (questions + STAR-format practice from resume)
- Calendar integration for interview scheduling
- Multi-resume support (different resumes for different role types)
- Shared boards for career coaches with clients (this turns the product into B2B)

---

## 9. Task template for Conductor sessions

When kicking off a task, use this template for the session prompt:

```
Task: T-XX — <title from plan>

Read PLAN.md for context. Do not exceed this task's scope.

Deliverable:
- <file paths to create or modify>

Acceptance criteria:
- <bullet list of what "done" looks like>

Do NOT touch:
- <files outside this task's scope, especially migrations owned by other tasks>

Tests required:
- <feature tests / unit tests>

When done, open a PR titled "T-XX: <title>" against main.
```

---

## 10. Decisions log

Resolved 2026-07-22:
- **Name:** JobScope
- **Deploy:** Laravel Cloud, `ap-southeast-1` (Singapore)
- **Voyage AI:** owner-funded, single key in `.env`, not BYOK
- **Payment:** none; invite-only, no Stripe in MVP
- **Scheduler:** Laravel Cloud native scheduler; no dedicated worker box
- **Access model:** invite-only, admin issues invites; owner + 2 test users initially
- **Search:** PostgreSQL FTS (`tsvector`) + pgvector, no external search service

Still open (not blockers, decide during relevant phase):
- [ ] Sentry paid vs Sentry free tier — free is fine for 3-user MVP
- [ ] Email transport for invites/follow-ups: Resend vs Postmark vs Laravel Cloud's default
- [ ] Whether to snapshot job HTML to S3 as immutable record (nice for audit, costs storage)

---

_End of plan v1. Update this file as decisions get made — every Conductor session should be able to open PLAN.md and know exactly where it fits._
