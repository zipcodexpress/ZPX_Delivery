# 02 — Local implementation plan

Status: proposed changes, not applied. Names marked “proposed” do not denote existing classes/routes/tables. Reconcile with current local code before editing.

## A. Non-negotiable implementation boundary

Implement the same operator sequence and field semantics as the established admin. Keep ThinkPHP, the existing PostgreSQL domain, shared identity, server-side authorization, CSRF, audit and custody safeguards [S01, S23]. Do not create another admin app or copy Yii controllers verbatim into the new domain.

The Phase 1 terminal base is terminal_452. Preserve existing legacy screens, routes, request/response behavior, service-type handling and serial protocol implementations. Android remains a future option, not a prerequisite for this work. New screens/client functionality must be additive and off by default until verified.

## B. Target operator workflow

1. **Box Models.** Familiar name, size category, dimensions with explicit units, allocability, and legacy price metadata. Retain versioning behind a simple form. Weight capacity is a hardware specification, not weight-based shipping pricing. Separate missing/unknown metadata from zero.
2. **Body Models.** Create a reusable body model, then open its **Body Box Layout**. Do not make the operator traverse an unrelated advanced module hierarchy.
3. **Body Box Layout.** For each slot select a box model, row, column and door address. Show a tabular/visual preview of the entire body and a summary of allocable versus non-allocable positions. Support repeated box models at different positions. Publish an immutable ready version once valid.
4. **Locker Sets.** Create an unbound cabinet configuration. Add bodies by model; set body name, display sequence and controller address. Preserve verified direction information. Auto-expand boxes from the selected model and show the controller/door address pair for every physical box.
5. **Bind Location.** Choose a site and a valid locker location, or create a new inactive location through the established service. Review the complete assembly and legacy ID mapping, then bind once. Binding does not activate hardware or shipping.
6. **Review / Commission.** Show configuration validation, source revision, ownership and missing device evidence separately. An imported or draft layout must never receive a false “ready for live delivery” indicator.

Existing direct locker URLs and manual inventory tools remain usable for their appropriate administrative purpose; the main setup path uses the familiar sequence above.

## C. Minimal architecture for assemble-before-bind

The current model instantiation requires a locker joined to a location; provisioning creates that location before the locker [S03, S04]. Prefer a configuration-only draft rather than making every runtime locker location nullable and rewriting authorization/queries.

**Proposed concept: LockerSetupDraft.** Use the smallest suitable existing draft facility, or add a narrowly scoped draft record with organization, stable draft ID, optimistic version, revision/hash, body/model-slot snapshot, optional legacy cabinet identity and binding result. Choose relational children or a validated versioned snapshot based on existing conventions. These are configuration drafts, not a second runtime locker inventory.

An unbound draft appears in Locker Sets and contains the actual planned body instances, sequences and addresses. It is not returned as a routable/allocable location. No dummy site or active location should be created to bypass workflow order.

### Binding transaction

- Authorize the actor and every referenced model/site/location within the same organization.
- Lock the draft and selected inactive destination; enforce expected draft version and an idempotency key with payload hash.
- Validate model snapshot completeness, unit provenance, board/channel profile rules and all address/position collisions.
- Reuse the empty draft locker that `SiteInventory::addLocation()` already creates, when appropriate. Do not blindly insert a second locker for that location.
- Otherwise use the existing provisioning rules to create the location/locker at this final step. Preserve one locker per location and existing IDs.
- Create/associate controller boards; instantiate body modules and the already-established internal box-module grouping; create one compartment per slot; copy model metadata and exact door addresses. Reuse common instantiation logic rather than maintaining two implementations.
- Reuse legacy_location_links and legacy_compartment_links. Verify existing board legacy_body_id semantics before adding body provenance. Add only missing model/slot mapping where justified.
- Keep new compartments FROZEN and new service locations inactive. Write the binding result, audit and draft state in the same PostgreSQL transaction.
- Any failure rolls back the complete binding. An identical replay returns the same identifiers. A changed payload under the same key conflicts.

Binding is not relocation. Importing or attaching an existing live cabinet needs its own explicit adopt/reconcile path; do not run the empty-draft materializer over occupied equipment.

## D. File-level change map

