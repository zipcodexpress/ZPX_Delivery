# Codex Implementation Instructions

## Objective

Implement the ZipcodeXpress Delivery Network as a modular logistics domain that integrates with the existing ZipcodeXpress locker platform without unnecessarily rewriting working locker functionality.

The first production scope is Austin-to-Austin locker delivery with support for:

- direct locker-to-locker delivery,
- one relay locker,
- multiple packages per driver task,
- planned driver routes,
- route matching,
- pricing,
- SI generation,
- origin deposit,
- custody events,
- relay transfer,
- destination validation,
- recipient pickup,
- driver earnings.

The schema and service boundaries MUST support unlimited future hops even though the MVP UI may expose only zero or one relay.

## Non-negotiable design rules

1. Never model a shipment as belonging directly to one driver.
2. Never assume one driver task contains one package.
3. Shipment identity must survive rerouting.
4. Shipment legs may be cancelled and regenerated.
5. Driver tasks may aggregate many shipment legs.
6. Custody history is append-only.
7. Locker open authorization must be scoped by driver + route/task + shipment/manifest + locker + time window.
8. SI must identify the shipment and final destination policy, not permanently encode every intermediate hop.
9. All locker validation mutations must be idempotent.
10. Pricing, driver pay, route-score weights, batch bonuses, service-level rules, and route constraints must be configuration-driven.
11. Store the inputs and result of important routing/matching decisions for auditability.
12. Do not build AI/ML into the first routing version. Use deterministic scoring with configurable weights.

## Suggested architecture

Use a modular monolith for the initial release unless the existing ZPX platform already has service boundaries requiring otherwise.

Suggested modules:

- Shipment
- Address/SI
- Locker Integration
- Routing
- Driver
- Matchmaking
- Dispatch
- Manifest/Batch
- Custody
- Pricing
- Payment
- Driver Compensation
- Tracking
- Notifications
- Claims
- Operations

## Implementation order

### Phase A — Foundation
- Create database tables in `sql/001_delivery_core.sql`.
- Add repository/model classes.
- Add service interfaces.
- Add enum/state definitions.
- Add migration and rollback scripts.

### Phase B — Shipment + Locker
- Shipment create/update.
- Quote request.
- SI generation.
- Origin locker reservation.
- Sender deposit validation.
- Final locker validation.
- Recipient pickup.
- Append-only shipment/custody events.

### Phase C — Driver
- Driver profile.
- Vehicle.
- Driver trip.
- Route polyline.
- Capacity.
- Driver availability.

### Phase D — Tasks + Batch
- Driver tasks.
- Task stops.
- Task packages.
- Route manifests.
- Batch pickup/drop.
- Manifest QR/token authorization.

### Phase E — Matchmaking
- Route corridor calculation.
- Pickup/drop detour calculation.
- Time compatibility.
- vehicle/package compatibility.
- capacity compatibility.
- driver reliability score.
- batch value score.
- total match score.
- ranked offer creation.

### Phase F — Multi-hop
- Candidate locker-path generation.
- relay selection.
- multiple shipment legs.
- leg dependencies.
- next-leg availability.
- relay dwell tracking.

### Phase G — Dynamic rerouting
- locker full/offline.
- driver cancellation.
- SLA risk.
- re-plan remaining route only.
- preserve completed legs.
- create a new route-plan version.

### Phase H — Money
- customer pricing.
- driver route pay.
- per-package incentives.
- batch bonus.
- stop pay.
- linehaul pay.
- settlement ledger.

## Definition of done for MVP

The acceptance scenario in `16_ACCEPTANCE_TESTS.md` passes end to end with automated integration tests and an admin-visible immutable event trail.
