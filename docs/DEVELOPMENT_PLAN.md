# ZPX Delivery — comprehensive development plan

Purpose: turn the current design into dependency-ordered implementation work across every application.
Audience: ZPX product owner, developers, Codex and pilot operators.
Status: Draft for review; implementation has not started.
Owner: Unassigned; assign an engineering lead before execution.
Last reviewed: 2026-09-18.

## 1. Baseline and scope

The repository README selects Phase 1 revision 4 as authoritative. Read [the entry point](../ZPX_Phase1_Codex_Package/CODEX_START_HERE.md), [execution contract](../ZPX_Phase1_Codex_Package/docs/13_IMPLEMENTATION_EXECUTION.md), [domain rules](../ZPX_Phase1_Codex_Package/docs/07_BACKEND_AND_STATE_MACHINES.md) and [acceptance backlog](../ZPX_Phase1_Codex_Package/docs/11_BACKLOG_AND_ACCEPTANCE.md).

The initial business is approximately 20 Austin locations, one hub and assigned drivers. The synthetic fixture has three downtown locations and 17 satellites; these are not confirmed site addresses. Existing apartments may participate through partitioned compartments. Public sites require new customer registration. The delivery platform has its own data and authentication.

Each parcel follows origin deposit, inbound collection, independent hub receiving, destination staging, outbound loading, final deposit and recipient collection. Every driver handoff scans the individual primary label; hub receiving independently scans each parcel. One public SI survives the journey; a separate secret grant authorizes pickup. Phase 1 uses one parcel per shipment in the UI and one parcel per delivery compartment.

The earlier delivery package describes direct/relay routing, route matchmaking and driver incentives. Those features remain future reference. Do not apply its SQL alongside the current Phase 1 SQL or combine competing shipment models.

## 2. Application boundaries

| Surface / target path | Build scope | First release and validation |
|---|---|---|
| API and workers — apps/api | Identity, permissions, location/hardware registry, ownership, shipment/label, payment, custody, hub, dispatch, device sessions, exceptions, outbox and reports | Modular monolith, new MySQL database, transactional tests and documented API |
| Public/customer website — apps/customer-web | Service explanation, eligible location finder, signup, shipping wizard, payment, PDF labels, tracking, recipient claim and support | Responsive desktop/phone browser; full shipping path without native installation |
| Customer mobile — apps/mobile customer navigation | Verified account, locations, shipping, label sharing, pairing, tracking and recipient access | Shared React Native application per current design; Android first, iOS build/device lane before claiming support |
| Driver mobile — apps/mobile driver navigation | Shift/runs, inbound collection, hub handoff status, outbound scan count, stop list/map, final deposit, returns and shift reconciliation | Server-assigned driver role; real-device camera, interrupted network and app-restart tests |
| Hub staff — apps/operations-web hub workspace | Receive, shortages/extras, quarantine, primary/slot scans, staging, printing, loading visibility and inventory | Scanner-friendly desktop/handheld web interface; independent worker authentication |
| Admin/dispatcher — apps/operations-web operations workspace | Staff/driver/vehicle/site management, route templates/waves, assignment, policies, support, finance exports, device health and audit | Role/hub/site-scoped navigation and server authorization; core setup screens arrive early |
| Terminal — apps/terminal | Existing apartment workflow adapter plus new sender, driver and recipient workflows; single serial command gate, durable journal and evidence | C# compatibility host; public and apartment modes; simulator before physical commissioning |
| Integration — adapters/legacy | Verified membership link, topology mapping, ownership projections and compatibility guards | Narrow bridge, no new delivery order writes into apartment tables |
| Shared tooling — packages/contracts, simulators/locker, tests/e2e, deployment | Generated clients, normalized error/status definitions, fault simulator, acceptance tests and environment configuration | One canonical contract, reproducible test commands and no live hardware in CI |

Use the existing combined customer/driver app baseline. Separate app-store binaries are an optional later decision, not an already accepted requirement. Hub staff do not need a separate native app initially; evaluate one only if handheld scanner requirements cannot be met by the web interface.

Share API types, identifier parsing and presentation primitives; keep custody and authorization decisions on the backend. Website and mobile must display the same server-calculated states. Store timestamps in UTC and convert operational schedules using America/Chicago.

