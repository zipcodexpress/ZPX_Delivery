# Pricing and Driver Compensation

## Customer Pricing

```
customer_price =
base_fee
+ expected_transport_cost
+ size_fee
+ weight_fee
+ service_level_fee
+ handling_cost
+ risk_allowance
+ demand_adjustment
- consolidation_discount
+ target_margin
```

Do not calculate customer price only from straight-line distance.

## Driver Pay Models

### Local batch route
base_route_pay
+ package_bonus
+ stop_bonus
+ priority_bonus
+ batch_bonus

### Multi-stop route
guaranteed_route_pay
+ incremental_package_pay
+ completion_bonus

### Intercity
guaranteed_linehaul_pay
+ volume_bonus
+ on_time_bonus

## Driver Opportunity Metric

```
incremental_earnings_per_minute =
offered_incremental_pay / incremental_minutes
```

Display this indirectly as attractive earning opportunities; store metric internally.

## Example

Base route: $10
8-package bonus: $14
stop pay: $5
batch bonus: $4
total driver pay: $33

## Financial audit

Every earning must be represented as append-only earning-line records before settlement.
Never store only a mutable driver balance.
