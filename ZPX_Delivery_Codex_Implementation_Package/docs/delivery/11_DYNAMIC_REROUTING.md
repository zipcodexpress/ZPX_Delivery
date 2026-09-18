# Dynamic Rerouting

Triggers:
- locker offline
- locker full
- compartment mismatch
- driver cancellation
- failed pickup
- route delay
- SLA risk
- weather/road event
- higher-capacity route available
- scheduled route missed

## Rules

1. Completed custody history is immutable.
2. Replan only remaining path.
3. Current physical package location is the new routing origin.
4. Final destination policy remains unchanged unless customer/operations explicitly changes it.
5. Any destination change requiring customer consent must be separately authorized.
6. New routing decision should record reason and score comparison.

## Example

Original:
A -> B -> C -> D

Package has reached B.
C becomes unavailable.

New:
B -> E -> D

Preserve:
A -> B completed.

Cancel:
B -> C
C -> D

Create:
B -> E
E -> D
