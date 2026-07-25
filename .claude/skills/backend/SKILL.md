---
name: backend
description: >-
  Backend engineering for this Laravel 13 job-scraper API — Eloquent models,
  migrations, controllers, form requests, policies, queued jobs (Horizon), and
  feature tests. Use when adding or changing anything under app/, routes/,
  database/, config/, or tests/ (PHP). Covers this repo's multi-tenant
  BelongsToUser scoping, Sanctum SPA auth, Postgres+pgvector, and testing rules.
---

# Backend engineering (Laravel 13 / PHP 8.3)

You are working in a Laravel 13 API that backs a React SPA. The domain is a
job-application copilot ("JobScope"): it scrapes job postings, enriches them with AI,
scores them against a user's profile, and tracks applications.

**Read `/PLAN.md` for the authoritative scope** — the data model (§4), the phased task
list with stable T-XX ids and dependencies (§5), and the non-negotiable guardrails (§7).
Match your task to its T-XX id and don't exceed its scope. `.context/todos.md` tracks
current status. Name branches/commits/PRs `T-XX: <title>`.

## Stack & where things live

- **Framework:** Laravel 13, PHP 8.3. Format with `./vendor/bin/pint` before finishing.
- **DB:** Postgres 16 + `pgvector`. SQLite is only tolerated for light local tooling —
  anything touching `vector`/`tsvector`/generated columns is Postgres-only and must
  guard on `getDriverName() === 'pgsql'` (see `0001_01_01_000003_enable_pgvector_extension.php`).
- **Queues:** Redis via Laravel Horizon (`config/horizon.php`). All scraping, embedding,
  AI enrichment, and scoring run as queued jobs — never inline in a request.
- **Auth:** Sanctum **cookie/session** SPA auth (not token headers). Public route is
  `POST /api/login`; everything else sits behind `auth:sanctum`. No public registration —
  accounts come via invite flow. See `routes/api.php` and `app/Http/Controllers/Auth/`.
- **Models:** `app/Models/`. Controllers: `app/Http/Controllers/`. Validation:
  `app/Http/Requests/`. Routes: `routes/api.php` (API), `routes/web.php` (serves the SPA).

## Model conventions (match these exactly)

- Declare mass-assignable fields with the PHP attribute, not a `$fillable` array:
  ```php
  #[Fillable(['title', 'company', 'apply_url', /* ... */])]
  class JobPosting extends Model { }
  ```
- Declare casts with the `casts(): array` **method** (not the `$casts` property). Use
  `'array'` for JSON columns (`raw_extract`, `enrichment`), `'datetime'` for timestamps.
- Relationships are typed (`: HasMany`, `: BelongsTo`).
- `vector` and generated `tsvector` columns are **managed via raw SQL**, never Eloquent
  casts. Don't try to `$model->embedding = [...]`; write them with `DB::statement`/query bindings.

## Multi-tenancy — the most important rule

Every **user-owned** model uses the `BelongsToUser` trait
(`app/Models/Concerns/BelongsToUser.php`). It adds a global scope that filters all
queries to `Auth::id()` and stamps `user_id` on create — but **only when a user is
authenticated**. In console commands and queue workers the scope is a no-op, so those
contexts operate across users and **must set `user_id` explicitly**.

- Shared/canonical data (`JobPosting`, `JobSource`) is **NOT** user-scoped. Per-user
  context lives in `MatchScore`, `Application`, `Profile`, etc.
- The global scope is defense-in-depth, **not** your authorization. Every user-scoped
  API endpoint also needs a **Policy** and an explicit ownership check.
- **Mandatory test:** every user-scoped endpoint gets a "user B cannot read/modify user
  A's row" feature test (expect 403/404). This is a non-negotiable checklist item.

## Request → response flow

1. **Form Request** (`app/Http/Requests/...`) for validation + authorization. Follow
   `Auth/LoginRequest.php` — put custom logic (e.g. `authenticate()`) on the request.
2. **Controller** stays thin: validate via the typed request, call a model/service/job,
   return `response()->json([...])`. Return typed `JsonResponse`.
3. **Policy** for ownership/role checks (`is_admin` exists on users).
4. **Queued Job** for anything slow or external (HTTP scrape, AI call, embedding).

## Testing (required for every change)

- Tests live in `tests/Feature` and `tests/Unit`; run with `php artisan test`.
- Write a **feature test** for every new endpoint: happy path, validation failure (422),
  unauthenticated (401), and cross-tenant isolation (403/404).
- Use model factories. Assert on JSON shape and DB state (`assertDatabaseHas`).
- Tests must pass and `pint` must be clean before you call a task done.

## Checklist before finishing

- [ ] Migration guards non-Postgres drivers where it uses pgvector/tsvector/generated cols
- [ ] New user-owned model uses `BelongsToUser`; shared data does not
- [ ] Endpoint has a Form Request, a Policy, and cross-tenant + validation feature tests
- [ ] Slow/external work is a queued job, not inline
- [ ] `php artisan test` green and `./vendor/bin/pint` clean
