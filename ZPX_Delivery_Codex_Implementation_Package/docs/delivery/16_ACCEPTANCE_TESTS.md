# MVP Acceptance Tests

## End-to-End Relay Test

Given:
- Locker A is an endpoint.
- Locker B is an authorized relay.
- Locker D is final destination.
- Driver 1 route overlaps A->B.
- Driver 2 route overlaps B->D.

When:
1. Customer creates A->D shipment.
2. System quotes and receives payment.
3. SI is generated.
4. Sender deposits package in A.
5. Route engine creates A->B->D.
6. Matchmaking assigns Driver 1 to A->B.
7. Driver 1 picks up a batch of at least 2 packages.
8. Driver 1 deposits shipment in B.
9. Custody changes Driver1 -> LockerB.
10. Matchmaking assigns Driver 2.
11. Driver 2 picks up shipment.
12. Driver 2 attempts final deposit at incorrect Locker C.
13. System denies authorization.
14. Driver 2 proceeds to Locker D.
15. Final deposit succeeds.
16. Recipient is notified.
17. Recipient retrieves package.
18. Shipment becomes COMPLETED.

Then:
- All custody transitions exist exactly once.
- Wrong destination never opens a door.
- Shipment ID and SI are unchanged through all hops.
- Active route plan and route history are visible.
- Both driver earnings are calculated.
- Driver task may contain multiple packages.
- Tracking view hides unnecessary internal complexity.

## Idempotency

Repeat every locker callback twice.
Expected:
- no duplicate custody events,
- no duplicate earnings,
- no duplicate package state transitions.

## Driver Capacity

Attempt to assign more packages/weight than vehicle capacity.
Expected:
- candidate rejected.

## Driver Cancellation

Driver cancels after assignment but before pickup.
Expected:
- leg returns to AVAILABLE,
- no custody changes,
- new driver may be assigned.

## Locker Full

Relay locker becomes full.
Expected:
- active route re-plans remaining path,
- new route-plan version created,
- completed legs preserved.

## Wrong Driver

Unassigned driver attempts package pickup.
Expected:
- validation denied.
