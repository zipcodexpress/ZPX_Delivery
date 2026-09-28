# Admin implementation backlog

Status: ADM-01/ADM-02 implemented on `codex/admin-foundation` pending merge. ADM-03 customer restriction and driver status slice is on `codex/admin-customer-driver`; read-only customer/driver detail and recent activity are on `codex/admin-identity-details`, both pending review. Search/filtering, support and fleet/commercial extensions remain. ADM-04–ADM-18 TODO. This is a task map, not a substitute for code and test evidence.
Start with [README](README.md); use [functions](FUNCTIONS.md) and [data/API](DATA_AND_API.md) for requirements.
Each task is a bounded reviewable increment. Split large tasks further while retaining the parent ID; do not implement this entire backlog in one PR.

## Execution order

ADM-01 -> ADM-02 -> ADM-03. Then ADM-04 and ADM-05 establish partner/site foundations; ADM-06–ADM-09 build hardware and operations management. ADM-10–ADM-12 introduce financial records and reporting after access boundaries are validated. This is a dependency sequence, not an instruction to spawn parallel agents.

Admin software work uses synthetic data and does not depend on a live door. Real commissioning, destination completion and payout activation retain their separate evidence gates. Existing P7.1 equipment work remains identifiable through ADM-08; this plan permits preparing independent admin foundations without declaring P5/P7 complete.

## ADM-01 — contract baseline and scoped access foundation

Requirements: F01, F11. Dependencies: none. Maps to P7.1 administration foundation.

- Inspect `apps/api/route/api.php`, `apps/api/src/Identity/Service.php`, `DriverManagementController.php`, `packages/ui/AdminWorkspace.tsx` and canonical OpenAPI.
- Reconcile existing runtime admin routes with the contract without changing behavior. Preserve original LocationCreate and StaffGrant compatibility.
- Define capability profiles and deny-by-default resource scope resolver; retain existing `requireRole` behavior. Add `/admin/access` and tests for network, location-limited and no-admin users. Partner-specific grants are added in ADM-04; unknown future scope fails closed now.
- Add only needed controller/service helpers; do not refactor every controller.
- Tests A01, A02, A03 below; contract generation/check and targeted identity/HTTP regression.
- Done: existing admin/driver routes have accurate contracts, no privilege expansion, and access hints match enforced server permissions.

## ADM-02 — admin shell and existing workflow navigation

Requirements: F01 and shared behavior. Dependency: ADM-01.

- Implement [ThinkPHP-rendered pages](THINKPHP_PRESENTATION.md), shared session-cookie path migration and existing identity/CSRF integration; no new React admin surface.
- Build navigation and list/detail form conventions; link current shipment, driver approval, route and recovery flows. Keep their URLs/API behavior usable.
- Add scope/freshness indicators, keyboard/empty/error states and refresh/back-navigation support. Do not publish menu items leading to placeholder success pages.
- Browser tests A02, A04 and A24. PHP syntax/template rendering checks and existing operations E2E remain passing.
- Done: a permitted operator can complete every preexisting admin flow; unauthorized profiles cannot gain a new action.

## ADM-03 — customer and driver administration

Requirements: F02, F03. Dependency: ADM-02.

- Add scoped lists/details and explicit SHIPPING restriction; preserve existing identity verification and customer recovery process.
- Extend driver list and guarded status management; suspension blocks new offers but keeps assigned custody visible. Carrier affiliation waits for ADM-04/ADM-09.
- Cover contact redaction, restriction semantics, stale update and suspended driver with active custody. Implement actual eligibility checks in relevant shipping/offer services.
- Tests A03, A05, A06. Done: restrictions are enforced by services; no admin form can mark identity/payment/custody successful.

## ADM-04 — partner entities, relationships and delegated access

Requirements: F06, F11. Dependencies: ADM-01, ADM-02.

- Add partner entities/roles, internal ZPX identity, typed grants and effective-dated resource relationships as required by current resources.
- Implement partner onboarding/list/detail, bounded invitation/grant/revoke, scope resolution, approval-limited change requests and offboarding blockers.
- Protect lists, counts, detail, joins and exports under the same scope; ensure users cannot grant privileges they lack or turn a partner role into ADMIN.
- Tests A01, A03, A07, A08. Done: two competing partners cannot read or modify each other's resources, while one customer can still ship through both.

## ADM-05 — sites, calendars and location provisioning

Requirements: F04. Dependencies: ADM-02, ADM-04.

