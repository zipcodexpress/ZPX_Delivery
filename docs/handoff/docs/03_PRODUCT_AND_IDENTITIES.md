# Product, customer identity, SI and labels

## Pilot scope

Approximately 20 locations: planning fixture uses 3 downtown and 17 satellites, one hub and two assigned drivers. Some may be existing apartments; remaining sites may be public retail. Actual site mix/addresses is not yet provided. Default movement: origin locker → inbound run → hub receiving → destination sorting/staging → outbound run → final locker → recipient. One physical parcel per shipment in initial UI; data model permits more later. One parcel per new-delivery compartment. Assigned drivers, fixed route order, no gig marketplace.

## Registration and access

New account records name, verified primary email and phone, and address (address_type PROFILE/RETURN/BILLING; the profile address is not the selected destination locker). Both contact channels are collected; shipping requires verified phone and email in the pilot. Driver/staff elevation is an approved role assignment, never self-selected. Visitor access to apartment lockers is denied unless the property explicitly enables it; resident access can be proved through an optional link to an existing resident record.

Account linking: authenticate new account; separately authenticate old account or complete a legacy-side verification challenge; bridge returns short-lived signed subject assertion with legacy_member_id and scoped property membership; new API consumes nonce once and stores link. Matching phone/email alone never grants the link. No shared password database. A property membership expiring does not erase outstanding packages; operations arranges authorized collection.

Recipient may initially be only a contact. Store encrypted recipient snapshot, then send claim invitation. Recipient creates/signs into new account and verifies matching invitation contact through one-time challenge; grant package-scoped access. Do not allow account search to enumerate recipients. Pickup requires an independent short-lived package/locker-scoped credential, not SI. Label is issued to sender once payment/shipping eligibility holds; no recipient signup is needed to print it.

## Four different identifiers

| Identifier | Format / use | Authority |
|---|---|---|
| package_uuid | Permanent UUID for physical parcel | Internal identity |
| SI | `ZPX-<location-code>-<20-char uppercase Base32 random suffix>`; at least 100 random bits, unique DB constraint | Public shipping identifier; destination binding resolved server-side |
| primary label token | `ZPX1:L:<24-char base64url token>` (18 random bytes) | Public scan lookup; not a credential |
| access grant | Opaque secure token, hash at rest, short-lived, one-use and action-scoped | Door/session authorization after auth |

Example SI uses placeholder suffix in docs, never a live credential. Lower-level internal IDs are API strings to avoid numeric precision loss. SI remains stable through hub sorting and route revisions; destination is fixed after origin deposit for Phase 1. Return-to-sender creates explicit return movement/policy, not an unauthorized final-destination edit. Never reuse old cargo_code/pick_code as both identity and access secret.

## Label specification

Default primary PDF: 4 × 6 inches monochrome; A4/Letter output positions the same label without scaling. Print fields: brand; large final locker code/name; origin code; SI; package reference and 1/1; declared dimensions/weight; service/date; QR; handling instructions. No resident unit, phone, email or recipient access code. QR encodes only primary label payload, sized with quiet zone for actual scanner validation. Optional Code 128 label lookup ID resolves to same version.

Customer flow: create → quote → payment confirmation → receive SI and PDF → print/attach → scan to verify → deposit at selected origin. New terminal does not need to print at all sites. Printerless customer is directed to designated staffed/print-enabled site BEFORE deposit. Hub replacement printing does not excuse unlabeled collection.

Hub secondary sticker: final location, run code/date/wave, stop number, stage slot, routing revision and package reference. Use a distinct `ZPXR1:` prefix if machine-readable. Routing sticker never opens a door or counts as parcel custody. Keep primary barcode visible; when run changes replace sticker without changing SI.

Label replacement: authorized hub supervisor identifies parcel using independent evidence and current custody; issues next label version; revokes prior token; removes/obscures old symbol; audits reason/actor. Unknown parcel goes to quarantine. Duplicate copies on two physical parcels cannot be resolved by software identity alone: second conflicting custody/scan is quarantined for staff investigation. Reprinting same active label is audited and shown as duplicate print, not a new package.

## Scan handoffs

Sender primary-label scan plus deposit session evidence establishes AT_ORIGIN. Driver removes each parcel, scans it in assigned pickup session; observed open/close and driver attestation establish inbound custody. Hub worker independently scans EACH parcel received; missing parcels stay with their previous recorded custodian plus incident until resolved. Sorter scans primary then staging slot. Outbound driver scans EACH parcel; 9 unique scans of 10 cannot depart. Destination primary scan authorizes a specific session; final deposit requires correlated closure and attestation/evidence. Recipient uses pickup credential; terminal evidence/recipient confirmation establishes collection.

## Pricing/payment defaults for development

Use versioned configurable rate cards and a fake payment provider in local tests. Never invent live rates. Quote binds origin/destination, parcel dimensions/weight, service and expiry. Server payment webhook, not client success screen, establishes eligibility. Capture policy: capture before primary label/deposit authorization for Phase 1. Cancel before deposit may refund under policy; after deposit use operations return. Repeated webhook/refund requests use stable idempotency keys. Existing apartment wallet/pickup fees are unrelated and must not be charged for delivery-network parcels unless a separately approved product rule explicitly says so.

## Scope limits

No arbitrary door-to-door delivery, hazardous/perishable shipments or refrigerated workflow in pilot. Max dimensions/weight and allowed-item rules are configurable site/service policies, to be supplied before public launch. Cutoffs and delivery promises derive from staffed hub waves and available route capacity. Stop accepting quotes that cannot be serviced rather than silently promising a deadline.
