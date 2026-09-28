# Current Development Status

> Shared handoff for Codex, Qwen Code and human developers. Hard limit: 200 lines.

## Objective and baseline

- Date: 2026-09-28. Agent: Codex. Branch: `codex/admin-foundation`, commit `f34eb71`, PR #31 open against `main` (based on `e5658f8`).
- Implement the admin backlog in `docs/admin/BACKLOG.md`, using `zpxadmin-tp8` as a visual/structural reference. Richard explicitly requires the new site under `apps/admin`; it remains mounted by the existing ThinkPHP API and uses the same PostgreSQL and identity.
- This branch is the bounded ADM-01/ADM-02 foundation; the full ADM-03–ADM-18 backlog remains. No schema migration is needed for these two tasks. Add partner/site tables only in their owning tasks after verifying relationships.
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

## Next exact actions

1. Review and merge PR #31. ADM-01/02 are a bounded foundation, not full admin production readiness.
2. ADM-03 customer/driver administration can be developed on a separate follow-up branch while PR #31 is reviewed, then rebased or retargeted after merge. It needs scoped lists, restrictions and eligibility checks; existing role/service fences must stay intact.
3. ADM-04 partner entities/grants and ADM-05 sites require guarded, additive PostgreSQL migrations, backfills and synthetic fixtures; do not fabricate legal partner ownership. Continue ADM-06–ADM-18 according to `docs/admin/BACKLOG.md`.

## Delivery baseline and gates

- Origin deposit/recipient pickup demo outcomes are synthetic assumptions, not physical evidence. Driver pickup, hub receiving/dispatch, route assignment and uncollected-run recovery have prior test coverage.
- Physical locker commissioning, destination deposit, verified recipient pickup and payout activation retain separate real-evidence gates. Hardware ambiguity never becomes automatic completion.
- Local dev: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`; customer `http://localhost:5173`, operations `http://localhost:5174`, API/admin `http://localhost:8000/admin`.
