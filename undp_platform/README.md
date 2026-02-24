# UNDP Web + API Platform (Vue + Laravel)

This repository delivers the **web frontend + backend API + dashboards production foundation**.  
Mobile client implementation is excluded by scope.

## Stack
- Backend: Laravel 12, Sanctum token auth, SQLite/MySQL compatible schema
- Frontend: Vue 3, Vue Router, Pinia, Vue I18n (AR/EN + RTL), Vite
- Exports: CSV + PDF (Dompdf)

## Implemented Domains (Current Baseline)
- OTP authentication with phone (`+218` default), resend cooldown, returning-user login
- RBAC matrix (Reporter, Municipal Focal Point, UNDP Admin, Partner/Donor Viewer, Auditor)
- User management APIs + status toggles + role assignment
- Immutable audit logs with filters
- Submission workflow APIs (pending queue, approve/reject/rework, timeline)
- Role-scoped dashboards (KPI, municipal overview, partner read-only)
- Geo map API and Vue map view
- CSV/PDF export endpoints

Detailed architecture and epic status:
- `docs/backend-architecture-production.md`
- `docs/epic-recheck-web-api.md`

## Key API Routes
- `POST /api/auth/request-otp`
- `POST /api/auth/verify-otp`
- `GET /api/users`
- `GET /api/submissions/pending`
- `POST /api/submissions/{id}/approve|reject|rework`
- `GET /api/dashboard/kpis`
- `GET /api/dashboard/map`
- `GET /api/audit-logs`
- `GET /api/exports/csv`
- `GET /api/exports/pdf`

## Local Setup
1. Install dependencies
   - `composer install`
   - `npm install`
2. Configure app
   - `cp .env.example .env` (if missing)
   - `php artisan key:generate`
3. Build database and seed sample users/projects/submissions
   - `php artisan migrate:fresh --seed`
4. Run app
   - `php artisan serve`
   - `npm run dev`

## Test and Build
- `php artisan test`
- `npm run build`

## Seeded Sample Accounts
Use OTP login with these phone numbers (country code `+218`):
- UNDP Admin: `910000001`
- Auditor: `910000002`
- Municipal Focal Point: `910000003`
- Partner/Donor Viewer: `910000004`
- Reporter: `910000101`

OTP codes are generated server-side and logged in `storage/logs/laravel.log` (placeholder SMS implementation).
