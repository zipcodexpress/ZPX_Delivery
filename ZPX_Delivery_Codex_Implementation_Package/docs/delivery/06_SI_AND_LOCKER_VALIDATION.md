# SI and Locker Validation

## Goal

Use the ZipcodeXpress Shipping Identifier to maintain shipment identity and destination authorization throughout multi-hop movement.

## SI policy

The SI should reference:
- shipment
- final destination locker and/or destination zone
- expiration
- relay acceptance policy
- validation policy
- active/revoked status

Intermediate route nodes belong to RoutePlan and ShipmentLeg, not to the immutable public SI identity.

## Validation types

1. ORIGIN_DEPOSIT
2. RELAY_DROP
3. RELAY_PICKUP
4. FINAL_DROP
5. RECIPIENT_PICKUP

## Relay Drop Validation

Required checks:
- shipment active
- SI active
- current leg valid
- driver has active authorization
- target locker matches current leg destination
- target locker supports relay role
- package compatible with compartment
- locker online
- reservation or capacity available
- package not already deposited
- time window valid

## Final Drop Validation

All relay checks plus:
- locker matches authorized final destination or permitted destination rule
- route leg is designated final leg

## Security

Create a short-lived `locker_authorization` token after server validation.

Do not allow driver credentials alone to open doors.

Authorization should bind:
- driver_id
- task_id / manifest_id
- shipment_id or allowed shipment set
- locker_id
- compartment_id
- operation
- expiry
- nonce

## Idempotency

Repeated request with same request_id returns the same logical result and MUST NOT generate duplicate custody events.
