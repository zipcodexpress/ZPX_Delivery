# ZPX Delivery

Initial development foundation for the Austin hub-and-spoke delivery network.

**Current database: PostgreSQL.** See [backend and database architecture](docs/BACKEND_DATABASE_ARCHITECTURE.md), [database decision](docs/decisions/0003-postgresql-backend.md) and [P1.1 verification](docs/verification/P1.1-postgresql.md). Use branch `feature/P1.1-postgresql-foundation` for this increment; earlier MySQL foundation notes below are historical. Canonical migrations live in `apps/api/database/migrations`, with checksum tracking and separate migration/runtime credentials. PostgreSQL startup uses a new volume and leaves any previous MySQL volume intact.

**Status:** P0.1/P0.2 in progress. Customer and operations web shells, local service configuration, API health bootstrap and a tested synthetic locker simulator are implemented. Shipping, authentication, native apps and physical terminal control are not yet implemented.

## Run on your M4 Mac

Keep your checkout on the external APFS SSD:

`/Volumes/<your SSD name>/Developer/ZPX_Delivery`

Follow [Mac local setup](docs/LOCAL_DEVELOPMENT_MAC.md) for cloning, prerequisites and troubleshooting. The SSD name is a parameter; no script formats or erases the disk. While this PR is unmerged, use branch `feature/P0.1-m4-foundation`.

From your checkout with Docker Desktop running:

```bash
python3 scripts/dev.py up
```

Customer: http://localhost:5173 · Operations: http://localhost:5174

Docker/MySQL startup and service readiness passed in Linux GitHub Actions; the physical M4 run remains pending. See [actual evidence and limits](docs/verification/M0-foundation.md). The current pages report environment readiness; they are not simulated completed shipping products.

## Project map

| Path | Responsibility |
|---|---|
| apps/api | Local PHP health bootstrap; ThinkPHP business implementation pending |
| apps/customer-web | React customer shell |
| apps/operations-web | React hub/admin shell |
| packages/ui | Shared web foundation components |
| packages/contracts | Generated shared API types and contract compilation gate |
| simulators/locker | Persistent authenticated normalized door-event simulator |
| scripts | Mac external-SSD checkout and local development commands |
| deployment | Local-only Docker Compose services |
| docs/handoff | Canonical Phase 1 revision 4 handoff, draft SQL and OpenAPI |
| docs/DEVELOPMENT_PLAN.md | Cross-application milestones and next sprint |
| docs/PROGRESS.md | Current stage and session handoff |

Native mobile and a new kiosk terminal are still required. Android is the preferred terminal direction; the old Windows terminal is reference-only, per [decision 0002](docs/decisions/0002-new-terminal-platform.md). Neither application has been scaffolded or built in this increment.

## Checks

```bash
npm ci --ignore-scripts
npm run check
npm run build
python3 -m unittest discover -s tests -p 'test_*.py'
python3 docs/handoff/tests/validate_handoff.py
```

`npm run test-db` and `npm run test-e2e` require the local Docker stack. They currently verify draft-table initialization and foundation HTTP readiness, respectively. They do not claim transactional domain coverage or the complete parcel journey.

## Design and collaboration

Start with [the handoff](docs/handoff/README.md), [execution contract](docs/handoff/docs/13_IMPLEMENTATION_EXECUTION.md), [development plan](docs/DEVELOPMENT_PLAN.md), [documentation workflow](docs/DOCUMENTATION_GUIDE.md) and [CONTRIBUTING](CONTRIBUTING.md).

The canonical API remains [OpenAPI](docs/handoff/contracts/openapi.json). The handoff was moved in a dedicated commit; no competing editable copy is maintained. The older ZPX_Delivery_Codex_Implementation_Package remains future-routing reference. Root ZIP files remain historical inputs.

Use feature branches and pull requests. Keep code, tests, affected design and milestone evidence together. GitHub Issues/Projects owns task status. Never commit credentials, real customer records or production configuration. The development Compose database user is not the final restricted production role.

See [remaining Phase 1 work and owner inputs](docs/PHASE1_REMAINING_WORK.md) for what comes next. API contract edits require `npm run contracts:generate`; `npm run check` rejects invalid contracts or stale generated definitions. Generated types do not imply the business API endpoints are implemented.
