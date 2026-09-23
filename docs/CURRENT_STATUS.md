# Current Development Status

> Shared checkpoint for Codex, Qwen Code, and human developers. Hard limit: 200 lines.

## Last Updated

Date: 2026-09-23
Agent: Codex
Checkpoint: P3.3 receiving discrepancy completion implemented and fully validated

## Branch / Baseline

- Branch: `feature/P3.3-discrepancy-completion`
- Baseline: `main` at `731684b` (`fix(dev): expose deterministic seeded label tokens`)
- Latest feature commit: `f40d729` (`feat(hub): complete receiving discrepancy workflow`)
- PR #13 is open against `main`: https://github.com/zipcodexpress/ZPX_Delivery/pull/13

## Current Objective

P3.3 hub receiving correctness is complete: durable SHORT, DAMAGED, and EXTRA discrepancies,
immutable receiving lifecycle, and run/stop completion. Next is package tracking/custody visibility
on its own branch after this work is reviewed/merged.

## Completed in Current Work

- Added migration `016_receiving_discrepancies.sql`, extending the existing `exceptions` table with
  organization, hub, driver, receiving-session, notes, and resolution metadata.
- SHORT: closing a partial session records receiving disposition and an open exception while the
  unreceived package remains `INBOUND_CUSTODY` under DRIVER custody.
- DAMAGED: a physical scan transfers custody DRIVER → HUB, records the damage disposition, notes,
  package/audit history, and an open exception.
- EXTRA: an off-manifest scan records an EXTRA receiving item, rejected scan evidence, and an open
  exception without changing custody or adding manifest membership.
- Closing a receiving session marks the inbound run and its stops `COMPLETED`.
- Opening is serialized per hub/run and any prior session makes the normal receiving lifecycle final.
- Added hub-scoped discrepancy list and resolve operations; cross-hub object probes return 404.
- Extended HubReceiving UI with condition selection, damage notes, discrepancy counts/list, and a
  resolution action. EXTRA messaging explicitly states custody was unchanged.
- Prior development fixture work is committed at `731684b`: deterministic hash-only DRIVER-IN labels
  `TEST-LABEL-001`…`005`, repeat-seed legacy upgrade, and clickable DriverWorkspace test tokens.

## In Progress / Exact Continuation Point

None. P3.3 is ready for review. Do not begin package tracking in this branch; after merge, create
`feature/package-tracking-custody-view` and use authoritative custody, scan, receiving, staging,
dispatch, package-event, and discrepancy records as described in the tracking plan.

## Tests

- `npm run test-db` passed after final P3.3 review (394 assertions): SHORT/DAMAGED/EXTRA,
  custody preservation, lifecycle, cross-hub denial, stale version, workbench resolution, plus full suite.
- PHP syntax checks passed for receiving service/controller/tests.
- `npm run check` passed: contract/type validation and 9 Node tests.
- `npm run build` passed for customer-web and operations-web.

## Important Decisions

- `exceptions` remains the durable discrepancy source; no parallel discrepancy table was added.
- No custody transfer occurs without a valid physical receipt. Session close never transfers missing parcels.
- DAMAGED means physically received into HUB custody plus an exception; EXTRA preserves current custody.
- One normal receiving session exists per hub/run. Corrections occur through discrepancy resolution.
- Resolve-before-scan and current package version checks remain mandatory.
- HUB_STAFF authorization remains location-scoped; cross-hub resources remain hidden with 404.
- Package tracking/custody visibility is next, before P4.1 UI, per
  `docs/PACKAGE_TRACKING_CUSTODY_PLAN.md`; it must consume authoritative events and discrepancies.
- Scan contract normalization remains a later dedicated branch; known field drift is not expanded here.
- P7.1 hub/admin/asset management remains deferred until after P5.2 (ADR 0008).

## Schema / API Impact

- DB: migration 016 adds discrepancy context/resolution fields and indexes to `exceptions`.
- API: `GET /hub/receiving-discrepancies` and
  `POST /hub/receiving-discrepancies/{id}/resolve`; receiving scans accept optional
  `disposition=RECEIVED|DAMAGED` and notes.
- UI: HubReceiving includes discrepancy capture, summary, workbench, and resolution.

## Important Files

- `apps/api/database/migrations/016_receiving_discrepancies.sql`
- `apps/api/src/HubReceiving/Service.php`
- `apps/api/src/Http/HubReceivingController.php`, `apps/api/route/api.php`
- `apps/api/tests/hub-receiving.php`
- `packages/ui/HubReceiving.tsx`, `packages/ui/hub-receiving.css`

## Known Remaining Gaps

- `/scans/resolve` contract drift remains: implementation fields differ from canonical contract and
  action/run context is not normalized.
- P4.1 hub staging/dispatch backend still needs its operations UI after package tracking.
- Staging scans are not yet journaled; add accepted/refused staging evidence together.
- Package tracking/custody visibility plan is present but intentionally not started on this branch.

## Next Branch Sequence

1. `feature/package-tracking-custody-view`
2. `feature/P4.1-hub-operations-ui`
3. `fix/scan-contract-normalization`
4. `feature/P4.2-outbound-driver-delivery`

## Local Notes

- Start: `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`
- Operations: http://localhost:5174 · Customer: http://localhost:5173 · API: http://localhost:8000
- Seed/re-seed: `python3 scripts/dev.py seed`; DRIVER-IN labels are `TEST-LABEL-001`…`005`.
- `scripts/dev.py test-db` uses a unique disposable Docker stack and preserves dev volumes.
