# Current Development Status

> Shared checkpoint for Codex, Qwen Code, and human developers. Hard limit: 200 lines.

## Last Updated

Date: 2026-09-23
Agent: Codex
Checkpoint: P4.1 hub operations UI implemented and fully validated locally

## Branch / Baseline

- Branch: `feature/P4.1-hub-operations-ui`
- This branch is intentionally stacked on `feature/package-tracking-custody-view`.
- Tracking PR #14 is open, mergeable, and all GitHub checks passed:
  https://github.com/zipcodexpress/ZPX_Delivery/pull/14
- Rebase P4.1 onto `main` after PR #14 merges.
- The two untracked planning documents remain human-authored and intentionally preserved.

## Current Objective

Make the existing hub staging and dispatch backend usable by hub operators without inventing P4.2
departure behavior. P4.1 implementation and validation are complete in the working tree.

## Completed in Current Work

- Added a HUB_STAFF workspace with Receiving, Staging, Dispatch, Exceptions, and History tabs.
- Reused the existing receiving/discrepancy workspace and operations package tracking screen.
- Staging now uses resolve-before-confirm, displays package state/version and destination slots, and
  explains that the server authoritatively verifies the destination.
- Backend staging now rejects a slot whose destination differs from the shipment destination.
- Staging verifies organization, current hub location, and HUB custody before changing package state.
- Accepted and refused staging attempts are durable `scan_events`; refused evidence survives rollback.
- Added hub-scoped dispatch workbench API on `GET /hub/dispatch-calls`.
- Dispatch workbench shows destination, slot, status, assigned driver, outbound run, expected pickup,
  loaded count, and remaining count.
- Existing `POST /hub/dispatch-calls` is now represented in the canonical contract and used by UI.
- Tightened driver dispatch list/accept/load queries to the active organization.
- Updated HubSlot wire schema, added dispatch schemas, and regenerated TypeScript types.

## Validation

- Final `npm run test-db` passed: full PostgreSQL suite, destination mismatch rejection, durable
  accepted/refused staging evidence, pending/dispatched workbench, HTTP route/CSRF, and load progress.
- `npm run check` passed with 74 validated API operations and 9 Node tests.
- `npm run build` passed for customer-web and operations-web.
- PHP syntax checks passed for dispatch service/controller/tests.

## Important Decisions

- The UI exposes only operations already supported by the backend: stage, create dispatch, inspect
  dispatch/driver/load progress. It does not fake departure authorization or cancellation.
- Destination matching, hub custody, organization, and assignment checks remain server-authoritative.
- No new projection or duplicate custody table was added.
- Refused staging evidence uses the shared post-rollback `ScanJournal` pattern.
- History reuses the package tracking/custody view delivered in PR #14.

## API / UI Impact

- `GET /api/delivery/v1/hub/dispatch-calls` lists assigned-hub dispatch state.
- `POST /api/delivery/v1/hub/dispatch-calls` creates a call from a ready staging slot.
- `POST /api/delivery/v1/hub/stage-scans` now enforces shipment destination and journals scans.
- HUB_STAFF operations-web now opens the consolidated Hub Operations workspace.

## Important Files

- `apps/api/src/HubDispatch/Service.php`
- `apps/api/src/Http/HubDispatchController.php`
- `apps/api/tests/hub-dispatch.php`
- `packages/ui/HubOperations.tsx`
- `packages/ui/HubReceiving.tsx`, `packages/ui/Account.tsx`
- `packages/ui/hub-receiving.css`
- `docs/handoff/contracts/openapi.json`, `packages/contracts/generated/api.ts`

## Remaining / Next Actions

1. Run final diff/security review and commit P4.1 locally.
2. Push as a stacked PR only if tracking PR #14 remains unmerged.
3. After P4.1, create `fix/scan-contract-normalization` before P4.2 outbound delivery.
4. P4.2 owns departure authorization and per-package outbound delivery; do not add it here.

## Local Notes

- Start: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`
- Operations: http://localhost:5174 · Customer: http://localhost:5173 · API: http://localhost:8000
- Seed/re-seed: `python3 scripts/dev.py seed`; DRIVER-IN labels are `TEST-LABEL-001`…`005`.
- `scripts/dev.py test-db` uses a unique disposable Docker stack and preserves dev volumes.
