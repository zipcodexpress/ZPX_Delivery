# ZPX_Delivery — Next Development Handoff for Codex

**Repository:** `https://github.com/zipcodexpress/ZPX_Delivery`  
**Prepared:** 2026-09-22  
**Purpose:** Detailed handoff for the next phase of local Codex development  
**Primary development mode:** Local-first development; local Git repository is the source of truth

---

## 1. Executive Summary

The ZPX_Delivery project has progressed substantially beyond the earlier stacked-PR state.

As of September 22, 2026:

- PR #10 — P2.3 Customer Portal — merged.
- PR #11 — P3.2 Driver inbound collection + driver management — merged.
- PR #12 — P3.3 Hub receiving + P4.1 hub sorting/dispatch/driver pickup — merged.
- Current `main` includes merge commit:

```text
1532680e9eeb079161d083d8bb65e11b69c38948
```

- There are currently no open PRs.
- PR #12 CI/Foundation workflow passed.
- The repository now has working customer, driver inbound, hub receiving, and backend hub dispatch flows.
- The next major goal should NOT be to immediately jump to P4.2.
- First close the important P3.3/P4.1 correctness and usability gaps.

Recommended development order:

```text
1. P3.3 discrepancy completion and receiving lifecycle correctness
2. P4.1 hub operations UI
3. Scan API contract normalization
4. P4.2 outbound load + ordered-stop driver workflow
5. P5.1 final delivery + recipient pickup
6. P5.2 exceptions/returns/quarantine/shift close
7. P7.1 admin/staff/assets only after P5.2
```

The highest architectural priority is:

> Maintain an indisputable package custody chain through every physical handoff.

Do not trade custody correctness for faster feature delivery.

---

# 2. Source of Truth

The local Git repository is authoritative.

Codex must follow the repository's `AGENTS.md`.

Use sources in this order:

1. Current local source code and tests
2. `AGENTS.md`
3. `docs/CURRENT_STATUS.md`
4. Local Git status/history
5. Permanent project documentation
6. GitHub PR/CI state when needed

Do NOT treat the following as authoritative development state:

- old Codex Cloud sessions
- AI chat history
- old remote workspaces
- stale branches
- unpublished agent state

Preferred workflow:

```text
VS Code
  ↓
Local repository
  ↓
Codex
  ↓
Local tests
  ↓
Local Git commits
  ↓
GitHub PR / CI
  ↓
Staging / Production
```

GitHub should primarily be used for:

- backup
- PR review
- CI
- collaboration

Normal implementation and testing should remain local.

---

# 3. Mandatory Codex Startup Procedure

Before modifying code, Codex must:

```bash
cat AGENTS.md
cat docs/CURRENT_STATUS.md
git status
git branch --show-current
git log --oneline -10
```

Then verify:

1. Local repository is clean or understand all existing modifications.
2. Current branch is correct.
3. `main` contains merged PR #12.
4. Merge commit `1532680e` or later is present.
5. No human/local work is silently overwritten.
6. Relevant source/tests are inspected before implementation.
7. Development begins only after enough context exists.

Do not perform a repository-wide scan unless necessary.

Use narrow searches first:

```bash
rg "HubReceiving"
rg "HubDispatch"
rg "scan_events"
rg "receiving_sessions"
rg "dispatch_calls"
```

The repository already contains detailed architecture documentation. Do not repeatedly reload unrelated documentation.

---

# 4. Immediate Documentation Fix Required

`docs/CURRENT_STATUS.md` must be checked before feature work.

At the time of this handoff, the GitHub copy still described:

```text
feature/P3.3-hub-receiving
PR #12 open
main at 71e852f
```

That became stale after PR #12 merged.

Codex should update the local `docs/CURRENT_STATUS.md` to reflect:

```text
Current baseline: main
PR #12: merged
main merge commit: 1532680e9eeb079161d083d8bb65e11b69c38948
Open PRs: none
```

The status file should describe the current operating state rather than preserve an old chronological record.

Keep it at or below 200 lines.

---

# 5. Functionality Already Implemented

## 5.1 Customer Flow

The project includes customer-side functionality for:

