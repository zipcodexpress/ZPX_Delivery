# Current Development Status

> Shared handoff for Codex, Qwen Code and human developers. Hard limit: 200 lines.

## Current objective and baseline

- Date: 2026-09-27. Agent: Codex. Branch: `codex/destination-device-protocol`, stacked on [PR #26](https://github.com/zipcodexpress/ZPX_Delivery/pull/26) (`5bea389`); [PR #25](https://github.com/zipcodexpress/ZPX_Delivery/pull/25) independently reviews partial-run pickup recovery.
- Objective: add signed enrolled-device command polling for prepared P5 final destination deposit, still without assuming door evidence or transferring custody.
- Date: 2026-09-27. Agent: Codex. Branch: `codex/partial-pickup-recovery`, feature commit `08d0f08`, based on merged `origin/main` at `338046c` (PR #24 merged). Review: [PR #25](https://github.com/zipcodexpress/ZPX_Delivery/pull/25).
- Objective: safely reassign an uncollected parcel from a partly collected inbound run without changing collected-parcel custody or hub receiving expectations.
- Date: 2026-09-28. Agent: Codex. Branch: `docs/admin-development-spec`, application baseline `338046c` (PR #24 merged); initial design commit `f1e70e5`.
- Current task complete: expanded global console specification in `docs/admin/GLOBAL_CONSOLE.md`, linked from the handoff README. Full menu catalog, people/contact directory, global equipment/shipment/tracking/payment views; ADM-01–ADM-18 backlog and A01–A34 acceptance cases. No application/schema changes.
- Richard confirmed ThinkPHP-rendered admin pages like `zpxadmin-tp8`; this replaces the earlier React admin expansion proposal. Existing React customer/driver/hub screens remain. Use the same delivery backend/services/PostgreSQL and identity.
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

## Validation and limitations

- `npm run check` passed: 91 canonical OpenAPI operations, TypeScript and 9 Node tests. `npm run build` passed both web apps.
- Disposable `npm run test-db` passed: final-deposit role/stop/revision/version/label/device credential/ownership fences, idempotency, unique claim, unchanged driver custody; origin claim release and unresolved-claim refusal. No physical hardware was tested.
- On this branch, `npm run check` and disposable `npm run test-db` passed again with signed-device invalid-signature/stale-time/replay, own-command, immutable-address, stale-parcel and frozen-ownership cases. Physical actuation and event ingestion remain untested/unimplemented.
- PR #24 is merged: administrators can cancel a wholly uncollected offer run and reopen its parcel demands without changing locker custody.
- Migration 022 adds a historical `RELEASED` manifest-item state. This branch lets an administrator release one `EXPECTED` parcel from a partly collected run only after recording a site-inspection or locker-inventory reference and reason. The service checks the exact run, parcel, demand, allocation, scan and receiving state, increments run/manifest revision, reopens only that demand, and never changes package custody or version.
- Driver and hub receiving counts exclude released items; the old run can receive and close its collected parcels without a false SHORT. The admin recovery tab shows per-parcel eligibility and evidence inputs. These references are operator assertions, not authenticated device telemetry.

## Validation and limitations

- `npm run check` passed: 91 canonical OpenAPI operations, TypeScript and 9 Node tests. `npm run build` passed both web apps. Isolated `npm run test-e2e` passed all 5 browser tests, including the new partial-recovery UI test.
- Disposable `npm run test-db` passed: partial release role/version/evidence/idempotency fences, collected-parcel custody preservation, hub receiving count and no false SHORT, a true SHORT for an unreceived collected parcel, and independent new-run reassignment. No physical hardware was tested.
- Route matching is a straight-line eligibility screen, not drive-time routing or automatic push notification. Production site coordinates require administrator configuration. A driver must consent to location sharing; stale/missing location receives no production offers.
- Real origin terminal pairing, authenticated physical door evidence, delayed/ambiguous evidence reconciliation, commissioning and supervised hardware pilot remain required. Live local Authorize.net sandbox capture previously returned a provider error; LOCAL_TEST payment is validated.

## Exact continuation point

1. Review the signed device command poll PR stacked on PR #26 after CI. A real locker/device commissioning flow is still missing.
2. Next development: authenticated device pairing creation/driver approval, signed physical event ingestion, then driver attestation and correlated deposit confirmation. After that, recipient notification/pickup grant and return/reconciliation.
1. Review and merge PR #25 after CI. Its local database, API, build and browser checks passed; the two untracked human notes remain untouched.
2. Continue the next independent roadmap step without waiting for merge: P5 final destination deposit and recipient notification/pickup grant, then enrolled Android terminal integration as far as practical without hardware.
1. Begin ADM-01 in `docs/admin/BACKLOG.md`: reconcile existing admin runtime/API contracts and add scoped capability foundation. ADM-02 then implements ThinkPHP pages with session-cookie migration from API-only path to root; read `THINKPHP_PRESENTATION.md` before coding.
2. PR #24 is merged. Delivery continuation remains partial-run missed pickup discrepancy resolution with explicit evidence, then enrolled Android origin terminal and P5 final destination deposit, recipient notification/pickup grant and return/reconciliation.
3. Documentation verification: local links, requirement/test/task references, code fences and handoff length checked; no runtime tests or production actions. Prior test results above belong to recovery implementation. Legacy apartment code was not modified.
4. Live settlement terms and real hardware commissioning remain explicit gates; independent synthetic admin development can proceed. New admin scope must not broaden existing location/hub grants or change physical custody through forms.
5. Global read work ADM-13–ADM-18 may follow access/shell foundations before advanced writes; NETWORK scope is explicit and does not imply unrestricted mutations or access to other organizations. Contacts use typed resource assignments and do not grant login permissions.

## Critical rules

- Physical eligibility depends on package state, custody, active manifest/session and version, not scan-event count.
- Each handoff is package-specific, scoped and idempotent. Wrong actor, locker, hub, revoked label or stale version fails closed.
- Hardware ambiguity never becomes automatic completion or automatic reopen.
- `docs/phase1_END_TO_END_DELIVERY_FLOW.md` is the canonical product flow.

## Local development

- Start: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`; customer `http://localhost:5173`, operations `http://localhost:5174`, API `http://localhost:8000`.
- Seed/reseed: `python3 scripts/dev.py seed`. Disposable tests preserve local development volumes.
- One prior browser run left a development-only pending sandbox payment on `ZPX-ORDER-E3D16499A1B0F728AB8B54BA`, with no confirmed charge; reconcile before retrying that order.
