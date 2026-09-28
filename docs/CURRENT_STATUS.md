# Current Development Status

> Shared handoff for Codex, Qwen Code and human developers. Hard limit: 200 lines.

## Objective and baseline

- Date: 2026-09-28. Agent: Codex. Current branch: `codex/admin-locker-models`, implementation commit `83bca38`, based on `codex/admin-locker-occupancy` (`a475223`). PR #38 is open against PR #37's branch; PR #37 is open against PR #36's branch; PR #36 targets PR #35's branch. Merge the stack in order.
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

## Current admin 360 slice

- Shipment lifecycle HTML detail links from list/customer/driver and reads authoritative package/custody/scan/receiving/exception timeline, quotes, payment attempts/refunds, run assignments, locker sessions and pickup grants. Synthetic assumptions and missing payment evidence are labeled. Customer detail shows recorded payment history/totals; driver detail shows application/approval, offers, assignments, scans and earnings/settlement ledger without implying a cash wallet. Recent sections link to cursor-paged customer/driver histories.
- Separate Sites and Lockers navigation. Locker detail includes recorded device, board, ownership manifest, body/box module and compartment inventory. Additive migrations 025–026 add explicit site property owner and host partner links, contract reference/start/end dates, and encrypted site contacts/assignments. Site partner role classification now includes SITE_OWNER. Changes to draft relationships are versioned and audited; contact changes are audited; no contract/hardware activation is implied.
- Disposable `npm run test-db` passed, including new scope, cross-network owner/host, encrypted contact, history pagination and HTML render checks. `npm run check` passed 105 OpenAPI operations and nine Node tests; `npm run build` passed. Local migrations 025–026 applied with existing dev data preserved. Browser verified customer/shipment lifecycle, site and locker inventory, driver earnings/scans and paged history routes. PR #36 is open on top of #35; all browser, local-stack and general CI checks passed on implementation. Preserve the two untracked human notes.

## Locker structure and occupancy slice

- Compared TP8 cabinet/body/model/box setup and locker status screens with this repository. The admin now shows body/module/box hierarchy and row/column positions, and draft box creation records unique positions within a body. Existing hardware address mapping and commissioning remain separate.
- Locker detail reports available, reserved, occupied, review and unavailable box counts from delivery ownership, mapped board/door, current claims, custody and session state. It separately counts locker-custody packages without a box claim. A cursor-paged package table shows origin/destination association, lifecycle phase (initial outbound, hub staging, final pickup, etc.), custody and box-claim evidence, and marks synthetic assumptions.
- Tests cover duplicate body position, unclaimed synthetic locker custody, available/reserved/occupied/review transitions, invalid cursor and rendered HTML. `npm run check` (105 operations, nine Node tests), `npm run build` and final disposable `npm run test-db` passed. Local API/admin image rebuilt and browser verified locker 17 and locker 4, including synthetic final-pickup and completed pickup rows. No new migration. No hardware tested.

## Reusable locker models slice

- New stacked branch adds migration 027 with network-scoped versioned box/body models, layout slots, immutable publication and optional model references on bodies/boxes. Migration 028 permits audited removal of draft positions while ready layouts remain locked. `LockerModels` provides model creation, layout editing by add/remove, ready publication and frozen structure instantiation at inactive locker locations.
- PHP lint, final disposable `npm run test-db`, `npm run check` (105 operations, nine Node tests) and `npm run build` passed. Migrations 027–028 applied locally; the API/admin image was rebuilt. Browser created a synthetic box/body model and two positions, marked the layout ready, instantiated it at inactive locker 21 and verified two new frozen/unmapped boxes. Ready layout hides draft editing. PR #37 CI is green. No real device was tested.
- Exact continuation: check PR #38 CI and fix any failures. Human notes remain untracked.

## Next exact actions

1. Check PR #38 CI and fix any failures. Do not stage the two human notes.
2. Next locker work: safe draft edit/deactivate controls, hardware commissioning and actual telemetry reconciliation. Draft inventory is not a live door. Do not make admin occupancy edits that bypass custody.
3. Other admin backlog: searchable cross-entity history, shared contact directory and coverage, contract documents/effective periods, locker owner/operator relationships, commercial terms, finance reconciliation/payout evidence, delegated partner grants and calendar/access policy. Overdue amounts remain draft configuration. DBeaver PostgreSQL mapping remains `127.0.0.1:5432`.

## Delivery baseline and gates

- Origin deposit/recipient pickup demo outcomes are synthetic assumptions, not physical evidence. Driver pickup, hub receiving/dispatch, route assignment and uncollected-run recovery have prior test coverage.
- Physical locker commissioning, destination deposit, verified recipient pickup and payout activation retain separate real-evidence gates. Hardware ambiguity never becomes automatic completion.
- Local dev: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`; customer `http://localhost:5173`, operations `http://localhost:5174`, API/admin `http://localhost:8000/admin`.