- Implement the concrete first-slice contracts in DATA_AND_API, including backward-compatible location creation and draft-site backfill.
- Add site forms and location associations, calendar/eligibility policy, host assignment, lifecycle and origin-hub configuration using current route service.
- Preserve one-locker-per-location and all existing IDs. Do not autoactivate a new site/locker or guess membership eligibility.
- Tests A03, A09, A10, A11; existing shipping and pickup-routing regressions.
- Done: an admin creates a mall site with two draft service locations; an unauthorized partner cannot attach them; closed/restricted sites cannot accept new unauthorized work.

## ADM-06 — locker catalog and physical inventory

Requirements: F05. Dependencies: ADM-04, ADM-05.

- Translate concepts from apartment `CabinetModelService`, `LockerService` and `ApartmentCabinetService` into delivery entities; do not copy their DB calls or status manipulation.
- Add versioned model/layout and cabinet modules; instantiate physical compartments while preserving existing board/door uniqueness.
- Record installation, owner and lead operator periods. Provide draft mapping review with explicit immutable publication.
- Tests A12, A13 and ownership/compartment DB regressions.
- Done: published model updates cannot mutate installed layouts; asset/site history survives valid transfers; occupied equipment refuses relocation.

## ADM-07 — commissioning and maintenance

Requirements: F05. Dependency: ADM-06. Production activation also depends on real terminal commissioning capability.

- Add checklists/evidence references, freshness display, work orders and guarded activation/maintenance/retirement.
- Separate SIMULATED from PHYSICAL evidence. Show exact missing commissioning requirements; do not fake a production-ready state.
- Tests A13, A14. Done for software: simulator paths and denial cases work; production hardware gate remains explicitly blocked if unverified.

## ADM-08 — hub staff, capacity and equipment

Requirements: F08, F09; existing decision 0008/P7.1. Dependencies: ADM-04, ADM-05; equipment endpoints do not require ADM-07.

- Add hub configuration/staff/slot forms and independent asset ledger. Reuse receiving, dispatch and scan-journal services unchanged where possible.
- Add scoped support cases linking current exceptions and recovery; no generic direct custody mutation.
- Tests A15, A16, plus receiving/dispatch regression. Verify runtime cannot UPDATE/DELETE asset history.
- Done: equipment can be issued/returned once under concurrency with actor/hub attribution and no per-scan hardware coupling.

## ADM-09 — carriers and fleet relationships

Requirements: F03, F07. Dependencies: ADM-03, ADM-04, ADM-05.

- Add carrier profile to partner, effective driver affiliations, vehicle management and explicit site permissions.
- Validate assigned driver/run/site independently of carrier commercial access; suspension does not silently remove custody.
- Tests A06, A07, A17. Done: carrier staff see only assigned commercial/operational scope and cannot use company affiliation to open a parcel door.

## ADM-10 — commercial agreements and earning ledger

Requirements: F10. Dependencies: ADM-04, ADM-06 and qualifying custody-service events.

- Implement draft/versioned agreement workflow, deterministic synthetic rates, obligation snapshots, immutable balanced entries, holds and reversal links.
- No live activation without configured commercial decisions. No activation from synthetic evidence. Do not alter current customer pricing or existing driver compensation semantics.
- Tests A18, A19, A20. Done: concurrent/replayed service facts yield one earning; agreement/ownership changes do not change past entries; reversals preserve auditability.

## ADM-11 — statements and manual payout reconciliation

Requirements: F10. Dependency: ADM-10.

- Generate/close immutable statements, separate preparer/approver, allow partner disputes and record externally executed payout evidence.
- Implement unknown-outcome reconciliation and retry identity; no live money-sending integration in this task.
- Tests A20, A21, A22. Done: one earning appears in at most one closed statement and the preparer cannot approve their own payout.

## ADM-12 — reports, audit exports and multi-region readiness

Requirements: F01, F11, F12. Dependencies: relevant preceding modules; finance reports require ADM-11.

- Add defined scoped metrics, background exports with download reauthorization, audit views and source freshness.
- Measure representative multi-partner query plans, enforce bounded pagination and indexes. Add no distributed infrastructure without measured need.
- Tests A03, A10, A23. Done: bounded/reportable performance evidence and no partner leaks through counts, queued jobs, exports or stale links.

## Acceptance catalog

Global console additions ADM-13–ADM-18 below extend the original twelve tasks. They can deliver read views before advanced mutation workflows; they do not require all original tasks to finish first.

