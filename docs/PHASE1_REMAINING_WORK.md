# Phase 1 remaining work and owner inputs

Purpose: explain the implementation sequence and decisions needed from Richard.
Audience: project owner and developers. Status: active planning; Phase 1 is not launch-ready.
Owner: Richard for business/site inputs; engineering execution owner unassigned. Last reviewed: 2026-09-18.

Task status remains in GitHub Issues; this document explains dependencies. See [progress](PROGRESS.md), [ordered backlog and T01–T20](handoff/docs/11_BACKLOG_AND_ACCEPTANCE.md), and [implementation review](https://github.com/zipcodexpress/ZPX_Delivery/pull/4).

## What works today

Customer and operations web shells, PHP health endpoints, local Docker/MySQL setup and a persistent normalized locker simulator exist. Linux CI verified startup, initialization of 73 draft tables and HTTP readiness. The next increment adds OpenAPI structural validation, deterministic shared TypeScript definitions and a stale-output check in CI. These are development foundations, not usable shipping or driver products. The schema still needs tracked migrations, permission constraints and transactional tests.

## Development sequence

| Order / tasks | Remaining implementation | Required evidence before completion |
|---|---|---|
| 1 — M0 / P0.1–P0.3 | Finish framework/reuse decision, ThinkPHP scaffold, migration runner, exact image/SDK pins, mobile shells, terminal dependency inventory and actual protocol fixtures | API/web/mobile builds, tracked repeatable migrations, generated client compilation, platform-specific results |
| 2 — P1.1–P1.3 | Restricted DB roles; synthetic seed loader; customer/staff/driver identities; verified contacts; role/site/assignment authorization; device enrollment; location and compartment ownership | Cross-user/site denial, immutable audit permissions and last-compartment concurrency test |
| 3 — P2.1–P2.2 | Shipment draft, quote and payment sandbox; stable shipping identifier; versioned QR/PDF label; scan resolver; customer website/mobile shipping and tracking | SI before deposit; printable/decodable label; replaced label revoked without changing parcel identity |
| 4 — P3.1–P3.3 | Terminal deposit and durable journal; assigned inbound driver route and individual scans; hub independent receipt and discrepancy handling | Simulator journey origin → driver → hub, power-loss recovery, four of five received leaves one with driver |
| 5 — P4.1–P5.2 | Hub sorting/staging; dispatcher route publishing; driver loading and ordered stops; destination deposit; recipient retrieval; returns/quarantine | Ten distinct scans; 6+4 two-stop route; nine plus duplicate cannot depart; correct custody through failed delivery |
| 6 — P6.1–P6.2 | Guard every legacy allocation/open/reset path; one terminal command gate; commission fixed partitions at one apartment and one public site | Physical mapping/protocol evidence, no cross-system collision, apartment deposit/pickup regression |
| 7 — P7.1–P8.1 | Admin policies, staff/vehicles, reporting, finance export, provider adapters, monitoring, backup/restore and rehearsal | Full T01–T20 evidence, supervised hardware checks, rollback and operational signoff |

## Application coverage

| Surface | Present | Still required |
|---|---|---|
| API backend | PHP health bootstrap | ThinkPHP modules, auth, migrations, transactions, idempotency/outbox, business endpoints and provider integrations |
| Customer website | React shell | Register/verify contacts and address, location selection, shipping/payment, printable labels, tracking and recipient claim |
| Customer/driver mobile | None yet | Shared native app with role navigation; customer flows; assigned driver runs, camera scans, ordered stops/maps and pending offline queue |
| Hub staff | Operations shell only | Individual receipt, discrepancies, destination staging, label reprint, manifests and dispatch handoff |
| Admin | Shared operations shell only | Sites/hardware/users/roles/drivers, ownership, routing, rates, exceptions, audit, reporting and finance |
| Terminal | Normalized simulator only | C# host, delivery/receive/pickup flows, existing apartment flows, one command gate, durable journal, scanner/printer/native protocol integration |
| Public website | No marketing pages | Service coverage, how-to-send/pickup, support and approved operating policies |

Phase 1 routing is dispatcher-published ordered stops via a hub. A driver scans every parcel when collecting, loading and delivering. Hub staff independently scan receipt. Map estimates and driver preferences never authorize an unassigned package or substitute for a complete manifest. A route optimization solver is not a launch prerequisite.

## What Richard can provide now

Software work continues with synthetic fixtures while these are gathered. Do not send live passwords, signing keys, tokens or customer records in chat.

| Priority | Requested input | Why / next action |
|---|---|---|
| First local run | Use the SSD plan from the September 16 conversation: `/Volumes/Development`, APFS/GUID; run the [Mac setup](LOCAL_DEVELOPMENT_MAC.md) when convenient | Reformat completion was not confirmed in retrieved context. The script verifies actual storage; record M4 startup results |
| Terminal integration | One representative terminal's OS, CPU/bitness, controller model/profile, serial baud/parity/address settings, scanner/printer models and available test locker | Reproduce the build and validate protocol/label hardware without touching operating lockers |
| Terminal build | Identify the deployed Zippora.exe revision and available native DLLs | Richard confirmed ZipporaService is only a watchdog. No need to provide that service; engineering will isolate its build reference and validate the executable independently |
| Legacy coexistence | Schema-only DB export without records or secrets, plus list of services/admin tools that allocate, open, reset or maintain compartments | Audit every writer before enabling delivery compartments at apartment sites |
| Pilot design | Proposed hub and first apartment/public test sites, operating/access hours, available compartment sizes and proposed delivery-only doors | Start with two sites for commissioning, then expand toward the 20-location Austin pilot |

## Decisions needed later, before launch

Provide the complete pilot location list; service area, pickup/delivery cutoffs, hub waves and staff/driver roles; parcel size/weight limits; price/refund/claims/prohibited-item policies; label-printing availability at home, hub and selected sites; chosen payment, SMS/email and map providers; Android driver-device models and iOS requirements; app ownership/signing/distribution; staging/production hosting and operational responsibility. Use sandbox credentials through the eventual secrets setup, not Markdown.

The development defaults remain synthetic sites, generated local credentials, sandbox/fake providers and no real billing or door commands. Apartment residents will not be bulk-copied into the new authentication database. Existing production stays separate, with fixed compartment ownership and the shared command gate required before any physical pilot.

## Next engineering handoff

1. Verify the API contract gate and generated types in CI; keep changes in PR 4 until reviewed.
2. Complete the backend reuse decision and introduce tracked MySQL migrations with failure/re-run and constraint tests.
3. Add identity/topology as the first business slice, then shipment/SI/labels, then the driver/hub custody journey.
4. Keep [PROGRESS.md](PROGRESS.md) and verification notes current with actual evidence and blockers; do not mark scaffolded screens or synthetic hardware tests as production completion.
