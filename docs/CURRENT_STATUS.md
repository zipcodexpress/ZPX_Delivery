# Current Development Status

> Shared checkpoint and handoff state for Codex, Qwen Code, and human developers.
> **Hard limit: 200 lines.**

---

## Last Updated

Date/Time: 2026-09-21
Agent: Qwen Code
Checkpoint reason: Milestone — P3.3 hub receiving and P4.1 hub dispatch committed locally; receiving version bug fixed

---

## Current Branch

`feature/P3.3-hub-receiving` — carries the P3.3, P4.1 and receiving-fix commits.
Not pushed. `origin` still has only `feature/P3.2-driver-management`.

## Last Relevant Commit

`7929b66` — fix(hub-receiving): resolve label version instead of assuming version 1

---

## Current Objective

P3.3 hub receiving and P4.1 hub sorting/dispatch are implemented and passing tests locally.
Next: close the P3.3 gaps listed below, build the missing P4.1 frontend, then push and open PRs.

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

## Upcoming

1. P4.1 frontend: hub staging and dispatch workspace for HUB_STAFF
2. P3.3 gap closure (authorization scoping, manifest membership, discrepancy workbench)
3. P4.2: outbound load, ordered-stop driver workflow

---

## Blockers / Known Issues

No blockers, and no failing tests. The following gaps are known and unaddressed:

- **P3.3 authorization scoping.** `openSession` verifies the caller's `hub_staff.hub_id` matches the
  target hub; `receiveScan`, `closeSession` and `getSession` do not. Any HUB_STAFF user can scan
  into, close, or read another hub's session. Organization scoping is applied only to the `users`
  row, not to sessions, packages or runs.
- **P3.3 manifest membership.** `receiveScan` never checks that the scanned package belongs to the
  session's run. A parcel in INBOUND_CUSTODY from a different run can be received into the session,
  and the `manifest_items` update then silently affects zero rows.
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

---

## Important Files Changed

- `apps/api/database/migrations/014_hub_receiving.sql`, `015_hub_dispatch.sql`
- `apps/api/src/HubReceiving/Service.php`, `apps/api/src/HubDispatch/Service.php`
- `apps/api/src/Http/HubReceivingController.php`, `apps/api/src/Http/HubDispatchController.php`
- `apps/api/src/Custody/Service.php` — `requireResolveAccess` (DRIVER or HUB_STAFF) for `resolveScan`
- `apps/api/route/api.php` — 10 hub endpoints
- `apps/api/tests/hub-receiving.php`, `apps/api/tests/hub-dispatch.php`
- `packages/ui/HubReceiving.tsx`, `packages/ui/hub-receiving.css`, `packages/ui/Account.tsx`

---

## Tests

### Passing

- `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py test-db` — full suite (hub-receiving 33, hub-dispatch 21)
- `npm run check` — contracts, tsc, 9 node tests
- `npm run build` — customer-web and operations-web

### Failing

None.

---

## Important Current Decisions

- Resolve-then-scan: clients learn `expected_package_version` from `/scans/resolve`. A package
  version is never assumed or hardcoded — it changes at every custody transfer.
- `/scans/resolve` is shared by DRIVER and HUB_STAFF; all other Custody methods remain driver-only.
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

Clean on `feature/P3.3-hub-receiving` at the status-update commit. Four commits ahead of `origin`:
P3.3 (`1bc9393`), P4.1 (`a8088ae`), the receiving fix (`7929b66`), and this status update.

---

## Next Recommended Actions

1. Push the branch and open PR(s) covering P3.3 + P4.1
2. Add hub-scoping checks to `receiveScan`, `closeSession` and `getSession`
3. Verify the scanned package is on the session run's manifest before transferring custody
4. Build the P4.1 hub staging/dispatch workspace UI
5. Build the P3.3 discrepancy workbench (DAMAGED/EXTRA dispositions, exception records)

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
