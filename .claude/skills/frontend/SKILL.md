---
name: frontend
description: >-
  Frontend engineering for this React 19 + TypeScript SPA — pages, components,
  shadcn/ui, TanStack Query data fetching, Zustand stores, React Router, and
  Sanctum cookie auth via the shared axios client. Use when adding or changing
  anything under resources/js/ or resources/css/. Covers this repo's api client,
  auth flow, @/ alias, Tailwind 4 + new-york shadcn conventions.
---

# Frontend engineering (React 19 / TypeScript / Vite)

You are working in a single-page app served by Laravel and built with Vite. It is the
UI for a job-application copilot ("JobScope"): job feed, match scores, application
kanban/tracker, and cover-letter generation/editing.

**Read `/PLAN.md` for the authoritative scope** — feature phases and stable T-XX task ids
(§5), UI-specific tasks (T-12 onboarding, T-21 sources, T-27 feed, T-33 match UI, T-43
kanban, T-53 letter editor, T-70–73 polish). `.context/todos.md` tracks current status.
Match your task to its T-XX id; name branches/commits/PRs `T-XX: <title>`.

## Stack & where things live

- **React 19 + TypeScript**, bundled by **Vite 8** (`vite.config.js`). Entry: `resources/js/main.tsx` → `App.tsx`.
- **Routing:** `react-router-dom` v7. Routes are declared in `App.tsx`; auth-gated routes
  wrap children in `<ProtectedRoute>` (`resources/js/components/ProtectedRoute.tsx`).
- **Server state:** `@tanstack/react-query` v5 (`resources/js/lib/queryClient.ts`). Use it
  for all data fetching/mutations — do **not** stuff server data into Zustand.
- **Client/UI state:** `zustand` v5 stores in `resources/js/store/` (e.g. `useAuthStore`, `useAppStore`).
- **HTTP:** the shared axios instance in `resources/js/lib/api.ts` — **always import `api`,
  never call `axios` directly.** It's preconfigured for Sanctum cookie auth.
- **Styling:** Tailwind CSS v4 (`resources/css/app.css`, CSS-variable theme, `neutral` base).
- **Components:** shadcn/ui, **new-york** style, in `resources/js/components/ui/`. Icons: `lucide-react`.
- **Path alias:** `@/` → `resources/js/`. Import as `@/lib/api`, `@/components/ui/button`, etc.
- **Typecheck** with `npm run typecheck` (`tsc --noEmit`); dev server is `npm run dev`.

## Auth & data-fetching flow (follow the existing pattern)

- Auth lives in `useAuthStore`. Login does `GET /sanctum/csrf-cookie` **first**, then
  `POST /api/login`; session is hydrated on app load via `GET /api/me` (`fetchMe`).
- The `api` client already sends credentials + the XSRF header (`withCredentials`,
  `withXSRFToken`). For any **mutating** request, ensure the CSRF cookie has been fetched
  once in the session (login already does this).
- API routes are under `/api/*`. Auth-only endpoints require the session cookie.
- For reads/writes to domain data, use React Query hooks that call `api.get/post/...`.
  Keep query keys stable and colocated; invalidate on mutation success.

## Component conventions

- **TypeScript everywhere** — no untyped props. Define an interface for props and for API
  response shapes (see `AuthUser` in `useAuthStore.ts`).
- Add shadcn primitives under `components/ui/`; build feature components alongside pages.
  Use the `cn()` helper from `@/lib/utils` for conditional classes; use `cva` for variants
  (see `components/ui/button.tsx`).
- Pages go in `resources/js/pages/` and are wired into `App.tsx`. Gate authenticated pages
  with `<ProtectedRoute>`.
- Use Tailwind utility classes + the theme's CSS variables (`text-muted-foreground`, etc.);
  don't hardcode hex colors. Prefer semantic tokens so light/dark stay consistent.
- Handle the three server states explicitly: loading, error, empty. Don't render against
  `undefined` data.

## Checklist before finishing

- [ ] All data fetching goes through React Query + the shared `api` client
- [ ] New authenticated pages are wrapped in `<ProtectedRoute>` and added to `App.tsx`
- [ ] Props and API response types are declared (no `any`)
- [ ] Mutations invalidate the relevant query keys
- [ ] `npm run typecheck` passes and `npm run build` succeeds