| ID | Setup/action | Required result |
|---|---|---|
| A01 | Customer/partner submits network role or forged scope | 403/404; no privilege change or hidden data |
| A02 | Authorized staff loads capabilities; visits old admin flows | Correct navigation and unchanged existing behavior |
| A03 | Partner A requests B's object/list/count/export, including guessed ID | No B records, counts or PII; no mutation; object existence concealed |
| A04 | Refresh detail URL, browser back, failed save, keyboard-only use | Navigation/state correct; input retained; visible usable feedback |
| A05 | SHIPPING-restricted customer starts shipment then views existing parcel | New shipping denied; tracking/support and managed existing custody remain available |
| A06 | Suspend driver holding parcel, then request/accept new offer | New work denied; parcel still assigned/accounted for, recovery explicit |
| A07 | Expired affiliation/grant or cross-network referenced entity | Denied server-side, including same previously valid URL |
| A08 | Partner attempts self-elevation; last network access admin revoked | Both refused with audit; partner cannot appoint network finance approver |
| A09 | Backfill locations; create site/location; retry same request | IDs/custody unchanged; one creation; contradictory idempotency payload conflicts |
| A10 | DST boundary, overnight hours, holiday closure, different state timezone | Eligibility follows local calendar deterministically; closed site stays closed |
| A11 | Concurrent site edit and deactivation while work exists | Stale edit rejected; new work stopped; existing custody not erased |
| A12 | Publish template, instantiate, then create newer template | Original deployed mapping unchanged; invalid dimensions/colliding addresses rejected |
| A13 | Relocate or transfer occupied/pending-command locker | Refused; no missing parcel or changed historical location; idle approved move appends history |
| A14 | Activate with missing/stale device evidence or simulator evidence only | Production activation denied with specific missing gates |
| A15 | Two staff concurrently issue same hub asset | One issue wins; current custody unique; immutable actor/hub ledger |
| A16 | Close support case for a missing parcel | Case state changes only; package custody unchanged; unsupported recovery not faked |
| A17 | Carrier-authorized but unassigned driver requests parcel action | Denied; affiliation/site agreement is not parcel authorization |
| A18 | Duplicate events with different IDs represent same service obligation | One earning by semantic uniqueness; no duplicate beneficiary payout |
| A19 | Change rate/owner after an earning; process delayed event | Snapshot governs; historical beneficiary/amount unchanged |
| A20 | Partial/full refund before/after close, rounding boundaries | Linked bounded adjustments; original immutable; currency journal balanced |
| A21 | Concurrent statement close includes same earning | One closed allocation only; other request conflicts/reuses result |
| A22 | Preparer approves own payout; provider outcome unknown | Self-approval refused; unknown stays pending; reconciliation precedes retry |
| A23 | Revoke scope after export queued or file generated | Worker/download refuses disclosure; file expiry enforced; CSV formula text neutralized |
| A24 | Old API-path cookie, fresh login, admin page, API call, logout | Old cookie cleared, one root-scoped cookie accepted, both surfaces authenticated, logout clears both paths; no duplicate-session ambiguity |
| A25 | Global operator filters all-network dashboard then city/partner | Counts and drill-down predicates agree; clearing filters restores authorized network view |
| A26 | One user has customer, driver and hub staff roles | One people result with role-specific tabs and independent tab permissions; no duplicate total |
| A27 | Location overrides site contact; primary contact expires | Correct effective primary/backup shown; expired contacts excluded and missing coverage flagged |
| A28 | Contact linked to login or shared among sites | No new account permission or verified-channel update; only authorized resource assignments visible |
| A29 | Search SI through package/locker/site/payment related links | Stable connected records, independent scope checks, no usable pickup/label secrets |
| A30 | One parcel delivered in a multi-parcel shipment; late event arrives | Other parcels stay incomplete; timeline labels occurred/received time and confirmed vs pending evidence |
| A31 | Payment capture outcome unknown; duplicate reconcile/refund attempts | No manual success, duplicate charge or excessive cumulative refund; stable provider identity |
| A32 | Mixed currencies/environments in report fixture | Separate totals; no test money in live totals; amounts and event bases explicitly named |
| A33 | Notification resend after grant revoked or job scope revoked | Stale sensitive message not sent; permission and current purpose reevaluated; deduplicated retry |
| A34 | Menu leaf lacks route/permission or feature implementation | Registry check fails or page hidden; global read role cannot acquire write/approve powers |

## ADM-13 — global navigation, search and network overview

Requirements: G01, global workspace and menu registry in GLOBAL_CONSOLE. Dependencies: ADM-01/02; cards use available domain read services and show unavailable for missing sources.