- account/authentication foundation
- shipment preparation
- quote/test pricing flow
- payment/provider sandbox flow
- payment history
- label generation
- printable QR/PDF label
- customer Sending/Receiving portal views
- customer portal integration

High-level flow:

```text
Customer
  ↓
Shipment
  ↓
Quote
  ↓
Payment
  ↓
SI / Label
  ↓
Customer portal
```

---

## 5.2 Driver Inbound — P3.2

P3.2 is merged.

Implemented capabilities include:

- driver registration
- PENDING → ACTIVE approval workflow
- driver profile
- driver's license details
- address information
- emergency contact
- driver-owned vehicle information
- vehicle verification fields
- run listing
- manifest reading
- run acknowledgment
- inbound pickup scans
- custody transfer
- wallet
- earnings
- transaction history
- fixed shift/route compensation
- admin driver approval/rejection
- admin payment recording

Inbound custody transition:

```text
AT_ORIGIN
   ↓ valid driver scan
INBOUND_CUSTODY
```

Important design rule:

> Vehicles are driver-owned, similar to Uber. The platform verifies vehicle details but does not assign company fleet vehicles as the normal driver model.

---

# 6. P3.3 Hub Receiving — Current State

P3.3 receiving exists and is merged.

Implemented:

- migration `014_hub_receiving.sql`
- receiving session
- HUB_STAFF authorization
- per-package receiving scan
- custody transfer from driver to hub
- close receiving session
- unreceived items marked SHORT in the receiving context
- HubReceiving frontend
- hub-location scoping
- cross-hub protection
- manifest membership validation
- refused scan journaling
- label version resolution before scan

Normal successful transition:

```text
INBOUND_CUSTODY
      ↓ hub staff independently scans package
AT_HUB
```

Important rule:

> Hub receiving must be independent from the driver's assertion that a parcel was handed over.

A package should only enter HUB custody after actual hub receiving evidence.

---

# 7. P4.1 Hub Sorting / Dispatch — Current State

P4.1 backend behavior exists and is merged.

Implemented backend capabilities include:

- stage package by destination/lot
- dispatch call creation
- pickup time windows
- available dispatch calls
- driver acceptance
- driver loading
- custody transfer HUB → DRIVER
- migration `015_hub_dispatch.sql`

Outbound custody transition:

```text
AT_HUB
  ↓ staging
STAGED
  ↓ driver load
OUTBOUND_CUSTODY
```

However:

> P4.1 is currently backend-heavy and lacks a complete hub operations frontend.

The HUB_STAFF experience is therefore incomplete.

---

# 8. Important Fixes Already Made

Do NOT accidentally undo these behaviors.

## 8.1 Never hardcode package version

A package's version changes during custody transitions.

Example:

```text
Seed/origin:
version = 1

After driver pickup:
version = 2
```

Hub receiving originally assumed version `1`, causing legitimate receiving scans to fail with:

```text
409 VERSION_MISMATCH
```

Current rule:

> Resolve current package version before performing custody changes.

Do not introduce code such as:

```text
expected_package_version = 1
```

Use `/scans/resolve` or the current canonical resolution mechanism.

---

## 8.2 HUB_STAFF is location-scoped

Do not use a bare:

```php
requireRole($user, 'HUB_STAFF')
```

if that only matches organization-wide grants.

Hub staff grants are location-scoped.

Authorization must resolve:

```text
user
 ↓
hub_staff
 ↓
hub
 ↓
location
 ↓
role grant for that location
```

This is a critical security rule.

---

## 8.3 Cross-hub object probing

Hub sessions belonging to another hub should not leak existence.

Existing behavior uses:

```text
404
```

for cross-hub session probing rather than revealing object existence with a role error.

Preserve this behavior unless there is a documented architecture change.

---

## 8.4 Refused scans must persist

Previously, rejected scans written inside the same transaction as the failed action were rolled back.

That meant failed attempts could disappear.

Current design stages refusal information and flushes the journal after rollback.

Do not regress this behavior.

---

# 9. Highest Priority Next Development

The first new development should be:

```text
feature/P3.3-discrepancy-completion
```

Goal:

> Complete P3.3 properly before building P4.2.

