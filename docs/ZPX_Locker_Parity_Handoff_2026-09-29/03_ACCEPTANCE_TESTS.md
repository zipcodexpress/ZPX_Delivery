# 03 — Acceptance tests and release gates

These are required tests to implement/run locally. They were NOT run against ZPX_Delivery or terminal_452 in this review. The accompanying JSON is only a synthetic address-mapping fixture, not a captured response or validated hardware profile.

## Setup and models

| ID | Exercise | Required result |
|---|---|---|
| L01 | Create Box Models, Body Model and three Body Box slots | Familiar sequence works; model/category/dimensions/allocability and explicit slot address persist. |
| L02 | Use the same small box model in two slots | Two independent installed boxes are produced, not one deduplicated by model ID. |
| L03 | Add two bodies from the three-slot fixture | Exactly six installed boxes, with correct body associations and snapshot versions. |
| L04 | Compare controller sequence 1/2 with controller addresses 7/3 | Display sequence and board address remain independent. |
| L05 | Use slot addresses 4/9/17 at rows 1/2/3 | Electrical addresses are not derived from row, index, generated ID or sequence. |
| L06 | Reorder bodies | Presentation order changes; physical board/door identities and box IDs do not. |
| L07 | Duplicate a display position versus duplicate an electrical target | Each gets a specific rejection; uniqueness scopes are correct. |
| L08 | Use the same door address on different boards | Allowed when the confirmed hardware profile permits it; same board/door pair is rejected. |
| L09 | Use unsupported channel, reserved address or ambiguous bus | Clear validation failure; never silently wrap/cast a value to a byte. |
| L10 | Publish model then change its draft/new version | Existing published version and installed layouts remain unchanged. |
| L11 | Import model with unknown units, missing dimensions or unverified direction | Raw data/provenance retained; unresolved mapping is explicit; no fabricated dimensions or operational capacity. |
| L12 | Add non-allocable panel and unsupported Phase 1 size | Layout retains it, but shipping selection/price logic does not treat it as an eligible parcel box. |
| L13 | Preserve middle/x-large and legacy price/model image identity | No silent category rename, unintended tariff change or incorrect model asset mapping. |

## Assembly and location binding

| ID | Exercise | Required result |
|---|---|---|
| B01 | Assemble a cabinet before selecting a location | Unbound configuration can be saved/reopened; no dummy active site or routable inventory is created. |
| B02 | Bind to an eligible inactive location with an existing empty draft locker | That locker is reused; no second locker or accidental ID replacement. |
| B03 | Bind by creating an inactive location at final step | Complete body/board/box mapping created; location inactive and new boxes frozen. |
| B04 | Fail an insert midway through binding | Full rollback: no orphan body, board, box, links or consumed draft. |
| B05 | Retry identical bind with same request key; then change payload | First replay returns identical result; altered payload conflicts; no duplicate six-box set. |
| B06 | Edit draft concurrently / submit stale version | One consistent revision wins; stale edit is rejected. |
| B07 | Reference another organization's model/site/locker | Server denies before creating data; no information leakage through previews or errors. |
| B08 | Try binding an already bound cabinet to another location | Rejected; approved relocation is a separate workflow with history and safety gates. |
| B09 | Re-import known legacy cabinet/body/box IDs | Reuses scoped mapping; no ID collision, duplicate inventory or operational-state reset. |
| B10 | Move/delete/readdress equipment with claim, live parcel, unresolved session or pending command | Rejected and audited; historical package/door identity preserved. |
| B11 | Activate using only ready template/import/simulator evidence | Production activation denied with concrete missing commissioning requirements. |
| B12 | Change display or model metadata during a pending hardware session | Command mapping remains tied to its accepted revision; no silent retargeting. |

## API and existing-terminal regression

