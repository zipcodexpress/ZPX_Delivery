# 01 — Verified locker setup gap review

## 1. Conclusion

ZPX_Delivery already has a substantial locker administration foundation. Reuse it. The missing work is legacy setup parity and integration of physical addressing into configuration—not a new admin application or a wholesale recreation of the legacy MySQL database.

The target model page explicitly says that it creates frozen inventory without controller addresses or Delivery ownership [S06]. `LockerModels::instantiate()` implements that behavior [S03]. Consequently, the presence of bodies and boxes in the current admin is not evidence that they can drive terminal_452.

Scope: source review of the files/ranges listed in SOURCES.json. No running admin, complete deployment topology, complete legacy controller override inventory or local unpushed work was inspected. The unavailable `zpx-patform` was not silently substituted with an assertion of equivalence.

## 2. Legacy behavior established from code

### Reusable templates

The Body Box form selects a body model and box model, then records row, column and `addr` [S12]. These are template slots, not installed physical boxes. Multiple slots may use the same box model.

### Physical cabinet assembly

The installed-body form accepts cabinet ID, body name, body model, sequence and controller `addr` [S11]. Body creation saves the body and copies every template slot into installed boxes within a transaction, including row, column and address [S10]. Existing model selection is disabled in the inspected edit form.

`direction` exists in the owner's supplied schema, but it is not exposed by the inspected body form. Preserve imported direction values; determine the deployed encoding and whether another screen supplies it before inventing a dropdown or default.

### Location binding

The legacy apartment-binding create action requires an existing cabinet and rejects binding that cabinet in two places [S13]. One property may contain multiple cabinets; the cabinet is the physical set, not the property itself.

### Address path to hardware

    cabinet_body.addr      → lockAddr → controller/board address
    cabinet_body_box.addr  → copied into cabinet_box.addr
    cabinet_box.addr       → boxAddr  → door/channel address
    cabinet_body.sequence  → presentation order, not controller address
    row / column           → presentation location, not door address

`CabinetBoxModel::getBodyBox()` joins the body controller address and installed box address [S16]. The base API configuration serializer emits these as `lockAddr` and `boxAddr` [S14]. The terminal V1 controller uses two separate byte parameters to send the serial command [S21]. Changing display order must not change electrical addresses.

## 3. Gap matrix

| Requirement | Current ZPX_Delivery evidence | Necessary change |
|---|---|---|
| Box Model catalog | Versioned name/code, millimeter dimensions and weight limit exist [S03, S06]. | Add legacy size category and allocability metadata; preserve legacy price and model identity without redefining shipment pricing. Resolve original units. |
| Body Model catalog | Versioned DRAFT/READY models already exist [S03]. | Keep them; present the familiar model workflow and provide safe draft editing/version cloning. |
| Body Box layout | Slots store box-model reference, row and column [S03]. | Add explicit door/channel address; show it in editor and preview. Validate duplicate positions and electrical collisions independently. |
| Cabinet body setup | Model instantiation accepts body code and numeric position [S03]. | Add/display body name, controller address/binding and legacy sequence metadata; preserve direction where present. |
| Automatic installed boxes | One body, an internal box-module grouping and frozen compartments are created from slots [S03]. | Preserve one box per slot and copy verified door addresses into hardware mappings. Do not require a new manual module step. |
| Assemble first; bind later | addLocation creates location plus locker first; instantiate requires its INACTIVE location [S03, S04]. | Add an unbound configuration-draft workflow and materialize/bind it at the final step. Do not weaken established runtime location assumptions casually. |
| Hardware persistence | controller_boards, compartment board/door links and legacy mappings already exist [S04, S07]. | Wire configuration to these entities, not duplicate hardware tables. |
| Existing API behavior | Legacy uses cabinet-specific form requests and C# response contracts [S14, S18, S19, S22]. | Leave working API consumers intact; add a scoped adapter/client for Delivery and verify concrete deployed responses. |
| Existing terminal as base | Current ADR/README still say reference-only Windows, new Android terminal [S08, S09]. | Explicitly supersede the terminal-platform decision and make terminal_452 the Phase 1 build/integration target. |
| Shared-site allocation | Target has ownership manifests; the inspected legacy allocator queries its own box table [S07, S16]. | Enforce a single allocation authority or disjoint physical ownership on BOTH paths. A new ownership row in PostgreSQL does not constrain the old allocator. |
| Admin cards and error reporting | Legacy endpoint inventory includes them [S22]; complete target parity was not reviewed. | Preserve existing endpoints; separately inventory cabinet-scoped maintenance access and redacted diagnostics. Do not clone legacy role grants into Delivery. |

## 4. Reuse the existing PostgreSQL domain