The ordered backlog describes P3.3 as:

```text
Hub receiving and discrepancy workbench
```

Receiving exists.

The discrepancy workbench and full exception semantics do not.

---

# 10. Development Phase 1 — P3.3 Discrepancy Completion

## 10.1 Objectives

Implement the missing operational discrepancy system for hub receiving.

Minimum discrepancy classes:

```text
SHORT
DAMAGED
EXTRA
```

Codex may also support existing relevant exception categories already present in the architecture, but should not invent unnecessary categories.

Before adding new tables, inspect the existing schema for:

- exception records
- operational cases
- package events
- audit records
- custody events
- discrepancy-related structures

Reuse established architecture when appropriate.

---

## 10.2 SHORT Behavior

Critical acceptance case:

```text
Driver arrives at hub with manifest of 5 packages.

Hub receives:
Package 1 ✓
Package 2 ✓
Package 3 ✓
Package 4 ✓

Package 5 is missing.
```

Expected result for Package 5:

```text
physical hub receipt = NO

custody = DRIVER
package state remains consistent with driver possession

discrepancy:
type = SHORT
status = OPEN
run = inbound run
hub = receiving hub
driver = responsible current custodian
package = missing package
reported_by = hub staff
reported_at = timestamp
```

The most important custody rule:

> A parcel that the hub did not physically receive must not be transferred to HUB custody merely because the receiving session closed.

Closing receiving should not erase the driver's outstanding custody.

---

## 10.3 DAMAGED Behavior

If a package physically arrives damaged:

Codex should define explicit semantics.

Possible pattern:

```text
Hub physically scans/receives package
        ↓
custody may transfer to HUB
        ↓
DAMAGED discrepancy created
        ↓
package may enter quarantine/exception workflow
```

Exact state behavior must be based on existing state enums and project architecture.

Do not invent a conflicting parallel state machine.

Required data should identify:

- package
- run
- hub
- previous custodian
- receiving user
- damage reason/notes
- timestamp
- resolution state

---

## 10.4 EXTRA Behavior

Example:

```text
Manifest expects:
A
B
C

Hub scans:
A
B
C
X
```

Parcel X is not on the expected manifest.

Do not silently attach it to the run.

The system should:

1. reject or isolate the unexpected receiving action according to project rules;
2. create a durable EXTRA discrepancy if appropriate;
3. preserve existing custody;
4. avoid accidental manifest membership creation;
5. leave an auditable record.

---

# 11. Durable Exception Record

The discrepancy workbench must not represent important exceptions only through package state omission.

There should be a durable record with sufficient data to answer:

```text
What happened?
Which package?
Which run?
Which driver?
Which hub?
Who reported it?
When?
What type of discrepancy?
What is its current resolution state?
Who resolved it?
How was it resolved?
```

Example conceptual fields:

```text
id
organization_id
hub_id
run_id
package_id
driver_id
type
status
reported_by
reported_at
notes
resolved_by
resolved_at
resolution_code
```

This is conceptual only.

Codex must inspect existing schema before creating a new model.

---

# 12. P3.3 Discrepancy Workbench UI

Extend the HUB_STAFF workspace with a usable discrepancy interface.

Suggested view:

```text
Receiving Session
────────────────────────────────

Expected        25
Received        22
Short            1
Damaged          1
Extra            1
Open Issues       3
```

Discrepancy table:

```text
Package        Type       Custody       Status       Action
----------------------------------------------------------
PKG-001        SHORT      DRIVER        OPEN         Review
PKG-019        DAMAGED    HUB           OPEN         Review
PKG-EXTRA-2    EXTRA      UNKNOWN/...   OPEN         Review
```

Actions should use server-side authorization and validation.

Do not allow the frontend to force package state changes directly.

---

# 13. Receiving Run / Stop State Lifecycle

Known issue:

Closing a receiving session currently leaves routing state similar to:

```text
route_run = ACKNOWLEDGED
hub_stop = EXPECTED
```

after receiving has actually occurred.

This should be corrected.

Codex must inspect:

- route run state enum
- stop state enum
- existing transition functions
- downstream P4.1/P4.2 expectations

Then define a consistent transition.

