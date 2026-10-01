# ZPX_Delivery — Package Tracking & Custody Visibility Development Plan

**Repository:** `zipcodexpress/ZPX_Delivery`  
**Recommended location:** `docs/PACKAGE_TRACKING_CUSTODY_PLAN.md`  
**Recommended branch:** `feature/package-tracking-custody-view`  
**Recommended sequence position:** After P3.3 discrepancy completion and before P4.1 Hub Operations UI

---

## 1. Purpose

Add a first-class package tracking and custody visibility feature to ZPX_Delivery.

The system should answer:

- Where is this package now?
- Who currently has custody?
- Which hub, locker, or driver is responsible?
- What physical handoffs occurred?
- Were any scans rejected?
- Was the package short, damaged, extra, returned, quarantined, or otherwise exceptional?
- What events led to the current state?

This feature should provide a single authoritative package timeline derived from package state plus append-only operational history.

---

## 2. Recommended Development Order

Update the sequence to:

```text
P3.3 discrepancy completion
    ↓
Package Tracking & Custody Visibility
    ↓
P4.1 Hub Operations UI
    ↓
Scan contract normalization
    ↓
P4.2 Outbound Driver Workflow
    ↓
P5.1 Final Delivery
```

Why here:

1. P3.3 introduces explicit discrepancies.
2. Tracking should expose those discrepancy events.
3. P4.1 hub screens can reuse the package-detail/timeline API and UI component.
4. P4.2/P5.x will add more custody events, so establish the tracking model before those expand.

---

## 3. Core Architecture Principle

ZPX_Delivery is a custody system, not only a status-display system.

The current package row answers:

```text
Where is it now?
Who has custody now?
```

The event history must answer:

```text
How did it get there?
Who transferred it?
Who received it?
When?
Where?
Was the event accepted or rejected?
```

Do not reconstruct the full history only from the current `packages` row.

---

## 4. Feature Scope

Implement two tracking views.

### 4.1 Internal Operations Tracking

Authorized operations users should be able to see:

- package ID
- package UUID
- shipment/public reference
- shipping identifier (SI)
- package state
- package version
- current custodian type
- current custodian reference
- current physical location
- origin
- destination
- current run
- current hub
- current driver where authorized
- custody history
- scan history
- receiving history
- staging history
- dispatch/load history
- discrepancy history
- later locker deposit/pickup history

### 4.2 Customer Tracking

Customers should see a privacy-safe timeline such as:

```text
Shipment created
At origin
Picked up
At hub
Processing at hub
Out for delivery
At destination locker
Picked up
Delivered
Exception / delayed
```

Do not expose internal driver IDs, hub staff IDs, sensitive locker details, rejected security probes, or internal exception notes.

---

## 5. Recommended Backend API

Prefer one consolidated internal timeline endpoint:

```text
GET /packages/{package_id}/timeline
```

Possible later lookup helpers:

```text
GET /packages/by-reference/{public_reference}/timeline
GET /packages/by-si/{si}/timeline
```

For customers, use a separately authorized representation, for example:

```text
GET /customer/shipments/{shipment_id}/tracking
```

Do not create multiple overlapping APIs unless required by existing architecture.

---

## 6. Internal Timeline Response

Conceptual response:

```json
{
  "package": {
    "id": "123",
    "package_uuid": "...",
    "public_reference": "ZPX-20260922-00123",
    "si": "ZPX-DEV-ABC123",
    "state": "AT_HUB",
    "version": 3
  },
  "current_custody": {
    "type": "HUB",
    "ref": "4",
    "display_name": "Austin Hub",
    "location_id": "3"
  },
  "events": [
    {
      "type": "CUSTODY_TRANSFER",
      "occurred_at": "2026-09-22T21:05:12Z",
      "from": {"type": "DRIVER", "ref": "12"},
      "to": {"type": "HUB", "ref": "4"},
      "location_id": "3",
      "run_id": "55",
      "result": "ACCEPTED"
    }
  ]
}
```

This is conceptual. Codex must inspect existing contracts/types before defining the final schema.

---

## 7. Timeline Event Categories

Normalize existing authoritative records into timeline events such as:

```text
SHIPMENT_CREATED
LABEL_CREATED
AT_ORIGIN
DRIVER_PICKUP
HUB_RECEIVE
HUB_RECEIVE_REJECTED
SHORT_REPORTED
DAMAGED_REPORTED
EXTRA_REPORTED
STAGED
STAGE_REJECTED
DISPATCH_CREATED
DISPATCH_ACCEPTED
OUTBOUND_LOAD
OUTBOUND_LOAD_REJECTED
DEPARTED_HUB
ARRIVED_DESTINATION
LOCKER_DEPOSIT
RECIPIENT_PICKUP
RETURN_STARTED
RETURN_RECEIVED_AT_HUB
QUARANTINED
DELIVERED
```