The owner's MySQL DDL describes legacy semantics. It is not a migration script for this application. The repository identifies PostgreSQL 17 and checksum-tracked canonical migrations under `apps/api/database/migrations` [S09]. Do not execute the supplied DROP TABLE statements or edit an already-applied migration.

| Legacy entity / field | Existing target concept | Handling |
|---|---|---|
| cabinet_box_model | locker_box_models | Extend missing legacy metadata; retain version snapshots and confirmed unit conversions. |
| cabinet_body_model | locker_body_models | Reuse; model identity and version are separate concepts. |
| cabinet_body_box | locker_body_model_slots | Reuse; add slot electrical address and, where needed, provenance. |
| cabinet_body | locker_body_modules plus board association | Preserve body identity/name/layout; do not confuse a display module with a controller. |
| cabinet_body.addr | controller_boards.board_address | Existing board uniqueness is `(locker_id, board_address)` [S07]. Do not invent unsupported multi-bus addressing. |
| cabinet_box | compartments | Preserve installed IDs via existing mappings; keep operational claims/custody independent of template metadata. |
| cabinet_box.addr | compartments.door_address | Bind with controller_board_id; test actual profile/channel limits. |
| cabinet.cabinet_id | lockers / locations plus legacy_location_links | Reuse the explicit source-system mapping; local generated ID need not equal the legacy ID. |
| cabinet_box.box_id | legacy_compartment_links | Reuse this existing mapping table [S07]. |
| cabinet_body.body_id | existing controller_boards.legacy_body_id and body provenance | Inspect existing import use before adding a second competing mapping. |
| o_apartment_cabinet | installation_sites → locations → lockers | One site can have multiple locker locations; retain current one-locker-per-location runtime model. |
| api_key / api_secret | legacy credential context and target device credential references | Do not copy plaintext secrets into admin forms, fixtures, Git or logs. Authentication domains remain distinct. |
| cabinet_admin_card / cabinet_error | existing legacy services, scoped maintenance/audit projections | Separate follow-up inventory; no blanket reuse of role strings as target permissions. |

New mapping for model/slot IDs may be needed. Confirm local schema first; reuse existing provenance structures where they safely serve the requirement. Never silently reset occupied/blocked boxes, orders, claims or credentials during configuration import.

## 5. Important semantic differences

**Size category and price.** Legacy model output includes `sizeCat`, `boxModelPrice`, dimensions, image and counts [S15]. The target model form currently does not include category, allocability or price [S03, S06]. Preserve raw legacy `middle`; an internal `MEDIUM` label needs an explicit two-way mapping. Keep `x-large` distinguishable even when Phase 1 shipping eligibility excludes it. A legacy model price is not automatically the new shipping tariff.

**Units and unknown values.** The supplied DDL does not state dimension units; target fields explicitly use millimeters. Do not assume centimeters, inches or millimeters. Retain raw measurements and provenance until units are verified. Do not invent dimensions or a weight capacity just to pass the current positive-number validator. Non-allocable service panels and incomplete drafts must not become shippable capacity.

**Occupancy is not a boolean port.** The inspected API treats status 0 and 3 as potentially allocable; 1 and 4 appear as occupied. The model transitions 0↔1 and 3↔4; a source comment associates status 3 with an accessibility-specific allocation path [S14, S16]. Preserve those meanings and inspect configuration/constants. Target occupancy is derived from compartment claims, custody, location, ownership and hardware mapping [S04]. Never implement `legacy status=0 => Delivery available`.

**Static versus dynamic configuration.** Template layout, installed addressing, fault state, occupancy and ownership are separate. A cached layout or an old `getBoxConfig` response is not a safe allocation lock. Structural synchronization must not overwrite either application's runtime records.

**Hardware evidence.** The inspected `OpenLocker()` returns void and catches exceptions [S21]. Calling it is not proof the door opened, a parcel was deposited or a recipient collected it. New Send flows must rely on their authorized command/session plus correlated device evidence and reconciliation, while keeping old behavior regression-tested.

## 6. Source limitations that must be resolved locally

Find the actual platform checkout and inspect `.gitmodules`, admin path, API path and reverse-proxy routing if it exists. Its deployment composition was not verified here. Check actual checked-out commit SHAs for all components; do not assume separate repository defaults equal deployed submodule revisions.

Inspect all overrides of relevant Base actions in Zippora/Ziplocker and real terminal URL selection. The reviewed Base contract and terminal source are concrete reference points, but no sanitized HTTP fixtures were captured and the complete 191 KB Zippora controller was not reviewed. Finish that bounded contract inventory before replacing or projecting any endpoint.

No full automated test suite, Windows build, running UI comparison or physical opening was performed. SOURCES.json provides exact file-blob identities and read ranges instead of overstating review coverage.
