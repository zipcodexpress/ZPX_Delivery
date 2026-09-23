# Current Development Status

> Shared checkpoint for Codex, Qwen Code, and human developers. Hard limit: 200 lines.

## Last Updated

Date: 2026-09-23
Agent: Codex
Checkpoint: P4.2 outbound load and departure foundation implemented and validated

## Branch / Baseline

- Branch: `feature/P4.2-outbound-driver-delivery`
- Baseline: `main` at `211b9a9` (PR #17 integrated P4.1 and scan normalization).
- The branch is fast-forwarded to the merged baseline; validated P4.2 changes are ready to commit.
- The two planning documents remain untracked human-authored files and are intentionally preserved.

## Current Objective

Deliver P4.2 outbound load and ordered-stop driver workflow with server-authoritative unique-package
departure eligibility. P5.1 final deposit remains out of scope.

## Completed in Current Work

- Dispatch acceptance freezes the staged physical package set into a versioned outbound manifest.
- Outbound route stops and manifest items are materialized instead of relying on slot state alone.
- Driver scans each outbound package through the canonical run scan endpoint with resolve-first,
  run revision, package version, client event UUID, idempotency, and If-Match preconditions.
- Successful load transfers custody exactly once from the assigned hub to the assigned driver,
  records scan/custody/package/outbox evidence, and marks only that manifest item loaded.
- Wrong driver, wrong run, off-manifest package, wrong hub custody, stale version/revision, revoked
  label, and duplicate physical package scans fail closed.
- `POST /runs/{run_id}/depart` authorizes only the assigned outbound driver and counts unique
  manifest package IDs whose package custody agrees with the loaded manifest state.
- Departure marks the run/dispatch pickup and releases the hub slot only after the exact manifest
  is loaded; nine unique packages plus a duplicate cannot depart.
- Driver UI chooses inbound versus outbound scan action, enables departure only after the visible
  manifest is fully loaded, and displays the ordered stop/package groups.

## Validation

- Full `npm run test-db` passed after implementation.
- Exact plan scenario passes: 10 packages, ordered 6 + 4 stop grouping, 9 unique + duplicate blocked,
  tenth unique load eligible, then departure succeeds.
- Existing inbound, receiving, discrepancy, staging, dispatch, tracking, identity, payment, and
  cross-scope PostgreSQL coverage continues to pass.
- PHP syntax passed for every changed PHP source/test file; `git diff --check` passed.
- `npm run check` passed with 74 canonical API operations and 9 Node tests.
- `npm run build` passed for customer-web and operations-web.

## Important Decisions

- Departure eligibility is derived from authoritative manifest rows joined to current package
  state/custody; scan-event count alone is never sufficient.
- A dispatch call currently creates its own outbound run, but the run/manifest model supports
  multiple ordered stops. Broader multi-call route planning remains an operations planning concern.
- The legacy bulk-load route/service was removed so no API can bypass individual physical scans.
- P5.1 owns final deposit and recipient pickup; P4.2 does not fabricate delivery completion.

## Important Files

- `apps/api/src/Custody/Service.php`
- `apps/api/src/HubDispatch/Service.php`
- `apps/api/src/Http/DriverController.php`, `apps/api/route/api.php`
- `apps/api/tests/hub-dispatch.php`
- `packages/ui/DriverWorkspace.tsx`

## Next Actions

1. Commit, push, and open the P4.2 PR.
2. Continue ordered-stop progress behavior without entering P5.1 final deposit scope.

## Local Notes

- Start: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`
- Operations: http://localhost:5174 · Customer: http://localhost:5173 · API: http://localhost:8000
- Seed/re-seed: `python3 scripts/dev.py seed`; DRIVER-IN labels are `TEST-LABEL-001`…`005`.
- `scripts/dev.py test-db` uses a unique disposable Docker stack and preserves dev volumes.