Do not create event types that have no authoritative evidence.

---

## 8. Authoritative Data Sources

Inspect and reuse existing tables/services such as:

```text
packages
shipments
package_labels
shipping_identifiers
custody_events
scan_events
manifest_items
route_runs
route_run_stops
receiving records
staging_assignments
dispatch_calls
discrepancy / exception records
audit_events
locker events
```

Preferred model:

```text
existing authoritative records
        ↓
timeline query/service
        ↓
normalized ordered events
```

Do not introduce a duplicate timeline-history table unless there is a strong architectural reason.

---

## 9. Current Custody Resolution

The service should expose:

```text
current state
current custodian_type
current custodian_ref
current location
package version
```

Examples:

```text
AT_ORIGIN
custody = LOCKER
```

```text
INBOUND_CUSTODY
custody = DRIVER
```

```text
AT_HUB
custody = HUB
```

```text
STAGED
custody = HUB
```

```text
OUTBOUND_CUSTODY
custody = DRIVER
```

Use actual existing enums.

---

## 10. Operations UI

Add a package-search/tracking screen to `operations-web`.

Suggested search keys:

```text
Public reference
Package ID
Package UUID
SI
```

Suggested view:

```text
PACKAGE TRACKING

Package
────────────────────────
Reference:      ZPX-20260922-00123
SI:             ZPX-DEV-ABC123
State:          AT_HUB
Version:        3
Destination:    AUS-014

Current Custody
────────────────────────
Custodian:      HUB
Hub:            Austin Central Hub
Location:       HUB-AUS-01
Since:          5:07 PM

Timeline
────────────────────────
2:10 PM   Shipment created
3:01 PM   Deposited at origin
4:12 PM   Driver pickup accepted
5:07 PM   Hub receipt accepted
5:32 PM   Staged for AUS-014
```

---

## 11. Discrepancy Display

P3.3 discrepancies must appear in the package timeline.

SHORT example:

```text
5:07 PM   SHORT reported
           Package not physically received
           Custody remains DRIVER
           Status: OPEN
```

DAMAGED example:

```text
5:09 PM   Hub receipt accepted
           Custody → HUB

5:10 PM   DAMAGED reported
           Status: OPEN
```

EXTRA example:

```text
5:12 PM   EXTRA package detected
           Not on manifest
           Custody unchanged
           Status: OPEN
```

Never imply a custody transfer when none occurred.

---

## 12. Rejected Scan Visibility

Authorized operations users should be able to see relevant rejected scans, for example:

```text
5:14 PM   HUB_RECEIVE rejected
           Reason: NOT_ON_MANIFEST
```

Do not expose sensitive security details to unauthorized roles or customers.

Rejected events should only be associated with a package when package identity was safely resolved.

---

## 13. Security and Authorization

Internal tracking must enforce:

- organization scope
- existing role scope
- hub-location scope where appropriate

Customers must only see their own authorized shipments.

Do not create a public unrestricted tracking endpoint.

---

## 14. Privacy Rules

Customer tracking should omit or mask:

```text
driver internal ID
hub staff user ID
device ID
internal exception notes
security-failure details
sensitive custodian_ref values
```

Use customer-friendly descriptions such as:

```text
Picked up by driver
Received at hub
Out for delivery
```

---

## 15. Package Search

Initial internal search should remain narrow:

```text
public_reference
package_uuid
SI
package_id
```

Possible endpoint:

```text
GET /operations/packages/search?q=...
```

Use an existing operations search pattern if one already exists.

All results must remain organization-scoped.

---

## 16. Timeline Ordering

Order by canonical event occurrence time with deterministic tie-breaking.

For example:

```text
occurred_at
event sequence / event ID
```

If both client and server timestamps exist, use the project's established canonical ordering policy and document it.

---

## 17. Idempotency

Retries must not display duplicate business events.

Example:

```text
same driver pickup request retried 3 times
```

should still appear as one accepted custody transfer.

Rejected scan attempts may appear separately when they are distinct recorded attempts.

---

## 18. Package Version Visibility

Internal tracking should show the current package version and, when available, version changes across custody events.

Example:

```text
AT_ORIGIN          version 1
Driver pickup      version 1 → 2
Hub receive        version 2 → 3
```

This will help diagnose stale scan/version failures.

---

## 19. Current Location vs Destination

Tracking must distinguish:

```text
Current location
```

from:

```text
Destination
```

Example:

