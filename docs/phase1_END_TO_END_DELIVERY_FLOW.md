# Phase 1 End-to-End Delivery Flow

Status: canonical Phase 1 product and custody flow.
Last reviewed: 2026-09-26.
Owner: ZipcodeXpress product.
Supersedes conflicting Phase 1 flow descriptions in older planning text. Detailed security/custody invariants in the existing handoff documents remain authoritative unless this document explicitly changes the product rule.

## 1. Phase 1 objective

Phase 1 provides low-cost locker-to-locker delivery through a ZPX hub:

`Sender -> Origin Locker -> Inbound Driver -> Hub -> Outbound Driver -> Destination Locker -> Recipient`

The network reduces driver miles and handling cost by aggregating multiple nearby locker pickups into inbound runs and sorting packages at the hub into destination-locker groups.

Phase 1 is not door-to-door delivery and does not support oversized freight. Future phases may add preferred-route / rideshare-style matching in which drivers accept work aligned with trips they already plan to make.

## 2. Public package identity and tracking

Use one stable public ZPX Shipping Identifier (SI) for the package journey.

- `package_uuid`: internal immutable physical package identity.
- `SI`: stable public ZPX shipping/tracking identifier from shipment creation through recipient pickup.
- `primary label token`: replaceable scan token encoded in QR/barcode; revoking/reprinting a label does not change SI.
- `pickup/access grant`: secret, short-lived, action/package/locker-scoped authorization; never use SI as a door-opening secret.

For Phase 1, the SI is the customer-facing ZPX tracking number. Do not create a second ZPX tracking-number namespace unless a later external-carrier integration requires one.

## 3. Sender, recipient and "send to myself"

A shipment has a sender account and a recipient contact snapshot. The sender and recipient may be the same person.

Supported Phase 1 cases:

1. Sender ships to another registered ZPX user.
2. Sender ships to a person who does not yet have a ZPX account.
3. Sender sets themselves as the recipient, tracks the shipment, receives the final pickup grant, and may securely share that one-time pickup credential with a trusted friend who physically retrieves the package.

The sender chooses the final destination locker. Recipient street address/contact information is separate from `destination_location_id/destination_locker_id`; it may be used for locker suggestions, service-area checks, support and future products.

A recipient does not need the ZPX app before the sender can create, pay for, label or ship the package.

## 4. Shipment creation

Customer flow:

1. Enter/confirm sender account.
2. Enter recipient name, phone, email and address, or choose "Send to myself".
3. Choose origin location.
4. Search/select the destination ZPX locker near the recipient.
5. Choose package size class: SMALL, MEDIUM or LARGE.
6. Show the exact Phase 1 price for that size.
7. Confirm prohibited-item/service rules.
8. Pay.
9. Generate SI and primary shipping label.
10. Attach label and travel to the selected origin locker.

Destination becomes fixed after confirmed origin deposit except through explicit operations/return handling.

## 5. Phase 1 pricing: size only

Phase 1 price is based only on the selected locker/package size class, not package weight or route distance.

Initial default development rate card:

| Size class | Default Phase 1 price |
|---|---:|
| SMALL | $1.00 |
| MEDIUM | $2.00 |
| LARGE | $3.00 |

Rates remain versioned/configurable in the database so operations can change them without code deployment.

Phase 1 does not support EXTRA_LARGE/oversized packages. The product must clearly tell customers to use another delivery method when the parcel does not fit the LARGE definition.

Each size class must publish customer-readable maximum interior dimensions. The UI should include measuring guidance and examples. Weight may still have a safety maximum but does not determine price.

## 6. Size estimate and upgrade at origin locker

The customer selects an estimated size before payment. A sender may discover at the origin locker that the paid size does not fit.

Allowed behavior:

- Same-size retry: choose another available compartment of the paid size.
- Upgrade only: SMALL -> MEDIUM/LARGE or MEDIUM -> LARGE.
- No automatic downgrade/refund at the locker in Phase 1.
- Before a larger door opens, server calculates the price difference using the same rate-card version/policy, obtains payment authorization/capture for the difference, and records a quote/payment adjustment.
- Only after successful adjustment may the larger compartment be reserved/opened.
- The final package size class is persisted on the shipment/package and used for destination capacity compatibility.
- If no compatible larger compartment exists or payment adjustment fails, no larger door opens and the package remains undeposited.

