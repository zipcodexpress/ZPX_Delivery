# Driver Route Matching

## Goal

Find drivers already traveling in the direction a shipment leg needs to move.

## Inputs

Driver:
- route polyline
- departure window
- detour tolerance
- vehicle/package capacity
- current assignments
- reliability
- current location if active

Leg:
- origin node
- destination node
- package dimensions/weight
- earliest pickup
- latest pickup
- latest drop
- priority
- payout budget

## Hard filters

Reject candidate if:
- insufficient capacity
- vehicle incompatible
- driver unavailable
- pickup after latest pickup
- projected drop after SLA
- pickup detour exceeds configured maximum
- delivery detour exceeds configured maximum
- driver not eligible
- package restrictions fail

## Scoring

Initial deterministic model:

```
score =
  route_alignment_weight * route_alignment
+ pickup_proximity_weight * pickup_proximity
+ destination_alignment_weight * destination_alignment
+ time_compatibility_weight * time_compatibility
+ batch_value_weight * batch_value
+ reliability_weight * driver_reliability
+ capacity_efficiency_weight * capacity_efficiency
- detour_time_weight * normalized_detour_time
- detour_distance_weight * normalized_detour_distance
- sla_risk_weight * sla_risk
```

All weights must be config records, not constants.

## Match audit

Store:
- score version
- individual feature values
- weights
- final score
- estimated detour
- estimated payout
- selected/not selected
