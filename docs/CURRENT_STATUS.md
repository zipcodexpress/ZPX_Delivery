# Current Development Status

> Shared checkpoint for Codex, Qwen Code, and human developers. Hard limit: 200 lines.

## Last Updated

Date: 2026-09-26
Agent: Codex
Checkpoint: PR #19 and #20 are merged into `main`. Origin deposit development has begun with a sender-only read-only size preview; no locker door or custody transition is implemented yet. Simulator evidence is acceptable for development, with real hardware validation reserved for pilot.

## Branch / Baseline

- Active branch: `codex/origin-deposit`, based on `main` at `b1fafa7` (merged PR #20; PR #19 merged at `cb93711`).
- Feature baseline: `b74a0f1` — merge of PR #18 (`feat(driver): enforce scanned outbound load before departure`) on 2026-09-24.
- PR #18 is merged. Do not treat `feature/P4.2-outbound-driver-delivery` as the active baseline.
- Existing untracked human notes in `docs/PACKAGE_TRACKING_CUSTODY_PLAN.md` and `docs/ZPX_DELIVERY_NEXT_DEVELOPMENT_HANDOFF_09_22.md` are preserved.
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

## DONE — Customer Shipment Initialization E2E development milestone

- Baseline gap audit is in `docs/CUSTOMER_INITIALIZATION_AUDIT.md`.
- Recipient address is encrypted in the existing contact snapshot; "Send to myself" uses the account's verified contacts and profile address, and self-recipient access is linked at creation.
- New shipments persist SMALL/MEDIUM/LARGE. An organization-scoped, versioned `PHASE1_SIZE` database policy publishes interior limits and $1/$2/$3 development rates; server validation and quote use it. Existing legacy packages keep a nullable class for compatibility.
- SI is issued once at shipment creation. The existing payment and PDF/QR label flow remains in use. Paid and actively labeled parcels show `READY_FOR_ORIGIN_DEPOSIT`; payment alone shows `LABEL_REQUIRED`.
- Synthetic fixture now has frozen LARGE compartments and non-real profile addresses; reseeding existing local fixtures adds missing data without replacing credentials or existing addresses.
- Running local stack has migration 017 and reseeded synthetic data. Customer browser flow confirmed form, draft, SI, published limits and $1 quote. The sandbox checkout provider rejected the external checkout request; the pending session is visible and retryable in the portal. Do not report a live sandbox payment as verified.

## NEXT

1. Origin deposit + size reconciliation: implement app/locker pairing, label scan, compatible compartment reservation, SMALL→MEDIUM/LARGE or MEDIUM→LARGE upgrade with price-difference payment before a larger door opens, then evidence-backed `AT_ORIGIN`. A read-only preview now computes allowable differences from the original paid rate card.
2. Pickup Demand / Driver Offer: create demand from `AT_ORIGIN`, notify nearby AVAILABLE drivers, atomic acceptance, multi-locker inbound run assembly.
3. Reuse existing P3/P4 inbound/hub/outbound custody flow.
4. P5 final destination deposit.
5. Recipient notification/pickup and return/reconciliation.
6. Android terminal/hardware integration and supervised pilot.

## Validation

- `npm run test-db` passed on disposable PostgreSQL; includes new self-recipient, all three size prices/limits, SI stability, `LABEL_REQUIRED` and `READY_FOR_ORIGIN_DEPOSIT`, plus existing custody/payment suites.
- On `codex/origin-deposit`, disposable `npm run test-db` passed with origin size preview authorization, original rate-card anchoring and authenticated HTTP route; `npm run check` passed with 77 API operations and 9 Node tests. No physical device was used.
- `npm run check` passed with 74 canonical API operations and 9 Node tests when loopback binding was permitted; `npm run build` passed for both web apps.
- Changed PHP syntax checks and `git diff --check` passed. Local `up` smoke passed for API, customer, operations, proxies and simulator.
- Browser test used the synthetic customer at `http://localhost:5173/`; draft creation and quote passed. Configured `AUTHORIZE_NET_SANDBOX` returned a generic provider error before payment. The external sandbox response/credentials require separate investigation; local test adapter integration passed.

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
- A browser test left one development-only pending sandbox payment on `ZPX-ORDER-E3D16499A1B0F728AB8B54BA`. It made no confirmed charge. Inspect/reconcile before any repeat checkout on that order.

## Product-flow correction — 2026-09-26

Canonical business flow: [phase1_END_TO_END_DELIVERY_FLOW.md](phase1_END_TO_END_DELIVERY_FLOW.md).

Before proceeding as if P5 were the only remaining feature lane, incorporate:
- P2.3 size-only SMALL/MEDIUM/LARGE pricing, published dimensions and origin size-upgrade payment difference;
- P3.0 Pickup Demand / Driver Offer, nearby-driver availability/offers and multi-locker inbound run assembly.

P4.2 outbound load/departure remains the implemented feature baseline.

Sender may equal recipient. A sender-as-recipient may intentionally share a one-time pickup grant with a trusted friend; SI remains public tracking identity and never opens a locker.

Preferred-route / rideshare-style matching is future Phase 2+.

## Portal and outbound follow-up — merged 2026-09-26

- Agent: Codex. Customer initialization and portal/driver follow-up were merged as PR #19 and #20.
- Completed here: scoped driver approvals, hub receiving resume, driver dispatch acceptance and stop-arrival progress, customer tracking for reported arrival, browser regression coverage, and development-only assumed destination/pickup outcomes. Assumed outcomes are fixtures, not evidence of a real locker handoff.
- Verification on the combined branch: disposable `npm run test-db` passed; `npm run check` passed (76 API operations and 9 Node tests); `npm run build` passed; isolated `npm run test-e2e` passed all four browser tests; `git diff --check` passed.
- Current work on `codex/origin-deposit`: sender-only `GET /packages/{package_id}/origin-size-options` now shows same-size/upgrade choices and price differences using the original paid rate card. It requires a paid, labeled parcel in sender custody and explicitly returns `door_authorized=false`; it cannot reserve or open a door. Next: implement the payment adjustment and pair/scan/claim/command/evidence workflow, then verified `AT_ORIGIN`, with explicit synthetic device evidence and failure/retry tests. Preserve frozen demo compartments until a deliberate virtual commissioning path exists. Do not infer completed physical delivery from synthetic fixtures.
