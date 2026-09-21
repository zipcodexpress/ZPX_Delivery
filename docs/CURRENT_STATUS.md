# Current Development Status

> Shared checkpoint and handoff state for Codex, Qwen Code, and human developers.
>
> **Hard limit: 200 lines.**

---

## Last Updated

Date/Time: 2026-09-21
Agent: Qwen Code
Checkpoint reason: Milestone — P3.2 driver inbound backend complete

---

## Current Branch

`feature/P3.2-driver-inbound`

## Last Relevant Commit

`a81bc24` — merge P2.3 customer portal (main baseline)

Uncommitted P3.2 work exists — inspect git status before making changes.

---

## Current Objective

Implement P3.2 driver inbound collection: driver run listing, manifest reading, run acknowledgment, label resolution, and atomic inbound pickup scans with custody transfer.

---

## Completed

- Migration 010: driver inbound indexes and run/manifest state constraints
- `Custody\Service`: listRuns, getRun, acknowledgeRun, resolveScan, inboundPickupScan
- `Http\DriverController`: route dispatch with auth/CSRF/idempotency
- Routes added to api.php for driver endpoints
- 42-test driver-inbound suite integrated into integration.php
- All tests pass: `npm run check`, `npm run build`, `python3 scripts/dev.py test-db`

---

## In Progress

- Driver frontend pages (operations-web) — not started
- Seed data extension for driver runs — not started

---

## Exact Continuation Point

File: `apps/operations-web/src/` (driver workspace pages)
Next: Add driver run list, manifest detail, and scan interface to operations-web.

---

## Upcoming

1. Driver frontend pages in operations-web
2. Update seed data with driver runs/manifests for local dev
3. P3.3 hub receiving (depends on P3.2)

---

## Blockers / Known Issues

No known blocking issues.

---

## Important Files Changed

- `apps/api/database/migrations/010_driver_inbound.sql`
- `apps/api/src/Custody/Service.php`
- `apps/api/src/Http/DriverController.php`
- `apps/api/route/api.php`
- `apps/api/tests/driver-inbound.php`
- `apps/api/tests/integration.php`

---

## Tests

### Passing

- `python3 scripts/dev.py test-db` — full integration suite including 42 driver-inbound tests
- `npm run check` — contracts, tsc, simulator/contract tests
- `npm run build` — customer-web and operations-web

### Failing

None.

### Still To Run

None — all relevant tests were run.

---

## Important Current Decisions

- Label token hash uses `sha256(payload)` matching existing label creation in Shipping\Service
- Driver scan idempotency uses same pattern as Shipping (advisory lock + idempotency_records)
- Run states: DRAFT → PUBLISHED → ACKNOWLEDGED → IN_PROGRESS → COMPLETED/CANCELLED
- Manifest item states: EXPECTED → LOADED → UNLOADED/SHORT/RETURNED

---

## API / Database Impact

API changes: 5 new driver endpoints added (GET /driver/runs, GET /runs/{id}, POST /runs/{id}/acknowledgments, GET /scans/resolve, POST /runs/{id}/scans)

Database changes: Migration 010 adds 3 indexes and 2 check constraints (no new tables)

Migration required: Yes (010_driver_inbound.sql)

---

## Git State

Uncommitted P3.2 changes ready for commit on `feature/P3.2-driver-inbound`.

---

## Next Recommended Actions

1. Commit P3.2 backend work
2. Add driver frontend pages
3. Update seed with driver run data
4. Run full test suite
5. Create PR when stable

---

## Handoff Notes

- The Custody\Service follows the same patterns as Shipping\Service (Transaction wrapper, idempotency, outbox events)
- Driver role check uses `requireRole($user, 'DRIVER')` via scoped_role_grants
- Label resolution matches Shipping label creation: token_hash = sha256(payload)
- Test creates its own org/driver/run/manifest to avoid coupling with seed data
