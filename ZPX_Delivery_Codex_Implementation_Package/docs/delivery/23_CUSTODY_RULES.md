# Custody Rules

## Custodian types

- CUSTOMER
- LOCKER
- DRIVER
- HUB_OPERATOR (future)
- FLEET (future)

## Rule

At any point after origin deposit and before recipient pickup, each physical package should have one logical custodian.

## Transfer examples

Sender -> Origin Locker
Origin Locker -> Driver 1
Driver 1 -> Relay Locker
Relay Locker -> Driver 2
Driver 2 -> Destination Locker
Destination Locker -> Recipient

## Event requirements

Each transfer requires:
- prior custody valid,
- authorization valid,
- physical operation confirmed,
- idempotency key,
- immutable event write.

## Transaction boundary

For a locker pickup:
1. validate current custody = locker,
2. validate authorized driver,
3. confirm successful compartment access / pickup event,
4. append custody event,
5. update materialized current-custody projection.

The append-only event is the audit source.
A current-custody column/table may exist as a projection for fast querying.
