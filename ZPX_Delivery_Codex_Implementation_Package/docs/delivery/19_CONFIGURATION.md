# Configuration

Never hard-code operational economics.

Config categories:

## Matching weights
- route_alignment_weight
- pickup_proximity_weight
- destination_alignment_weight
- time_compatibility_weight
- batch_value_weight
- reliability_weight
- capacity_efficiency_weight
- detour_time_weight
- detour_distance_weight
- sla_risk_weight

## Route rules
- max_hops_by_service_level
- max_relay_dwell_minutes
- max_driver_detour_minutes
- max_driver_detour_miles
- relay_transfer_penalty
- minimum_hub_free_capacity

## Pricing
- base_fee
- per_mile
- per_lb
- size fees
- service premiums
- consolidation discounts
- target margin

## Driver pay
- base route pay
- per-package tiers
- stop pay
- batch bonuses
- linehaul bonuses
- on-time bonuses

Version configs and store the version applied to each quote/match/pay calculation.