| ID | Exercise | Required result |
|---|---|---|
| C01 | Capture sanitized fixtures for effective getAccessToken/getBoxConfig/getBoxModelList/getConfig | Confirm actual subclass behavior, wrappers, field casing, scalar types, null/empty forms, sorting and IDs. Historical API-summary tests are not substituted. |
| C02 | Deserialize fixtures with current C# helpers/models | Success on actual supported build; no unreviewed serializer replacement. |
| C03 | Exercise token signing in a test context | Signed timestamp label and POST kts value stay correct; secrets absent from fixtures/logs. |
| C04 | Query legacy occupancy statuses 0/1/3/4, blocked boxes and active orders | Existing accessibility/status semantics retained; stale available flags never override claims/ownership. |
| C05 | Disable the new feature and run current courier deposit/resident pickup/admin workflows | Existing outputs, routing, notifications and behavior remain unchanged. |
| C06 | Enable Send and inspect network calls | New client uses Delivery routes/credentials; old ExpressBoxService host/service-type and order APIs remain intact. |
| C07 | Exercise existing protocol selection v1/v2/test | Existing factory implementations preserved; no second independent serial writer. |
| C08 | Simulate serializer/config failure | Clear failure state; does not erase valid local configuration or mark capacity available. |
| C09 | Import/export structural configuration | Legacy box IDs and body/controller pairs preserved; no occupancy/blocked/credential writes from the import. |
| C10 | Try using a legacy admin-card role as a network-admin grant | No privilege transfer; maintenance access is explicitly scoped. |

## Coexistence and new Send

| ID | Exercise | Required result |
|---|---|---|
| H01 | Legacy and Delivery attempt the same physical compartment | Enforced owner/allocator boundary prevents dual allocation; UI-only filtering is insufficient. |
| H02 | Legacy directly requests a Delivery-owned door, including alternate legacy allocation paths | Denied by the approved boundary; PostgreSQL-only ownership entries are not accepted as proof. |
| H03 | Ownership changes with stale/missing acknowledgments | No activation until all required participants agree on the same revision. |
| H04 | Legacy release/unblock follows an older operation | Cannot accidentally return a Delivery-owned compartment to legacy allocation. |
| H05 | Sender presents SI for a different locker or unauthorized shipment | No command issued and no custody transition. |
| H06 | Sender changes size after arrival | Confirmed size/price adjustment and correct eligible door claim; no silent charge substitution. |
| H07 | V1 OpenLocker call returns but hardware does not respond | Not marked open/deposited; outcome remains failed/unknown under actual evidence rules. |
| H08 | Duplicate command/result or retry after timeout/restart | No blind second opening; idempotency and reconciliation retain one operation identity. |
| H09 | Receive event with wrong device/session/door/mapping revision | Rejected or quarantined; cannot confirm parcel custody. |
| H10 | Legacy operation and Send start simultaneously | Existing terminal interaction/serial coordination prevents conflicting command execution. |
| H11 | Empty authorized test cabinet physical trial | Confirm actual board/door mapping, sensing, timeout/restart behavior and feature-off rollback. Record real evidence separately from simulator results. |
| H12 | Attempt pilot at shared site before allocator exclusion is implemented | Feature remains disabled; no claim that preserving old screens alone ensures compatibility. |

## Definition of done

**Admin setup parity complete:** the operator completes the required sequence on local synthetic data; all required fields and address mappings persist; binding is atomic/idempotent/scoped; installed layouts remain protected; relevant browser/database tests pass with actual recorded commands/results.

**Terminal/API compatibility complete:** the actual Windows build and deserializer tests pass against reviewed nonproduction fixtures; feature-off legacy regressions pass; new Send uses a separate authorized workflow and established controller transport.

**Live shared-cabinet release ready:** owner/allocator boundaries are enforced by every relevant path, device/configuration acknowledgments are valid, and explicit physical commissioning passes. Admin or simulator completion alone cannot satisfy this gate.

## Evidence record template

    Date / local branch / commit:
    Relevant pre-existing changes preserved:
    Commands executed:
    Result and actual failing output, if any:
    Fixture / test database identity:
    Windows build / device profile:
    Physical equipment authorization and evidence (or NOT TESTED):
    Changed source and migration paths:
    Remaining blockers and exact next action:
