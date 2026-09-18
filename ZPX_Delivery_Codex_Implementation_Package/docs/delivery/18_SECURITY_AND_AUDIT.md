# Security and Audit

## Authentication

- consumer auth
- driver auth
- admin auth
- locker/device credentials

## Authorization

Locker operations must authorize operation-specific tokens.

## Replay Prevention

Each authorization:
- nonce
- expiry
- operation
- locker
- shipment/manifest scope

## Audit

Audit:
- state transitions
- routing decisions
- driver assignment
- offer acceptance
- manual operator changes
- compartment authorization
- custody transitions
- pricing
- earning adjustments

## Sensitive data

Do not expose recipient phone/address unnecessarily to drivers.
Drivers primarily need locker locations and package handling data.

## Fraud controls

Future hooks:
- impossible travel
- repeated failed validation
- unusual pickup behavior
- GPS mismatch
- device mismatch
- excessive cancellations
