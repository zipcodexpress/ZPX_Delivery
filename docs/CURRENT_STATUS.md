# Current Development Status

> Shared handoff for Codex, Qwen Code and human developers. Hard limit: 200 lines.

## Current objective and baseline

- Date: 2026-09-27. Agent: Codex. Branch: `codex/pickup-recovery`, feature commit `645f8ca`, based on merged `origin/main` at `73aec6c` (PR #23 merged). Review: [PR #24](https://github.com/zipcodexpress/ZPX_Delivery/pull/24).
- Objective: let operations release and reassign a missed, wholly uncollected pickup run while preserving recorded locker custody.
- Local Git source is authoritative. Platform: PostgreSQL, ThinkPHP 8, React. Android is the intended locker terminal; old Windows terminal is reference only.
- Preserve the untracked human notes `docs/PACKAGE_TRACKING_CUSTODY_PLAN.md` and `docs/ZPX_DELIVERY_NEXT_DEVELOPMENT_HANDOFF_09_22.md`; do not stage them.

## Implemented baseline

- Customer shipment initialization, verified contacts, size-only pricing, confirmed development payment, stable SI and replaceable label.
- Development-only virtual origin deposit creates OPEN pickup demand with explicit synthetic evidence; no physical hardware is claimed.
- Driver opt-in, expiring exact-item pickup offers, atomic acceptance into multi-locker inbound runs, and individual pickup scans/custody. Hub receiving/discrepancy, staging, outbound dispatch/load/departure and arrival are implemented.
- Synthetic destination deposit/recipient pickup demo outcomes are assumptions, not device evidence.

## Current branch milestone

- PR #23 is merged: explicit origin-to-hub routes, fresh driver location, 25 km pickup eligibility, route recheck at offer acceptance and an admin route setup portal are in `main`.
- This branch adds an admin recovery list and reasoned release action for offer-created inbound runs that are entirely uncollected. The action locks the run and parcels, refuses recorded pickup/receiving or custody ambiguity, cancels the old run, removes active allocations, reopens demand with a fresh four-hour operational pickup window, and records package/audit events. It never changes package state, locker custody or version.
- The admin portal has a Pickup recovery tab. A driver can request and accept a new offer after release; partial runs remain assigned pending a separate discrepancy workflow.

## Validation and limitations

- `npm run check` passed: 90 canonical OpenAPI operations, TypeScript and 9 Node tests. `npm run build` passed both web apps. Isolated `npm run test-e2e` passed all 5 browser tests, including the recovery tab.
- Disposable `npm run test-db` passed after the final recovery guard and list changes: role/version/idempotency fences, partial-run refusal, unchanged locker custody, released allocation, demand reopening and new-run reassignment. No physical hardware was tested.
- Route matching is a straight-line eligibility screen, not drive-time routing or automatic push notification. Production site coordinates require administrator configuration. A driver must consent to location sharing; stale/missing location receives no production offers.
- Real origin terminal pairing, authenticated physical door evidence, delayed/ambiguous evidence reconciliation, commissioning and supervised hardware pilot remain required. Live local Authorize.net sandbox capture previously returned a provider error; LOCAL_TEST payment is validated.

## Exact continuation point

1. Review and merge PR #24 after CI. The branch is validated locally; the two untracked human notes remain untouched.
2. After merge: partial-run missed pickup discrepancy resolution with explicit evidence, then enrolled Android origin terminal and P5 final destination deposit, recipient notification/pickup grant and return/reconciliation.

## Critical rules

- Physical eligibility depends on package state, custody, active manifest/session and version, not scan-event count.
- Each handoff is package-specific, scoped and idempotent. Wrong actor, locker, hub, revoked label or stale version fails closed.
- Hardware ambiguity never becomes automatic completion or automatic reopen.
- `docs/phase1_END_TO_END_DELIVERY_FLOW.md` is the canonical product flow.

## Local development

- Start: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`; customer `http://localhost:5173`, operations `http://localhost:5174`, API `http://localhost:8000`.
- Seed/reseed: `python3 scripts/dev.py seed`. Disposable tests preserve local development volumes.
- One prior browser run left a development-only pending sandbox payment on `ZPX-ORDER-E3D16499A1B0F728AB8B54BA`, with no confirmed charge; reconcile before retrying that order.
