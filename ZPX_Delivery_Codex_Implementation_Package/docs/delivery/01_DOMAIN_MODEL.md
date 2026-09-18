# Domain Model

## Shipment

Represents the customer-facing delivery contract.

A shipment does not change identity when its route changes.

Key fields:
- shipment_id
- sender_user_id
- recipient_user_id / recipient contact snapshot
- origin_locker_id
- final_destination_locker_id
- service_level
- package data
- quoted_price
- paid_price
- promised_delivery_at
- shipment_status
- active_route_plan_id
- SI reference

## RoutePlan

A versioned proposed/active path for one shipment.

Example:

AUS-001 -> AUS-HUB-02 -> DAL-HUB-01 -> DAL-091

A reroute creates a new route-plan version rather than mutating route history invisibly.

## ShipmentLeg

One planned transport edge between two locker nodes.

One shipment may have many legs.

A leg may be:
- planned
- available_for_match
- offered
- assigned
- in_transit
- completed
- cancelled
- failed

## DriverTrip

A driver-declared or ZPX-generated movement opportunity.

Examples:
- Austin -> Dallas
- Downtown Austin -> Round Rock
- ZPX generated multi-stop local route

## DriverTask

Work assigned/offered to a driver.

A task can contain many packages and many stops.

## RouteManifest

A batch authorization object grouping packages for loading/unloading.

## CustodyEvent

Immutable record of custody transfer.

## LockerNode

Logical delivery node. One physical locker may have roles:
- ENDPOINT
- RELAY
- HUB

## ShippingIdentifier (SI)

Persistent shipment identity and destination-authorization reference.

Must support:
- final locker binding
- destination-zone binding
- expiration
- relay policy
- status
- validation rules

## PricingQuote

Immutable quote snapshot.

## DriverEarning

Earning line items generated from route/task completion:
- base route
- package bonus
- stop bonus
- priority bonus
- linehaul
- on-time bonus
- adjustment
