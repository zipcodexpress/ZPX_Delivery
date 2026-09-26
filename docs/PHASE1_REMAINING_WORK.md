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
| 1 | Customer Shipment Initialization E2E | Audit existing customer app/API/schema first; complete account/login/verification, recipient or Send to Myself, origin + destination locker selection, SMALL/MEDIUM/LARGE, authoritative price, payment, shipment finalization, stable SI and label | Brand-new customer reaches READY_FOR_ORIGIN_DEPOSIT without manual DB/admin intervention |
| 2 | Origin deposit + size upgrade | Phone/locker pairing, label scan, compatible compartment, upgrade payment difference before larger door opens, physical evidence, custody to origin locker | Correct deposit reaches AT_ORIGIN; under-sized estimate upgrades safely; failed adjustment opens no larger door |
| 3 | Pickup Demand / Driver Offer | Demand creation from AT_ORIGIN, driver availability, nearby offers, atomic acceptance, multi-locker inbound run assembly | Two-driver race yields one winner; one driver can accept multiple nearby origins; custody remains at origin until scans |
| 4 | Existing P3/P4 journey | Reuse current inbound pickup, hub receiving/discrepancy, staging, outbound load/departure | Existing tests remain green; no redesign unless regression is demonstrated |
| 5 | P5 final destination deposit | Driver stop progress, exact destination validation, final-deposit scan/session/device evidence, custody DRIVER -> DESTINATION_LOCKER | Wrong destination blocked before open; physical evidence required |
| 6 | Recipient pickup + reconciliation | Ready notification, single-use grant, sender-as-recipient sharing, pickup, failed-delivery return/reconciliation | Replay denied; package reaches COLLECTED or accountable return state |
| 7 | P6-P8 hardware/operations/pilot | Android terminal, legacy guards, monitoring, backup/restore, supervised staged rollout | Physical T01-T20 evidence and no unresolved critical custody defects |

Do not divert into route optimization, broad dashboard polishing, marketing pages, complex analytics, or nonessential CRUD before this vertical path is complete.

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

Codex must start here:

1. Pull current `main` and read `AGENTS.md`, `docs/CURRENT_STATUS.md`, and `docs/phase1_END_TO_END_DELIVERY_FLOW.md`.
2. Audit the existing customer-web + API + PostgreSQL implementation for Customer Shipment Initialization. Produce a concise IMPLEMENTED / PARTIAL / MISSING checklist before changing code.
3. Reuse existing identity, shipping, Authorize.net/payment, SI/label and tracking code. Do not rebuild completed P1/P2 foundations.
4. Implement only the missing pieces needed for the acceptance journey: new customer -> recipient/Send to Myself -> origin/destination locker -> size -> price -> payment -> SI/label -> READY_FOR_ORIGIN_DEPOSIT.
5. Add automated acceptance coverage for that journey.
6. After that milestone is green, move to origin deposit + size upgrade; only then implement Pickup Demand / Driver Offer.
7. Update `CURRENT_STATUS.md` before context/token exhaustion and after every merged milestone.

## Canonical flow update — 2026-09-26

[phase1_END_TO_END_DELIVERY_FLOW.md](phase1_END_TO_END_DELIVERY_FLOW.md) is now the canonical Phase 1 business flow.

Before Phase 1 can be considered end-to-end complete, add two items ahead of/alongside the existing P5 work:

1. P2.3 size-only pricing and origin size upgrade payment adjustment (SMALL/MEDIUM/LARGE; development defaults $1/$2/$3; no EXTRA_LARGE in Phase 1).
2. P3.0 Pickup Demand / Driver Offer with nearby AVAILABLE drivers, atomic acceptance and multi-locker inbound run assembly.

P4.2 remains valid and should not be redesigned. P5 final deposit/recipient pickup then completes the physical journey.

Future preferred-route / carpool-style driver matching is Phase 2+ and should reuse the Pickup Demand / Driver Offer foundation.

