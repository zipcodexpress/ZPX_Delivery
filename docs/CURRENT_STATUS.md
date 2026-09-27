# Current Development Status

> Shared handoff for Codex, Qwen Code and human developers. Hard limit: 200 lines.

## Current objective and baseline

- Date: 2026-09-26. Agent: Codex.
- Branch: `codex/pickup-offers`, started from `codex/origin-deposit` at `6d8ead7`. PR #21 merged into `main` on 2026-09-26 local time; this branch contains the next pickup-offer development.
- Current objective: turn evidence-confirmed origin pickup demands into driver-accepted, exact inbound runs and carry individual parcels into driver custody.
- Existing untracked human notes in `docs/PACKAGE_TRACKING_CUSTODY_PLAN.md` and `docs/ZPX_DELIVERY_NEXT_DEVELOPMENT_HANDOFF_09_22.md` are preserved and must not be staged.
- Local Git source is authoritative. Platform: PostgreSQL, ThinkPHP 8, React. Android is the intended terminal; old Windows terminal is reference only.

## Implemented baseline

- Customer registration/contact verification, sender-as-recipient, encrypted recipient snapshot, shipment creation, size policy, server-confirmed development payment, stable SI and primary label/PDF.
- Driver inbound pickup/custody, hub independent receiving and discrepancies, operations custody timeline, hub staging/dispatch and exact outbound load/departure gate. Customer/driver/hub portal follow-ups from PR #19/#20 are merged.
- Synthetic destination deposit/pickup demo outcomes are assumptions, not device evidence.

## Current branch milestone

- Migration 020 adds driver opt-in availability, expiring origin-group offers, demand assignment/version/deadline and a marker for runs created from offers. An approved driver with an active shift requests offers; the initial policy requires exactly one active hub in the organization and uses explicit driver request instead of geolocation or push notification.
- Each offer snapshots exact demand ids/versions and expires after at most 15 minutes; refresh replaces prior unaccepted offers. Acceptance locks the driver, offer and snapshotted OPEN demands, verifies paid/ready package state and vehicle capacity, and creates or extends a published inbound run with exact manifest items and active allocations. First valid acceptance wins; idempotent retry returns the same assignment. The driver portal can request/accept offers. An authenticated package scan resolves only that package's demand.
- Multi-hub routing, proximity filtering, automatic outbound notifications, partial-capacity assignment and a physical origin terminal remain future work. Current offers group by origin under a single active hub and an active shift.

- Sender-only origin size options and upgrade quotes use the original paid `PHASE1_SIZE` rate-card version. Measured parcel dimensions must fit the requested class and both route endpoints. Only SMALL→MEDIUM/LARGE and MEDIUM→LARGE are accepted.
- A separate price-difference checkout uses the existing LOCAL_TEST or Authorize.net sandbox provider. Pending/failed adjustment leaves the parcel in its original class and sender custody; confirmed payment updates size, dimensions and versions before any larger virtual door can open. Migration 018 extends `pricing_quotes` additively.
- Seed creates distinct `SIM-*` virtual compartments and simulated devices. Existing physical demo compartments remain `FROZEN`; no real door address is invented or commanded.
- Development-only origin session validates the sender, paid and labeled shipment, exact origin, active label, package version and compatible virtual claim. It journals a command, accepts correlated synthetic open/close or unknown events, then requires sender attestation before `CREATED → AT_ORIGIN` and locker custody. Unknown outcome never auto-reopens or transfers custody.
- Confirmed virtual deposit appends scan/custody/package events with `synthetic_simulation=true` and `physical_hardware_verified=false`, occupies the claim and creates one OPEN pickup demand (migration 019). The test flow is available in the customer portal with explicit simulation labels.
- The canonical flow document states that virtual evidence does not complete production Step C. Android terminal enrollment, authenticated real telemetry, commissioning and a supervised pilot remain required for real deposits.

## Validation and current limitations

- Disposable `npm run test-db` passed after the active-session and revoked-label gates. It covers upgrade pricing/payment, wrong site/label, CSRF, version fences, open/close/attestation, idempotent replay, unknown door, expired unopened claim recovery and production-mode refusal.
- `npm run check` passed: 82 OpenAPI operations, TypeScript and 9 Node tests. `npm run build` passed both web apps.
- Isolated `npm run test-e2e` passed all 5 browser tests, including size-difference payment resumption after reload and virtual origin deposit. The test stack forces LOCAL_TEST payment and is removed afterward.
- No physical hardware was used or verified. Configured live local Authorize.net sandbox checkout previously returned a provider error; the local test adapter is verified, but provider troubleshooting remains separate.
- The virtual door events are generated by the development API adapter; the standalone Node locker simulator and Android terminal are not yet integrated with these sessions. Real device telemetry and sandbox upgrade capture need separate validation.
- Local validation and PR creation are complete. Check PR CI/review feedback before merge; no physical hardware validation was performed.
- Current branch `npm run test-db` passed, including competing drivers, exact offer snapshots, idempotent acceptance, two-locker run assembly and scan resolution. `npm run check` (86 operations), `npm run build` and isolated `npm run test-e2e` (5 browser tests, including deposit → offer → scan) passed after final code edits. No physical hardware was tested.

## Next development after this PR

1. Commit and open a PR against merged `main`. After review/merge, add explicit multi-hub routing and location-aware offer eligibility. Then add cancellation/reassignment for missed pickups and continue real terminal integration.
2. Real origin terminal path: independent enrolled-device authentication, ownership generation and physical address enforcement, delayed/ambiguous evidence reconciliation and supervised hardware pilot. Never treat development adapter events as real device evidence.
3. P5 final destination deposit, recipient notification/pickup grant and return/reconciliation.

## Critical rules

- Physical eligibility depends on package state, custody, active manifest/session and version, not scan-event count alone.
- Each handoff is package-specific, scoped and idempotent; wrong actor, locker, destination, revoked label or stale version fails closed.
- Hardware ambiguity never becomes automatic completion or automatic reopen.
- `docs/phase1_END_TO_END_DELIVERY_FLOW.md` is the canonical product flow. Keep this status concise and update it at handoffs.

## Local development

- Start: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`; customer `http://localhost:5173`, operations `http://localhost:5174`, API `http://localhost:8000`.
- Seed/reseed: `python3 scripts/dev.py seed`. Disposable tests preserve local development volumes.
- One prior browser run left a development-only pending sandbox payment on `ZPX-ORDER-E3D16499A1B0F728AB8B54BA`, with no confirmed charge. Reconcile it before retrying checkout on that order.
