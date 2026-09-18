# ZipcodeXpress Delivery Network — Development Readiness

Revision 4 • Austin Phase 1 • 2026-09-17

This revision adds a paste-ready Codex kickoff prompt, project instructions and milestone-level implementation criteria. See [the execution contract](docs/13_IMPLEMENTATION_EXECUTION.md). The reviewed source revisions remain unchanged.

## Outcome

The development package is now grounded in the actual terminal, API, administration and customer-portal repositories. It is ready for Codex to begin a separate delivery application and simulator-based implementation. Live locker deployment remains gated by terminal build recovery, real controller tests and safe compartment ownership rollout.

## What will be built

| Application | Phase 1 responsibility |
|---|---|
| Unified locker terminal host | Keep apartment carrier/resident operations; add sender, delivery driver and new recipient flows; one hardware command gate |
| Customer website | Register, verify contacts/address, choose locations, quote/pay, SI/label download, tracking and recipient claim |
| Native customer/driver mobile app | Customer shipping/pickup plus assigned-driver route, scan, loading, delivery and return workflows |
| Hub workbench | Individual receipt scans, discrepancy handling, sorting slots, routing-sticker/replacement-label printing and staging |
| Administration/dispatch web | Location/driver/vehicle management, route planning, ownership commissioning, exceptions, finance and reconciliation |
| Delivery API and workers | Independent identity/database, shipment/package/label domain, reservations, custody, payments and notifications |

The existing apartment platform is not the new delivery business platform. We reuse protocol knowledge and carefully isolated terminal capabilities. Existing customer balances/resident directories/orders are not silently copied or merged.

## Most important source findings

1. The current terminal already provides selectable hardware protocol implementations, scanner patterns and printer wrapper declarations. Electrical hardware interfaces can be retained, with actual device verification.
2. Existing preauthorization selects an available box without reserving it; later deposit commits occupancy. This is not a safe shared allocation service for two independent systems.
3. The apartment admin has a cabinet-wide reset path. New delivery-owned doors must be protected there as well as in terminal opening code.
4. A pickup path commits after door-open confirmation and monitors closure separately. New custody events need package-specific scan and correlated physical-session evidence.
5. The terminal project references a missing ZipporaService project. Full terminal build is not yet reproduced. New backend/UI/simulator work can proceed while resolving it.

Exact files and revisions are in docs/01_REPOSITORY_REVIEW.md and evidence/source_inventory.json.

## Shared apartment lockers: Phase 1 decision

Reserve a configured subset of compartments for delivery at existing apartment sites. Preserve the remaining compartments for apartment receiving. Backend, admin and terminal enforce ownership, not just a UI convention or blocked flag. Public sites can dedicate all compartments to delivery. This avoids conflicting allocations while the business platforms remain separate.

This partitioning is a new engineering recommendation, not a change already made to your lockers. Door counts and eligible users are selected during site commissioning. A fully dynamic shared pool is a later integration requiring one common allocator across all legacy writers.

## Label, SI and driver operation

One permanent package identity/SI, one active primary QR label, and optional hub route sticker. Sender receives SI and printable label before unattended deposit. Hub can replace a damaged primary label through an audited process; routing stickers never replace the package identity. SI is public identification, not a pickup password.

Every driver scans each parcel. Ten outbound parcels require ten distinct accepted scans; duplicate scans cannot make nine become ten. Route screen groups parcels by final locker, e.g. six at stop 1 and four at stop 2. Hub receives each parcel independently. Destination delivery is pending until correlated terminal evidence and actor confirmation establish completion. Missing/failed parcels retain explicit custody and return/reconciliation tasks.

## Included engineering deliverables

Twelve detailed design documents; Codex entry point and ordered backlog; 54-operation OpenAPI contract with 74 schemas; 73-table proposed new-database migrations; legacy bridge change design; four pinned repository revisions and 66 source references; synthetic 20-location/two-stop delivery fixture; static validation script and report.

SQL is a development migration proposal, not a tested production migration. It must run in a NEW delivery database and pass real MySQL constraint/concurrency tests before release. The handoff does not contain a built new mobile app or production backend.

## First development milestone

Build new app skeletons, MySQL schema, auth and protocol simulator. Implement customer shipment/label → origin deposit → driver pickup → hub receiving. Then add sorting, outbound ten-package/two-stop delivery and recipient pickup. Run failure scenarios throughout. Commission one supervised apartment site and one public site only after compatibility guards and hardware tests pass.

## Information needed before hardware rollout

Missing terminal project/dependencies or explanation of unused reference; actual Windows/CPU/DLL environment; controller vendor/profile/serial settings; schema-only legacy database export; deployed-version/writer inventory; approved apartment compartment allocation/access policy. Live providers, rates, site hours and mobile distribution settings are configurable launch decisions rather than blockers for initial implementation.

Open CODEX_START_HERE.md in the ZIP to begin. The earlier handoff is superseded by this revision.