Example: customer paid $1 SMALL; package requires MEDIUM. Server charges the additional $1 before authorizing a MEDIUM origin compartment.

## 7. Origin locker deposit

App-assisted Phase 1 flow:

1. Sender arrives at selected origin locker.
2. Phone authenticates and pairs/approves the specific locker/action.
3. Sender scans the primary package label.
4. Server validates paid shipment, SI/label, selected origin and requested/final size.
5. Server reserves a compatible DELIVERY compartment.
6. Terminal journals the command before physical open.
7. Correct door opens.
8. Sender places package, closes door and confirms placement.
9. Device evidence is correlated.
10. Server commits `CREATED -> AT_ORIGIN` and locker custody.

A successful scan or open command alone is not a deposit.

Confirmed `AT_ORIGIN` creates or updates a pickup demand.

## 8. Pickup Demand and Driver Offer — required Phase 1

Pickup Demand / Driver Offer is a Phase 1 feature, not a future optimization.

A confirmed origin deposit makes the package eligible for inbound collection. The system creates a pickup-demand item and aggregates compatible ready packages by origin locker, hub and pickup window.

Required entities/concepts:

### pickup_demands
- id
- organization_id
- origin_location_id / origin_locker_id
- destination_hub_id
- ready_at
- pickup_deadline
- status: OPEN / PARTIALLY_ASSIGNED / ASSIGNED / PICKED_UP / EXPIRED / CANCELLED
- package_count and size summary projection
- version

### pickup_demand_items
- pickup_demand_id
- package_id
- ready_at
- current assignment state
- unique active membership

### driver_availability
- driver_id
- AVAILABLE / BUSY / OFFLINE
- optional current/last location and timestamp
- vehicle/capacity reference
- availability window

### driver_offers
- id
- driver_id
- pickup_demand_id or grouped opportunity id
- offered_at / expires_at
- status: OFFERED / VIEWED / ACCEPTED / DECLINED / EXPIRED / CANCELLED
- offer revision/idempotency fields

### inbound opportunity/run assembly
The backend may present one or several nearby origin lockers as a pickup opportunity. Driver acceptance atomically reserves the offered work and materializes/updates an INBOUND run with exact packages/stops.

## 9. Nearby-driver notification and acceptance

Phase 1 uses simple availability/proximity matching, not route optimization.

Eligibility may use:

- driver is approved and AVAILABLE;
- recent driver location is available with consent, or driver explicitly requests "pickups near me";
- origin is within configured service radius;
- driver/vehicle has capacity;
- pickup deadline can be met;
- all accepted packages go to the designated hub.

The system may notify multiple eligible nearby drivers. The first valid acceptance wins the exclusive assignment for the affected demand/package set. Expired/stale offers cannot assign work.

A driver can accept several nearby locker pickups within the configured collection window (for example approximately one hour) and carry all collected packages to the hub. Package custody remains individually tracked even when opportunities are grouped.

Phase 1 compensation can remain route/shift configured; it does not require bidding.

## 10. Future preferred-route matching — not Phase 1

Future Phase 2+ may add rideshare/carpool-style preferred-route matching:

- driver supplies intended origin/destination/time corridor;
- system offers package work naturally aligned with that trip;
- matching may consider detour, time, capacity and payout;
- driver may choose opportunities based on preferred direction.

This future matching must reuse Pickup Demand / Driver Offer rather than replacing custody, manifest or package identity.

Do not implement autonomous gig bidding, dynamic auction pricing or route-marketplace complexity in Phase 1.

## 11. Inbound collection

After accepting assigned pickup work:

1. Driver follows exact assigned origin stops.
2. At each locker, driver authenticates.
3. Each physical package is removed/scanned individually.
4. Server validates package, run, driver, locker and version.
5. Custody transfers `ORIGIN_LOCKER -> INBOUND_DRIVER` exactly once per package.
6. Driver may collect from multiple nearby lockers.
7. Driver delivers all collected packages to the designated hub.

Completing a stop or run cannot silently remove missing packages from custody.

## 12. Hub receiving and sorting

Hub receiving is independent from the driver.

