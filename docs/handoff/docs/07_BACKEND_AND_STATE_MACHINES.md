# Backend services, states and transactions

## Modular monolith

New ThinkPHP API modules: Identity, Location, HardwareRegistry, Ownership, Shipment, Label, QuotePayment, RouteDispatch, Scan, Custody, Hub, LockerSession, Notification, Exception, Reporting. Controller validates HTTP/auth; application service enforces transaction; repositories/DAO own queries; integrations are injected adapters. Background worker consumes transactional outbox and scheduled reconciliation. Redis may accelerate cache/queues, but durable custody/idempotency/commands live in SQL. No cross-database transaction with apartment DB.

## State dimensions

Package physical state: CREATED → AT_ORIGIN → INBOUND_CUSTODY → AT_HUB → STAGED → OUTBOUND_CUSTODY → AT_DESTINATION → COLLECTED. RETURN_CUSTODY → AT_HUB covers failed outbound delivery. Separate disposition NORMAL/QUARANTINED/INVESTIGATING/RETURN_REQUESTED/LOST_CONFIRMED; preserve last known custodian, never assign fictional physical position on loss. Sender pre-deposit cancel changes order/eligibility, not a physical movement. Later return-to-sender uses explicit movement and receiving authorization with original package identity; final disposition RETURNED closes it.

Shipment order/payment status, label status, session status, run status and package physical state are distinct. Notification failure does not undo delivery. Printing failure does not create a second shipment. A revoked label does not cancel a parcel already in custody.

| Action | Required prior state and authority | Effect |
|---|---|---|
| Confirm origin deposit | CREATED; paid eligible; sender grant; origin session evidence | AT_ORIGIN, locker custody |
| Confirm inbound pickup | AT_ORIGIN; assigned run/driver; scan and session evidence | INBOUND_CUSTODY, driver custody |
| Receive at hub | INBOUND_CUSTODY or RETURN_CUSTODY; receiving hub worker; scan | AT_HUB, hub custody, prior allocation released |
| Stage | AT_HUB; hub sorter; compatible slot/run | STAGED; package event, custody unchanged |
| Load outbound | STAGED; assigned driver/run/hub; primary scan | OUTBOUND_CUSTODY, driver custody |
| Confirm final deposit | OUTBOUND_CUSTODY; assigned run; correct final locker; evidence | AT_DESTINATION, locker custody |
| Confirm recipient pickup | AT_DESTINATION; verified recipient grant; evidence | COLLECTED, recipient custody |
| Return after failed delivery | OUTBOUND_CUSTODY; assigned explicit return task | RETURN_CUSTODY, same driver; no transfer until hub receipt |

Legacy pickup state is never translated automatically into these transitions. Projection includes custodian_type/ref, current_location (nullable in transit), version and disposition. Audit state corrections are append-only operations with evidence and reason.

## Reservation and command protocol

Acquire compatible DELIVERY door in SQL transaction with row lock or atomic compare-and-set; unique active claim on compartment and package; check owner_generation and no pending command/quarantine. Reservation lease expires only before command dispatch. Dispatch sets claim non-releasable until reconciliation; do not free by TTL afterward. Take locks in stable order: run if relevant → package IDs sorted → compartment IDs sorted → active claims/manifest item. Use bounded deadlock retry with same idempotency key.

Command state: QUEUED → DISPATCH_INTENT → DISPATCH_RECORDED → OPEN_OBSERVED → CLOSE_OBSERVED → CONFIRMED; terminal reports UNKNOWN on ambiguous restart/timeout. Server delivery command expired before first dispatch → CANCELLED. Outbox retry sends same command ID; terminal persisted journal returns existing disposition. Commit physical transition only on new package version and satisfied evidence policy. Already-confirmed callback replays return prior result. Out-of-order close event is held for correlation, not assumed successful.

## Scan atomicity

Authenticate actor/scope first even for retries. Request contains label payload, explicit action, run/session context, client_event_id and expected versions. Begin transaction; acquire unique scoped idempotency key; same fingerprint returns original accepted result, different fingerprint → conflict. Resolve active label; lock package; check custody, manifest/run revision, role/location and transition; persist scan audit; append custody event only for physical transfer (stage/reprint are package events); update projection and allocation; enqueue outbox; persist response; commit. A new request key for same completed scan returns already_processed only when same actor/action/context remains identifiable. A duplicate in another run is not silently successful.

Unknown or rejected scans are separate audit events; never increment loaded count. Counter = count of accepted distinct manifest items currently loaded and valid for run, not count of HTTP successes.

## Event envelope

event_id UUID; schema_version 1; event_type; organization_id; package_id; aggregate_version; causation_id; correlation_id; actor subject/type; observed_at and received_at; device/session/command IDs when applicable; previous/new custodian for transfer; evidence assurance; body. No credentials or raw payment data. Outbox consumers dedup by event_id, record attempts and dead-letter visibly. External notification failure does not roll back physical event.

## Payment and reporting

Payment provider signatures verified; webhook uniqueness before processing; amount/currency/quote must match; refunds keyed and bounded by captured amount minus prior approved refunds. Integer cents only. Use protected references for documents/photos; report queries use projections/read models, never mutate history. Payroll export approved route/shift records only, no automated driver withdrawals in Phase 1.

## Phase 1 canonical amendment — 2026-09-26

Read [phase1_END_TO_END_DELIVERY_FLOW.md](../../phase1_END_TO_END_DELIVERY_FLOW.md).

Add application modules/services for PickupDemand and DriverOffer (or equivalent bounded services inside RouteDispatch).

New non-custody workflow states:

Pickup demand:
`OPEN -> PARTIALLY_ASSIGNED/ASSIGNED -> PICKED_UP`, with `EXPIRED/CANCELLED` terminal alternatives.

Driver offer:
`OFFERED -> VIEWED -> ACCEPTED`, with `DECLINED/EXPIRED/CANCELLED` alternatives.

Rules:
- `AT_ORIGIN` confirmation creates/activates pickup-demand membership transactionally or through durable outbox processing.
- Offer acceptance must be atomic and version/idempotency protected. Two drivers racing the same package set produce one winner.
- Accepting an offer creates/updates an exact INBOUND run/manifest; it never transfers custody by itself.
- Custody remains `ORIGIN_LOCKER` until the assigned driver physically removes/scans each package.
- Multi-locker opportunity grouping must not permit bulk custody transfer.

Pricing change:
- package price derives from SMALL/MEDIUM/LARGE class, not weight/distance.
- origin size upgrade requires successful payment adjustment before larger compartment authorization.
- final accepted size class becomes authoritative for destination compatibility.

