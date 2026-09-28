# Current Development Status

> Shared handoff for Codex, Qwen Code and human developers. Hard limit: 200 lines.

## Current objective and baseline

- Date: 2026-09-27. Agent: Codex. Branch: `codex/pickup-routing`, based on merged `main` at `f350fd0` (PR #22).
- Objective: make driver pickup offers use an explicit origin-to-hub route and nearby-driver location eligibility.
- Local Git source is authoritative. Platform: PostgreSQL, ThinkPHP 8, React. Android is the intended locker terminal; old Windows terminal is reference only.
- Preserve the untracked human notes `docs/PACKAGE_TRACKING_CUSTODY_PLAN.md` and `docs/ZPX_DELIVERY_NEXT_DEVELOPMENT_HANDOFF_09_22.md`; do not stage them.

## Implemented baseline

- Customer shipment initialization, verified contacts, size-only pricing, confirmed development payment, stable SI and replaceable label.
- Development-only virtual origin deposit creates OPEN pickup demand with explicit synthetic evidence; no physical hardware is claimed.
- Driver opt-in, expiring exact-item pickup offers, atomic acceptance into multi-locker inbound runs, and individual pickup scans/custody. Hub receiving/discrepancy, staging, outbound dispatch/load/departure and arrival are implemented.
- Synthetic destination deposit/recipient pickup demo outcomes are assumptions, not device evidence.

## Current branch milestone

- Migration 021 adds `origin_hub_routes` and driver-location freshness timestamp. An administrator can map each active origin to an active hub in the same organization, optionally setting verified coordinates; route updates have version/idempotency fences and audit history.
- A sole active hub remains the compatible fallback. With multiple hubs, unmapped origins receive no offers. Acceptance rechecks the route, so changing a route cannot redirect an older offer. Already assigned runs keep their frozen hub and manifest.
- A driver explicitly requests offers and shares location through the driver portal. Production requires a location shared within 15 minutes and origin coordinates within a 25 km straight-line radius at both offer creation and acceptance. Going offline clears location and cancels unaccepted offers. Local development fixtures may omit coordinates.
- Admin portal exposes pickup route setup. The existing virtual-deposit-to-driver-pickup browser flow now exercises route setup and driver geolocation.

## Validation and limitations

- Disposable `npm run test-db` passed: multi-hub mapping, organization/role/version fences, near/far driver matching, stale-route rejection, frozen prior run hub, and offline location clearing. No physical hardware was tested.
- `npm run check` passed: 88 canonical OpenAPI operations, TypeScript, and 9 Node tests. `npm run build` passed both web apps. Isolated `npm run test-e2e` passed all 5 browser tests.
- Route matching is a straight-line eligibility screen, not drive-time routing or automatic push notification. Production site coordinates require administrator configuration. A driver must consent to location sharing; stale/missing location receives no production offers.
- Real origin terminal pairing, authenticated physical door evidence, delayed/ambiguous evidence reconciliation, commissioning and supervised hardware pilot remain required. Live local Authorize.net sandbox capture previously returned a provider error; LOCAL_TEST payment is validated.

## Exact continuation point

1. Review the current diff and create a PR against `main`; check CI and review feedback before merge.
2. Add cancellation/reassignment for missed pickup work without silently changing custody or reusing ambiguous locker evidence.
3. Complete the enrolled Android origin terminal and P5 final destination deposit, recipient notification/pickup grant and return/reconciliation.

## Critical rules

- Physical eligibility depends on package state, custody, active manifest/session and version, not scan-event count.
- Each handoff is package-specific, scoped and idempotent. Wrong actor, locker, hub, revoked label or stale version fails closed.
- Hardware ambiguity never becomes automatic completion or automatic reopen.
- `docs/phase1_END_TO_END_DELIVERY_FLOW.md` is the canonical product flow.

## Local development

- Start: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`; customer `http://localhost:5173`, operations `http://localhost:5174`, API `http://localhost:8000`.
- Seed/reseed: `python3 scripts/dev.py seed`. Disposable tests preserve local development volumes.
- One prior browser run left a development-only pending sandbox payment on `ZPX-ORDER-E3D16499A1B0F728AB8B54BA`, with no confirmed charge; reconcile before retrying that order.