Example conceptual progression:

```text
PUBLISHED
   ↓
ACKNOWLEDGED
   ↓
IN_PROGRESS
   ↓
ARRIVED_AT_HUB
   ↓
RECEIVED / COMPLETED
```

Use actual existing enum names if they already exist.

Do not introduce unnecessary new states if existing ones can represent the lifecycle safely.

Add integration tests for state transitions.

---

# 14. Receiving Session Reopen Semantics

Known issue:

Current behavior can allow:

```text
open
receive
close
reopen
```

and then recalculate expected count using items already handled or classified SHORT.

This can make the same physical receiving run appear incomplete again.

Codex should explicitly define one of these patterns:

### Preferred Pattern A — single receiving lifecycle

```text
One receiving session per run/hub handoff
```

After close:

```text
session = immutable/final
```

Corrections occur through discrepancy resolution rather than creating another normal receiving session.

### Alternative Pattern B — supervised reconciliation session

If reopening is required:

```text
original session remains immutable
reconciliation session references original
only unresolved items are expected
```

Do not simply create a new ordinary receiving session that resets expected counts.

---

# 15. Tests Required for P3.3 Completion

Add targeted integration/security tests.

Minimum test scenarios:

### A. Four of five receiving

```text
manifest = 5
received = 4
close session
```

Verify:

- four packages are HUB custody
- one package remains DRIVER custody
- one SHORT discrepancy exists
- run/session counts are correct

### B. Damage

Verify:

- valid physical receipt is recorded
- custody semantics are correct
- DAMAGED discrepancy exists
- audit/history exists

### C. Extra parcel

Verify:

- package not on manifest cannot silently join run
- custody is not incorrectly changed
- EXTRA discrepancy or existing canonical exception is recorded

### D. Cross-hub access

User from hub B attempts to:

- read session from hub A
- scan into session from hub A
- close session from hub A
- resolve discrepancy from hub A

Expected:

```text
denied
no unauthorized state changes
no information leakage
```

### E. Version mismatch

Stale version must fail.

Fresh resolved version must succeed.

### F. Session reopening

Closed receiving cannot silently re-count already processed/SHORT packages.

---

# 16. Completion Gate for Phase 1

Before merging the P3.3 completion branch, run:

```bash
ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py test-db
npm run check
npm run build
```

Also run appropriate PHP syntax/static checks used by the repository.

Then:

```bash
git diff
git status
```

Verify:

- no credentials
- no `.env`
- no generated junk
- no unrelated refactors
- no architecture rewrites
- CURRENT_STATUS updated

Commit stable work locally.

---

# 17. Development Phase 2 — P4.1 Hub Operations UI

After P3.3 completion, create:

```text
feature/P4.1-hub-operations-ui
```

Goal:

> Make the already-built P4.1 backend actually usable by hub operators.

Suggested top-level HUB_STAFF workspace:

```text
Hub Operations

- Receiving
- Staging
- Dispatch
- Exceptions
- History
```

Do not introduce a separate full admin application yet.

Keep this inside the established `operations-web` architecture.

---

# 18. Staging UI

Staff workflow:

```text
Scan package
    ↓
Resolve package
    ↓
Determine destination
    ↓
Determine required stage slot/lot
    ↓
Confirm stage
```

Display:

```text
Package
Destination
Expected stage location
Current stage location
Current package state
Run/wave
```

Wrong destination example:

```text
REJECTED

Package destination:
LOCKER-B

Current staging slot:
LOCKER-A
```

The frontend should explain rejection, but backend validation must be authoritative.

---

# 19. Dispatch UI

Hub staff should be able to view operational dispatch state.

Suggested table:

```text
Destination   Ready   Window       Status      Driver
-----------------------------------------------------
Site A        6       2:00–2:30    OPEN        -
Site B        4       2:00–2:30    ACCEPTED    Driver 14
```

Useful actions, if supported by backend:

```text
Create dispatch
View dispatch
View assigned driver
View pickup/load progress
Close/cancel according to business rules
```

Do not add unsupported business operations merely because they look useful in the UI.

---

# 20. Driver Load Progress

Example:

