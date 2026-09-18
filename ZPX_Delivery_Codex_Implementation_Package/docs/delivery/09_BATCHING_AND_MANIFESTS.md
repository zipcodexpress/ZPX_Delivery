# Batching and Route Manifests

## Principle

Driver economics improve when one route carries many packages.

A driver task may transport multiple shipment legs.

## Route Manifest

Manifest groups packages authorized for one task or task segment.

Fields:
- manifest_id
- task_id
- driver_id
- origin locker
- destination locker or stop
- package list
- status
- token/QR
- valid time window

## Batch Pickup

One manifest scan should:
1. authenticate driver,
2. validate task,
3. resolve package set,
4. validate custody,
5. return authorized compartments,
6. open or sequence compartments,
7. write individual package custody events.

Never replace per-package custody records with only a manifest-level record.

## Capacity

Batch planner tracks:
- package count
- size buckets
- weight
- optional volume
- current vehicle load

## Batch objective

Prefer assignments that:
- increase packages moved per driver mile,
- reduce repeated locker stops,
- improve driver pay per incremental minute,
- maintain SLA.
