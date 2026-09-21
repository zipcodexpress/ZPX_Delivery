# Current Development Status

> Shared checkpoint and handoff state for Codex, Qwen Code, and human developers.
> **Hard limit: 200 lines.**

---

## Last Updated

Date/Time: 2026-09-21
Agent: Qwen Code
Checkpoint reason: Milestone — P3.2 driver inbound complete (backend + frontend + seed)

---

## Current Branch

`feature/P3.2-driver-inbound`

## Last Relevant Commit

`d344f3a` — feat(P3.2): add driver workspace frontend and seed data

---

## Current Objective

P3.2 driver inbound collection is complete. Next: P3.3 hub receiving.

---

## Completed

- Migration 010: indexes + run/manifest state constraints
- `Custody\Service`: listRuns, getRun, acknowledgeRun, resolveScan, inboundPickupScan
- `DriverController` + 5 API routes
- `DriverWorkspace.tsx` frontend: run list, manifest, scan interface, progress
- Account.tsx routes DRIVER role to workspace in operations audience
- Seed extended: vehicle, shift, inbound run, 5 packages AT_ORIGIN with labels
- 42 driver-inbound tests + seed test updates — all passing
- `npm run check`, `npm run build`, `python3 scripts/dev.py test-db` — all green

---

## In Progress

None.

---

## Exact Continuation Point

P3.2 is complete. Next milestone: P3.3 hub receiving.
File: `apps/api/src/Custody/Service.php` — add hub receiving methods.
Branch: create `feature/P3.3-hub-receiving` from this branch or main.

---

## Upcoming

1. P3.3: Hub receiving sessions, independent receipt scans, discrepancy workflows
2. P4.1: Hub sorting, staging slots, waves
3. P4.2: Outbound load, ordered-stop driver workflow

---

## Blockers / Known Issues

No known blocking issues.

---

## Important Files Changed

- `apps/api/database/migrations/010_driver_inbound.sql`
- `apps/api/src/Custody/Service.php`
- `apps/api/src/Http/DriverController.php`
- `apps/api/route/api.php`
- `apps/api/src/Development/Seed.php`
- `apps/api/tests/driver-inbound.php`
- `apps/api/tests/seed.php`
- `packages/ui/DriverWorkspace.tsx`
- `packages/ui/driver.css`
- `packages/ui/Account.tsx`

---

## Tests

### Passing

- `python3 scripts/dev.py test-db` — full suite including 42 driver-inbound + seed tests
- `npm run check` — contracts, tsc, simulator/contract tests
- `npm run build` — customer-web and operations-web

### Failing

None.

---

## Important Current Decisions

- Driver frontend uses shared `packages/ui/` pattern (no separate app)
- DRIVER role in operations audience routes to DriverWorkspace, not Shipping
- Seed creates 5 AT_ORIGIN packages for local driver testing
- Label token hash = sha256(payload) matching Shipping\Service

---

## API / Database Impact

API: 5 driver endpoints (GET /driver/runs, GET /runs/{id}, POST /runs/{id}/acknowledgments, GET /scans/resolve, POST /runs/{id}/scans)
DB: Migration 010 (3 indexes, 2 check constraints, no new tables)

---

## Git State

Clean on `feature/P3.2-driver-inbound`. Ready for PR.

---

## Next Recommended Actions

1. Create PR for P3.2
2. Start P3.3 hub receiving on new branch
3. Re-seed dev DB (`python3 scripts/dev.py down && python3 scripts/dev.py up`) to get driver run data

---

## Handoff Notes

- Driver workspace accessible at http://localhost:5174 with DRIVER-IN account
- Seed credentials in `.local/seed-credentials.txt` after re-seed
- DBeaver: use `docker compose exec postgres psql -U postgres -d zpx_delivery_dev` (port not exposed to avoid test conflicts)
