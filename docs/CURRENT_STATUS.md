# Current Development Status

> Shared handoff for Codex, Qwen Code and human developers. Hard limit: 200 lines.

## Objective and baseline

- Date: 2026-09-28. Agent: Codex. Current branch: `codex/admin-sites-inventory`, implementation commit `d644e91`, based on `codex/admin-partner-registry` (`7e33369`). PR #35 targets PR #34's branch; PRs #31–#34 remain stacked.
- Implement the admin backlog in `docs/admin/BACKLOG.md`, using `zpxadmin-tp8` as a visual/structural reference. Richard explicitly requires the new site under `apps/admin`; it remains mounted by the existing ThinkPHP API and uses the same PostgreSQL and identity.
- PR #31 contains ADM-01/02; PR #32 contains ADM-03 restriction/status controls; PR #33 contains ADM-03 read-only details; PR #34 contains the first ADM-04 partner registry/draft creation. This branch implements an ADM-05/06 and people-address partial slice. Search/filtering, support, fleet/commercial, delegated partner grants and resource relationships remain.
- Preserve the untracked human notes `docs/PACKAGE_TRACKING_CUSTODY_PLAN.md` and `docs/ZPX_DELIVERY_NEXT_DEVELOPMENT_HANDOFF_09_22.md`; do not stage them.

## Implemented on this branch

- `apps/admin` contains ThinkPHP routes, access resolver, page controller, view templates and scoped CSS. Network admins have dashboard, shipment list, pending-driver decisions, pickup routes and recovery pages, using existing services. Non-admins receive explicit denial.
- `GET /api/delivery/v1/admin/access` returns deny-by-default capability hints and network/location scopes from current grants. No new role grants or authorization broadening. Contract and generated TS types updated.
- Browser session cookie changes from API-only path to root so `/admin` can share identity; legacy path is explicitly expired on sign-in/logout. Admin login has pre-login CSRF cookie, Origin check and existing identity rate limiting. Existing JSON login/logout behavior is retained.
- `topthink/think-view` is locked in Composer. API Docker image includes sibling `apps/admin` while keeping one application/database.
- No new database migration was needed for ADM-01/02. The local development database had four pending existing migrations, including `021_pickup_routing.sql`; the migration image was rebuilt and they were applied. PostgreSQL remains exposed only on `127.0.0.1:5432` for DBeaver.
- ADM-03 first slice: migration 022 adds audited customer SHIPPING restrictions and driver optimistic version. Admin pages and JSON endpoints list scoped customers/drivers and apply reasoned restriction/revocation or suspension/reactivation. Shipping checks restrictions only for new shipments. Suspension cancels open offers and takes driver offline; assigned runs and parcel custody remain. Offer actions recheck active driver status under lock. Reactivation does not restore availability.
- ADM-03 detail slice: scoped JSON and HTML detail routes show masked customer contacts and ten recent linked shipments, or driver qualification/availability and up to ten recent runs with active assignments first. Both show redacted recent admin action names/times. No new schema or contact reveal.
- ADM-04 first slice: migration 023 adds `network_partners` and typed classification roles with network-consistent keys. Existing networks receive a neutral internal identity; fresh synthetic networks seed one. Admin list/detail/create forms and JSON routes create audited EXTERNAL drafts only. Registry roles confer no resource access, activation, legal ownership or delegated grants.

## Validation

- `npm run check` passed (95 validated OpenAPI operations, TypeScript, 9 Node tests). `npm run build` passed both React apps. Composer validation, PHP syntax checks and template escaping/rendering passed.
- Disposable `npm run test-db` passed, including network/location/no-admin scopes, anonymous redirect, denial, missing-CSRF refusal and preexisting driver/workflow regressions. No physical hardware was tested.
- Live local admin sign-in with the synthetic seed account, all four page families, `/admin/access`, stylesheet and sign-out passed. Browser route assignment changed one synthetic origin to the seeded hub and displayed the result. Existing operations portal remained authenticated after root cookie migration.
- ADM-03 `npm run check` passed (100 OpenAPI operations, TypeScript, 9 Node tests); `npm run build` passed both React apps; PHP syntax checks passed. Disposable `npm run test-db` passed with customer/driver scope, restriction, idempotency, stale version, suspended offers and active-run preservation.
- Local development migration 022 applied. With the synthetic admin, `/admin/customers` rendered masked contacts and restriction/restore forms completed; `/admin/drivers` rendered statuses and active-run counts. PostgreSQL stayed available to DBeaver at `127.0.0.1:5432` and API/admin at `127.0.0.1:8000`.
- Detail slice: `npm run check` passed (102 OpenAPI operations, TypeScript, 9 Node tests); `npm run build` passed both React apps; PHP syntax and disposable `npm run test-db` passed. Local browser rendered customer shipment/action detail and driver assignment/status detail. Foreign resources returned 404 and audit reasons stayed redacted.
- Partner slice: `npm run check` passed (105 OpenAPI operations, TypeScript, 9 Node tests); `npm run build`, PHP lint and the final disposable `npm run test-db` passed. Local migration 023 applied; browser created a synthetic draft partner and opened its detail. Final API image rebuilt and local page rechecked. DBeaver remains at `127.0.0.1:5432`; admin at `127.0.0.1:8000`.

## Next exact actions

1. Migration 024 adds draft installation sites and backfills existing locations without changing IDs; body/box modules and address-book metadata. Admin pages create/edit draft sites, create inactive locker locations, inspect and group all boxes, add frozen draft boxes, edit site/location overdue draft amounts, deactivate active locations, and edit customer names/multiple encrypted addresses. Browser verified synthetic site, location, body, module and frozen box creation on local dev. No site or hardware activation is inferred.
2. Validation: disposable `npm run test-db` passed with new admin-site/inventory/address tests; `npm run check`, `npm run build`, PHP lint and `git diff --check` passed. Local migration 024 applied and admin API rebuilt; DBeaver PostgreSQL mapping remains `127.0.0.1:5432`. PR #35 is open; check CI and merge stacked PRs in order. Preserve the two untracked human notes.
3. Remaining: active site/location commissioning and reactivation, versioned hardware catalog and real device mapping/evidence, contact/admin APIs, delegated partner grants, calendar/access policy and the broader ADM backlog. Overdue amounts are draft configuration only; no real charge is collected.

## Delivery baseline and gates

- Origin deposit/recipient pickup demo outcomes are synthetic assumptions, not physical evidence. Driver pickup, hub receiving/dispatch, route assignment and uncollected-run recovery have prior test coverage.
- Physical locker commissioning, destination deposit, verified recipient pickup and payout activation retain separate real-evidence gates. Hardware ambiguity never becomes automatic completion.
- Local dev: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`; customer `http://localhost:5173`, operations `http://localhost:5174`, API/admin `http://localhost:8000/admin`.
