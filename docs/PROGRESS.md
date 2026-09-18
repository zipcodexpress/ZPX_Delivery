# Development progress

Purpose: milestone/session summary; task status belongs in GitHub Issues/Projects.
Audience: ZPX owner, developers and Codex.
Status: Development started; M0 incomplete. Owner: unassigned. Last reviewed: 2026-09-18.

## Current stage

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

User local preference: M4 Mac, external SSD. Exact volume name not yet provided. No files have been written to the user's Mac by this cloud session. Native shell Git authentication here remains unavailable; connector commits preserve the work remotely.

## Current evidence

Node/TypeScript check, both web production builds, 5 simulator tests, 3 setup tests and static handoff validation pass. [GitHub Actions run 35401756927](https://github.com/zipcodexpress/ZPX_Delivery/actions/runs/35401756927) also passed PHP lint, Docker startup, initialization of all 73 draft tables and service/proxy HTTP readiness. Physical M4 and locker validation remain pending. The PHP endpoint is health-only, not a completed business API.

## Next dependencies

1. Get the first M4 startup results and lock tested image digests; Linux Docker/MySQL CI now passes.
2. Finish P0.1 reuse decision and real ThinkPHP/application scaffolding; semantic OpenAPI validation and client generation.
3. Add mobile/native build lanes and terminal protocol fixtures.
4. Convert draft schema into tracked migrations and enforce/test P1.1 constraints and runtime roles.
5. Implement P1.2/P1.3 identity/topology before shipping/payment/label workflows.

## Hardware and business gates

P0.3 deployed-writer inventory, ZipporaService resolution and physical protocol evidence remain needed before shared-locker commissioning. Actual sites, providers and service policies remain launch decisions.

No application has been deployed, no production data accessed and no physical door commanded.
