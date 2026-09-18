# Codex development entry point

Revision 4. Start with [the kickoff prompt](CODEX_KICKOFF_PROMPT.md), follow [AGENTS.md](AGENTS.md), and use [the execution contract](docs/13_IMPLEMENTATION_EXECUTION.md) for milestone outputs and completion evidence. Its M0 database/contract checks refine the PR sequence below.

## Task

Build a new ZPX Delivery Network for approximately 20 Austin lockers, one sorting hub and assigned drivers. Preserve apartment carrier deposits and resident pickups at participating existing sites. Support public shopping-center/convenience-store sites without a preloaded resident directory. Each driver scans EACH package at pickup/loading/delivery. Hub receiving independently scans EACH parcel. Route QR or tote scan never substitutes for this.

The four supplied repositories are reference code. Do not implement the whole business platform inside zpxadmin-php8x or zpxapi_tp8. Create a separate checkout/project for delivery. Prepare proposed legacy adapter changes as isolated patches/branches, but do not merge or deploy them without normal release review. The current request authorizes specification preparation; a later development session must follow its own authorization for repository writes.

## Proposed target layout (new paths, not existing source)

| Path | Responsibility |
|---|---|
| apps/api | ThinkPHP 8 modular backend; new delivery DB; migrations and integration tests |
| apps/customer-web | React/TypeScript customer shipping, labels and tracking |
| apps/operations-web | React/TypeScript hub workbench and administrative/dispatch views |
| apps/mobile | New React Native/TypeScript app with customer and assigned-driver role navigation |
| apps/terminal | C# WinForms host and new delivery module; compatibility build lane for existing terminals |
| packages/contracts | OpenAPI-generated request/response types and event fixtures |
| adapters/legacy | Narrow apartment account-link/inventory configuration bridge; no business-order writes from new API |
| simulators/locker | Deterministic door/controller and network fault simulator |
| tests/e2e | Cross-application acceptance scenarios |
| deployment | Local/dev/staging configuration, release and rollback runbooks |

These are design choices based on existing PHP/React/C# skills, not claims that new projects already exist. Resolve supported runtime/dependency patch versions in PR 0, record exact lockfiles, and do not copy the legacy dependency tree wholesale. Existing terminal targets .NET Framework 4.5.2; reproducing its compatibility build is distinct from approving that runtime for new production deployments. Keep the protocol adapter portable enough for a supported-OS terminal build. Mobile first targets supervised Android driver devices, with iOS customer/driver build and device tests before declaring iOS supported.

## Non-negotiable engineering rules

1. New platform owns new customers, shipments, packages, labels, routes, custody, billing and operations records. No direct writes to apartment member/o_store records from delivery API.
2. At shared sites every physical compartment has one owner: LEGACY, DELIVERY or FROZEN. Phase 1 uses fixed partitioning with versioned ownership; do not implement a shared free pool using two databases.
3. All serial door commands pass through ONE terminal command gate/process. Scan ID, SI and printed route sticker are not access credentials.
4. Exactly-once business effects are achieved with durable idempotency, uniqueness and retries; do not promise exactly-once physical actuation on hardware lacking command IDs.
5. Unknown/timeout after actuation means reconcile, not free the compartment or blindly open again.
6. Backend state transitions require validated facts from assigned actors and enrolled terminal events. Client-supplied `authorized=true` or `confirmed=true` is never evidence.
7. One primary label per parcel; one parcel per compartment for new delivery operations in Phase 1; stable SI through hub sorting and route changes.
8. Customer registration/recipient claim verification is separate from apartment resident lookup. Link identities only after proofs described in doc 03.
9. Every scan and rejected action is auditable; every physical custody transfer is append-only. Notifications follow commit through outbox.
10. Do not reuse legacy MD5/client password hashing, broad token acceptance or GET mutation semantics in the new API.

## Start implementing

PR 0: reproduce reference terminal build or document missing binaries/projects, select build matrix, generate new app skeletons, schema fixtures, protocol simulator and CI. Do not wait for real lockers to build backend/UI.

PR 1: create new DB from both SQL files, auth/RBAC, identity, locations, immutable hardware mapping, ownership projection, shipment/package/SI and label service. Execute MySQL constraints/concurrency tests; revise draft migrations as necessary before merge.

PR 2: implement label PDF and scan resolver; customer website and mobile shipment draft → quote → payment sandbox → SI/label; printerless locations must explicitly advertise printing capability.

PR 3: origin deposit through terminal simulator → assigned-driver scan pickup → hub independent receiving; durable journal, server reconciliation and audit trail must work before adding routing features.

PR 4–8: follow detailed backlog. Never defer legacy command-gate and ownership enforcement until after real shared-locker testing.

## First end-to-end result

Create ten parcels for two destination lockers, print/scan labels, collect from origin(s), receive and stage at hub, load ten distinct scans onto one route, deliver six to stop 1 and four to stop 2, then recipient retrieval. Also run wrong locker, 9/10 load, duplicate scan, revoked label, hub shortage, network timeout and legacy reset scenarios. All ten parcels must reconcile to exactly one custodian each.
