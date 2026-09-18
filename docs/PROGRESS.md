# Development progress

Purpose: milestone/session summary; task status belongs in GitHub Issues/Projects.
Audience: ZPX owner, developers and Codex.
Status: Development started; M0 incomplete. Owner: unassigned. Last reviewed: 2026-09-18.

## Current stage

Current development: [P1.1 PostgreSQL foundation issue 5](https://github.com/zipcodexpress/ZPX_Delivery/issues/5), branch `feature/P1.1-postgresql-foundation`, based on PR 4. Richard selected PostgreSQL. Canonical 73-table migrations, migration tracking, restricted runtime role, integrity/history rules and modular PHP infrastructure are implemented. See [backend/database architecture](BACKEND_DATABASE_ARCHITECTURE.md) and [current verification](verification/P1.1-postgresql.md). Previous MySQL results below are historical, not PostgreSQL evidence. Full P1.1 remains in progress.

[P0.1/P0.2 foundation issue 3](https://github.com/zipcodexpress/ZPX_Delivery/issues/3): IN_PROGRESS.
Planning remains in [PR 2](https://github.com/zipcodexpress/ZPX_Delivery/pull/2).
Implementation branch: feature/P0.1-m4-foundation, stacked on the planning branch.
Implementation review: [draft PR 4](https://github.com/zipcodexpress/ZPX_Delivery/pull/4). Code and staged notes are committed; no merge or deployment has occurred.

## Implemented this increment

- Moved the authoritative Phase 1 handoff to docs/handoff without changing original ZIP archives.
- Created an application workspace, locked Node/web dependencies and added customer/operations React shells.
- Added local-only API health bootstrap, MySQL draft-schema initialization and Docker configuration.
- Implemented synthetic door command/evidence service with durable state, authentication, idempotency, ownership checks and fault scenarios.
- Added M4 external APFS checkout validation and local startup scripts that preserve existing configuration/data.
- Added automated tests and CI; actual results are in [M0 verification](verification/M0-foundation.md).
- Added external OpenAPI schema/reference validation and generated TypeScript definitions for the 54-operation canonical API; CI checks consistency and compiles the definitions. Four additional tests reject malformed contracts. See [contract verification](verification/M0-contracts.md).

## Remaining work and owner inputs

See [Phase 1 remaining work](PHASE1_REMAINING_WORK.md) for the application-by-application status, dependency order and information requested from Richard. No user decision blocks contract/migration/software work. Hardware commissioning does require terminal/controller details, missing source, deployed-writer inventory and selected test sites. Local setup requires the actual external SSD mount path and an M4 test run.

User local preference: M4 Mac, external SSD. The September 16 SSD conversation proposed `Development`, mounted at `/Volumes/Development`, formatted APFS with GUID Partition Map. Completion was not confirmed in the retrieved conversation. Planned checkout: `/Volumes/Development/Developer/ZPX_Delivery`; the setup script will validate the actual volume. No files have been written to the user's Mac by this cloud session. Native shell Git authentication here remains unavailable; connector commits preserve the work remotely.

Richard clarified that the entire old terminal is reference-only for locker/API behavior and door protocols. Build a new terminal, with Android as the preferred direction. Zippora.exe, ZipporaService, Windows screenshots and legacy build dependencies are not requested. [Decision 0002](decisions/0002-new-terminal-platform.md) supersedes previous Windows migration/testing assumptions; hardware adapter and physical coexistence validation remain necessary.

## Current evidence

Latest increment: [CI run 35403370288](https://github.com/zipcodexpress/ZPX_Delivery/actions/runs/35403370288) passed code and Docker jobs for commit `3847544`. Contract/type checks, 9 Node tests, 3 Python tests, web builds, database initialization and HTTP readiness passed. Remaining-work and owner-input notes are committed alongside code.

Node/TypeScript check, both web production builds, 5 simulator tests, 3 setup tests and static handoff validation pass. [GitHub Actions run 35401756927](https://github.com/zipcodexpress/ZPX_Delivery/actions/runs/35401756927) also passed PHP lint, Docker startup, initialization of all 73 draft tables and service/proxy HTTP readiness. Physical M4 and locker validation remain pending. The PHP endpoint is health-only, not a completed business API.

## Next dependencies

1. Get the first M4 startup results and lock tested image digests; Linux Docker/MySQL CI now passes.
2. Finish P0.1 reuse decision and real ThinkPHP/application scaffolding; add request transport/runtime validation around the now-generated API types.
3. Add mobile/native build lanes and terminal protocol fixtures.
4. Convert draft schema into tracked migrations and enforce/test P1.1 constraints and runtime roles.
5. Implement P1.2/P1.3 identity/topology before shipping/payment/label workflows.

## Hardware and business gates

P0.3 deployed-writer inventory and physical protocol evidence remain needed before shared-locker commissioning. ZipporaService is a watchdog per Richard's clarification, not a required delivery module. Actual sites, providers and service policies remain launch decisions.

No application has been deployed, no production data accessed and no physical door commanded.