```text
Run: OUT-20260922-004

Expected packages: 10
Loaded packages:    7
Remaining:          3

Departure:
BLOCKED
```

When all valid packages are loaded:

```text
Expected: 10
Loaded:   10

Departure:
ELIGIBLE
```

Actual departure authorization belongs in P4.2.

The UI may prepare for it, but must not fake P4.2 behavior.

---

# 21. Development Phase 3 — Scan Contract Normalization

Create:

```text
fix/scan-contract-normalization
```

This should occur before adding more scan-heavy mobile flows.

Known contract drift:

Implementation currently may use response fields like:

```text
package_state
package_version
```

while contract expects:

```text
state
version
allowed_actions
```

The resolver also needs correct handling of:

```text
action
run_id
```

and hub receiving should follow canonical event/idempotency requirements such as:

```text
client_event_id
```

if required by the contract.

---

# 22. Goals for Scan Contract Normalization

Define `/scans/resolve` as a shared precondition API.

Conceptual request:

```json
{
  "label": "...",
  "action": "HUB_RECEIVE",
  "run_id": "..."
}
```

Conceptual response:

```json
{
  "package_id": "...",
  "state": "INBOUND_CUSTODY",
  "version": 2,
  "allowed_actions": [
    "HUB_RECEIVE"
  ]
}
```

Use actual project contract schema.

Do not create a second competing scan resolver.

---

# 23. Regression Coverage for Scan Contract

At minimum verify:

```text
DRIVER inbound pickup
HUB_RECEIVE
HUB_STAGE
OUTBOUND_LOAD
```

Also test:

- stale version
- wrong run
- wrong hub
- wrong role
- action not allowed
- duplicate client event
- same valid retry/idempotency behavior

The more clients depend on scanning, the more expensive contract drift becomes.

Resolve it before driver outbound/mobile development expands.

---

# 24. Development Phase 4 — P4.2 Outbound Driver Delivery

Then create:

```text
feature/P4.2-outbound-driver-delivery
```

The ordered backlog defines P4.2 as:

```text
Outbound load and ordered-stop driver workflow
```

This is the next major milestone after P4.1.

---

# 25. P4.2 Core Acceptance Scenario

Official acceptance intent:

```text
Ten scans
6 + 4 grouping
9 + duplicate cannot depart
```

Design around that behavior.

Example:

```text
Hub
 │
 ├─ Destination A
 │    6 packages
 │
 └─ Destination B
      4 packages

Total = 10 physical packages
```

Driver accepts dispatch:

```text
Driver scans packages one-by-one
```

Valid result:

```text
10 unique expected physical packages loaded
```

Then:

```text
departure eligible
```

Invalid example:

```text
9 unique packages
+
1 duplicate scan
=
still only 9 loaded packages
```

Expected:

```text
DEPARTURE BLOCKED
```

This must be enforced server-side.

---

# 26. P4.2 Required Invariants

## Unique physical package count

Departure authorization must be based on:

```text
unique expected package IDs successfully loaded
```

not scan count.

## Correct driver

Wrong driver cannot load/depart another driver's accepted route.

## Correct run

Package belonging to another run must reject.

## Correct hub

Package must be physically loading from the correct hub context.

## Custody

Each successful load transfers:

```text
HUB
 ↓
DRIVER
```

exactly once.

## Race safety

Two drivers racing for the same parcel:

```text
one succeeds
one fails
```

No overwritten custodian.

---

# 27. Ordered Stop Workflow

After load completion:

```text
Run
 ↓
Stop 1
 ↓
Stop 2
 ↓
...
```

For the example:

```text
Stop 1:
Destination A
6 packages

Stop 2:
Destination B
4 packages
```

Driver application should show:

- current stop
- next stop
- package count
- package list
- completion state
- unresolved packages
- offline-safe cached route data when later implemented

Do not allow arbitrary stop completion when required package custody is unresolved.

---

# 28. Do NOT Build P7.1 Yet

Do not jump early into:

```text
hub creation
hub administration
staff administration
asset inventory
scanner inventory
asset check-in/check-out
finance reports
general admin console
```

The repository ADR already defers hub/admin/asset management.

Recommended dependency chain:

