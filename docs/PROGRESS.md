# Development progress

Purpose: milestone/session summary. `CURRENT_STATUS.md` is the active handoff; this file records durable progress.
Audience: ZPX owner, developers, Codex, and Qwen Code.
Status: P4.2 merged; software flow is implemented through outbound driver departure. P5 final deposit/pickup is next.
Last reviewed: 2026-09-25.

## Current baseline

- Repository: `zipcodexpress/ZPX_Delivery`
- Branch baseline: `main`
- Reviewed feature baseline: `b74a0f1` — merge of PR #18 on 2026-09-24.
- Platform: PostgreSQL + ThinkPHP 8 + React; Android is the preferred new terminal direction.
- Development policy: local checkout is the working source of truth; GitHub `main` is the shared remote baseline after push/merge.

## Milestone progress

| Area | Status | Evidence / result |
|---|---|---|
| P0/P1 foundation | Implemented baseline | PostgreSQL migrations/seed, restricted roles, transactional/concurrency tests, ThinkPHP lifecycle, React shells, simulator, identity/RBAC/topology foundations |
| P2 shipping/providers | Implemented baseline | Shipment/customer flows, SI/labels, test/sandbox payment and provider work, tracking foundations |
| P3 inbound custody | Implemented and merged | Driver individual pickup scans, custody transfer, hub independent receiving, discrepancy workflow; PRs through #13 |
| Package tracking | Implemented and merged | Assignment-scoped package search and authoritative custody timeline; PR #14 |
| P4.1 hub operations | Implemented and merged | Staging/dispatch workspace and scan normalization integrated to main; PRs #15-#17 |
| P4.2 outbound delivery | Implemented and merged | Exact outbound manifest, individual load scans, hub-to-driver custody, ordered stops, departure gate; PR #18 |
| P5 final mile | NEXT | Destination locker deposit, recipient pickup, failed-delivery/return reconciliation |
| P6 hardware/coexistence | Pending | Android terminal and real locker/controller commissioning; legacy ownership/command-gate validation |
| P7/P8 release/pilot | Pending | Operational hardening, T01-T20 evidence, supervised staged rollout |

## Latest verified delivery behavior

PR #18 completed the current software frontier:

- Dispatch acceptance freezes the staged physical package set into a versioned outbound manifest.
- Every outbound physical package must be individually scanned; the legacy bulk-load bypass was removed.
- Successful load transfers custody exactly once from the assigned hub to the assigned driver.
- Wrong driver/run, off-manifest package, wrong hub custody, stale revision/version, revoked label and invalid duplicate behavior fail closed.
- Departure eligibility is derived from authoritative manifest rows plus current package custody, not scan-event count.
- The acceptance scenario passes with 10 packages in ordered 6 + 4 stop groups: 9 unique accepted scans plus one duplicate remain blocked; the tenth unique scan enables departure.
- Driver UI exposes outbound load progress and ordered stop/package groups.

Validation recorded with the P4.2 work: `npm run test-db`, `npm run check`, `npm run build`, changed PHP syntax checks and `git diff --check`.

## Current development focus

The next objective is P5.1/P5.2: complete one authoritative sender-to-recipient journey.

Target custody path:

`ORIGIN_LOCKER -> INBOUND_DRIVER -> HUB -> OUTBOUND_DRIVER -> DESTINATION_LOCKER -> RECIPIENT`

The next implementation must add final destination deposit with server-side run/driver/package/destination validation and correlated locker evidence, then recipient single-use pickup, followed by full/offline/ambiguous-door and return reconciliation. No failure path may fabricate a completed delivery or lose accountable custody.

## Important architectural invariants

- Physical package scans are individual and idempotent; scan count alone never establishes custody or departure.
- Custody changes are server-authoritative and must agree with package/run/manifest/location scope.
- Raw production label tokens are not exposed or persisted in operational responses.
- Hub staff independently receive packages; drivers cannot self-certify hub receipt.
- Locker authorization must be destination/device scoped and correlated to physical door evidence.
- Ambiguous hardware outcomes remain unresolved until reconciled; never auto-reopen or silently mark delivered.
- Route optimization is not an authorization mechanism and is not on the current critical path.

## Documentation drift correction

Older statements in this repository that describe the project as only a scaffold, P1/P2 as the active branch, P3 driver/hub custody as unbuilt, or PR #18 as open are superseded by this checkpoint. Historical verification documents remain evidence for their original milestone and should not be rewritten as current status.

## Next milestones

1. P5.1 final destination deposit and stop progress.
2. Recipient claim/pickup with single-use authorization and custody completion.
3. Failed-delivery, return-to-hub and end-of-run reconciliation.
4. Android terminal plus real locker/controller integration.
5. Operational hardening and supervised physical pilot.

See [PHASE1_REMAINING_WORK.md](PHASE1_REMAINING_WORK.md) for the remaining critical path and [CURRENT_STATUS.md](CURRENT_STATUS.md) for the active coding handoff.
