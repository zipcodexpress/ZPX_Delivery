# Repository review and reuse decisions

## Review boundary

Read-only source review at pinned revisions in evidence/repositories.json. Recursive trees were not truncated; selected application/controller/model/UI files were read and searched locally. This is a targeted architecture review, not an exhaustive security audit, full build, schema extraction or proof of what is deployed. No production database, resident data, device or endpoint was accessed. Source manifests and paths are recorded in evidence/source_inventory.json. Stored source snapshots are review scratch only and are not included in this package.

## Findings

| Evidence | Verified behavior | Development consequence |
|---|---|---|
| terminal_452: Zippora/Zippora.csproj | WinForms references; target .NET Framework 4.5.2; AnyCPU; local/native dependencies; project reference to ../ZipporaService/ZipporaService.csproj | Preserve hardware compatibility lane; inventory DLL architectures. ZipporaService is absent from reviewed tree, so full build reproducibility is unverified |
| Zippora/ControllerNew/ExpressBoxActionControllerFactory.cs | Selects v1, v2 and test; unknown returns null | Explicit profile per site; fail closed on unsupported profile. V4 source exists but is not selected here |
| ControllerNew/ExpressBoxActionControllerV1.cs | Open uses 16 67 00 03 01 + board + door + CRC; status query uses command 02 and DD; decodes lock/status responses | Extract byte-level adapter with fixture tests, preserve address and CRC semantics |
| ControllerNew/ExpressBoxActionControllerV2.cs | Increments door address before sending; separates fresh/freeze/normal behavior | Never normalize away profile-specific address offset; refrigerated behavior is out of pilot scope |
| Common/CommonValues.cs | Active response constants AA/BB; commented alternate vendor values 04/06; distinct open ACK and query status constants | Inventory actual board/firmware per site; source alone cannot identify installed protocol variant |
| Common/SerialClass.cs | Default constructor sets 19200 despite comment describing 9600; configurable constructors/setters exist | Capture actual configuration, not comments, for each commissioning record |
| Controller/BoxHelper.cs and Shared/DataService.cs | Global response/status lists; OpenBox calls SetDoorStatusClosed before send; physical API lacks business operation IDs | Do not treat cached default state as physical evidence; add freshness and single-flight command correlation |
| frmSelectBoxModel.cs | preAuthForBox returns boxId/lockAddr/boxAddr, then passes to frmOpen | Natural seam for new delivery reservation/session flow, not reuse of old auth endpoint |
| frmOpen.cs: StartOpenTask / PickupCommitOrder / ClosePickUp | Pickup commit can follow successful open check; closure monitoring is separate | New delivery custody requires per-package scan plus the new session's evidence policy |
| Controller/CommitTaskHelper.cs | Text task.db retry list for commitForPick; failed-task rewrite conditional on nonempty failures | Replace for new workflows with durable journal and server idempotency; do not infer exactly-once processing |
| Common/TSCLIB_DLL.cs | Native printer entry-point declarations exist | Printer integration reference only, not proof all sites have printers or required driver DLL |
| zpxapi_tp8: app/cabinet/controller/Zippora.php: preAuthForBox | Calls common CabinetBoxModel.assignBox(..., false) | Selection alone does not reserve a compartment |
| app/common/model/CabinetBoxModel.php | assignBox reads/randomly selects availability; occupyBox reads then writes status; releaseBox changes status and clears blocked | Do not reuse as concurrent new-platform reservation engine; document/test all shared-site mutators |
| app/cabinet/controller/Zippora.php: commitForStore | Occupies box, inserts o_store, queues notification | Existing apartment store workflow stays separate; new service adds atomic custody/outbox transaction |
| same: commitForPick | Updates pick_time and calls releaseBox; locker flag/recent-open handling exists | Not interchangeable with audited hub/driver handoff |
| app/cabinet/controller/Base.php: getRuntimeBoxAllocable; Zippora.getBoxConfig | Runtime availability combines blocked/status/active o_store; topology exposes cabinet/body/box addresses | Copy physical mapping through bridge; preserve richer occupancy checks during partition commissioning |
| backend/models and controllers in zpxadmin-php8x | cabinet → cabinet_body → cabinet_box; o_store pickup/deposit history; member + o_member_apartment membership | New mapping must distinguish physical compartment IDs from body layout templates |
| backend/controllers/UtilityController.php: actionResetLockers | Marks outstanding o_store rows picked and resets nonzero cabinet_box status for selected cabinet | Shared sites require guarded reset that cannot free DELIVERY-owned compartments or falsify delivery history |
| backend/controllers/CabinetbodyController.php: createBox | Materializes compartment instances from body-model layout | Reference for topology import, not new shipment schema |
| backend/models/Zdeliver.php and API Ziplocker.php / ZDeliverModel | Older delivery fields/endpoints exist, including from/to cabinet and courier_id | Historical concept only; user says current operation is apartment receiving. Do not infer production-ready routing/hub support |
| ZPXWebsite: src/App.jsx and Account components | React customer portal with account/register/transactions screens | Reuse visual conventions selectively; implement new shipping/label/route screens separately |
| src/components/Account/Login.jsx; src/config/network.jsx | Browser MD5 password transform, localStorage token, _accessToken/_memberId request parameters | New identity/API contract; no automatic shared sessions or wholesale copy of auth |
| API app/cabinet/controller/Zippora.php: getAccessToken | MD5-based cabinet signing/token construction; failure includes debugSign | New per-device credentials, freshness/replay checks; do not inherit debug/auth design |

## Existing business flow reconstructed

Carrier selects resident/unit and parcel details → terminal asks preAuthForBox → API chooses cabinet_box → terminal uses board/door address to open → legacy terminal commitForStore → API creates o_store and notifies resident. Resident proves pickup through legacy mechanisms → terminal opens → pickup commit updates o_store and releases box. This describes examined paths; multiple asset/store/share and pickup variants exist and require regression coverage.

## Reuse boundary

Reuse/reference: board protocol algorithms, address mapping, scanner integration patterns, terminal layout/accessibility assets, approved branding, physical inventory mapping and operational vocabulary. Wrap/refactor: serial command handling, printer calls and terminal API clients. New: customer identity, driver/hub operations, shipment/package/SI/labels, route and custody domain, transactional allocator, payment eligibility and new administration. Preserve existing apartment business services behind guarded compatibility paths.

## Source reference format

Source links are generated in evidence/source_inventory.json using exact repository revision and path. For example, [preAuth/deposit/pickup controller](https://github.com/zipcodexpress/zpxapi_tp8/blob/f59bb3d3b7f243f3536bbdcdb6fd536647f303ea/app/cabinet/controller/Zippora.php), [common allocator](https://github.com/zipcodexpress/zpxapi_tp8/blob/f59bb3d3b7f243f3536bbdcdb6fd536647f303ea/app/common/model/CabinetBoxModel.php), [admin reset](https://github.com/zipcodexpress/zpxadmin-php8x/blob/b617fa51e912e91f1fb982457601ada6b95b859f/backend/controllers/UtilityController.php), and [terminal protocol factory](https://github.com/zipcodexpress/terminal_452/blob/0494a99453effd69be548f1a69ebe5a782768ace/Zippora/ControllerNew/ExpressBoxActionControllerFactory.cs).
