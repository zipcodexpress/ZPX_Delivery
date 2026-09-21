# Current Development Status

> Shared checkpoint and handoff state for Codex, Qwen Code, and human developers.
> **Hard limit: 200 lines.**

---

## Last Updated

Date/Time: 2026-09-21
Agent: Qwen Code
Checkpoint reason: Milestone — hub authorization gaps fixed; refused scans now journaled; hub management deferred (ADR 0008)

---

## Current Branch

`feature/P3.3-hub-receiving` — carries the P3.3, P4.1 and all fix commits.
Not pushed. `origin` still has only `feature/P3.2-driver-management`.

## Last Relevant Commit

`26e4274` — fix(scan): journal refused scans after the rollback, not inside it

---

## Current Objective

P3.3 hub receiving and P4.1 hub sorting/dispatch are implemented, authorization-hardened and
passing tests locally. Next: build the missing P4.1 frontend, close the remaining P3.3 gaps,
then push and open PRs.

---

## Completed

- P3.2 driver inbound + driver management (on `feature/P3.2-driver-management`, pushed)
- P3.3 hub receiving — migration 014, `HubReceiving\Service`, 4 endpoints, `HubReceiving.tsx`
  workspace routed for HUB_STAFF, 33-assertion suite. Open a session per inbound run, scan each
  parcel independently to transfer custody DRIVER → HUB (INBOUND_CUSTODY → AT_HUB), close to mark
  unscanned manifest items SHORT.
- P4.1 hub sorting/dispatch — migration 015, `HubDispatch\Service`, 6 endpoints, 21-assertion suite.
  Stage parcels to destination lots, create dispatch calls with pickup windows, driver accepts, then
  loads at the hub transferring custody HUB → DRIVER (STAGED → OUTBOUND_CUSTODY).
- Fix: the receiving workspace resolves the label to read the real package version instead of
  hardcoding `expected_package_version: 1`. Seeded origin parcels are version 1 and driver pickup
  increments them to 2, so every UI scan previously failed with 409 VERSION_MISMATCH.
- `/scans/resolve` now accepts HUB_STAFF as well as DRIVER.
- Fix: hub receiving and dispatch are scoped to the caller's hub. `HUB_STAFF` grants are
  location-scoped (as `Seed.php` creates them) but every hub service called `requireRole` without a
  location, which only matches org-wide grants — so the seeded `HUB-STAFF` account was denied by
  every hub endpoint. `hubId()` now resolves the hub through its location and checks the grant
  there; `Custody::requireResolveAccess` had the same defect. Sessions are filtered by the caller's
  hub and answer 404 not 403, and `receiveScan` rejects off-manifest parcels with `NOT_ON_MANIFEST`.
- Fix: refused scans are journaled. `Transaction::run` rolls back on any throwable, so the old
  `recordRejectedScan` row was discarded along with the transaction — refused driver pickups never
  persisted. `Custody\ScanJournal` stages inside the transaction and writes after the rollback,
  covering driver pickup and all eight hub receiving refusal paths.

---

## In Progress

None.

---

## Exact Continuation Point

**P4.1 is backend-only** — the commit touches no files under `packages/ui`, so hub staging and
dispatch have no workspace screen. HUB_STAFF currently signs into operations-web and lands directly
on the P3.3 receiving screen (`Account.tsx` routes HUB_STAFF → `HubReceiving`).

Pick up with either the P4.1 hub workspace UI, or the P3.3 gaps below.

---

## Blockers / Known Issues

No blockers, and no failing tests. The following gaps are known and unaddressed:

- **P3.3 discrepancy workbench is missing.** The backlog defines P3.3 as "hub receiving and
  discrepancy workbench". Migration 014 permits `DAMAGED` and `EXTRA` dispositions but nothing ever
  writes them. Short parcels remain INBOUND_CUSTODY under DRIVER custody by omission only — no
  exception record, package event or notification is raised.
- **Run state not advanced.** Closing a receiving session leaves the route run `ACKNOWLEDGED` and
  its hub stop `EXPECTED`.
- **Contract drift on `/scans/resolve`.** It returns `package_state`/`package_version` rather than
  the specified `state`/`version`/`allowed_actions`, and ignores the `action` and `run_id` request
  fields. The `Receive` schema also requires `client_event_id`, which hub receiving neither sends
  nor validates. Correcting resolve affects the driver flow too, so it was left alone.
- **Duplicated hub-location lookup.** `HubReceiving::hubId()`, `HubDispatch::hubId()` and
  `Custody::requireResolveAccess()` each carry the same `hub_staff → hubs → locations` query.
  Extract it if a fourth caller appears.
- **Re-opened sessions.** `openSession` only blocks a second *open* session, and `expected_count`
  counts every manifest item regardless of state. A session opened after a close that wrote parcels
  off as SHORT will still report those parcels as expected.
- **No admin surface.** `ADMIN` and `DISPATCHER` fall through `Account.tsx` into the `Shipping`
  component, and the five contract-specified `/admin/*` endpoints are unimplemented. Deferred with
  hub management — see ADR `docs/decisions/0008-hub-management-and-asset-custody.md`.
