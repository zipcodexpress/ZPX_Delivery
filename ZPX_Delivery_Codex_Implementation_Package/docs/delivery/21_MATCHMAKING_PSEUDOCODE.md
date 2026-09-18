# Matchmaking Pseudocode

```text
function findMatches(shipmentLeg):
    candidates = findActiveDriverTripsNearCorridor(
        shipmentLeg.origin,
        shipmentLeg.destination,
        shipmentLeg.timeWindow
    )

    ranked = []

    for trip in candidates:
        if !driverEligible(trip.driver):
            continue

        if !capacityFits(trip, shipmentLeg.package):
            continue

        baseline = trip.baseRoute

        routeWithLeg = calculateInsertion(
            baseline,
            shipmentLeg.origin,
            shipmentLeg.destination
        )

        detourMinutes = routeWithLeg.minutes - baseline.minutes
        detourMiles = routeWithLeg.miles - baseline.miles

        if detourMinutes > trip.maxDetourMinutes:
            continue

        if trip.maxDetourMiles != null &&
           detourMiles > trip.maxDetourMiles:
            continue

        projectedDrop = calculateProjectedDropTime(routeWithLeg)

        if projectedDrop > shipmentLeg.latestDropoff:
            continue

        features = {
            routeAlignment,
            pickupProximity,
            destinationAlignment,
            timeCompatibility,
            batchValue,
            driverReliability,
            capacityEfficiency,
            normalizedDetourTime,
            normalizedDetourDistance,
            slaRisk
        }

        score = weightedScore(features, currentConfig)

        payout = compensationEngine.calculate(
            trip,
            shipmentLeg,
            detourMinutes,
            detourMiles,
            batchContext
        )

        ranked.append({
            trip,
            score,
            payout,
            detourMinutes,
            detourMiles,
            features
        })

    sort ranked by:
        score DESC,
        detourMinutes ASC

    persistScoringAudit(ranked)

    return ranked
```

## Batch extension

After single-leg scoring:
1. group compatible legs by pickup corridor,
2. group by destination corridor,
3. test vehicle capacity,
4. calculate combined insertion route,
5. compute combined payout,
6. compare package-per-mile and earnings-per-incremental-minute,
7. generate batch offer if superior.
