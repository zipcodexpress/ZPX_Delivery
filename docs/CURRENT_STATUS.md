# Current Development Status

> Shared handoff for Codex, Qwen Code and human developers. Hard limit: 200 lines.

## Current objective and baseline

- Date: 2026-09-27. Agent: Codex. Branch: `codex/partial-pickup-recovery`, based on merged `origin/main` at `338046c` (PR #24 merged).
- Objective: safely reassign an uncollected parcel from a partly collected inbound run without changing collected-parcel custody or hub receiving expectations.
- Local Git source is authoritative. Platform: PostgreSQL, ThinkPHP 8, React. Android is the intended locker terminal; old Windows terminal is reference only.
- Preserve the untracked human notes `docs/PACKAGE_TRACKING_CUSTODY_PLAN.md` and `docs/ZPX_DELIVERY_NEXT_DEVELOPMENT_HANDOFF_09_22.md`; do not stage them.

## Implemented baseline

- Customer shipment initialization, verified contacts, size-only pricing, confirmed development payment, stable SI and replaceable label.
- Development-only virtual origin deposit creates OPEN pickup demand with explicit synthetic evidence; no physical hardware is claimed.
- Driver opt-in, expiring exact-item pickup offers, atomic acceptance into multi-locker inbound runs, and individual pickup scans/custody. Hub receiving/discrepancy, staging, outbound dispatch/load/departure and arrival are implemented.
- Synthetic destination deposit/recipient pickup demo outcomes are assumptions, not device evidence.

## Current branch milestone

- PR #24 is merged: administrators can cancel a wholly uncollected offer run and reopen its parcel demands without changing locker custody.
- Migration 022 adds a historical `RELEASED` manifest-item state. This branch lets an administrator release one `EXPECTED` parcel from a partly collected run only after recording a site-inspection or locker-inventory reference and reason. The service checks the exact run, parcel, demand, allocation, scan and receiving state, increments run/manifest revision, reopens only that demand, and never changes package custody or version.
- Driver and hub receiving counts exclude released items; the old run can receive and close its collected parcels without a false SHORT. The admin recovery tab shows per-parcel eligibility and evidence inputs. These references are operator assertions, not authenticated device telemetry.

## Validation and limitations

- `npm run check` passed: 91 canonical OpenAPI operations, TypeScript and 9 Node tests. `npm run build` passed both web apps. Isolated `npm run test-e2e` passed all 5 browser tests, including the new partial-recovery UI test.
- Disposable `npm run test-db` passed: partial release role/version/evidence/idempotency fences, collected-parcel custody preservation, hub receiving count and no false SHORT, a true SHORT for an unreceived collected parcel, and independent new-run reassignment. No physical hardware was tested.
- Route matching is a straight-line eligibility screen, not drive-time routing or automatic push notification. Production site coordinates require administrator configuration. A driver must consent to location sharing; stale/missing location receives no production offers.
- Real origin terminal pairing, authenticated physical door evidence, delayed/ambiguous evidence reconciliation, commissioning and supervised hardware pilot remain required. Live local Authorize.net sandbox capture previously returned a provider error; LOCAL_TEST payment is validated.

## Exact continuation point

1. Run isolated browser suite, review diff and schema compatibility, then commit/push `codex/partial-pickup-recovery` and open PR.
2. Continue the next roadmap step without waiting for merge if independent: enrolled Android origin terminal and P5 final destination deposit, recipient notification/pickup grant and return/reconciliation as far as practical without hardware.

## Critical rules

- Physical eligibility depends on package state, custody, active manifest/session and version, not scan-event count.
- Each handoff is package-specific, scoped and idempotent. Wrong actor, locker, hub, revoked label or stale version fails closed.
- Hardware ambiguity never becomes automatic completion or automatic reopen.
- `docs/phase1_END_TO_END_DELIVERY_FLOW.md` is the canonical product flow.

## Local development

- Start: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`; customer `http://localhost:5173`, operations `http://localhost:5174`, API `http://localhost:8000`.
- Seed/reseed: `python3 scripts/dev.py seed`. Disposable tests preserve local development volumes.
- One prior browser run left a development-only pending sandbox payment on `ZPX-ORDER-E3D16499A1B0F728AB8B54BA`, with no confirmed charge; reconcile before retrying that order.
