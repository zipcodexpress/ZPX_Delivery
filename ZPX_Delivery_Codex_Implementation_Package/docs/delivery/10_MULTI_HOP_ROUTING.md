# Multi-Hop Routing

## Model

Treat locker network as a directed weighted graph.

Nodes:
- locker nodes / hubs

Edges:
- possible driver/scheduled-route movement opportunities

Path example:

A -> B -> C -> D

## Candidate Generation

For each shipment:
1. generate direct candidate,
2. identify relay nodes inside viable corridors,
3. identify scheduled-route hubs if intercity,
4. generate bounded-hop candidate paths,
5. estimate time, cost, transfer count, capacity risk,
6. rank paths.

MVP:
- max 2 legs.

Schema:
- unlimited legs.

## Route Score

```
route_score =
  sla_score
+ capacity_score
+ expected_driver_supply_score
+ consolidation_score
+ reliability_score
- transport_cost_score
- transfer_penalty
- dwell_time_penalty
- exception_risk
```

## Transfer penalty

Each additional locker transfer introduces handling and delay risk.
Do not select more hops purely because a path is geographically shorter.

## Route plan versioning

Never silently mutate a route already in execution.

On reroute:
- preserve completed legs,
- cancel invalid future legs,
- create new route plan version,
- create replacement future legs,
- store reroute reason.
