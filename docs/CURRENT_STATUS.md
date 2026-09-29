# Current Development Status

> Shared handoff for Codex, Qwen Code and human developers. Hard limit: 200 lines.

## Objective and baseline

- Date: 2026-09-29. Agent: Codex. Branch: `codex/host-postgres`, based on PR #40 branch. Local Git is the source of truth.
- Current objective: move the local development PostgreSQL database from Docker-only storage to a host-managed PostgreSQL 17 service, preserving data and allowing DBeaver access without Docker. Implementation is in stacked PR #41, based on PR #40.
- Continue the admin backlog in `docs/admin/BACKLOG.md` using `zpxadmin-tp8` as the UI reference. The admin site lives in `apps/admin`, mounted by the ThinkPHP API with the same PostgreSQL database and identity.
- PR #26 and the admin stack #31–#38 are merged into `main`. PR #40 contains this branch; `checks`, `local-stack` and `browser` GitHub jobs passed. It awaits review/merge.
- Preserve the untracked human notes `docs/PACKAGE_TRACKING_CUSTODY_PLAN.md` and `docs/ZPX_DELIVERY_NEXT_DEVELOPMENT_HANDOFF_09_22.md`; do not stage them.
- Host DB cutover completed locally: Homebrew PostgreSQL 17.11 runs at macOS login from `/opt/homebrew/var/postgresql@17`; the restored `zpx_delivery_dev` database matches all 95 Docker-source tables by exact row count. `.env.dev` now has `DB_HOST=host.docker.internal`; PostgreSQL listens on localhost:5432 and DBeaver can use `zpx_runtime` with the existing private `DB_PASSWORD`. The original Docker volume is stopped and retained, not deleted. Private 0600 backups are under `.local/postgres-backups/` (including `docker-cutover.dump` and role export). Host PostgreSQL works with Colima stopped; web/API containers still need Colima.

## Implemented baseline

- Admin foundation: scoped network-admin access, session/CSRF-protected ThinkPHP pages, dashboard, shipments, pending-driver decisions, pickup routing and recovery.
- Customers and drivers: audited customer shipping restrictions, driver suspension/reactivation, scoped detail and cursor-paged history, shipment and payment context, driver application, assignments, scans and earnings records.
- Partner/site registry: draft partner creation, sites separate from lockers, owner/host relationships, contacts, contract dates, addresses, location configuration and guarded status changes.
- Lockers: body and box hierarchy, position management, occupancy and package lifecycle views, claim/custody evidence, versioned box/body models, ready-layout publication and frozen instantiation at inactive locations. This branch adds explicit SMALL/MEDIUM/LARGE/XLARGE box classes (existing models stay UNCLASSIFIED), ordered site-bound body-set assembly, automatic frozen box generation in one transaction, and stale-form replay protection. Manual draft tools remain under an advanced disclosure.
- Admin navigation now uses a static capability-filtered registry for implemented pages with GLOBAL_CONSOLE menu groups and native collapsible sections. Unimplemented routes remain hidden.
- Delivery: PR #26 added final-destination deposit preparation and signed device commands. Synthetic deposit/pickup outcomes remain assumptions, not physical locker evidence.

## Validation and merge resolution

- Each updated admin PR passed GitHub `checks`, `local-stack` and `browser` jobs before merge. PR #26's checks also passed; all were green by the final merge audit.
- Local `npm run check` and `npm run build` passed after resolving each merge conflict. The final combined contract has 107 validated OpenAPI operations and the integration schema assertion expects 95 tables. CI local-stack exercised disposable PostgreSQL integration; the temporary merge checkout cannot run `scripts/dev.py test-db` directly because that script requires the external-volume checkout path.
- Conflicts from independently added contract operations and tables were reconciled in `tests/contracts/validation.test.mjs`, `apps/api/tests/integration.php`, and the generated contract. The schema additions are additive. No real locker was tested in this merge work.
- This branch passed `npm run check`, `npm run build`, PHP lint and disposable `npm run test-db` with size validation, ordered generation, atomic rollback, stale submission, site/active guards, form route and HTML rendering. The local dev server was rebuilt and migration 029 applied; the `/admin/lockers/21` UI and grouped sidebar were visually checked. No physical locker was used.
- PR #40 passed both GitHub runs of `checks`, `local-stack` and `browser` after the implementation and handoff commits.
- Host DB cutover: host TCP runtime login, container-to-host login, migration no-op, full local smoke, and localhost login with Colima stopped passed. The disposable `npm run test-db` suite and 5 browser tests passed. Initial test-db startup raced PostgreSQL health; the wait was fixed and the failed disposable stack removed. Browser cleanup was verified after narrowing test services. Host data was never used by tests.
- PR #41's first `checks` run failed only because `tests/test_dev_setup.py` still expected the old Compose call sequence. The assertion now covers the readiness wait, profile-aware cleanup and restoration of `DB_HOST`; all 10 local Python unit tests pass. The updated PR passed `checks`, `local-stack` and `browser` on both GitHub runs.

## Exact continuation point

1. Review/merge PR #41 after PR #40. Retarget #41 to `main` once #40 merges. Keep the old Docker volume until a routine host backup policy is in place. DBeaver itself has not been tested; the equivalent TCP login succeeded from macOS while Colima was stopped.
2. Review and merge PR #40. Then start from updated `main` and read `docs/admin/GLOBAL_CONSOLE.md` and `docs/admin/BACKLOG.md`; the global menu catalog is broader than the implemented routes. Next bounded slice: safe draft edit/deactivate controls, hardware commissioning and telemetry reconciliation without allowing admin occupancy edits to bypass custody.
3. Remaining admin work includes cross-entity search, shared contact directory and coverage, contract documents/effective periods, locker owner/operator relationships, commercial terms, finance reconciliation and payouts, delegated partner grants, and calendar/access policy. Overdue amounts remain draft configuration.
4. Physical locker commissioning, authenticated door evidence, recipient pickup and payout activation require their own verification gates. Do not treat synthetic outcomes as physical evidence.

Local development: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`; customer `http://localhost:5173`, operations `http://localhost:5174`, API/admin `http://localhost:8000/admin`, PostgreSQL for DBeaver `127.0.0.1:5432`.
