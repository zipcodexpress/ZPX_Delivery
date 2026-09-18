# Test Strategy

## Unit tests

- shipment state transitions
- leg state transitions
- SI expiration
- wrong-destination validation
- driver capacity
- detour thresholds
- match score calculation
- pricing components
- earning calculations
- route-plan versioning

## Integration tests

- shipment + quote + payment
- origin locker deposit
- relay drop
- relay pickup
- final destination
- recipient pickup
- driver cancellation
- locker full reroute
- batch manifest
- idempotent locker callbacks

## Property/invariant tests

Invariants:
- one active shipment route plan maximum
- completed custody event cannot be deleted
- final locker must satisfy SI destination policy
- package never exceeds vehicle capacity assignment
- completed route leg cannot be reassigned
- no package may be in two active driver tasks simultaneously
- each successful custody transfer creates exactly one event

## Load tests

Focus:
- tracking reads
- driver match query
- batch offer generation
- locker validation
- event ingestion

## Failure tests

- DB timeout after locker authorization
- duplicate callback
- driver app offline
- locker goes offline
- payment callback duplicate
- route provider timeout
