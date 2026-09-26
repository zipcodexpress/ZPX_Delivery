# Hub workbench, routes and administration

## Hub operator workflow

Receiving session selects inbound run and hub; scan each primary label. Require current driver custody and manifest membership; record received parcel individually and clear its active inbound allocation. If wrong hub/unknown parcel, supervised exception intake records actual possession/quarantine with reason without falsely completing planned delivery. Show expected/received/missing/extra separately. Close session with discrepancy record; do not force all expected items into received status.

Sorting scan resolves destination, service cutoff and eligible outbound wave. Operator scans staging slot; backend checks slot hub, destination and run. Confirmed staging creates package/run/slot association. Slot and routing sticker barcode namespaces are distinct from primary label. Routes may group multiple destination slots into one lane; every package remains separately identified. Re-sort before loading changes assignment with audit/version; after load requires custody handoff.

Loading: driver signs into assigned outbound run and scans each parcel; hub worker may supervise but cannot impersonate driver. One package scan → one custody transfer. Tote/container optional for grouping; container scan is not bulk transfer. Daily inventory counts physically scan parcels; mismatch creates investigation, not overwrite of custody.

Printing station: primary replacement labels require supervisor role and identity evidence; route stickers generated after staging; print jobs track status and retry without issuing new identity. Browser printing is supervised; automatic OS printer agents are optional adapter work. Existing TSCLIB wrapper proves an interface reference, not installed hub facilities.

## Route model

Route template → dated run → ordered stops → versioned manifest → per-parcel tasks. INBOUND ends at hub; OUTBOUND begins at hub; RETURN ends at hub. One driver/vehicle per active run in pilot, checked against shift overlap. Package appears in at most one active movement allocation; a transfer must release prior allocation before next. Multi-hop shipment schema is not needed to drive Phase 1: stable package plus ordered movement records preserves history and allows later hops.

Phase 1 planner is deterministic, dispatcher-assisted, not an optimizer. Algorithm:

1. Select service wave/cutoff and confirmed staged eligible packages.
2. Group by final locker; compute count, weight, bounding dimensions and volume estimates.
3. Place destination groups into an appropriate route template/vehicle within shift/time windows; large group may split across runs BEFORE loading.
4. Validate stop access windows, destination compatible capacity estimate and loading limits. Destination free count is advisory until reservation; stale capacity blocks promising guaranteed slot.
5. Dispatcher sets final stop order and publishes revision with exact package set. Route geometry/road-time adapter can return estimate or Unknown; never fabricate ETA.
6. Driver scans load, server verifies live custody/capacity, then departs.

Driver may select a preferred AVAILABLE run before dispatch assignment; acceptance must atomically assign driver and vehicle once. No bidding, per-package marketplace or autonomous addition to active route. Mid-run change preserves loaded custody and requires acknowledged revision. Forecasted free compartments do not authorize deposit; final session does.

Capacity: max package count, total weight/volume, individual largest dimension/fit, stop handling time and shift duration. Inbound load rises at each stop; outbound load falls; check load on every segment. Mixed inbound/outbound runs are out of initial UI scope. Do not count a large parcel as fitting just because volume sum is small. No incremental-minutes payout formulas in pilot; compensation is fixed shift/route export configured by operations.

## Administration modules

| Module | Core actions | Restrictions |
|---|---|---|
| Locations | onboard site, mode, hours, accessibility/printer capability | property approval before HYBRID |
| Hardware inventory | import cabinet/body/door mapping, protocol profile | no inferred address remapping |
| Ownership commissioning | freeze, inspect/drain, generation publish and ACKs | supervisor; never direct live free/occupied toggle |
| Users/roles | verified contacts, links, staff scopes | least privilege; no shared legacy sessions |
| Drivers/vehicles | eligibility, documents reference, shifts, limits | role approval; suspended cannot start new run |
| Dispatch | templates, waves, publish, assignment, partial-departure revision | protect loaded packages/custody |
| Shipments | status/timeline, label reprint, pre-deposit cancel | no arbitrary custody editing |
| Hub | receiving/sorting/staging/inventory | hub-scoped access |
| Exceptions/support | damage/missing/unknown door, return tasks, claims | reasons/evidence and compensating events |
| Finance | quotes, paid/refund status, shift/pay export | no copying apartment wallet balances |
| Audit/reporting | immutable event timeline, scan history, reconciliation | exports role-scoped and redacted |
| Device health | last seen, pending commands, journal backlog | health data is not proof of delivery |

RBAC roles: CUSTOMER, DRIVER, HUB_RECEIVER, HUB_SORTER, DISPATCHER, SUPPORT, FINANCE, DEVICE_TECH, OPERATIONS_ADMIN. Scope role grants to organization and optionally hub/location. Split FINANCE refund authority from door-operation authority. Maintenance physical opening requires DEVICE_TECH reason plus site authorization; it creates a maintenance incident, not a customer pickup event.

## Required reports

Unaccounted parcels by current custodian; receiving shortages; hub dwell time; runs expected/scanned/delivered/returned; unknown compartment sessions; scans rejected by reason; label replacement history; on-time performance; route cost and capacity; legacy/delivery partition utilization separately. Every run close and shift close shows remaining driver-held parcels and prevents silent loss.

## Phase 1 canonical amendment — 2026-09-26

Read [phase1_END_TO_END_DELIVERY_FLOW.md](../../phase1_END_TO_END_DELIVERY_FLOW.md).

The earlier "assigned drivers, fixed route order, no gig marketplace" description is narrowed:
- Outbound Phase 1 remains dispatcher/run based as designed.
- Inbound Phase 1 now requires Pickup Demand / Driver Offer before run materialization.
- Confirmed origin deposits create pickup-demand items.
- Compatible items may aggregate by origin locker, hub and pickup window.
- Multiple eligible nearby AVAILABLE drivers may receive the same offer, but first valid atomic acceptance wins the affected demand/package set.
- One driver may accept several nearby origin lockers and the backend assembles/updates an exact INBOUND run.
- Phase 1 does not use bidding, dynamic auctions or preferred-route optimization.
- Preferred-route / rideshare-style matching is future Phase 2+.

Admin additions:
- size classes and published interior dimensions;
- size-only versioned rate card;
- pickup demand queue/SLA;
- driver availability/offer monitoring;
- stale-demand re-offer/dispatcher override;
- offer/acceptance audit.

