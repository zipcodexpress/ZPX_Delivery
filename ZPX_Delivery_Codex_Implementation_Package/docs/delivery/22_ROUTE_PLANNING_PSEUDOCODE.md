# Multi-Hop Route Planning Pseudocode

```text
function planShipment(shipment):
    origin = shipment.currentPhysicalNode
    destination = shipment.finalDestination
    service = shipment.serviceLevel

    candidates = []

    candidates += directPath(origin, destination)

    relayNodes = findEligibleRelayNodes(
        origin,
        destination,
        corridorWidth = config.relayCorridorWidth
    )

    for relay in relayNodes:
        candidates += path(origin, relay, destination)

    if service.allowsMoreHops:
        candidates += boundedGraphSearch(
            origin,
            destination,
            maxHops = config.maxHops[service],
            nodeFilter = nodeHasCapacityAndIsOperational
        )

    for candidate in candidates:
        candidate.expectedDriverSupply = estimateDriverSupply(candidate)
        candidate.cost = estimateTransportCost(candidate)
        candidate.time = estimateEndToEndTime(candidate)
        candidate.capacityRisk = estimateCapacityRisk(candidate)
        candidate.transferRisk = transferPenalty(candidate.hops)
        candidate.consolidation = estimateConsolidation(candidate)

        candidate.score = scoreRoute(candidate)

    selected = bestFeasible(candidates)

    createVersionedRoutePlan(selected)
    createShipmentLegs(selected)

    storeDecisionAudit(candidates, selected)

    return selected
```