```text
P4.2
  ↓
P5.1
  ↓
P5.2
  ↓
P7.1
```

Follow it unless requirements are deliberately changed.

---

# 29. Later Development — P5.1

After P4.2:

```text
P5.1 Final deposit and recipient claim/pickup
```

This milestone completes most of the end-to-end package journey:

```text
Sender
 ↓
Origin locker
 ↓
Inbound driver
 ↓
Hub
 ↓
Outbound driver
 ↓
Destination locker
 ↓
Recipient
```

Key safety requirements include:

- wrong destination locker rejected before door actuation
- scan alone does not mean delivered
- physical close/attestation required
- recipient authorization cannot be based only on knowing an email address
- expired/replayed recipient grant rejected

---

# 30. Later Development — P5.2

After P5.1:

```text
P5.2 Exceptions, hub return, quarantine and shift close
```

Important operational rule:

> Driver custody must remain active until an independent hub receiving event occurs.

Example:

```text
Destination full/offline
       ↓
Return task created
       ↓
Driver keeps parcel custody
       ↓
Driver returns to hub
       ↓
Hub independently scans receipt
       ↓
Custody transfers back to HUB
```

Route/shift completion must not erase unresolved physical custody.

---

# 31. CURRENT_STATUS.md Rules

`docs/CURRENT_STATUS.md` is not a diary.

It should contain only concise current handoff state.

Keep:

```text
Current branch
Current objective
Latest relevant commit
Completed recent work
Work in progress
Blockers
Important decisions
Important files
Tests
Exact continuation point
Next recommended actions
```

Remove obsolete historical details that already exist in Git history.

Maximum:

```text
200 lines
```

Preferred:

```text
50–100 lines
```

Update it after meaningful milestones.

---

# 32. Codex Token / Context Efficiency

Continue following the repository's token-efficient workflow.

Do not repeatedly read the full repository.

Start with:

```text
AGENTS.md
CURRENT_STATUS.md
git status
git log -10
relevant source
relevant tests
```

Avoid unnecessary reads of:

```text
node_modules
vendor
build output
large logs
DB dumps
binary files
generated files
old unrelated commits
```

For small changes:

```text
implement
 ↓
targeted tests
 ↓
git diff review
```

For major changes:

```text
implement
 ↓
targeted/integration tests
 ↓
one broad review pass
```

Do not run repetitive repository-wide AI review loops.

---

# 33. Branch Plan

Recommended branch sequence:

```text
main
 │
 ├─ feature/P3.3-discrepancy-completion
 │
 ├─ feature/P4.1-hub-operations-ui
 │
 ├─ fix/scan-contract-normalization
 │
 ├─ feature/P4.2-outbound-driver-delivery
 │
 ├─ feature/P5.1-final-delivery
 │
 └─ feature/P5.2-exceptions-return
```

Each branch should begin from the latest clean `main` unless there is a deliberate stacking reason.

Prefer small reviewable feature boundaries.

---

# 34. Git Rules for Codex

Before editing:

```bash
git status
```

Before commit:

```bash
git diff
git diff --staged
```

Before push:

verify:

```text
correct branch
correct files
tests complete
no secrets
no unrelated generated output
```

Suggested commit style:

```text
feat(hub): add discrepancy workbench
fix(receiving): preserve driver custody for short parcels
test(hub): cover five-parcel partial receiving
feat(hub-ui): add staging and dispatch workspace
fix(scan): align resolver response with contract
feat(driver): enforce complete outbound load before departure
```

---

# 35. Definition of Done for Each Major Branch

A branch is not complete merely because the main happy path works.

Definition of done:

```text
Requirement implemented
Authorization verified
Custody invariants verified
Idempotency verified
Relevant concurrency/race behavior verified
Targeted tests pass
Full DB suite passes where appropriate
npm run check passes
npm run build passes
No secrets
No unrelated refactor
CURRENT_STATUS updated
Stable local commit exists
```

---

# 36. Exact First Codex Task

Use the following instructions for the next Codex session.

