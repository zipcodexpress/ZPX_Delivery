# ZPX Locker-to-Locker Delivery Network — Codex Implementation Package

This repository package defines the implementation architecture for the ZipcodeXpress multi-hop locker-to-locker delivery platform.

## Core concepts

- Shipment identity is independent from route.
- A shipment may have unlimited route legs.
- A driver task may contain many shipments.
- A package may move through multiple relay lockers before final delivery.
- Smart lockers are asynchronous custody-transfer nodes.
- Driver planned routes are first-class inputs to matchmaking.
- The matching engine should maximize package movement while minimizing incremental driver miles/minutes.
- Shipping Identifier (SI) and final-destination validation remain independent of the current route.
- Every physical custody transition is append-only and auditable.

## Recommended implementation order

1. Core shipment domain
2. SI and locker validation
3. Driver / vehicle / planned trip domain
4. Driver tasks and custody transfer
5. Route manifests and package batching
6. Matchmaking and detour scoring
7. Multi-hop routing
8. Dynamic rerouting
9. Pricing, driver compensation, settlement
10. Operations, claims, analytics, hardening

Start with `docs/delivery/00_CODEX_IMPLEMENTATION.md`.