1. Hub worker opens/selects receiving session.
2. Scan every physical package.
3. Missing package remains with prior recorded driver custody until resolved.
4. Accepted package transfers to HUB custody.
5. SHORT/DAMAGED/EXTRA create explicit discrepancy evidence.

Sorting:

1. Scan package/SI label.
2. Resolve final destination locker.
3. Assign destination staging slot/lane.
4. Group packages by destination locker and outbound wave/run.
5. Routing sticker may be printed but never replaces the primary package scan or SI.

## 13. Outbound load and route

Outbound dispatch freezes an exact manifest.

1. Driver accepts assigned outbound run.
2. Scan every staged physical package.
3. Each accepted scan transfers `HUB -> OUTBOUND_DRIVER`.
4. Departure is allowed only when exact authoritative manifest/custody conditions are satisfied.
5. Driver sees ordered destination-locker stops and package groups.

Duplicate scans do not increase loaded count.

## 14. Final destination deposit

At each destination stop:

1. Driver scans package for `FINAL_DEPOSIT`.
2. Server validates organization, assigned run/driver, exact manifest item, package state/version/custody and destination locker.
3. Server creates destination locker session and reserves compatible compartment based on final package size class.
4. Only the enrolled destination terminal/device receives door authorization.
5. Terminal journals before open; physical open/close evidence is correlated.
6. Driver confirms placement.
7. Server commits `OUTBOUND_CUSTODY -> AT_DESTINATION` and `DRIVER -> DESTINATION_LOCKER` custody.
8. Only then is recipient notification released.

Wrong destination must be rejected before door actuation. A green scan alone is not Delivered.

If the destination is full/offline/inaccessible, driver keeps custody and receives an explicit return/exception task; the system must not silently substitute another locker.

## 15. Recipient notification and pickup

After confirmed final deposit:

- create notification outbox;
- notify recipient through configured SMS/email and push when available;
- show SI, destination locker, pickup instructions and expiry policy;
- never include a reusable door secret in ordinary tracking information.

Pickup authorization options:

1. Registered recipient claims shipment and receives package-scoped short-lived pickup grant.
2. Sender-as-recipient receives the pickup grant and may intentionally share that one-time credential with a trusted person.

The bearer of a valid transferable pickup grant may physically retrieve the package for the sender/recipient in this Phase 1 convenience flow. The grant remains one-use, time-limited, package/locker/action scoped and revocable. Sharing the public SI alone never authorizes pickup.

At locker:

1. Present valid pickup grant/pairing.
2. Server validates grant/package/destination/device.
3. Correct compartment opens.
4. Package removed; close/evidence/attestation recorded.
5. Server commits `AT_DESTINATION -> COLLECTED` and recipient/authorized-bearer collection evidence.
6. Grant cannot be replayed.

## 16. Tracking

Customer tracking by SI shows privacy-safe milestones:

- Shipment created
- Label issued
- Deposited at origin
- Pickup assigned
- Collected by inbound driver
- Received at hub
- Sorted/staged
- Loaded for destination
- Out for delivery
- Ready for pickup
- Collected
- Exception/return states when applicable

Public/customer tracking must not expose secret pickup grants, full driver routes, unnecessary recipient PII or raw internal custody references.

Operations may view the authoritative custody timeline separately.

## 17. Required database changes

Existing shipment/custody tables remain authoritative. Add or explicitly extend:

- package/shipment size class: SMALL/MEDIUM/LARGE;
- published size-dimension policy;
- versioned size-only rate card;
- quote/payment adjustment records for locker-side upgrade;
- pickup_demands;
- pickup_demand_items;
- driver_availability;
- driver_offers;
- grouped inbound opportunity/run linkage where needed;
- notification reason/template for READY_FOR_PICKUP;
- pickup grant sharing/authorized-bearer audit metadata without storing plaintext secrets.

All physical transfers still use existing append-only scan/custody/event architecture.

## 18. Required API/functions

Customer:
- list size classes/dimensions/prices;
- create shipment with recipient + destination locker + estimated size;
- choose "send to myself";
- quote/pay;
- issue SI/label;
- request/approve origin size upgrade and pay difference;
- track by authenticated shipment/SI;
- obtain/share/revoke pickup grant where policy permits.