## 3. Foundation and reuse decision — P0.1

Time-box a Fleetbase evaluation to 3–5 engineering days before substantial scaffolding. This is an investigation, not an adoption decision or a fresh audit of upstream code.

Pin candidate revisions for Fleetbase/Fleet-Ops, Navigator and Customer Portal. Record licenses, installation/build results, API dependencies, extension seams and actual user/driver flows. Demonstrate order creation, two-stop assignment, parcel-level scanning and an external locker adapter seam. Compare adapting these against the current ThinkPHP/React baseline, including upgrade ownership and closed-source licensing needs.

Pass only if package-level custody, hub receipt, duplicate protection and device separation can be extended without bypassing framework authorization or deeply rewriting the core. Otherwise keep the current stack and reuse suitable independent libraries and workflow ideas. Do not make both Fleetbase and a new service authoritative for the same order or custody state.

Keep the current stack as the planning baseline: ThinkPHP 8 API, React/TypeScript web, React Native mobile and C# terminal. Record exact supported versions in the reserved docs/decisions/0001-toolchains.md. Record reuse selection separately as 0002-platform-reuse.md. If Fleetbase is selected, update affected application paths, schema strategy and contracts together before implementing features.

Routing begins with dispatcher-approved order and published manifests. Evaluate VROOM/OSRM behind adapters when automated optimization becomes valuable; they are not prerequisites for the first 20-site flow. Never confuse route optimization with authorization to open lockers.

## 4. Milestones and dependencies

Task IDs below refer to the existing P0.1–P8.1 backlog. M0–M2 retain the execution contract's meanings; M3–M5 extend its commissioning and operations plan.

| Milestone | Tasks / dependency | Outputs across applications | Exit evidence |
|---|---|---|---|
| M0: executable foundation | P0.1, P0.2; start P0.3 and early P1.1 schema execution | Reuse/toolchain decisions, repository layout, app shells, dev environment, CI, semantic contract validation, disposable MySQL migration run, simulator | Clean builds on claimed platforms; contract generation compiles; schema constraints tested; timeout/wrong-address simulator works |
| M1a: identity and shipment | P1.1–P1.3 then P2.1–P2.2 | Customer web/mobile signup, roles, device enrollment, core admin topology/staff screens, shipment/quote/payment sandbox, SI and label | Verified customer prints readable label; cross-user access denied; last-door race has one winner; revoked label rejected |
| M1b: first custody slice | P3.1–P3.3; depends M1a and simulator | Terminal deposit, driver inbound collection and hub receiving; sessions, journal and custody ledger | Five collected, four received leaves one with driver; retries produce one transfer; ambiguous door action remains held |
| M2: complete delivery journey | P4.1–P5.2; depends M1b | Hub slots/waves, outbound manifests, route publishing, driver loading/navigation, final deposit, recipient claim/pickup and returns | Ten unique scans, six/four destination grouping, wrong locker denied, full locker return reconciled, recipient grant single use |
| M3: hardware and coexistence | P0.3, P6.1–P6.2; physical end-to-end requires M2 | Legacy writer audit/guards, all terminal commands gated, printer/scanner/board matrix, one public and one apartment site | T13/T14/T16/T19 on representative hardware; delivery ownership never bypassed by reset/maintenance paths |
| M4: operational release | P7.1–P7.2; core setup work already delivered earlier | Remaining admin policies, real provider adapters, refunds, reporting, payroll export, monitoring, backup/restore, deployment and support runbooks | T17/T18/T20, restricted DB roles, failed provider retries, restore without replaying opens, signed mobile/terminal builds |
| M5: supervised pilot and rollout | P8.1; depends M3 and M4 | Staff training, commissioning records, rehearsal, staged site enablement and rollback drills | T01–T20 evidence with physical/simulated scope stated; no unresolved critical custody/ownership defect |

Hardware inventory work starts in M0, rather than waiting for software completion. M3 physical commissioning cannot start on shared doors until compatibility guards and ownership acknowledgments work. Website design and app shells can proceed alongside API foundations; business features must integrate against agreed contracts and a real development backend.

