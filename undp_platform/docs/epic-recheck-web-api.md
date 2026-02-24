# Epic Recheck (Web + API Scope)

Updated: February 19, 2026

Status legend:
- `DONE`: implemented and validated in current codebase
- `PARTIAL`: implemented baseline, but not all acceptance/UX/perf details
- `MISSING`: not implemented yet
- `OUT OF SCOPE`: mobile-client-only item (backend may be ready)

## AUTH & RBAC

| Epic | Status | Notes |
|---|---|---|
| AUTH-01 Secure Login (AR/EN) | DONE | Phone + country picker (+218 default), numeric keypad entry, inline validation, OTP boxes with auto-advance, OTP autofill hint, resend countdown, change-number flow, returning-user welcome toast, and loading/skeleton behavior are implemented. |
| AUTH-02 User & Role Management | DONE | User CRUD, role assignment, enable/disable, filters, sortable columns, pagination, CSV export, create-user modal, edit side-panel, role-change confirmation modal, and inline validation are implemented. |
| AUTH-03 RBAC Matrix Enforcement | DONE | API middleware + policy + query scoping implemented. Unauthorized routes/components guarded. Blocked permission attempts are logged. |
| AUTH-04 Audit Log of Key Actions | PARTIAL | Central audit log with advanced filters, quick date presets, pagination, detail panel, polling-based refresh, immutable entries, and CSV/PDF export links implemented. Missing push-based realtime stream and richer audit-detail segmentation/report templates. |

## Web Validation Portal

| Epic | Status | Notes |
|---|---|---|
| WEB-01 Validator Worklist | PARTIAL | Pending tab with count badge, municipality scope label, project/date/status/search filters, sorting controls, pagination, and periodic auto-refresh are implemented. Missing explicit unread/read persistence and minor UX polish. |
| WEB-02 Validate Submissions | PARTIAL | Split-screen detail+timeline, structured approve/reject/rework modal, reason templates, mandatory comment enforcement for reject/rework, and media evidence preview/download are implemented. Missing richer media gallery interactions and animation polish. |
| WEB-03 Municipal Overview | PARTIAL | Dedicated municipal overview view + API with municipality-scoped KPIs, clickable status breakdown, and project progress cards are implemented. Missing advanced chart visualizations. |
| WEB-04 Submission Audit Trail View | DONE | Immutable submission timeline with actor/time/comment implemented in API and detail view. |

## Reporting & Maps

| Epic | Status | Notes |
|---|---|---|
| WEB-05 KPI Dashboard | PARTIAL | KPI cards, municipality/project/status breakdown, trend table, advanced filters, chips, and status drill-down are implemented. Missing richer charting library visualizations. |
| WEB-06 Geo-Mapped View | PARTIAL | Server-side map clustering, zoom-aware cluster/raw rendering, persistent filters, reset action, and marker detail popups are implemented. Missing dedicated full-screen map route/layout and marker spiderfy behavior. |
| WEB-07 Partner/Donor Read-Only Dashboard | PARTIAL | Role-restricted read-only partner dashboard with municipality/project/date filters and approved-only trends is implemented. Missing final donor-focused chart aesthetics and stricter read-only visual lock indicators. |
| WEB-08 CSV/PDF Exports | PARTIAL | CSV + PDF export endpoints implemented with role-scoped filtering, including users CSV export for user-management view. Missing asynchronous export jobs, progress polling, and completion notifications for large exports. |

## Mobile Epics (MOB-01..MOB-10)

| Epic Group | Status | Notes |
|---|---|---|
| Mobile UI/UX implementation | OUT OF SCOPE | No Flutter/mobile app code in this repository by request. |
| Mobile-supporting backend contracts | PARTIAL | OTP, submission workflow, media upload, statuses, and push dispatch scaffolding exist. Full offline sync semantics/backoff/chunking orchestration remain mobile-client responsibility plus some backend hardening. |

## Summary
- Backend and Vue web foundation is strong and test-validated.
- Core RBAC, auditability, workflow, and reporting APIs are in place.
- Not all epic UX/performance details are complete; several items are in `PARTIAL` and need a final implementation phase for full acceptance parity.

## Current Not-Done (Backend/Web/Dashboard/APIs)
1. Push-based realtime updates (WebSocket/SSE) for audit/worklist/dashboard instead of polling.
2. Async export generation with queue progress API and ready notifications.
3. Rich chart components (donut/stacked/interactive bar) for dashboard analytics.
4. Full-screen map experience with advanced marker interactions (spiderfy/overlap handling).