- Add static authorized menu registry, network scope filters, scoped exact-identifier search, network dashboard and map using existing location tooling.
- Build read services with bounded queries, capability-specific projections and explicit freshness/event bases. No new search infrastructure or road-routing engine required.
- Tests A03, A25, A29, A34. Done: global users can discover implemented records across regions; restricted users cannot infer hidden search results/counts.

## ADM-14 — unified people and location contact directory

Requirements: G02. Dependencies: ADM-01/02; site/location assignment writes require ADM-05, partner assignment writes require ADM-04.

- Add unified read projection of existing user/customer/driver/hub-staff identities, without duplicate login tables. Add independent contact records and typed effective-dated assignments with primary/backup rules.
- Implement contact list/detail/edit/archive, resource-specific views, site fallback and coverage warnings. Backfill only explicit known contact data with provenance; do not infer a login/verification from a phone match.
- Tests A03, A26, A27, A28. Done: one person with multiple roles appears once; primary/backup contacts resolve correctly without granting access or leaking other site relationships.

## ADM-15 — global locker, compartment and device inspection

Requirements: G03. Dependencies: ADM-01/02; use existing inventory for read-only baseline, ADM-06/07 for provisioning and commissioning writes.

- Add global compartment/device lists and linked site/locker/session views, with claim/occupancy, partition ownership, pending evidence and stale telemetry distinguished.
- No generic command replay, open-all, occupancy clearing or plaintext credentials.
- Tests A03, A13, A14, A29. Done: locate a parcel's recorded door and capacity constraints globally without mutating hardware/custody.

## ADM-16 — global shipment, tracking and dispatch workspace

Requirements: G04. Dependencies: ADM-01/02 and existing shipping/custody read services. ADM-14 contacts enrich navigation but do not block package visibility.

- Add shipment/package lists, SI lookup, joined but bounded custody timeline, labels, recipient pickup metadata, demand/offers/runs and exception views.
- Reuse existing routes and service actions; new domain mutations require explicit separate implementation. Preserve per-package state, rejected/pending evidence and actual event ordering.
- Tests A03, A29, A30 and existing custody regressions. Done: global operators trace packages end-to-end without treating incomplete/synthetic evidence as physical delivery.

## ADM-17 — customer payment, pricing and financial reconciliation views

Requirements: G05. Dependencies: ADM-01/02, current payment/driver services. Partner earning links wait for ADM-10/11.

- Provide global payment attempt/detail, pricing/quote and driver-earning views; distinguish wallet payment methods from stored money.
- Specify and implement guarded reconcile/refund contracts only through available provider/domain adapters. Unsupported mutations show a blocker; read-only visibility is separately deliverable.
- Tests A03, A31, A32 plus relevant provider/payment regressions. Done: unknown payment outcomes and accounting-versus-external settlement are visible and cannot be manually falsified.

## ADM-18 — notifications, integrations and system operations

Requirements: G06. Dependencies: ADM-01/02 and existing messaging/outbox; support links reuse ADM-08 when available.

- Add masked notification history, versioned template preview, explicit authorized resend, integration/queue health and allowlisted settings with audit.
- Use existing idempotent handlers for retries; no arbitrary job/SQL/shell runner. Do not send production messages as part of tests.
- Tests A03, A23, A33, A34. Done: operators can diagnose failures and perform supported scoped recovery without revealing secrets or sending stale/unauthorized messages.

## Definition of done and verification

For each task, record exact implementation paths, migration/contract changes, commands run, outcomes and remaining gates in `docs/verification/ADM-XX.md`. Update CURRENT_STATUS concisely; do not claim the entire admin complete from a mock or a table definition.

Existing commands: `npm run contracts:generate`, `npm run contracts:check`, `npm run check`, `npm run build`, `npm run test-db`, `npm run test-e2e`. Database/browser commands use disposable Docker environments through `scripts/dev.py`; select relevant test coverage and do not claim unavailable dependencies passed. ThinkPHP-rendered templates, if selected, also require rendering/browser validation and PHP syntax checks through the configured PHP runtime.

One focused implementation pass, relevant validation and one diff review normally suffice; repeat only for a concrete failure. Do not run whole-repository AI reviews after every form edit. Do not import old production tables or run migrations against the apartment application.

## First complete admin demonstration

Using synthetic data: create an owner/operator partner -> assign a restricted staff member -> create a mall site and two operational locations -> provision a model and locker -> show missing production commissioning evidence -> view the partner's permitted resource -> prove another partner is denied. Continue later with a confirmed synthetic service obligation -> one test earning -> statement -> independent manual reconciliation approval, explicitly excluding it from live financial processing.