- **Staging is not in the scan journal.** `HubDispatch::stageScan` writes no `scan_events` row even
  on success, so neither accepted nor refused stage scans appear there. Attribution exists via
  `staging_assignments.assigned_by`. Add both together if staging is ever journaled (ADR 0008).

---

## Important Files Changed

- `apps/api/database/migrations/014_hub_receiving.sql`, `015_hub_dispatch.sql`
- `apps/api/src/HubReceiving/Service.php`, `apps/api/src/HubDispatch/Service.php`
- `apps/api/src/Http/HubReceivingController.php`, `apps/api/src/Http/HubDispatchController.php`
- `apps/api/src/Custody/Service.php`, `ScanJournal.php` — resolve access; refused-scan journal
- `apps/api/route/api.php` — 10 hub endpoints
- `apps/api/tests/hub-receiving.php`, `hub-dispatch.php`, `driver-inbound.php`
- `packages/ui/HubReceiving.tsx`, `packages/ui/hub-receiving.css`, `packages/ui/Account.tsx`

---

## Tests

### Passing

- `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py test-db` — full suite, 373 assertions
  (hub-receiving 46, hub-dispatch 21). Both hub fixtures grant `HUB_STAFF` scoped to the hub
  location, matching `Seed.php`; an org-wide grant would hide the location-scoping regressions.
- `npm run check` — contracts, tsc, 9 node tests
- `npm run build` — customer-web and operations-web

### Failing

None.

---

## Important Current Decisions

- Resolve-then-scan: clients learn `expected_package_version` from `/scans/resolve`. A package
  version is never assumed or hardcoded — it changes at every custody transfer.
- `/scans/resolve` is shared by DRIVER and HUB_STAFF; all other Custody methods remain driver-only.
- Authorization: hub services resolve the caller's hub through `hub_staff → hubs → locations` and
  check `HUB_STAFF` against that hub's location. A bare `requireRole($user,'HUB_STAFF')` silently
  rejects every location-scoped grant, which is the shape `Seed.php` creates.
- Cross-hub probes answer 404 rather than 403, so session ids cannot be enumerated. `openSession`
  is the exception: it answers 403 when the payload itself names a hub the caller is not assigned to.
- Hub management (locations, staff, assets, asset in/out ledger) is **deferred to P7.1** in backlog
  order — do not build it before P4.2 → P5.1 → P5.2. The design is already fixed by ADR
  `docs/decisions/0008-hub-management-and-asset-custody.md`: custody-chain-only asset ledger,
  separate tables with no changes to `scan_events`/`label_print_jobs`, admin area in operations-web.
- P4.1 was committed onto the P3.3 branch rather than a branch of its own.
- Compensation: fixed shift/route rate per design docs (no per-minute formulas).
- Driver approval: PENDING → ACTIVE workflow via admin endpoints.
- Vehicles are driver-owned (Uber model); the `drivers` row stores vehicle details for verification
  only, and the system never assigns a vehicle from a fleet.

---

## API / Database Impact

API: 10 hub endpoints (4 receiving + 6 dispatch). `/scans/resolve` is now reachable by HUB_STAFF.
DB: Migration 014 (receiving CHECKs + 2 indexes), Migration 015 (dispatch_calls, run pickup
timestamps, slot status).

---

## Git State

Clean on `feature/P3.3-hub-receiving`, 10 commits ahead of `origin` and none pushed: P3.3
(`1bc9393`), P4.1 (`a8088ae`), then the receiving version fix (`7929b66`), hub authorization fix
(`7f09fed`), scan journal fix (`26e4274`) and four documentation commits.

---

## Next Recommended Actions

1. Push the branch and open PR(s) covering P3.3 + P4.1
2. Build the P4.1 hub staging/dispatch workspace UI — P4.1 is backend-only today
3. Build the P3.3 discrepancy workbench (DAMAGED/EXTRA dispositions, exception records)
4. Advance the route run and its hub stop when a receiving session closes
5. P4.2: outbound load, ordered-stop driver workflow

---

## Handoff Notes

- Start dev stack: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`
- Operations portal (hub receiving + driver workspace): http://localhost:5174
- Customer portal: http://localhost:5173 · API: http://localhost:8000
- Seed accounts include `HUB-STAFF` (hub receiving) and `DRIVER-IN` (inbound run); the seed creates
  the hub, `hub_staff` link and a PUBLISHED inbound run with five AT_ORIGIN parcels.
- Seed/re-seed: `docker compose exec api php bin/seed.php`; test labels `TEST-LABEL-001`…`005`
- Seeded origin parcels are `version=1`; after driver pickup they are `version=2`. Relevant to any
  work touching scan preconditions.
- DB shell: `docker compose exec postgres psql -U postgres -d zpx_delivery_dev`
- Prefix `ZPX_ORGANIZATION_ID=1` to docker compose commands
- `scripts/dev.py test-db` builds a uniquely named throwaway stack; it never removes dev volumes