Driver:
- set availability;
- request/list nearby pickup opportunities;
- receive pickup offers;
- accept/decline offer atomically;
- view multi-locker inbound run;
- existing inbound scans/hub handoff;
- existing outbound load/departure;
- final deposit and return/exception actions.

Operations/hub:
- configure size classes/dimensions/rates;
- view pickup demand queue;
- dispatch/re-offer stale demand;
- monitor SLA/deadline;
- existing receive/stage/dispatch/custody timeline.

Workers:
- aggregate origin deposits into pickup demands;
- select eligible drivers;
- send/deduplicate offers/notifications;
- expire/re-offer demand safely;
- send recipient-ready notifications;
- reconcile exceptions.

## 19. Phase 1 acceptance additions

In addition to existing T01-T20:

- A SMALL shipment prices at $1 using the configured default rate card; MEDIUM $2; LARGE $3.
- A sender can select themselves as recipient and complete tracking/pickup.
- A shipment can target a non-app recipient contact.
- SMALL that does not fit can upgrade to MEDIUM only after successful $1 difference payment; no larger door opens before payment.
- A confirmed origin deposit creates an OPEN pickup demand.
- Two eligible nearby drivers receive/see the same offer; only one acceptance can assign the package set.
- One driver can accept multiple nearby origin pickups and collect them into one inbound run to the hub.
- Each package still transfers custody individually.
- Expired offer cannot steal already-assigned demand.
- Destination deposit triggers READY_FOR_PICKUP only after physical evidence commits AT_DESTINATION.
- Shared one-time pickup grant can be used once by the intended bearer; replay and SI-only pickup are denied.
- Oversized/does-not-fit-LARGE shipment is rejected for Phase 1 instead of improvising a door/rate.

## 20. Development order impact

Current P4.2 work remains valid.

Before calling Phase 1 end-to-end complete:

1. Add size-only rate/size policy and origin size-upgrade payment adjustment.
2. Add Pickup Demand / Driver Offer and nearby multi-locker inbound acceptance.
3. Implement P5 final destination deposit.
4. Implement recipient notification/pickup, including sender-as-recipient transferable one-time grant.
5. Finish return/reconciliation.
6. Commission Android terminal/physical hardware and supervised pilot.

The new pickup-demand layer must integrate with the existing run/manifest/custody model rather than replacing it.
## 21. Codex implementation start — current next work

Before building later legs of the route, complete the customer-side initiation vertical slice because it defines the authoritative shipment, recipient, destination, size, price, payment, SI and label consumed by every downstream step.

### Step A — audit before code

Inspect the current customer web, API, migrations, contracts and tests. For each item below classify IMPLEMENTED / PARTIAL / MISSING and cite the code path:

- account registration/login/contact verification/profile;
- recipient contact/address and "Send to myself";
- origin location selection;
- destination locker search/selection;
- SMALL/MEDIUM/LARGE size policy and published dimensions;
- size-only price quote;
- server-confirmed payment;
- shipment/package finalization;
- stable SI;
- primary label/PDF/QR;
- READY_FOR_ORIGIN_DEPOSIT customer status.

Do not redesign or duplicate existing P1/P2 features that already work.

### Step B — Customer Shipment Initialization E2E milestone

Close only the missing gaps until this acceptance case passes:

A brand-new customer registers and verifies, creates a shipment to a friend or themselves, selects origin and destination lockers, selects SMALL/MEDIUM/LARGE, sees the authoritative price, pays successfully, receives one stable SI plus printable/scannable label, and sees the shipment in READY_FOR_ORIGIN_DEPOSIT.

A full stored-payment-method wallet is not a prerequisite. Phase 1 requires reliable payment confirmation and the ability to charge a later size-upgrade difference.

### Step C — only after Step B

Implement origin deposit + size upgrade:

- app/locker pairing;
- label scan;
- compatible compartment selection;
- paid size mismatch upgrade;
- price-difference payment before larger door authorization;
- physical evidence;
- authoritative `AT_ORIGIN`.

Only confirmed `AT_ORIGIN` creates Pickup Demand eligibility.

### Step D — then Pickup Demand / Driver Offer

After origin deposit is complete, implement nearby driver offers, atomic acceptance and multi-locker inbound run assembly. Existing P3/P4 custody, hub and outbound code must be reused.

