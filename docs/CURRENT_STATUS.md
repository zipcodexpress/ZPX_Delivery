# Current Development Status

> Shared handoff for Codex, Qwen Code and human developers. Hard limit: 200 lines.

## Objective and baseline

- Date: 2026-09-28. Agent: Codex. Branch: `codex/admin-foundation`, commit `f34eb71`, PR #31 open against `main` (based on `e5658f8`).
- Implement the admin backlog in `docs/admin/BACKLOG.md`, using `zpxadmin-tp8` as a visual/structural reference. Richard explicitly requires the new site under `apps/admin`; it remains mounted by the existing ThinkPHP API and uses the same PostgreSQL and identity.
- This branch is the bounded ADM-01/ADM-02 foundation; the full ADM-03–ADM-18 backlog remains. No schema migration is needed for these two tasks. Add partner/site tables only in their owning tasks after verifying relationships.
- Date: 2026-09-27. Agent: Codex. Branch: `codex/partial-pickup-recovery`, feature commit `08d0f08`, based on merged `origin/main` at `338046c` (PR #24 merged). Review: [PR #25](https://github.com/zipcodexpress/ZPX_Delivery/pull/25).
- Objective: safely reassign an uncollected parcel from a partly collected inbound run without changing collected-parcel custody or hub receiving expectations.
- Date: 2026-09-28. Agent: Codex. Branch: `docs/admin-development-spec`, application baseline `338046c` (PR #24 merged); initial design commit `f1e70e5`.
- Current task complete: expanded global console specification in `docs/admin/GLOBAL_CONSOLE.md`, linked from the handoff README. Full menu catalog, people/contact directory, global equipment/shipment/tracking/payment views; ADM-01–ADM-18 backlog and A01–A34 acceptance cases. No application/schema changes.
- Richard confirmed ThinkPHP-rendered admin pages like `zpxadmin-tp8`; this replaces the earlier React admin expansion proposal. Existing React customer/driver/hub screens remain. Use the same delivery backend/services/PostgreSQL and identity.
- Local Git source is authoritative. Platform: PostgreSQL, ThinkPHP 8, React. Android is the intended locker terminal; old Windows terminal is reference only.
- Preserve the untracked human notes `docs/PACKAGE_TRACKING_CUSTODY_PLAN.md` and `docs/ZPX_DELIVERY_NEXT_DEVELOPMENT_HANDOFF_09_22.md`; do not stage them.

## Implemented on this branch

- `apps/admin` contains ThinkPHP routes, access resolver, page controller, view templates and scoped CSS. Network admins have dashboard, shipment list, pending-driver decisions, pickup routes and recovery pages, using existing services. Non-admins receive explicit denial.
- `GET /api/delivery/v1/admin/access` returns deny-by-default capability hints and network/location scopes from current grants. No new role grants or authorization broadening. Contract and generated TS types updated.
- Browser session cookie changes from API-only path to root so `/admin` can share identity; legacy path is explicitly expired on sign-in/logout. Admin login has pre-login CSRF cookie, Origin check and existing identity rate limiting. Existing JSON login/logout behavior is retained.
- `topthink/think-view` is locked in Composer. API Docker image includes sibling `apps/admin` while keeping one application/database.
- No new database migration was needed for ADM-01/02. The local development database had four pending existing migrations, including `021_pickup_routing.sql`; the migration image was rebuilt and they were applied. PostgreSQL remains exposed only on `127.0.0.1:5432` for DBeaver.

## Validation

- `npm run check` passed (95 validated OpenAPI operations, TypeScript, 9 Node tests). `npm run build` passed both React apps. Composer validation, PHP syntax checks and template escaping/rendering passed.
- Disposable `npm run test-db` passed, including network/location/no-admin scopes, anonymous redirect, denial, missing-CSRF refusal and preexisting driver/workflow regressions. No physical hardware was tested.
- Live local admin sign-in with the synthetic seed account, all four page families, `/admin/access`, stylesheet and sign-out passed. Browser route assignment changed one synthetic origin to the seeded hub and displayed the result. Existing operations portal remained authenticated after root cookie migration.
- PR #24 is merged: administrators can cancel a wholly uncollected offer run and reopen its parcel demands without changing locker custody.
- Migration 022 adds a historical `RELEASED` manifest-item state. This branch lets an administrator release one `EXPECTED` parcel from a partly collected run only after recording a site-inspection or locker-inventory reference and reason. The service checks the exact run, parcel, demand, allocation, scan and receiving state, increments run/manifest revision, reopens only that demand, and never changes package custody or version.
- Driver and hub receiving counts exclude released items; the old run can receive and close its collected parcels without a false SHORT. The admin recovery tab shows per-parcel eligibility and evidence inputs. These references are operator assertions, not authenticated device telemetry.

## Next exact actions

1. Review and merge PR #31. ADM-01/02 are a bounded foundation, not full admin production readiness.
2. ADM-03 customer/driver administration can be developed on a separate follow-up branch while PR #31 is reviewed, then rebased or retargeted after merge. It needs scoped lists, restrictions and eligibility checks; existing role/service fences must stay intact.
3. ADM-04 partner entities/grants and ADM-05 sites require guarded, additive PostgreSQL migrations, backfills and synthetic fixtures; do not fabricate legal partner ownership. Continue ADM-06–ADM-18 according to `docs/admin/BACKLOG.md`.
- `npm run check` passed: 91 canonical OpenAPI operations, TypeScript and 9 Node tests. `npm run build` passed both web apps. Isolated `npm run test-e2e` passed all 5 browser tests, including the new partial-recovery UI test.
- Disposable `npm run test-db` passed: partial release role/version/evidence/idempotency fences, collected-parcel custody preservation, hub receiving count and no false SHORT, a true SHORT for an unreceived collected parcel, and independent new-run reassignment. No physical hardware was tested.
- Route matching is a straight-line eligibility screen, not drive-time routing or automatic push notification. Production site coordinates require administrator configuration. A driver must consent to location sharing; stale/missing location receives no production offers.
- Real origin terminal pairing, authenticated physical door evidence, delayed/ambiguous evidence reconciliation, commissioning and supervised hardware pilot remain required. Live local Authorize.net sandbox capture previously returned a provider error; LOCAL_TEST payment is validated.

## Delivery baseline and gates

- Origin deposit/recipient pickup demo outcomes are synthetic assumptions, not physical evidence. Driver pickup, hub receiving/dispatch, route assignment and uncollected-run recovery have prior test coverage.
- Physical locker commissioning, destination deposit, verified recipient pickup and payout activation retain separate real-evidence gates. Hardware ambiguity never becomes automatic completion.
- Local dev: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`; customer `http://localhost:5173`, operations `http://localhost:5174`, API/admin `http://localhost:8000/admin`.
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
