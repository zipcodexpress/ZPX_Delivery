# Current Development Status

> Shared checkpoint for Codex, Qwen Code, and human developers. Hard limit: 200 lines.

## Last Updated

Date: 2026-09-23
Agent: Codex
Checkpoint: Package tracking and custody visibility implemented and validated locally

## Branch / Baseline

- Branch: `feature/package-tracking-custody-view`
- Baseline: merged P3.3 work (`main` includes PR #13 at `07f923e`)
- Package-tracking changes are ready for a focused commit.
- The two planning documents remain untracked human-authored files and are intentionally preserved.

## Current Objective

Deliver privacy-safe customer milestones plus an authoritative, assignment-scoped operations package
search and custody timeline. This milestone is implemented; the next planned feature is P4.1 hub
operations UI.

## Completed in Current Work

- Added exact operations package search by public reference, package UUID, shipping identifier (SI),
  or internal package ID at `GET /operations/packages/search?q=...`.
- Search reuses organization and current role/location/hub assignment scope; unauthorized and
  cross-organization identifiers return no results.
- Extended operations tracking with package identity, current custody/location, package version,
  and a deterministic timeline assembled from existing authoritative tables.
- Timeline sources include package events, custody events, accepted/refused scan evidence,
  receiving dispositions, staging assignments, dispatch calls, and discrepancy creation/resolution.
- Customer tracking retains only privacy-safe shipment milestones; it does not expose actors,
  custody references, scan evidence, discrepancy notes, or operations details.
- Added the operations-web package search and detailed custody timeline to the existing shipment
  workspace. No parallel tracking screen or duplicate event table was introduced.
- Updated the canonical OpenAPI contract and regenerated TypeScript types.

## Tests

- `npm run test-db` passed: full PostgreSQL suite, including the new tracking aggregation,
  rejected scan, customer redaction, all identifiers, hub scope, and cross-org checks.
- `npm run check` passed: OpenAPI/generated types, TypeScript, and 9 Node tests.
- `npm run build` passed for customer-web and operations-web.
- PHP syntax checks passed for the shipping service/controller/test.
- `git diff --check` passed.

## Important Decisions

- No tracking projection/table was added. Read models are built from authoritative operational data.
- Existing operations authorization remains the single scope rule: ADMIN/DISPATCHER grants may be
  network or location scoped; hub staff must have active membership at the package's current hub.
- Search is exact-match only to reduce accidental disclosure and noisy result sets.
- Internal actors, custody references, rejected scans, and discrepancy notes are operations-only.
- Raw label tokens are never queried or returned by tracking/search APIs.
- Scan contract normalization remains a later dedicated branch.

## API / UI Impact

- New API: `GET /api/delivery/v1/operations/packages/search?q=...`.
- Extended operations response: `GET /operations/shipments/{id}/tracking` includes optional
  `package`, `current_custody`, and `events` fields.
- Customer response shape remains compatible: `shipment_id` plus `milestones`.
- Operations shipment workspace now supports package lookup and authoritative custody inspection.

## Important Files

- `apps/api/src/Shipping/Service.php`
- `apps/api/src/Http/ShippingController.php`, `apps/api/route/api.php`
- `apps/api/tests/shipping.php`
- `packages/ui/Shipping.tsx`, `packages/ui/shipping.css`
- `docs/handoff/contracts/openapi.json`, `packages/contracts/generated/api.ts`

## Known Remaining Gaps

- P4.1 still needs a dedicated hub staging/dispatch operations UI.
- Staging actions are represented by the authoritative assignment and package event; a dedicated
  staging scan record should be added with P4.1 accepted/refused scan evidence.
- `/scans/resolve` contract normalization remains pending.
- P4.2 outbound driver delivery and P7.1 hub/admin/asset management remain later milestones.

## Next Actions

1. Commit the validated package-tracking milestone and open a PR when requested.
2. After merge, create `feature/P4.1-hub-operations-ui`.
3. Build receiving/staging/dispatch workbench UI on existing hub services.
4. Follow with `fix/scan-contract-normalization`, then P4.2 outbound delivery.

## Local Notes

- Start: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`
- Operations: http://localhost:5174 · Customer: http://localhost:5173 · API: http://localhost:8000
- Seed/re-seed: `python3 scripts/dev.py seed`; DRIVER-IN labels are `TEST-LABEL-001`…`005`.
- `scripts/dev.py test-db` uses a unique disposable Docker stack and preserves dev volumes.