| Existing path | Change |
|---|---|
| apps/admin/src/LockerModels.php | Add permitted legacy metadata and slot address; validate/publish complete layouts; extend/reuse instantiation rather than duplicate it. |
| apps/admin/src/SiteInventory.php | Add/use final binding entry point; preserve current location checks, occupancy projections and maintenance safeguards. |
| apps/admin/src/PageController.php | Inspect its current adapters, then add only needed page/action adapters. This file was located but not fully reviewed here. |
| apps/admin/routes.php | Add scoped draft/edit/bind actions and navigation; retain current URLs and action names where possible. |
| apps/admin/view/locker_models.html | Add size/allocability metadata and door-address editing; present Body Box Layout as a clear model-specific step. |
| apps/admin/view/locker_detail.html | Inspect existing view, then show body name/sequence, board address and each box address independently; clear commissioning status. |
| apps/admin/view/lockers.html | Include unbound configuration drafts and setup progression without presenting them as active lockers. |
| apps/admin/view/site_detail.html | Add final binding entry points and reflect the selected locker configuration. |
| apps/api/database/migrations/ | New additive migration(s), next numbers chosen from the LOCAL checkout. Never edit checksummed migrations or execute MySQL DROP statements. |
| apps/api/src/… | Reuse actual local domain/transaction services for binding, ownership and commands. New class names should follow existing conventions. |
| docs/handoff/contracts/openapi.json | Change only affected implemented contracts; regenerate shared types using repository commands when applicable. |
| Existing PHP integration/browser/contract tests | Extend nearby coverage; do not create a parallel test framework or blindly edit table/operation counts. |
| docs/CURRENT_STATUS.md and docs/admin/BACKLOG.md | Record actual slice state and exact next action; keep CURRENT_STATUS <=200 lines and preserve unrelated work. |
| README.md and docs/decisions/0002-new-terminal-platform.md | Link the new superseding decision; preserve old historical rationale and ongoing safety rules. |

## E. Addressing and validation rules

- Explicitly separate display row/column, body display sequence, controller address and door/channel address.
- Preserve raw legacy sequence strings; validate and document conversion to the target numeric ordering. Do not cast ambiguous values silently or lose an original value.
- A repeated model ID is not a repeated slot. One slot creates one installed box.
- Preserve current board uniqueness `(locker_id, board_address)` unless an explicit future bus design changes it [S07]. For this slice, reject unsupported ambiguous multi-bus configurations rather than guess.
- Enforce unique electrical target per installed controller/door pair, and unique display position in the appropriate body scope. Same door number on different controllers is valid when profiles permit it.
- Byte representability alone is insufficient. Validate model/protocol-specific channel ranges and reserved addresses from the actual terminal/controller configuration. Do not use a blanket 0–255 door acceptance rule.
- Published model changes create new versions, never mutate installed addressing. Reordering a display must not regenerate or renumber doors.
- Structural/address/location edits for installed equipment require the appropriate maintenance workflow, no occupied or held claims, no unknown outcomes or pending commands, and consistent owner manifests. Retire rather than destroy historical identity where necessary.
- Unknown direction/unit/status encodings produce an explicit unresolved mapping, not a fabricated default. Imports retain source revision and original IDs.

## F. Reusing the existing API without breaking the current terminal

### Keep the working legacy path

Leave `ExpressBoxService` legacy calls and their configured host/service path intact. Do not globally change `Api_Host`, force a different `serviceType`, redirect every `/cabinet/zippora/*` call into Delivery, or change the meaning of `preAuthForBox`, `commitForStore`, `proveCode` and `commitForPick`.

The reviewed client uses form-encoded requests and ret/msg/data models [S18, S19]. Token signing uses a `timestamp` label in the signed string while sending `kts` on the wire [S18]. Compatibility tests must retain those details without retaining secrets. New Delivery authentication must use the existing enrolled-device/authorization design, not introduce the legacy exceptions as public new write endpoints.

### Add the Delivery path

**Proposed names:** DeliveryApiClient and a separate Delivery API host/configuration. Implement them in terminal_452 while using its existing scanner, UI conventions, controller factory and single serial owner. Prefer current `/api/delivery/v1` operations; inspect implemented contracts before inventing new endpoints. A facade under `/cabinet/delivery` is only an option if a concrete consumer needs it, not a requirement to add another redundant API surface.

Reuse the established cabinet configuration DTO shape where it reduces terminal changes. A read-only projection can express Delivery's mapped inventory as `boxConfig.cabinets[].boxes[]`, including body/model IDs, sequence, lockAddr and boxAddr, with explicit ID mapping. It must not turn every inventory row into allocable capacity. Freeze exact wire types, field casing, empty representations, ordering and image/model identity against sanitized fixtures and the actual C# deserializer before claiming drop-in compatibility.

The compatibility projection is not a reason to transplant legacy MySQL controllers and order writes into PostgreSQL. Old apartment orders stay in their existing services. New shipments and claims stay in Delivery domain services; do not disguise a new Send as a legacy store order to avoid implementing custody.

### Configuration authority modes

For an existing imported cabinet, legacy configuration remains authoritative until an explicit controlled transition. Delivery admin can display/import metadata and stage proposed changes, but must not silently write configuration back to MySQL or overwrite operational flags.

For a newly commissioned Delivery-only cabinet, Delivery may become configuration authority after its mapping and device integration are verified. The extended terminal's new client can read that configuration without globally retargeting the legacy client.

For a shared existing cabinet, approve a single configuration publisher/revision and an allocation boundary. Do not run two autonomous “last write wins” configuration syncs. A draft publication is not proof that the terminal has acknowledged that revision.

## G. Shared-cabinet coexistence — a release gate, not a reason to block admin work

The inspected legacy allocator selects by cabinet, model, blocked and its own status [S16]. It does not consult PostgreSQL ownership_manifests. Therefore:

- A Delivery ownership manifest alone does not prevent legacy allocation of the same door.
- Merely setting `is_allocable=0` on a shared box model is not an established per-door ownership solution. The inspected allocator does not itself filter that field, and the legacy model is shared across boxes.
- Do not misuse “blocked/faulty” to mean “owned by Delivery”; legacy release code can clear blocked state [S16].

Preferred Phase 1 coexistence uses the existing LEGACY/DELIVERY/FROZEN ownership design with a proven exclusion/acknowledgment path on both sides. If implementing that exclusion requires a narrow legacy compatibility guard, isolate it, preserve existing API behavior, regression-test all affected allocators and obtain explicit review before live rollout. Do not characterize it as “zero legacy code change” when it is not.

If no legacy code changes are acceptable, start with physically dedicated Delivery equipment not exposed to legacy allocation, or keep shared-cabinet Send disabled. The admin workflow can still be completed and tested in the meantime. Never let both databases independently allocate the same physical door.

One terminal process/established controller transport should own the serial connection. Serialize new Send interaction with existing kiosk workflows; do not start a second independent serial writer. A future shared command gate must preserve proven existing operations and not be slipped in as an unrelated rewrite.

## H. New Send behavior

Add a clearly separated, feature-gated Send entry point. Its workflow validates user/shipment/Shipping Identifier, correct origin cabinet, authorized size/price choice and claim before requesting a door command. A size change follows the approved Delivery repricing/payment workflow; never silently reuse a legacy model charge as a new tariff.

Execute the authorized board/door through the existing controller implementation. Correlate resulting observations with command/session, device, compartment and mapping/ownership generation. `OpenLocker()` returning is not success [S21]. Persist/handle uncertain outcomes without blindly reopening on retry. Keep the original client/session state separate from new Delivery credentials and sessions.

Pickup and driver scanning remain distinct authorized workflows. New functionality must not alter how current residents retrieve existing apartment parcels. Feature-off behavior is the regression baseline.

## I. Bounded implementation slices

### Slice 0 — reconcile local scope

Read AGENTS/current status, git status/log/branch; inspect relevant local code. Locate the real platform checkout and any pinned submodules. Record inspected commits locally. Create the superseding terminal decision and make a short status checkpoint. Do not overwrite human changes.

### Slice 1 — model and Body Box parity

Extend box metadata and body layout with door address, draft editing/preview and validation. Retain published immutability. Add migration, authorization, validation and browser tests. Finish this slice before starting a terminal rewrite or broad project cleanup.

### Slice 2 — unbound cabinet assembly and final binding

Build the setup draft, body sequencing/controller association, expanded layout preview and atomic idempotent final binding. Reuse existing tables/links and internal auto-generated module. Add collision, cross-org, rollback, replay and occupied-equipment tests.

### Slice 3 — compatibility projection and legacy regression harness

Complete the actual local endpoint/override inventory. Capture sanitized test fixtures from nonproduction services; test them with terminal_452's current deserializer. Preserve configuration/model identity and prevent config-driven state clearing. No real door commands in this slice.

### Slice 4 — additive terminal Send and physical pilot

Build the existing Windows target, establish a feature-off regression baseline, add the separate Delivery client and Send interaction, then verify simulated command/evidence handling. Only an authorized empty test cabinet may be used for physical tests. Shared-site release depends on the allocation boundary above. Do not claim a live rollout when only the simulator passed.

## J. Draft superseding decision text

Use the next available ADR number found locally; do not assume one here.

> **Title: Phase 1 terminal_452 reuse and legacy locker setup parity**
>
> **Owner decision: September 29, 2026.** Phase 1 uses terminal_452 as the actual terminal base, with additive Send and required Delivery interactions. Existing legacy workflows and cabinet API behavior must continue unchanged from the operator's perspective. Android is a future option rather than the immediate implementation target.
>
> This supersedes ADR 0002's reference-only Windows/new-terminal direction, not its requirements for custody integrity, authorized device evidence, single-controller ownership, safe retries, legacy continuity or physical commissioning.
>
> Locker administration follows Box Model → Body Model → Body Box Layout → cabinet bodies/sequence/controller addresses → location binding. Extend the existing ThinkPHP/PostgreSQL admin instead of recreating its domain.
>
> This decision does not authorize production schema resets, automatic equipment takeover, two independent allocators for one door, or live door-opening tests.

## K. Validation and handoff

Use the local repository's actual targeted PHP/database/browser tests. Repository-documented broader commands include `npm run check`, `npm run build`, `npm run test-db` and `npm run test-e2e`; contract edits require `npm run contracts:generate` [S09]. Inspect scripts first and use disposable/test data. Windows compilation/deserialization and hardware tests are separate from backend tests.

Record exactly what ran and its result. Report environment blockers without marking the feature verified. After each meaningful slice update docs/CURRENT_STATUS.md within 200 lines, preserve the branch/worktree context and name the exact next action. GitHub writes must follow normal local git review, not substitute for the offline authoritative checkout.