```text
Current:
Austin Hub

Destination:
AUS-014
```

Never display the destination as though the package is already physically there.

---

## 20. Test Requirements

### A. Driver Pickup

Verify:

```text
AT_ORIGIN / LOCKER
→ INBOUND_CUSTODY / DRIVER
```

and one accepted pickup event in the timeline.

### B. Hub Receive

Verify:

```text
INBOUND_CUSTODY / DRIVER
→ AT_HUB / HUB
```

and the timeline shows the DRIVER → HUB handoff.

### C. SHORT Parcel

Manifest = 5, hub receives 4.

Verify fifth parcel:

```text
custody remains DRIVER
SHORT discrepancy exists
no false HUB custody transfer
```

### D. DAMAGED Parcel

Verify timeline shows receipt plus DAMAGED discrepancy in correct order.

### E. Staging

Verify:

```text
state = STAGED
custody remains HUB
```

### F. Outbound Load

Verify:

```text
STAGED / HUB
→ OUTBOUND_CUSTODY / DRIVER
```

### G. Rejected Scan

Wrong manifest/wrong hub:

```text
no custody change
rejection visible where authorized
```

### H. Authorization

Verify:

- cross-organization package access denied
- unauthorized hub access denied
- customer cannot see another customer's package

---

## 21. Future P5.x Integration

The same timeline should later support:

```text
destination locker arrival
door command sent
physical close evidence
deposit confirmed
recipient pickup
final delivery
return to hub
quarantine
claims
```

The API should be extensible so future milestones add event sources without redesigning the feature.

---

## 22. Branch and Commit Plan

Recommended branch:

```text
feature/package-tracking-custody-view
```

Possible commits:

```text
feat(tracking): add package custody timeline service
test(tracking): cover driver and hub custody transitions
feat(operations): add package search and tracking view
feat(customer): add privacy-safe shipment tracking timeline
docs(tracking): document custody timeline semantics
```

---

## 23. Definition of Done

The feature is complete when:

- internal package timeline endpoint exists
- current state/location/custody are accurate
- custody history comes from authoritative records
- P3.3 discrepancy events appear correctly
- rejected scans do not create false custody changes
- organization/role/location access is enforced
- operations package search works
- operations tracking UI exists
- customer-safe tracking representation exists or is explicitly deferred
- targeted tests pass
- DB/integration suite passes
- `npm run check` passes
- `npm run build` passes
- `docs/CURRENT_STATUS.md` is updated

---

## 24. Codex Task Prompt

```text
Implement Package Tracking & Custody Visibility for ZPX_Delivery.

Read:
- AGENTS.md
- docs/CURRENT_STATUS.md
- docs/PACKAGE_TRACKING_CUSTODY_PLAN.md

Use the local Git repository as the source of truth.

Create:
feature/package-tracking-custody-view

Do not begin until P3.3 discrepancy completion is merged or available on the current branch.

Goals:

1. Add an authoritative package timeline service.
2. Expose current state, custody, physical location, destination, and package version.
3. Build timeline events from existing authoritative records rather than a parallel history store.
4. Include custody events, scan events, hub receiving, staging, dispatch/load, and P3.3 discrepancies.
5. Ensure SHORT does not imply HUB custody.
6. Ensure rejected scans never create false custody transfers.
7. Enforce organization, role, and location scope.
8. Add an operations package search/tracking view.
9. Add a privacy-safe customer tracking representation if it fits naturally; otherwise document it as the next subtask.
10. Add integration/security tests.

Do not:
- expose production label secrets
- create unrestricted public tracking
- duplicate authoritative custody history
- weaken existing authorization
- perform unrelated refactors

Before completion run:

ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py test-db
npm run check
npm run build

Review git diff and git status.
Update docs/CURRENT_STATUS.md.
Commit stable work locally.
```

---

## 25. Repository Placement

Put this file at:

```text
ZPX_Delivery/
└── docs/
    └── PACKAGE_TRACKING_CUSTODY_PLAN.md
```

Also update your master handoff file:

```text
docs/ZPX_DELIVERY_NEXT_DEVELOPMENT_HANDOFF.md
```

Change the sequence from:

```text
P3.3 discrepancy completion
↓
P4.1 Hub Operations UI
↓
Scan contract normalization
↓
P4.2 outbound driver workflow
```

to:

```text
P3.3 discrepancy completion
↓
Package Tracking & Custody Visibility
↓
P4.1 Hub Operations UI
↓
Scan contract normalization
↓
P4.2 outbound driver workflow
```

Do **not** put this feature spec into `AGENTS.md`. `AGENTS.md` should remain focused on development process, safety rules, and handoff behavior.
