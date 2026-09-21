# Current Development Status

> Shared checkpoint and handoff state for Codex, Qwen Code, and human developers.
> **Hard limit: 200 lines.**

---

## Last Updated

Date/Time: 2026-09-21
Agent: Qwen Code
Checkpoint reason: Milestone — P3.2 driver management complete

---

## Current Branch

`feature/P3.2-driver-management`

## Last Relevant Commit

`8a9dfea` — fix(migration): move settled_at/settlement_reference to driver_pay_entries

---

## Current Objective

P3.2 driver management is complete. Next: P3.3 hub receiving or PR merge.

---

## Completed

- P3.2 driver inbound backend (runs, manifest, scan, custody transfer)
- P3.2 driver inbound frontend (run list, manifest, scan interface)
- P3.2 driver management (registration, approval, profile, wallet, transactions)
- P3.2 driver frontend tabs (Runs, Profile, Wallet, Transactions)
- P3.2 compensation model (fixed shift/route rate, admin payment recording)
- P3.2 seed data (vehicle, shift, inbound run, 5 AT_ORIGIN packages with labels)
- P3.2 seed fix (verified contacts with encrypted values and correct HMAC)
- Migration 010 (driver inbound indexes) + Migration 011 (driver management)
- Documentation: `docs/DRIVER_MANAGEMENT.md`
- All tests passing: `npm run check`, `npm run build`, `python3 scripts/dev.py test-db`

---

## In Progress

None.

---

## Exact Continuation Point

P3.2 is complete. Next milestones:
- P3.3: Hub receiving (hub staff independently scan parcels, discrepancy handling)
- P4.1: Hub sorting, staging slots, waves
- P4.2: Outbound load, ordered-stop driver workflow

---

## Upcoming

1. P3.3: Hub receiving sessions, independent receipt scans, discrepancy workflows
2. P4.1: Hub sorting, staging slots, waves and route publishing
3. P4.2: Outbound load, ordered-stop driver workflow

---

## Blockers / Known Issues

No known blocking issues.

---

## Important Files Changed

- `apps/api/database/migrations/010_driver_inbound.sql`
- `apps/api/database/migrations/011_driver_management.sql`
- `apps/api/src/Custody/Service.php` — driver inbound scan service
- `apps/api/src/Driver/Service.php` — driver management service
- `apps/api/src/Http/DriverController.php` — driver inbound HTTP
- `apps/api/src/Http/DriverManagementController.php` — driver management HTTP
- `apps/api/route/api.php` — 13 new driver endpoints
- `apps/api/src/Development/Seed.php` — driver run fixture + verified contacts
- `packages/ui/DriverWorkspace.tsx` — 4-tab driver workspace
- `packages/ui/driver.css` — driver workspace styles
- `packages/ui/Account.tsx` — DRIVER role routing
- `docs/DRIVER_MANAGEMENT.md` — driver lifecycle documentation

---

## Tests

### Passing

- `python3 scripts/dev.py test-db` — full suite including driver-inbound + seed tests
- `npm run check` — contracts, tsc, simulator/contract tests
- `npm run build` — customer-web and operations-web

### Failing

None.

---

## Important Current Decisions

- Compensation: fixed shift/route rate per design docs (no per-minute formulas)
- Driver approval: PENDING → ACTIVE workflow via admin endpoints
- Driver workspace: 4 tabs (Runs, Profile, Wallet, Transactions) in operations-web
- Seed creates verified contacts with encrypted values and correct HMAC for login
- ZPX_ORGANIZATION_ID must be prefixed to docker compose commands (caching issue)

---

## API / Database Impact

API: 13 driver endpoints (5 inbound + 8 management)
DB: Migration 010 (3 indexes) + Migration 011 (driver approval + compensation columns)

---

## Git State

Clean on `feature/P3.2-driver-management`. Ready for PR.

---

## Next Recommended Actions

1. Create PR for P3.2 (inbound + management combined)
2. Start P3.3 hub receiving on new branch
3. Re-seed dev DB to test driver workspace UI

---

## Handoff Notes

- Driver workspace at http://localhost:5174 with DRIVER-IN account
- Seed credentials: run `docker compose exec api php bin/seed.php`
- Test labels: TEST-LABEL-001 through TEST-LABEL-005
- DBeaver: `docker compose exec postgres psql -U postgres -d zpx_delivery_dev`
- Prefix `ZPX_ORGANIZATION_ID=1` to docker compose commands
