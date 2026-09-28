# Current Development Status

> Shared handoff for Codex, Qwen Code and human developers. Hard limit: 200 lines.

## Current objective and baseline

- Date: 2026-09-27. Agent: Codex. Branch: `codex/destination-pairing`, stacked on [PR #27](https://github.com/zipcodexpress/ZPX_Delivery/pull/27) (which is stacked on [PR #26](https://github.com/zipcodexpress/ZPX_Delivery/pull/26)); [PR #25](https://github.com/zipcodexpress/ZPX_Delivery/pull/25) independently reviews partial-run pickup recovery.
- Objective: let an enrolled destination terminal create a short-lived pairing scene and let the arrived driver preview/approve the exact site and action before deposit preparation.
- Local Git source is authoritative. Platform: PostgreSQL, ThinkPHP 8, React. Android is the intended locker terminal; old Windows terminal is reference only.
- Preserve the untracked human notes `docs/PACKAGE_TRACKING_CUSTODY_PLAN.md` and `docs/ZPX_DELIVERY_NEXT_DEVELOPMENT_HANDOFF_09_22.md`; do not stage them.

## Implemented baseline

- Customer shipment initialization, verified contacts, size-only pricing, confirmed development payment, stable SI and replaceable label.
- Development-only virtual origin deposit creates OPEN pickup demand with explicit synthetic evidence; no physical hardware is claimed.
- Driver opt-in, expiring exact-item pickup offers, atomic acceptance into multi-locker inbound runs, and individual pickup scans/custody. Hub receiving/discrepancy, staging, outbound dispatch/load/departure and arrival are implemented.
- Synthetic destination deposit/recipient pickup demo outcomes are assumptions, not device evidence.

## Current branch milestone

- PR #25 separately reviews evidence-backed recovery of uncollected parcels from partially collected inbound runs; it is not a dependency of this branch.
- This branch adds `POST /runs/{run_id}/stops/{stop_id}/final-deposits`: driver, run/stop revision, parcel version, active label, shipping identifier, exact arrived destination, current driver custody, approved pairing, active enrolled device, physical-command enablement and current DELIVERY compartment ownership are checked in one transaction. It reserves one compatible door and journals a pending command. It does not dispatch, open, claim delivery, create a pickup grant, or transfer custody.
- Accepted origin pickup now releases only an occupied claim matching that parcel's origin locker. An unresolved/mismatched claim blocks transfer. A pending final-deposit claim is never automatically freed on timeout; it requires evidence reconciliation.
- This stacked branch adds Ed25519-signed per-device GET command poll, timestamp and durable nonce replay fence, own-locker/ownership/session/package/run/physical-address checks, and an immutable first-dispatch payload hash. A terminal cannot poll a command after custody/version/ownership/address changes; polling never confirms door evidence or custody.
- This branch adds signed device-created FINAL_DEPOSIT pairing scenes, driver preview and revision-fenced approval tied to an arrived stop, then consumption by final-deposit preparation. Other actors/stops and repeat approval are refused.

## Validation and limitations

- `npm run check` passed: 91 canonical OpenAPI operations, TypeScript and 9 Node tests. `npm run build` passed both web apps.
- Disposable `npm run test-db` passed: final-deposit role/stop/revision/version/label/device credential/ownership fences, idempotency, unique claim, unchanged driver custody; origin claim release and unresolved-claim refusal. No physical hardware was tested.
- On this branch, `npm run check` and disposable `npm run test-db` passed again with signed-device invalid-signature/stale-time/replay, own-command, immutable-address, stale-parcel and frozen-ownership cases. Physical actuation and event ingestion remain untested/unimplemented.
- On the pairing branch, `npm run check` passed with 93 canonical operations and 9 Node tests; disposable `npm run test-db` passed device scene creation, driver preview/approval, idempotent replay, wrong actor/stop and approved-scene consumption into a second parcel reservation. No physical hardware was tested.
- Route matching is a straight-line eligibility screen, not drive-time routing or automatic push notification. Production site coordinates require administrator configuration. A driver must consent to location sharing; stale/missing location receives no production offers.
- Real origin terminal pairing, authenticated physical door evidence, delayed/ambiguous evidence reconciliation, commissioning and supervised hardware pilot remain required. Live local Authorize.net sandbox capture previously returned a provider error; LOCAL_TEST payment is validated.

## Exact continuation point

1. Review destination pairing PR stacked on PR #27 after CI. A real locker/device commissioning flow is still missing.
2. Next development: signed physical event ingestion, then driver attestation and correlated deposit confirmation. After that, recipient notification/pickup grant and return/reconciliation.

## Critical rules

- Physical eligibility depends on package state, custody, active manifest/session and version, not scan-event count.
- Each handoff is package-specific, scoped and idempotent. Wrong actor, locker, hub, revoked label or stale version fails closed.
- Hardware ambiguity never becomes automatic completion or automatic reopen.
- `docs/phase1_END_TO_END_DELIVERY_FLOW.md` is the canonical product flow.

## Local development

- Start: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`; customer `http://localhost:5173`, operations `http://localhost:5174`, API `http://localhost:8000`.
- Seed/reseed: `python3 scripts/dev.py seed`. Disposable tests preserve local development volumes.
- One prior browser run left a development-only pending sandbox payment on `ZPX-ORDER-E3D16499A1B0F728AB8B54BA`, with no confirmed charge; reconcile before retrying that order.
