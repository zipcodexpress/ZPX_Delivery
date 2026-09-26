# Phase 1 remaining work and owner inputs

Purpose: identify only the work still required to reach a supervised Phase 1 pilot.
Audience: project owner, Codex, Qwen Code, and developers.
Status: active; software custody flow is complete through outbound departure, but Phase 1 is not launch-ready.
Owner: Richard for business/site inputs; engineering execution currently AI-assisted/local.
Last reviewed: 2026-09-25.

## Authoritative baseline

- GitHub `main` is the shared remote baseline. Local development remains the working source of truth between pushes.
- Baseline reviewed here: `b74a0f1` — PR #18 merged P4.2 outbound load/departure on 2026-09-24.
- PostgreSQL + ThinkPHP 8 + React is the accepted platform baseline.
- The old Windows terminal is reference-only. New kiosk/terminal development targets Android.
- Earlier planning text that describes P1/P2/P3/P4 as not implemented is historical and must not be used to restart completed work.
- Use [CURRENT_STATUS.md](CURRENT_STATUS.md) first for the active checkpoint and [PROGRESS.md](PROGRESS.md) for milestone history.

## Completed baseline — do not redesign

The following foundations are implemented and validated sufficiently to continue forward:

- PostgreSQL migrations, seed/test infrastructure, runtime/migration role separation, transactional and scope tests.
- Customer/staff/driver identity foundations and scoped authorization.
- Shipment/payment sandbox, SI/labels, customer tracking foundations and provider integration work.
- Driver registration/profile/wallet plus inbound run acceptance and package-by-package pickup.
- Hub independent receiving, SHORT/DAMAGED/EXTRA discrepancy handling and custody preservation.
- Operations package search and authoritative custody timeline.
- Hub staging, dispatch workspace and normalized resolve/scan contracts.
- Outbound manifest freeze, package-by-package driver loading, custody transfer, ordered stop grouping and server-authoritative departure.
- P4.2 acceptance scenario: 10 packages grouped 6 + 4; 9 unique scans plus a duplicate cannot depart; the tenth unique accepted scan enables departure.

These areas may receive fixes discovered by later end-to-end testing, but they are not the current feature-development target.

## Current critical path

| Order | Milestone | Remaining implementation | Exit evidence |
|---|---|---|---|
| 1 | P5.1 final destination deposit | Driver stop arrival/progress, destination validation, individual final-deposit scan, compartment authorization, correlated door/deposit evidence, custody `DRIVER -> DESTINATION_LOCKER` | Correct run/driver/package/destination succeeds once; wrong locker/driver/package/replay fails closed; custody timeline matches physical event |
| 2 | P5.1/P5.2 recipient pickup | Recipient claim/pickup grant, single-use authorization, terminal pickup flow, correlated door evidence, custody `DESTINATION_LOCKER -> RECIPIENT`, package completion | Valid recipient retrieves once; expired/revoked/replayed grant denied; package reaches terminal completed state |
| 3 | P5.2 exception and return reconciliation | Full/offline/inaccessible locker, failed/ambiguous door action, undelivered parcel, return-to-hub, unresolved-run visibility and reconciliation | No failure invents delivery; every parcel retains an accountable location/custodian; run cannot silently close with unresolved parcels |
| 4 | P6 hardware/coexistence | Android terminal, scanner/controller transport, durable local journal, one command gate, fixed ownership partitions and legacy-writer guards | Simulator plus representative physical origin/destination tests survive restart/network/power/duplicate events without unauthorized opens |
| 5 | P7 operational readiness | Remaining admin/policies, monitoring, provider hardening, reports/finance export, backup/restore, deployment/support runbooks | Operational alerts/reconciliation and restore tests pass; production roles/secrets/builds are controlled |
| 6 | P8 supervised pilot | One representative end-to-end physical route, then staged 2 -> 5 -> 10 -> 20 site rollout | T01-T20 evidence as applicable; zero unresolved critical custody/ownership defect before each expansion |

Do not divert the critical path into route optimization, broad dashboard polishing, marketing pages, complex analytics, or nonessential CRUD before the complete package journey and exception reconciliation are proven.

## Application coverage now

| Surface | Present baseline | Still required for Phase 1 |
|---|---|---|
| API backend | Auth, shipping, payment/provider foundations, custody, receiving, discrepancies, staging, dispatch, tracking, outbound load/departure | Final deposit, recipient pickup, returns/reconciliation, hardware authorization, operational hardening |
| Customer website | Registration/sign-in, shipping/payment/labels/tracking foundations | Complete recipient claim/pickup UX and final delivery/exception states |
| Driver experience | Operations-web driver workspace with profile/wallet/runs, inbound scans, outbound load/departure and ordered stops | Stop execution, final deposit, failed-delivery/return flow; native/mobile hardening later |
| Hub staff | Receiving, discrepancies, staging, dispatch and history workspace | Return/reconciliation refinements discovered during P5 |
| Admin/operations | Scoped operational views, package search/custody timeline, payment/driver foundations | Remaining launch policies, device/site commissioning, monitoring/reporting/support controls |
| Terminal | Locker simulator and protocol-oriented foundation; legacy terminal is reference-only | Android kiosk, durable journal, origin/final deposit and recipient pickup against real hardware |
| Public/marketing | Not critical to custody path | Coverage/how-to/support content before public launch |

## Owner inputs that now matter

Software can continue with synthetic fixtures, but physical commissioning needs: controller model/protocol and connection method; scanner/printer details; proposed Android kiosk hardware; legacy services/admin tools that allocate/open/reset compartments; first hub plus representative apartment/public test locations; access hours and delivery-only compartment allocation.

Before public launch, finalize service area/cutoffs, parcel limits, pricing/refunds/claims/prohibited items, notification/map providers, device distribution/signing, hosting, support ownership and pilot rollout rules. Keep credentials and customer records out of Markdown.

## Next engineering handoff

1. Start P5.1 from `main` after `b74a0f1`; do not reopen P1-P4 feature work unless a regression is demonstrated.
2. Implement final destination deposit as the next bounded vertical slice, preserving existing scan/custody/idempotency/version invariants.
3. Follow immediately with recipient pickup and exception/return reconciliation so the first package can complete sender-to-recipient with authoritative custody throughout.
4. Update `CURRENT_STATUS.md` before context/token exhaustion and after every merged milestone; update this file only when the remaining critical path materially changes.

## Canonical flow update — 2026-09-26

[phase1_END_TO_END_DELIVERY_FLOW.md](phase1_END_TO_END_DELIVERY_FLOW.md) is now the canonical Phase 1 business flow.

Before Phase 1 can be considered end-to-end complete, add two items ahead of/alongside the existing P5 work:

1. P2.3 size-only pricing and origin size upgrade payment adjustment (SMALL/MEDIUM/LARGE; development defaults $1/$2/$3; no EXTRA_LARGE in Phase 1).
2. P3.0 Pickup Demand / Driver Offer with nearby AVAILABLE drivers, atomic acceptance and multi-locker inbound run assembly.

P4.2 remains valid and should not be redesigned. P5 final deposit/recipient pickup then completes the physical journey.

Future preferred-route / carpool-style driver matching is Phase 2+ and should reuse the Pickup Demand / Driver Offer foundation.