```text
Continue development of ZPX_Delivery from the LOCAL repository.

The local Git repository is the source of truth.
Do not use Codex Cloud workspace state as the development source.

First follow AGENTS.md exactly.

Startup:

1. Read AGENTS.md.
2. Read docs/CURRENT_STATUS.md.
3. Run git status.
4. Run git branch --show-current.
5. Run git log --oneline -10.
6. Verify local main contains merged PR #12 and commit 1532680e or later.
7. Preserve all existing local/human changes.
8. Inspect only the code/tests relevant to the task before expanding scope.

First update docs/CURRENT_STATUS.md if it still says PR #12 is open.
PR #12 has already been merged into main.

Then create:

feature/P3.3-discrepancy-completion

Objective:

Complete P3.3 before starting P4.2.

Required work:

1. Implement the missing hub receiving discrepancy backend and workbench.
2. Support explicit SHORT, DAMAGED, and EXTRA discrepancy handling.
3. Preserve physical custody correctness.
4. A package not physically received by the hub must remain in DRIVER custody.
5. Create or reuse a durable operational exception record.
6. Ensure discrepancies are auditable and resolvable.
7. Correct route-run and hub-stop state transitions when receiving closes.
8. Correct receiving-session reopen semantics.
9. Preserve organization and hub-location authorization.
10. Preserve cross-hub 404 behavior where already used.
11. Never hardcode package versions.
12. Continue using resolve-before-custody-change.
13. Add targeted integration and security tests.
14. Do not implement P7.1 admin/hub/asset management.
15. Do not perform unrelated architecture refactors.

Required T04 acceptance scenario:

Driver arrives with five manifest parcels.
Hub independently receives four.
The fifth parcel remains in DRIVER custody.
A durable unresolved SHORT discrepancy is created for the fifth parcel.
Closing the receiving session does not falsely transfer custody.
The run/stop/session states remain internally consistent.

Also test:

- DAMAGED parcel
- EXTRA parcel
- cross-hub denial
- stale package version
- session reopening/reconciliation

Use existing architecture before creating new tables or services.

Run targeted tests during implementation.

Before completing the branch run:

ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py test-db
npm run check
npm run build

Review:

git diff
git status

Update docs/CURRENT_STATUS.md.

Keep CURRENT_STATUS.md <= 200 lines.

Commit stable work locally using clear commit messages.

Stop after P3.3 completion and report:
- what changed
- files changed
- schema/API impact
- test results
- remaining known gaps
- recommended next branch

Do not automatically begin P4.1 UI or P4.2 until this branch is complete.
```

---

# 37. Recommended Task After P3.3 Completion

After the P3.3 branch is merged, the next task should be:

```text
feature/P4.1-hub-operations-ui
```

Codex should then build:

```text
Hub Operations
  ├─ Receiving
  ├─ Staging
  ├─ Dispatch
  ├─ Exceptions
  └─ History
```

After that:

```text
fix/scan-contract-normalization
```

Then:

```text
feature/P4.2-outbound-driver-delivery
```

---

# 38. Overall Architecture Principle

The ZPX_Delivery platform is fundamentally a custody system, not merely a parcel tracking UI.

Every physical handoff should answer:

```text
Who had the package before?
Who physically received it?
What evidence proves the transfer?
When did it occur?
Where did it occur?
Was the event accepted or rejected?
What package version was used?
Was the action authorized?
Can it be replayed safely?
Can the history be reconstructed later?
```

The development sequence should protect that architecture.

The most important invariant is:

```text
No custody transfer without valid physical handoff evidence.
```

That principle should guide P3.3, P4.1, P4.2, P5.1, P5.2 and later production operations.

---

# 39. Final Recommended Sequence

```text
CURRENT main
    │
    ▼
P3.3 discrepancy completion
    │
    ▼
P4.1 hub operations UI
    │
    ▼
Scan contract normalization
    │
    ▼
P4.2 outbound driver workflow
    │
    ▼
P5.1 final locker deposit + recipient pickup
    │
    ▼
P5.2 exception/return/quarantine/shift close
    │
    ▼
P7.1 admin/staff/assets/reports
    │
    ▼
P7.2 providers/monitoring/backup
    │
    ▼
P8.1 supervised pilot
```

This sequence provides the safest path from the current implementation to a complete operational delivery platform.
