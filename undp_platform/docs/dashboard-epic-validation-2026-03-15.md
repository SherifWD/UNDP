# Dashboard Epic Validation

Updated: March 15, 2026

Scope:
- Dashboard and web-portal epics only
- Mobile epics intentionally excluded
- Validation aligned to roles that can access dashboard surfaces

Status legend:
- `DONE`: implemented and reachable in current dashboard flow
- `PARTIAL`: implemented, but not at full epic acceptance detail
- `GAP`: still missing or materially below epic intent

## Role Summary

| Role | Dashboard Access | Validation |
|---|---|---|
| Reporter | Home dashboard + scoped projects/submissions | `PARTIAL` |
| Municipal Focal Point | Municipal overview + validation worklist + reports | `PARTIAL` |
| UNDP Admin | System reports + users + audit + municipal overview | `PARTIAL` |
| Partner / Donor Viewer | Partner dashboard + projects + funding request flow | `PARTIAL` |
| Auditor | Audit log + system reports | `PARTIAL` |

## Epic Validation

| Epic | Status | Current validation notes |
|---|---|---|
| AUTH-02 User & Role Management | `PARTIAL` | User management, create/edit/disable, role scoping, pagination, and role-limited navigation exist. Remaining gaps are richer municipality/status filters, explicit before/after role comparison UI, and more complete audit drill-down from user rows. |
| AUTH-03 RBAC Matrix Enforcement | `DONE` | API middleware, scoped queries, hidden dashboard routes/buttons, access-denied routing, and audit logging for blocked permissions are present. Admin municipal dashboard access was aligned with permission scope in this pass. |
| AUTH-04 Audit Log of Key Actions | `PARTIAL` | Central audit log, filters, timeline/detail drill-in, and immutable read-only display exist. Remaining gaps are broader filter dimensions from the epic table, richer CSV/PDF audit export UX, and live-update polish. |
| WEB-01 Validator Worklist | `PARTIAL` | Pending queue, scope-aware filtering, sorting, pagination, and auto-refresh exist. Remaining gaps are unread-state handling and stronger bulk validation tooling. |
| WEB-02 Validate Submissions | `PARTIAL` | Approve/reject/rework, mandatory reject reason, timeline, and submission detail review are implemented. Remaining gaps are richer split-screen media navigation and deeper validator productivity tooling. |
| WEB-03 Municipal Overview | `PARTIAL` | KPI cards, municipality-scoped project breakdown, status donut, and project drill-down are implemented. Admin can now open the same view for a selected municipality. Remaining gaps are stronger cross-chart drill-down and export polish. |
| WEB-04 Submission Audit Trail View | `DONE` | Submission timeline, actor/timestamp/comments, and immutable read-only protection are implemented. |
| WEB-05 KPI Dashboard | `PARTIAL` | Filters, KPI cards, donut analytics, municipality/project breakdowns, map integration, trend series, backlog aging, and funding-overview analytics are present. Remaining gaps are more advanced comparative reporting and narrative summaries. |
| WEB-06 Geo-Mapped View | `DONE` | Interactive map, clustering, filters, project/submission marker distinction, popups, and fullscreen mode are implemented. |
| WEB-07 Partner / Donor Read-Only Dashboard | `PARTIAL` | Partner dashboard is read-only for operational data, scoped to approved aggregates, and now includes donor funding-request visibility. New funding-request actions are exposed only through project views. Remaining gap is clearer separation between read-only analytics and donor action surfaces in UX copy. |
| WEB-08 CSV/PDF Exports | `PARTIAL` | Role-scoped export endpoints, async export jobs, progress polling, and download flow exist. Remaining gaps are richer column previews and clearer export presets per report type. |

## Role-by-Role Notes

### Reporter
- `DONE`: own-scope dashboard data, own submissions scope, assigned-project visibility
- `PARTIAL`: epic asks for stronger "My Submissions" dashboard framing and first-use empty-state guidance

### Municipal Focal Point
- `DONE`: municipality-scoped worklist, validation actions, municipal overview KPIs, project drill-down
- `PARTIAL`: epic asks for stronger bulk actions, richer export behavior, and more advanced submission issue surfacing

### UNDP Admin
- `DONE`: full system reports, user management, audit log, export access, funding-request review
- `PARTIAL`: epic asks for complete parity with every focal-point view plus deeper settings/report-builder UX

### Partner / Donor Viewer
- `DONE`: partner dashboard, approved-only aggregate visibility, project access, project-level funding request creation, admin review workflow
- `PARTIAL`: epic originally described fully read-only analytics; current scope now intentionally adds donor funding-request actions, so UX language should keep those two modes clear

### Auditor
- `DONE`: audit log access and system-wide reporting access
- `PARTIAL`: epic asks for broader audit filtering/export evidence views than currently exposed in the dedicated audit screen

## Most Important Remaining Gaps

1. User-management and audit-log filtering still do not fully match the full epic matrix.
2. Validator and municipal dashboards need stronger bulk-action and drill-down ergonomics.
3. Reporting is materially stronger after this pass, but still lacks some executive-style comparative and narrative reporting views.
4. Export UX is functional but still below the requested preview/notification polish level.
