# Current Development Status

> Shared checkpoint for Codex, Qwen Code, and human developers. Hard limit: 200 lines.

## Last Updated

Date: 2026-09-25
Agent: ChatGPT project review
Checkpoint: PR #18 merged; P4.2 remains the implemented feature baseline. Next coding starts with Customer Shipment Initialization E2E, then origin deposit/size upgrade, then Pickup Demand / Driver Offer.

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

## NOW — Customer Shipment Initialization E2E

Do not start by rebuilding P1/P2. First audit the existing customer application/API/database against the canonical flow and classify every required capability as IMPLEMENTED / PARTIAL / MISSING.

Then close only the gaps needed for one complete customer initialization journey:

1. Register/login/verification/profile works for a new customer.
2. Create recipient contact/address or choose "Send to myself".
3. Select origin locker/location.
4. Search/select destination locker.
5. Select SMALL/MEDIUM/LARGE using published interior dimensions.
6. Show the authoritative size-only price.
7. Complete server-confirmed payment.
8. Create/finalize shipment/package.
9. Issue one stable SI and printable/scannable primary label.
10. Show shipment as READY_FOR_ORIGIN_DEPOSIT.

Phase 1 does not require a full stored-card wallet before continuing. Payment-method storage is optional; the required capability is reliable quote/payment plus later size-upgrade payment adjustment.

## NEXT

1. Origin deposit + size reconciliation: app/locker pairing, label scan, compatible compartment reservation, SMALL→MEDIUM/LARGE or MEDIUM→LARGE upgrade with price-difference payment before a larger door opens, then authoritative `AT_ORIGIN`.
2. Pickup Demand / Driver Offer: create demand from `AT_ORIGIN`, notify nearby AVAILABLE drivers, atomic acceptance, multi-locker inbound run assembly.
3. Reuse existing P3/P4 inbound/hub/outbound custody flow.
4. P5 final destination deposit.
5. Recipient notification/pickup and return/reconciliation.
6. Android terminal/hardware integration and supervised pilot.

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

