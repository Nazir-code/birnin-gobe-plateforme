# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository layout

This working directory has two top-level folders:

- `birnin-gobe-code/` — the actual application (Laravel + React/Inertia). All commands below are run from this directory.
- `PROJET BIRNIN GOBE/` — non-code reference material: the cahier des charges (functional spec), technical handover analysis, and UI mockups (`Maquette Birnin Gobe V 1.0/`). These are the functional/business source of truth; treat them as read-only reference, not code to edit.

BIRNIN GOBE is a business platform for managing a national competition (candidate applications, eligibility, evaluation/jury, admin back-office).

## Commands

All commands run from `birnin-gobe-code/`.

Initial setup (Docker-based):
```bash
cp .env.example .env
docker compose build
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose up -d
```
App served at `http://localhost:8080` (via Caddy reverse proxy).

Backend (PHP/Laravel):
```bash
php artisan test                          # run all tests (PHPUnit)
php artisan test --filter=TestName        # run a single test
vendor/bin/pint                           # code style fixer (Laravel Pint)
```

Frontend (React/TypeScript/Inertia, Vite):
```bash
npm install
npm run dev            # Vite dev server
npm run build           # production build
npx playwright test     # run E2E tests (tests/E2E/)
```

CI (`.github/workflows/ci.yml`) runs `php artisan test` on PHP 8.4 with pdo_pgsql/redis, and `npm install && npm run build` on Node 22 — mirror these when validating changes.

## Architecture

**Stack**: Laravel 13 (modular monolith) + React 19/TypeScript via Inertia 3, Filament 5 for standard admin screens, Tailwind CSS 4, PostgreSQL, Redis (cache/queues/workers), S3-compatible storage (MinIO locally), ClamAV for uploads, Caddy, Docker Compose. See ADR-001 (`docs/decisions/ADR-001-monolithe-modulaire.md`): one Laravel deployable, but business rules must stay inside module boundaries rather than spread across controllers — the intended domain boundaries are `Auth`, `Campaign`, `Candidate`, `Application`, `Eligibility`, `Evaluation`, `Jury`, `Notification`, `Reporting`, `Audit`, `Storage`, `Content`, `Administration`.

**Domain layer pattern** (`app/Domain/<BoundedContext>/`): business logic is kept out of controllers/models via explicit domain classes, e.g. `app/Domain/Application/`:
- `ApplicationStatus` — a backed enum is the only valid representation of status (never a French label string, per the "no business status as a French label" UX contract).
- `ApplicationStateMachine` — the single source of truth for legal status transitions (`assertCanTransition`); throws `DomainException` on illegal transitions.
- `SubmitApplication` — a use-case class (not a controller/model method) that wraps a transition in a `DB::transaction`, validates completeness, writes an immutable submission snapshot, and records an audit event. New state-changing workflows should follow this shape rather than mutating `status` directly.
- `app/Domain/Audit/AuditWriter` — centralized audit trail writer (`AuditEvent` model); any state-changing use case should call it with actor, action, target, old/new value, and reason.

Routes in `routes/web.php` are currently prototype-only (`Route::get` closures rendering Inertia pages, no auth/policy/campaign-scope middleware yet) — see the comment there; production routes need auth/verified middleware, resource policies, and campaign scoping before going further.

**Frontend** (`resources/js/`): Inertia + React, pages resolved by `createInertiaApp` from `Pages/**/*.tsx` (see `app.tsx`). Structure:
- `Pages/<Area>/...` — one Inertia page component per route (`Public/Home`, `Candidate/Dashboard`, `Candidate/Application/Challenge`, `Admin/Dashboard`, `Evaluator/Assignments`). `docs/design/SCREEN_MAP.md` maps each mockup screen to its route and implementation file.
- `Layouts/` — `PublicLayout`, `CandidateLayout`, `DarkSidebarLayout` (admin/evaluator).
- `Components/` — shared UI (`Ui.tsx`, `Brand.tsx`, `ProgressSteps.tsx`, `StatCard.tsx`).
- `i18n/fr.ts` — static UI strings. Only French is populated; Hausa/Zarma are intentionally not invented for sensitive text and must fall back to French until institutionally validated translations exist (see `resources/js/i18n/README.md`). Dynamic content (FAQ, themes, criteria, help text, notifications) must come from the CMS/DB, not be hardcoded.
- **No demo-data module.** `data/demo.ts` was deleted once the last screen stopped importing it (ADR-020): it still held the mockups' invented figures — « 5 000+ jeunes impactés », a hardcoded 30 June 2026 deadline — and an unused file full of plausible-looking constants is an invitation to import them back. Per ADR-002 and `docs/design/SCREEN_MAP.md`, dates/stats/names/counters visible in mockups are demo data and must never be hardcoded as business rules — real values come from `Campaign` config or application data, served as Inertia props.

Design tokens (colors, radii, shadows, spacing, focus states, form components) are centralized in `resources/css/app.css` (ADR-002, `docs/decisions/ADR-002-design-system.md`) — mockups are rebuilt as real React/CSS components, not rendered as images.

**Non-negotiable UX contracts** (`docs/architecture/BLUEPRINT-UI-FOUNDATION.md`): mobile-first from 320px; explicit save states; lossless autosave; forward/back navigation without data loss; forms read-only after submission; visible focus/keyboard nav/AA contrast; no business status stored as a French label; sensitive data hidden based on role and resource.

**Fidelity rule** (`docs/design/SCREEN_MAP.md`): the mockups (`docs/design/reference/`) are the visual reference; the cahier des charges and technical handover analysis (in `PROJET BIRNIN GOBE/`) are the functional/security reference. Inconsistencies in generated mockup images (wrong geography/year/label) should not be reproduced if they contradict BIRNIN GOBE / PIDUREM / ANSI source material.

**Infrastructure** (`docker-compose.yml`): `app` (PHP/Laravel), `caddy` (reverse proxy, port 8080), `postgres`, `redis`, `worker` (`queue:work`), `scheduler` (`schedule:work`), `minio` (S3-compatible storage), `clamav`. Env vars in `.env.example`.

## Current implementation state

This is an early-stage implementation/starter, not a finished product — per `README.md`: pages for the screens listed above are coded and the architectural skeleton (status enum, state machine, `SubmitApplication` use case, audit trail, initial migrations for `campaigns`/`applications`/`attachments`/`audit_events`) is in place, but real authentication, RBAC policies, the CMS, Filament admin, persistent autosave, S3/antivirus uploads, and full business workflows still need to be wired in as vertical increments.