## 5. Feature-level implementation sequence

### Customer sending and receiving

Build register/verify/profile → location finder → shipping wizard → quote → server-confirmed payment → SI/label → origin pairing → tracking. Then add recipient invitation/claim and short-lived pickup grant, cancellation before deposit and return/support requests afterward. Collect email, phone and address; do not silently import apartment residents. Display print capability before a printerless customer travels to a site.

Primary labels are 4x6 inch PDF with Letter/A4 placement, stable package/SI and public QR lookup. Keep access secrets and recipient contact data off labels. Validate real printer/scanner output before launch. Hub route stickers have a distinct identity namespace and never replace a required parcel scan.

### Driver and hub

Deliver inbound pickup before outbound routing. The driver sees destination for identification but inbound next leg ends at the hub. Hub staff receive under their own account; driver cannot mark everything received. Missing/extra/damaged items enter explicit discrepancy/quarantine workflows.

Next build destination slots, wave assignment and route publishing. Driver scans each staged parcel, sees accepted/expected counts and final stop grouping, then departs only after server checks exact manifest equality and current custody. Nine parcels plus a duplicate stays nine. Route changes preserve loaded items and require acknowledgment. Offline screens show cached routes and pending scans; they cannot invent accepted counts or authorize departure.

Final-locker delivery checks destination before opening, then waits for correlated door and actor evidence. Full/offline/inaccessible destinations create return work while custody remains with the driver. Close run/shift only with unresolved items explicitly visible.

### Terminal

Implement the hardware abstraction and simulator first; then command gate, transactional local journal, device authentication/polling and pairing; then origin deposit, inbound pickup, final deposit and recipient pickup. Keep one serial owner. Journal before dispatch; unknown post-dispatch state never causes automatic reopening or expiry-based compartment release.

App-assisted pairing is the initial slice. Terminal-local label entry/attestation and recipient code entry need explicit device-scoped delegated-action API additions in P3.1/P5.1 before those screens ship. Public carrier flow accepts supported registered ZPX shipments only; arbitrary external retail tracking numbers are outside Phase 1.

Preserve legacy apartment screens and isolate them behind a workflow adapter. Build recovery for restart with a door open, stale/wrong-address frames, duplicate callbacks and failed event upload.

## 6. Contract and database readiness work

The current handoff check reports 54 API operations, 74 schemas and 73 proposed tables. Counts do not establish completeness or executable migrations.

Before feature implementation:
1. Run external OpenAPI semantic validation and client generation; check authentication variants, response errors, pagination and aggregate version semantics.
2. Reconcile prose/schema differences, including requested_size described for locker session creation but absent from its current request schema. Decide whether size is derived from the package or explicitly selectable; update both sources and tests.
3. Specify delegated kiosk actions and complete admin/device-enrollment/report endpoints as their tasks begin. Maintain a screen-to-endpoint inventory so UI teams do not invent APIs.
4. Execute current SQL 001 then 002 against disposable MySQL; normalize into versioned application migrations. Validate case-sensitive identifiers, active allocation/claim/label uniqueness and cross-entity scope checks.
5. Test atomic event + projection + outbox commits, idempotent retries, competing driver loads and overlapping compartment reservations.
6. Separate database migration role from runtime role; prohibit historical custody rewrites and unsafe cascade deletes.
7. Keep payments, labels, package physical state, sessions and route status distinct. A notification or print failure must not reverse a physical transfer.

Do not create all CRUD screens simply because tables exist. Sequence migrations and minimal operational screens by the milestones while preserving one coherent model.

## 7. First implementation sprint

Target: M0 plus the first identity/topology work, assuming the required build environments are available.

| Order | Task | Deliverable |
|---|---|---|
| 1 | P0.1 documentation organization | Dedicated move of current handoff to docs/handoff; update links and validator paths; retain original ZIPs unchanged |
| 2 | P0.1 reuse decision | Bounded Fleetbase evaluation and recorded baseline decision; no speculative wholesale rewrite |
| 3 | P0.1 toolchains and shells | Exact runtime/SDK versions, lockfiles, local services, API health check, web/mobile shells and CI |
| 4 | P0.2 simulator | Scripted open/close/timeout/wrong-address/crash cases; synthetic compartment ownership |
| 5 | P1.1 schema foundation | Executable migrations and fixture loader; MySQL constraint/concurrency harness |
| 6 | P0.1 contract quality | Semantic OpenAPI validation, generated clients and screen/API gap inventory |
| 7 | P0.3 hardware discovery | Record deployed terminal/OS/board profiles, missing ZipporaService dependency and all legacy mutation paths |
| 8 | P1.2/P1.3 first increment | Login/verification/RBAC and network topology services with isolation tests |

Create task issues using the repository template when execution starts. Each task has a responsible developer, acceptance IDs and bounded PR scope. Do not mark the sprint complete because only shells compile: record which M0 lanes passed and which are blocked.

## 8. Tests and release evidence

Follow T01–T20 from the existing acceptance document without renumbering. CI includes domain tests, real MySQL tests, contract/client checks, browser E2E, mobile/native builds and Windows terminal/simulator tests. Physical board/printer/scanner tests are separate evidence.

The mandatory rehearsal covers ten parcels, two final lockers, hub shortage, revoked label, duplicate scan, wrong driver, wrong destination, route revision during load, capacity exhaustion, power loss, network outage, recipient replay, legacy bulk reset and restore reconciliation.

Add operational measurements before accepting live parcels: unresolved custody count, unknown sessions, terminal journal backlog, hub dwell, scan rejection reasons, run completion and notification failure. Tune thresholds using rehearsal evidence. Stop rollout immediately for unauthorized opens, ownership mismatch or custody inconsistency.

Deploy first to staff-only test accounts and one representative public/apartment pair. Proposed expansion is 2 → 5 → 10 → 20 approved locations, gated by reconciliation and incident review rather than calendar dates. A rollback stops new work, preserves the command gate and completes/returns existing parcels; it never restores an unguarded terminal over occupied delivery doors.

## 9. People, estimates and decisions

Planning estimate only: with a dedicated backend lead, web engineer, mobile engineer, terminal/integration engineer, shared QA/DevOps support and an available operations owner, allow roughly 12–18 elapsed weeks to a supervised pilot. This is not a commitment and has not been validated by a build spike. Re-estimate after M0.

Indicative overlapping windows: M0 weeks 1–2; M1 weeks 3–6; M2 weeks 7–10; M3 hardware work starts in week 1 and commissioning targets weeks 9–12; M4 weeks 10–14; M5 weeks 15–18 with contingency. One developer using Codex should not inherit the same schedule. Device availability, missing terminal dependencies, provider onboarding and app distribution may extend the critical path.

Assign human ownership for product/service rules, backend/data, mobile, terminal/hardware, hub/admin UX, release/QA and pilot operations. Codex can implement bounded tasks and maintain evidence; physical validation and business decisions need the corresponding owner.

Before hardware commissioning obtain terminal OS/CPU/DLL inventory, missing project/reference resolution, actual controller protocol settings, legacy schema-only export and deployed writer list. Before public launch obtain confirmed sites/access hours/door partitions, hub waves, driver/vehicle capacity, parcel limits, pricing/refunds, providers, support procedures and mobile distribution details. Use synthetic fixtures and fake providers until these decisions are supplied.

## 10. Repository continuity

GitHub is authoritative. Update code, contracts, tests and affected specifications together in feature PRs. Keep original archives unchanged. Use [documentation workflow](DOCUMENTATION_GUIDE.md) and existing templates.

At each task start fetch the latest repository state and inspect active changes. Record the reviewed revision. At each task end record actual commands/results, blockers and next dependency in milestone verification. Use GitHub Issues/Projects as task status authority once adopted; PROGRESS.md is a milestone/session summary with links, not a competing task tracker.

Use small branches such as feature/P3.1-terminal-journal. Merge after relevant checks and review, without force-pushing others' work. Committing or downloading files alone does not continuously synchronize a local machine.

This planning session obtained a pinned text snapshot through the GitHub connection. Native git clone failed because shell credentials were unavailable; no full local Git history or working clone is claimed. Connector commits/PRs can keep this plan in GitHub. Establish a repository-enabled development environment or authenticated local checkout before the build sprint.
