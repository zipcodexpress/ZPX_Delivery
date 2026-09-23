# Current Development Status

> Shared checkpoint for Codex, Qwen Code, and human developers. Hard limit: 200 lines.

## Last Updated

Date: 2026-09-23
Agent: Codex
Checkpoint: Scan contract normalization implemented and validated locally

## Branch / Dependency Chain

- Current branch: `fix/scan-contract-normalization`
- Latest scan-normalization commit: `d1c1390` (`fix(scan): normalize resolve and inbound scan contracts`).
- This branch is stacked on P4.1, which is stacked on package tracking.
- PR #14 package tracking: open, mergeable, all checks passed:
  https://github.com/zipcodexpress/ZPX_Delivery/pull/14
- PR #15 P4.1 hub operations UI: open against PR #14's branch, mergeable, all checks passed:
  https://github.com/zipcodexpress/ZPX_Delivery/pull/15
- After each dependency merges, rebase/retarget the next branch onto `main`.
- The two untracked planning documents remain human-authored and intentionally preserved.

## Current Objective

Normalize shared label resolution and inbound driver scans to the canonical API contract before P4.2.
Implementation and database validation are complete in the working tree.

## Completed in Current Work

- Changed `/scans/resolve` from legacy GET query input to canonical authenticated POST JSON input.
- Resolver accepts `label_payload`, `action`, and optional `run_id` and validates strict fields.
- Resolver now returns canonical `state`, `version`, `si`, `destination_location_id`, and
  `allowed_actions`; legacy `package_state`/`package_version` response aliases were removed.
- Resolver filters labels by active organization and authorizes requested actions against current
  state, manifest/run assignment, driver assignment, and hub assignment/custody.
- Updated hub receiving and staging UI to use canonical resolve actions and versions.
- Driver pickup UI now resolves first, then submits canonical RunScan fields:
  `label_payload`, `action`, `client_event_id`, `run_revision`, and `expected_package_version`.
- Driver pickup validates UUID client event identity, If-Match/run revision, package version, action,
  assigned run, and manifest membership before custody mutation.
- Accepted scan uses the client event UUID as the durable operation identity.
- Driver scan response now matches canonical ScanResult: result, package/SI/location/version,
  run/stop context, expected/accepted/pending counts, and load-complete `can_depart` signal.
- Legacy GET resolver calls and legacy `label_token` driver scan bodies are no longer accepted.

## Validation

- `npm run test-db` passed after normalization: full PostgreSQL suite, canonical resolve fields,
  action/run authorization, run/package revision checks, idempotency, durable accepted/refused scans,
  legacy GET rejection, receiving, staging, dispatch, custody, and cross-scope tests.
- PHP syntax checks passed for all changed PHP source/test files.
- `npm run check` passed with 74 validated API operations and 9 Node tests.
- `npm run build` passed for customer-web and operations-web.
- `git diff --check` and the focused diff/security review passed.

## Important Decisions

- Canonical contract names are authoritative; compatibility aliases were not retained.
- Resolve is a read-only precondition operation but still requires authenticated POST, CSRF for
  browser sessions, and an idempotency key under the shared controller rules.
- `allowed_actions` is derived server-side and requested actions fail closed when context is invalid.
- `can_depart` only means inbound loading is complete; actual departure authorization remains P4.2.
- No raw label token is returned or persisted by resolver/scan APIs.

## Important Files

- `apps/api/src/Custody/Service.php`
- `apps/api/src/Http/DriverController.php`
- `apps/api/tests/driver-inbound.php`, `apps/api/tests/hub-receiving.php`
- `apps/api/tests/hub-dispatch.php`
- `packages/ui/DriverWorkspace.tsx`
- `packages/ui/HubReceiving.tsx`, `packages/ui/HubOperations.tsx`

## Next Actions

1. Push this branch and open it as a stacked PR against P4.1 while dependencies are open.
2. Merge/rebase the PR chain in order: #14, #15, then scan normalization.
3. Begin P4.2 outbound driver delivery only after the normalized scan contract lands.

## Local Notes

- Start: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`
- Operations: http://localhost:5174 · Customer: http://localhost:5173 · API: http://localhost:8000
- Seed/re-seed: `python3 scripts/dev.py seed`; DRIVER-IN labels are `TEST-LABEL-001`…`005`.
- `scripts/dev.py test-db` uses a unique disposable Docker stack and preserves dev volumes.
