# ZPX Delivery

Initial development foundation for the Austin hub-and-spoke delivery network.

**Current status:** PostgreSQL + ThinkPHP + React are running on the external SSD.
Local identity, customer sending/receiving, operator shipment inspection, test quotes,
local/sandbox checkout and printable test labels are implemented. See [current progress](docs/PROGRESS.md)
and [shipping verification](docs/verification/P2.1-shipping.md). Authorize.net sandbox authentication, hosted token issuance, test capture and label generation passed.
See [provider evidence](docs/verification/P2.2-providers.md). SMTP authentication and one
authorized test email passed; automatic mail delivery remains opt-in. Driver/hub handoffs,
native apps and physical terminal control remain incomplete.

**Database:** PostgreSQL 17. Canonical migrations live in `apps/api/database/migrations`,
with checksum tracking and separate migration/runtime credentials. Earlier MySQL
handoff SQL remains historical input; do not execute it for this application.

## Run on your M4 Mac

Keep your checkout on the external APFS SSD:

`/Volumes/<your SSD name>/Developer/ZPX_Delivery`

Follow [Mac local setup](docs/LOCAL_DEVELOPMENT_MAC.md) for cloning, prerequisites and troubleshooting. The SSD name is a parameter; no script formats or erases the disk. The shipping increment is on `feature/P2.1-shipping`, stacked on the identity and database foundation branches.

From your checkout with Colima or Docker Desktop running:

```bash
python3 scripts/dev.py up
```

Customer: http://localhost:5173 · Operations: http://localhost:5174

Docker/MySQL startup and service readiness passed in Linux GitHub Actions; the physical M4 run remains pending. See [actual evidence and limits](docs/verification/M0-foundation.md). The current pages implement the local flows described in the shipping verification notes; they do not claim a completed physical delivery.

## Project map

The [admin development handoff](docs/admin/README.md) specifies the ThinkPHP-rendered admin site, partner/site/locker management, access control, revenue-sharing records and sequenced implementation tasks.

| Path | Responsibility |
|---|---|
| apps/api | ThinkPHP identity and local shipping services |
| apps/customer-web | Customer account, sending/receiving and local shipping |
| apps/operations-web | Staff accounts and scoped shipment inspection |
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

`npm run test-db` verifies domain, security, and concurrency behavior in disposable PostgreSQL containers. `npm run test-smoke` checks readiness and proxy behavior on the running local stack. `npm run test-e2e` creates and removes a separate seeded Docker stack, then runs headless browser checks for inbound pickup, hub receiving and dispatch, outbound loading and arrival, admin approvals, and customer/recipient shipment history. It requires Docker and a Playwright Chromium browser (`npx playwright install chromium` on Linux; local Chrome is used on macOS).

The development seed also includes two separate historical outcomes: an assumed destination deposit and an assumed recipient pickup. Their shipment references end in `DEPOSIT-DEMO` and `PICKUP-DEMO`; their events identify them as synthetic assumptions with no device evidence. Rerunning `python3 scripts/dev.py seed` adds them to an existing local fixture without changing its active parcels or credentials. This supports portal development; locker commands and physical verification are still outside this repository's implemented flow.

## Design and collaboration

Start with [the handoff](docs/handoff/README.md), [execution contract](docs/handoff/docs/13_IMPLEMENTATION_EXECUTION.md), [development plan](docs/DEVELOPMENT_PLAN.md), [documentation workflow](docs/DOCUMENTATION_GUIDE.md) and [CONTRIBUTING](CONTRIBUTING.md).

The canonical API remains [OpenAPI](docs/handoff/contracts/openapi.json). The handoff was moved in a dedicated commit; no competing editable copy is maintained. The older ZPX_Delivery_Codex_Implementation_Package remains future-routing reference. Root ZIP files remain historical inputs.

Use feature branches and pull requests. Keep code, tests, affected design and milestone evidence together. GitHub Issues/Projects owns task status. Never commit credentials, real customer records or production configuration. The runtime database role is restricted separately from the migrator; production deployment hardening remains outstanding.

See [remaining Phase 1 work and owner inputs](docs/PHASE1_REMAINING_WORK.md) for what comes next. API contract edits require `npm run contracts:generate`; `npm run check` rejects invalid contracts or stale generated definitions. Generated types do not imply the business API endpoints are implemented.

## Local shipping preview

Verified customer accounts can send **and** receive. Open the customer portal to
create a draft, request a test quote, simulate checkout, and print a watermarked
test label. Use Receiving to claim a shipment reference with matching verified
contacts and a fresh code from `python3 scripts/dev.py inbox`. Operators can inspect
the queue within their staff assignments. All sites, prices and payments are synthetic;
no drop-off or charge occurs. See [shipping evidence and remaining scope](docs/verification/P2.1-shipping.md).
