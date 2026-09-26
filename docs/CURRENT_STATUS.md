# Current Development Status

> Shared checkpoint for Codex, Qwen Code, and human developers. Hard limit: 200 lines.

## Last Updated

Date: 2026-09-25
Agent: ChatGPT project review
Checkpoint: PR #18 merged; P4.2 outbound load/departure is the feature baseline; P5.1 final destination deposit is next.

## Branch / Baseline

- Active shared branch: `main`.
- Feature baseline: `b74a0f1` — merge of PR #18 (`feat(driver): enforce scanned outbound load before departure`) on 2026-09-24.
- PR #18 is merged. Do not treat `feature/P4.2-outbound-driver-delivery` as the active baseline.
- Documentation drift correction commits follow the feature baseline; pull current `main` before new work.
- Local checkout is the working source of truth during development; push reviewed milestones to GitHub so `main` remains the shared remote baseline.
- Platform baseline: PostgreSQL + ThinkPHP 8 + React. New terminal direction is Android; old Windows terminal is reference-only.

## DONE — do not redesign without a demonstrated regression

- PostgreSQL migration/seed/test foundation, scoped identities and authorization.
- Shipment/payment sandbox, SI/label and customer tracking foundations.
- Driver management and individual inbound pickup/custody transfer.
- Hub independent receiving and SHORT/DAMAGED/EXTRA discrepancy workflow.
- Operations package search and authoritative custody timeline.
- Hub staging/dispatch workspace and canonical resolve/scan normalization.
- P4.2 outbound manifest freeze, individual package load scans, hub-to-driver custody transfer, ordered stop groups and exact departure gate.
- Acceptance scenario: 10 packages grouped 6 + 4; 9 unique scans plus a duplicate cannot depart; tenth unique accepted scan enables departure.
- Legacy bulk outbound-load bypass removed.

## NOW — P5.1 final destination deposit

Build the next bounded vertical slice from the merged P4.2 baseline:

1. Driver progresses to an assigned ordered stop.
2. Resolve and scan each package for final deposit.
3. Server validates organization, run, assigned driver, manifest item, package state/version/custody and exact destination.
4. Authorize only the correct destination locker/compartment through the terminal/device boundary.
5. Correlate door/deposit evidence before committing physical delivery state.
6. Transfer custody exactly once from `DRIVER` to `DESTINATION_LOCKER`.
7. Expose stop/package progress without allowing client-calculated completion.
8. Preserve refused/ambiguous evidence and idempotent retry behavior.

P5.1 must reuse existing custody, scan journal, versioning, idempotency and tracking architecture rather than creating a parallel delivery state model.

## NEXT

1. Recipient claim/pickup grant and single-use retrieval: `DESTINATION_LOCKER -> RECIPIENT`.
2. Failed delivery and return reconciliation: full/offline/inaccessible locker, ambiguous door action, undelivered package, return-to-hub and unresolved end-of-run custody.
3. Android terminal/hardware integration with durable journal, one command gate and real scanner/controller tests.
4. Operational hardening, monitoring/restore evidence and supervised pilot.

## P4.2 validation inherited by baseline

- `npm run test-db` passed.
- Exact 10-package / 6+4 / duplicate-blocked departure scenario passed.
- Existing inbound, receiving, discrepancy, staging, dispatch, tracking, identity, payment and cross-scope PostgreSQL coverage continued to pass.
- Changed PHP syntax checks and `git diff --check` passed.
- `npm run check` passed with 74 canonical API operations and 9 Node tests.
- `npm run build` passed for customer-web and operations-web.

## Critical invariants

- Current package custody plus authoritative manifest state determines physical eligibility; scan-event count alone is insufficient.
- Every physical handoff is package-specific, scoped, version checked and idempotent.
- Wrong driver/run/hub/destination/off-manifest/revoked/stale requests fail closed.
- Hardware ambiguity never becomes automatic delivery completion or automatic reopen.
- P5 final deposit and recipient pickup must extend the existing custody timeline so operations can always answer where a package is and who holds custody.
- Route optimization, dashboard polishing and broad noncritical CRUD are not the current critical path.

## Important Files

- `apps/api/src/Custody/Service.php`
- `apps/api/src/HubDispatch/Service.php`
- `apps/api/src/Http/DriverController.php`
- `apps/api/route/api.php`
- `apps/api/tests/hub-dispatch.php`
- `packages/ui/DriverWorkspace.tsx`
- `docs/PHASE1_REMAINING_WORK.md`
- `docs/PROGRESS.md`

## Local Notes

- Start: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`
- Operations: `http://localhost:5174` · Customer: `http://localhost:5173` · API: `http://localhost:8000`
- Seed/re-seed: `python3 scripts/dev.py seed`; DRIVER-IN labels are `TEST-LABEL-001`…`005`.
- `scripts/dev.py test-db` uses a unique disposable Docker stack and preserves dev volumes.
- Before ending a substantial Codex/Qwen session, update this file with completed work, verification, blockers and the exact next action.

## Product-flow correction — 2026-09-26

Canonical business flow: [phase1_END_TO_END_DELIVERY_FLOW.md](phase1_END_TO_END_DELIVERY_FLOW.md).

Before proceeding as if P5 were the only remaining feature lane, incorporate:
- P2.3 size-only SMALL/MEDIUM/LARGE pricing, published dimensions and origin size-upgrade payment difference;
- P3.0 Pickup Demand / Driver Offer, nearby-driver availability/offers and multi-locker inbound run assembly.

P4.2 outbound load/departure remains the implemented feature baseline.

Sender may equal recipient. A sender-as-recipient may intentionally share a one-time pickup grant with a trusted friend; SI remains public tracking identity and never opens a locker.

Preferred-route / rideshare-style matching is future Phase 2+.

