# Mobile applications and customer website

## Deliverables

Build new native mobile app for customer and driver roles using shared contracts but distinct navigation. Driver permissions are server-assigned. Phase 1 Android driver build first; iOS remains a required build/device-test lane before iOS availability. Customer responsive website offers the full shipping/label workflow without requiring native app installation. Mobile app offers printable PDF/share-to-print instructions; do not imply every phone or kiosk can print directly.

Reuse branding and appropriate layout ideas from ZPXWebsite, not localStorage token/auth implementation. New browser frontend uses same-origin session cookies with CSRF protection through API/BFF routing; native app uses short-lived bearer access token and OS-protected refresh token. No token in label QR, route sticker, navigation URL or analytics. Actual credentials/adapters configured per environment.

## Customer screen inventory

| Screen | Required behavior | API family |
|---|---|---|
| Register/verify/profile | name, email, phone, addresses; pending-verification errors | auth, profile |
| Location finder | public/eligible apartment sites, hours, printer/access capability; list fallback if map unavailable | locations |
| Shipment wizard | origin, destination, recipient, dimensions/weight, service | shipments, quotes |
| Payment | hosted/tokenized provider UI, pending/failed/success based on server | payments |
| SI and label | prominent SI, download PDF, attachment instructions, printerless alternative | labels |
| Deposit pairing | confirm physical site, scene approval, accepted/pending evidence status | pairing, sessions |
| Tracking/detail | meaningful milestones, promised window, exception support | tracking |
| Receive/claim | verify invitation contact, package-scoped access, pickup QR | claims, grants |
| History/support | cancel pre-deposit, request return afterward, receipts/incidents | shipments, exceptions |

No address-based home delivery in pilot. Apartment membership controls location eligibility, not customer account existence. Do not show driver location or full route to unrelated recipients.

## Driver screens

My Shift → Assigned Runs → Run Details → Load/Collect → Active Route → Stop Detail → Scan/Session → Exceptions → Reconciliation. Run header: type INBOUND/OUTBOUND/RETURN, hub, date/wave, vehicle, expected parcels, distinct accepted count, revision and status. Route map is accompanied by ordered stop list. Navigation launches external navigation with public stop address; no recipient contact in URL.

Inbound pickup screen: expected parcels/compartments per stop, Authenticate at locker, open one authorized door, scan primary package QR, observe/confirm removal. Destination displayed for identification; next transport task remains HUB. Complete stop only if every manifest item is collected or explicitly exceptioned. Missing parcel is not silently removed.

Hub handoff screen: Awaiting hub scan, received X/Y, outstanding IDs. Driver cannot self-certify hub receipt. Hub staff scan independently using their role. Inbound run completion cannot discard driver-held items.

Outbound load screen: expected 10, scanned unique 0. Each camera/wedge scan submits stable client_event_id and run revision; show package ID/SI suffix, final locker, stop, success/error feedback. Disable camera auto-repeat until result presented; backend dedup remains authoritative. Nine plus repeated first parcel stays nine. Return Wrong route/Wrong driver/Wrong hub/Revoked label/Not staged/Route changed with actionable resolution. Scan label to staging preview is separate from custody load action.

Departure button enabled only when expected set equals accepted loaded set and every accepted package is still owned by driver, vehicle limits fit and route version acknowledged. Partial departure requires dispatcher revision excluding only parcels not physically loaded; print/sticker and UI updated. Already-loaded parcel reassignment requires handoff/return.

Stop details group six parcels for locker A, four for B in fixture. Primary scan at final stop creates session; app must show Pending deposit until terminal evidence reconciles. A green scan alone is not Delivered. If full/offline/access blocked, preserve driver custody and request approved return task. Do not silently substitute a nearby final locker.

## Network and concurrency UX

All command buttons use persistent client UUID and payload fingerprint. Offline: cached route is visible with Last synced, queued scans clearly marked Pending; no authoritative counter increments, new door authority or departure. Restore after app kill preserves pending command IDs, not bearer access beyond secure storage. Server conflict returns latest revision; ask driver to acknowledge updated stop order while stopped. Never nag for scanning interaction while moving. No background GPS dependency for pilot; optional foreground location/navigation only with permission.

Accessibility: large scan targets, audible plus visible feedback, manual typed-label recovery with role policy, camera-permission help, high contrast, no color-only error distinction. Manual typed identity is not a physical scan; flag assurance and require supervisor approval where scan is mandatory and unreadable. Test small Android device, iPhone, kiosk resolution and desktop hub scanner separately.

## Component plan

Shared package: API DTOs, identifier parser, error codes and status labels. Separate platform implementations for CameraScanner, SecureTokenStore, PrintLabel, NavigationLauncher, PendingCommandStore. Domain rules stay on backend. Unit-test parsers/state presentation; end-to-end tests exercise server authorization and driver workflow with simulator, not just mocked success screens.

## Phase 1 canonical amendment — 2026-09-26

Read [phase1_END_TO_END_DELIVERY_FLOW.md](../../phase1_END_TO_END_DELIVERY_FLOW.md).

Customer UX additions:
- Shipment wizard explicitly captures recipient contact/address, selected destination locker, and SMALL/MEDIUM/LARGE estimated size.
- Support "Send to myself".
- Show published interior dimensions/examples for each size.
- Show exact size-only price before payment (development defaults $1/$2/$3).
- Origin-deposit UX supports upgrade payment difference before a larger compartment can open.
- Tracking uses SI as the public ZPX tracking reference.
- Sender may obtain/revoke/share a one-time pickup grant when sender is also recipient.

Driver UX additions required in Phase 1:
- Availability control: AVAILABLE/BUSY/OFFLINE.
- Nearby pickup opportunities / offers.
- Offer detail includes origin locker(s), package count/size summary, hub, pickup window/deadline.
- Atomic accept/decline; stale/expired offers cannot assign work.
- Driver may accept multiple nearby origin lockers and receive one assembled INBOUND run to the hub.
- Package custody still transfers one package at a time during scan/removal.

Future preferred-route/carpool-style matching is Phase 2+ and must reuse the Pickup Demand / Driver Offer model.

