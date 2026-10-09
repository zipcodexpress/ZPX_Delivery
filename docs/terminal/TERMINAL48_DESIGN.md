# Terminal48 workflow, UI and implementation design

Date: 2026-10-01. Current agent: Codex.
Status: native Delivery shell and backend workflows implemented; live cabinet binding/hardware validation remains pending.

2026-10-06 visual redesign: [Quiet confidence design specification](TERMINAL48_ELEGANT_DESIGN.md) and [interactive prototype](terminal48-elegant-preview.html) supersede the earlier visual styling. Warm ivory/deep green, compact identity header, persistent connectivity footer, whole-card Home actions, code-only admin keypad, actual three-tower diagram and selected-door inspector. Eleven screens checked at800×600 and784×521. This is a reviewable design deliverable; native implementation is the next phase, with all verified API/controller behavior retained. Operator has since verified basic Windows7 hardware actions and activated test inventory; earlier pending-hardware/frozen-inventory notes are historical.

2026-10-05 operator UI correction: restore Terminal452 manager structure before further styling. Large proportional towers occupy the main canvas below a compact toolbar; restore original controller/display cell from reference geometry without treating it as an openable compartment. Tap a tower to select it; tap a physical cell or choose its displayed number to open an individual door; Open tower opens every configured physical door on that tower, excludes controller cells, confirms the exact board/count, journals the whole target set before dispatch, and retains recovery until all targets are freshly verified Closed. No automatic replay after interruption. Home displays original physical cabinet identity, ZIP (explicit Not set if absent), API connection status; internal Delivery keys remain available in diagnostics. Preserve source identity through originalCabinetId metadata derived from the commissioned reference mapping, without renumbering protected historical rows. Test real three-board layout and bulk failure/restart with fake hardware before private packaging; do not actuate real doors during developer tests.
Target workspace: `E:\development\terminal48`. Base: local `terminal452`.

## Occupancy reconciliation (2026-10-06)

Test-site override confirmed2026-10-06: operator explicitly requests every physical box available for test cases and defines Terminal452 as reference only. Apply this to operational cabinet103/original10191 only; historical source occupancy is not imported as current test custody. All32 physical doors activated using new allocable model versions and Delivery ownership generation1; exclude controller cell, preserve original records, and continue enforcing current Delivery claims/unfinished-session guards. Restart terminal after this configuration change. The reconciliation procedure below remains relevant to future real-data cutovers, not a blocker for this authorized empty Delivery test inventory.

Operator reports admin login, identity/layout and physical individual/tower operations verified. Next milestone is a read-only reconciliation worksheet, not automatic parcel migration or inventory activation. Read current MySQL cabinet_box flags and uncollected o_store records using the same box identities/NULL pick_time rule as Terminal452 getPickList; also withhold zero-pick-time records and pending z_deliver origin/destination holds conservatively. Compare source cabinet/body/box identity, board/door, row/column and confirmed millimeter geometry with the commissioned Delivery mapping. Closed sensors are never empty evidence. Preserve active Delivery claims and interrupted sessions. Report occupied, blocked, conflicting, unmapped and empty-unverified doors separately; source records never become new Delivery parcels or pickup grants. Controller cells remain display only. Reports contain only identities/addresses/counts/flags, no names, pickup codes or contacts. Read-only transactions on both databases; no source writes, ownership activation, compartment unfreezing or real OPEN commands. A consistent snapshot is a dated review aid, not ongoing protection against an old terminal writing later. Before activation require per-door physical empty verification, exclusivity from legacy writers, current snapshot and existing ownership/commissioning checks. Initially keep all103 inventory FROZEN and retain existing parcel workflow guards. Add regression cases for duplicates, source/status conflicts, stale snapshots, missing mapping, source holds and existing Delivery custody before running the actual cabinet103 review.
Interactive design: [Terminal48 screen preview](terminal48-preview.html).

## Authenticated terminal maintenance (2026-10-05)

Latest operator correction supersedes file import: Delivery owns cabinet_admin_card with the legacy field names and getAdminCardList form/token/envelope API. Terminal reads active codes from this API when signing in and rechecks the selected record before maintenance. No file selection, email, default password, or offline fallback on an API failure. Codes are restricted to the authenticated cabinet, never logged or shipped in packages; provisioning requires database administration. Preserve existing cabinet enrollment, confirmation, journaling and sensor gates.

Correction from operator screenshots: the email/password proposal below is superseded. Use the existing Terminal452 cabinet-scoped admin code roster, imported once from its zp.db by the installer under the operating Windows account. Store only salted verifiers encrypted with Windows DPAPI. Show one masked code field and numeric keypad. After authentication place the physical diagram beside tower/door selection and Open, within the small Windows7 viewport; no mandatory reason typing. Retain device enrollment/configuration checks, journaling, explicit target confirmation, and fresh sensor recovery. Import is not exposed on the public kiosk screen. Roster changes require reimport; network email login is not required for local maintenance. Existing legacy parcels are not migrated: screenshots show occupied door9, so sensor Closed must never be labeled Empty, and maintenance opening must never commit pickup. Parcel occupancy/resident management parity remains unfinished.

User requests Terminal452 admin tower/door opening parity in Terminal48. Add Admin entry on the idle Locker status page; use existing Delivery native email/password login and network ADMIN capability checks, rechecked before every open. Passwords/tokens stay out of local files. Select one configured tower and one configured door (display raw address+1), supply a maintenance reason and confirm the exact target. Reuse Terminal452Door's existing serial owner and wire gate; never start legacy Main/frmOprMain. Local durable journal records actor/reason/address/intent and observed phases; unresolved parcel or maintenance operations block another open. Maintenance changes no parcel custody and records no fabricated delivery events. Lost/unknown operations require authenticated fresh matching-board Closed verification, never automatic reopen. Offline preview is synthetic. Validate mapping, denial, restart, duplicate/unknown gates and compact UI before packaging; do not actuate hardware during development.

## Compact Windows7 layout (2026-10-04)

User's running Windows7 screenshot confirms the Delivery shell, a two-line oversized banner and a clipped footer. Replace the banner with one 56px row: ZPX DELIVERY plus Home, without the slogan. Keep Home at48px and the status/Help footer at64px. Fit the complete decorated window inside the actual display working area rather than requiring an816x639 window. Compact home spacing; allow vertical content scrolling on shorter displays while keeping header/footer visible. Validate800x600 and a784x521 client (800x560 working area minus typical window chrome), with synthetic preview only. This layout correction does not add the missing authenticated Admin Manager or bypass the single serial/command gate.

## Read-only physical locker status (2026-10-04)

User reports the physical locker is now connected and requests Terminal452 diagram/status parity. Before implementation: add a 48px `Locker status` header action for idle navigation and a dedicated scrollable screen, preserving the compact56px header and persistent64px footer. Active parcel sessions retain their existing navigation/recovery gate. Show cabinet/board identification, configured controller COM/baud/profile, a physical layout using the existing DrawBoxHelper geometry, and explicit Open (red), Closed (green), Unknown/offline (gray) labels. Door captions retain Terminal452's raw-address+1 numbering; electrical addresses are diagnostic only. Status does not imply parcel occupancy or allocation availability. Hide legacy selection/address-edit controls; diagram clicks never open doors. Compact board height180px retains legacy cell proportions, putting the full test-cabinet diagram above the footer at784x521; additional boards can scroll within the diagram. Accessible descriptions update each board's door states in place.

Keep API readiness, configuration loading, controller communication and scanner initialization separate. Poll configured boards round-robin through the existing Terminal452Door/SerialClass owner and original V1/V2 QueryStatus/parser. Never start legacy Main/CheckBoardPackageHelper alongside this shell. Pause background polling while a parcel operation runs; serialize each diagnostic request against foreground open/status requests. Cache only validated status frames for configured boards, copy explicitly decoded sensor values, expire old readings to Unknown, and never treat an unreported channel's legacy default value as Closed. Foreground evidence must be from a matching response received after its query, not the diagram cache.

Workflow: document → adapter/cache and passive UI → synthetic CRC/address/freshness/layout tests → Release/Delivery builds → private Windows7 update package. Developer validation must not open serial ports or actuate doors. Remote operator installs the update, closes Terminal452/other COM owners, opens Locker status, and verifies changing sensor readings and disconnected-board Unknown behavior. Manual maintenance opens/Admin Manager remain outside this slice; authorization, journaling, custody and configuration APIs remain unchanged.

## Accepted legacy parity (2026-10-02)

The user's correction supersedes earlier proposals that introduce a new controller, separate key enrollment, printer, or forced payment/requote for selecting a different physical compartment. Reuse Terminal452 and zpxapi_tp8:

- Existing appSettings keys and MD5 API key/secret + timestamp + cabinet ID signature; the same ret/msg/data 24-hour token response. Secrets stay in the private config.
- Existing V1/V2 factory, SerialClass, frame/CRC/parser, BoxHelper and DrawBoxHelper. No new wire protocol; V2 retains its existing door offset.
- After observed door closure, Yes records the deposit; No, I didn't releases only the held reservation, keeps parcel custody/version unchanged, and allows another model selection without rescanning the label.
- Printer omitted. Original Terminal452/default legacy Main remains preserved.
- Historical cabinet 10123 maps to Delivery cabinet 30, currently REFERENCE/unbound. A site/operational layout binding is required before config auth can enable that terminal. Do not infer ownership or activate historical rows automatically.
- New controller adapter compiles; no physical hardware was opened or verified. Scanner settings/ASCII/start-stop frames are integrated in the new shell; original scanner forms/config are preserved. Actual COM2 hardware remains untested.

## 1. Objective and evidence

Build a .NET Framework **4.8 WinForms** edition derived from Terminal452, with customer sending, recipient collection, inbound driver pickup and outbound driver delivery. Preserve the existing apartment/carrier and service-type workflows. A deployed kiosk runs one application and one serial owner.

Inspected local baselines:

| Source | State | Evidence |
| --- | --- | --- |
| ZPX Delivery | Clean `main` at `fb4fe4d92bf8b741976e1207ed5546c75b58ca7b` before design work | Source, routes, custody services, current status and terminal handoff |
| Terminal452 | Clean `master` at `0494a99453effd69be548f1a69ebe5a782768ace` | `Zippora/Zippora.csproj`, Main, ExpressBoxService, BoxHelper, controller factory/V1/V2, existing deserializer script |
| Development tools | VS Community 2026 **Insiders**, installation `18.11.12224.323`; MSBuild `18.11.0.42713` | Local `vswhere -all -prerelease` and `MSBuild -version` |
| Framework target | v4.8 reference assemblies present locally | `C:\Program Files (x86)\Reference Assemblies\Microsoft\Framework\.NETFramework\v4.8` |

Source links: [Windows handoff](WINDOWS_CODEX_HANDOFF.md), [ADR 0009](../decisions/0009-terminal-452-locker-setup.md), [canonical journey](../phase1_END_TO_END_DELIVERY_FLOW.md), [terminal specification](../handoff/docs/04_TERMINAL.md), [protocol specification](../handoff/docs/09_API_AND_DEVICE_PROTOCOL.md), [TP8 reference](../integrations/TP8_TERMINAL_API_REFERENCE.md), [inventory/readiness](../integrations/TP8_CABINET_API_INVENTORY.md), [acceptance tests](../ZPX_Locker_Parity_Handoff_2026-09-29/03_ACCEPTANCE_TESTS.md).

Current code overrides aspirational wording in those documents. In particular, QR pairing uses the **entire returned `scene_payload`**, not scene ID alone. Implemented device events accept three fields, not the larger proposed telemetry envelope. Existing inbound scans transfer custody directly; they do not provide terminal door authorization.

The existing Main designer starts at 800×600 and expands to full screen. Support that minimum first, then 1024×768 and 1280×800. Actual kiosk resolution, DPI, controller type, Windows edition and native architecture still need inventory. Windows 7 SP1 can install Framework 4.8, but is out of support; VS2026 does not list it as a supported target platform. An actual Windows 7 launch/TLS/native-driver test is a deployment gate. Sources: [Microsoft Framework requirements](https://learn.microsoft.com/en-us/dotnet/framework/get-started/system-requirements), [VS compatibility](https://learn.microsoft.com/en-us/visualstudio/releases/2026/compatibility), checked 2026-10-01.

## 2. Product boundary and ownership

The phone owns account login, shipment creation/payment, driver assignment, actor approval and actor attestation. The kiosk owns scan entry where applicable, clear physical instructions, enrolled device identity, authorized command execution, durable local evidence and synchronization. The backend selects compartments and owns custody transitions. No customer/driver password entry or card collection at the public kiosk.

"Send a package" initially means **deposit a shipment prepared and paid on the phone**. A customer without a shipment sees phone instructions to create/pay/obtain a label. On-kiosk address entry, card payments and full shipment creation are outside this first terminal release. Label printing is optional and depends on the existing verified printer; lack of a printer must be explained before travel/deposit rather than assuming the user has a usable label.

Public sites show Pick up / Send a package / Driver services. Apartment sites additionally preserve existing resident/carrier workflows through an explicit Apartment services entry. Legacy-only mode retains the existing home until the new shell passes regression. Public sites do not expose apartment rosters. Help and authenticated staff maintenance are separate from parcel actions.

## 3. Capability and API reuse decisions

| Capability | Current source truth | Terminal48 decision |
| --- | --- | --- |
| Legacy apartment deposit/pickup/manual dropoff | `terminal452/Zippora/Service/ExpressBoxService.cs`, existing form POST `/cabinet/zippora/{action}` and `ret/msg/data` | Reuse unchanged through legacy screens/adapter; keep TP8 host and credentials separate |
| Legacy allocation | TP8 `preAuthForBox`, `commitForStore`, `proveCode`, `commitForPick` | Only legacy orders; never substitute these for Delivery custody or claims |
| Structural cabinet and models | `apps/api/route/api.php`, `Custody/DeviceCommands.php` | Reuse signed GET `/devices/me/cabinet-config` and `/devices/me/box-models`; separate Delivery DTO/cache |
| Driver final-deposit pairing | `Custody/Pairings.php`, `Http/PairingController.php` | Reuse signed scene create/poll; only `FINAL_DEPOSIT` supported |
| Final-deposit preparation/confirmation | `Custody/FinalDeposit.php`, `Http/DriverController.php` | Reuse authenticated driver endpoints on phone/harness; terminal device cannot impersonate driver |
| Command delivery/reporting | `Custody/DeviceCommands.php`, device/final-deposit integration tests | Reuse signed command poll and ordered command-events; currently final deposit only |
| Customer origin deposit | Only `/development/origin-deposits*` is implemented | New real device/pairing/session/evidence integration required; screens disabled outside design preview until available |
| Inbound driver pickup | `Custody/Service.php` accepts scan and immediately transfers custody/releases origin claim | Reuse assignment/label lookup domain; add terminal session/door/evidence integration before enabling kiosk pickup; preserve existing mobile scan compatibility |
| Recipient collection | No implemented enrolled-device collection flow verified in current route table | Add one-use grant validation/session/evidence/attestation against existing custody; SI is never a door credential |
| Live final-deposit phone UI | Mobile driver screens lack pairing/preparation/confirmation integration | Add companion flow in existing mobile app; disposable authenticated harness may validate software meanwhile |
| Session completion projection for kiosk | Command poll lacks SI, display compartment code and confirmed custody status | Add narrow signed own-session read projection before production UI promises completion or shows parcel-specific data |
| Recovery, shared allocation, notifications | Complete operator recovery and recipient delivery are still gaps; TP8 allocator lacks shared ownership exclusion | Keep shared mode disabled; journal unresolved work; notification outbox creation is not proof of SMS delivery |

All Delivery routes in this document are relative to `/api/delivery/v1`. No global TP8 host replacement or new generic `/cabinet/delivery` facade is needed.

## 4. Navigation and screen catalogue

| ID | Screen | Main content and action | Availability |
| --- | --- | --- | --- |
| H01 | Welcome | Site name, three large task cards, status, Help, Apartment services when configured | Shell |
| P01 | Pair your phone | Action/site, QR and expiry, instructions; Cancel before dispatch | Final deposit API exists; other actions pending |
| S01 | Prepare to send | Prepared-label path; phone creation/payment guidance | Planned |
| S02 | Scan shipping label | Focused scanner input, labelled manual-entry fallback, validation | Real origin API pending |
| S03 | Review deposit | SI, origin/destination, paid size, verified size guidance | Real origin/session read pending |
| S04 | Package does not fit | Same-size retry or upgrade request, server quote, phone payment state | Upgrade/payment/claim recovery pending |
| D01 | Driver services | Pick up at this locker / Deliver to this locker; site/action pairing | Final-deposit pairing only |
| D02 | Assigned parcels | Exact stop, authoritative expected/completed counts, one parcel at a time | Signed session projection pending |
| D03 | Review parcel | SI, size, compartment display code from server; wait for actor preparation | Planned projection |
| L01 | Opening compartment | Persistent operation identity, cabinet diagram, progress; no repeat open | Journal/gate implementation required |
| L02 | Place/remove and close | Large compartment identifier, spatial highlight, clear physical instructions | Correlated observations required |
| L03 | Confirm on phone | Door closed separately from actor placement/removal; wait for server | Final driver confirmation exists; other actions pending |
| L04 | Recorded successfully | Only authoritative confirmed state; next parcel/Done | Signed session read pending |
| R01 | Collect a package | Explicit Delivery pickup grant or Apartment pickup choice; manual fallback only if supported | Delivery grant flow pending |
| E01 | Connection interrupted | Before dispatch: stop/retry read; after dispatch: retained session and pending sync | Recovery implementation required |
| E02 | Compartment needs assistance | Unknown outcome, keep parcel custody honest, support reference; no reopen | Recovery/operator backend pending |
| A01 | Staff diagnostics | Authentication, read-only health/profile/config/journal summary; audited service actions later | Staff authorization design pending |

Home task cards for unsupported Delivery functions remain visible with an "Available soon" explanation during development; deployment may hide them via capabilities. Available soon does not imply a release date. A disabled public transaction never creates a fake pairing or sends a legacy allocation call.

## 5. Workflows and success definitions

### Customer sends a new package

1. Choose Send. Create/pay on phone if needed; attach the active primary label.
2. Pair and approve **this origin and ORIGIN_DEPOSIT** on phone; wrong site/action rejected.
3. Scan label at kiosk; server validates label/payment/origin/size and current package version.
4. Review shipment and size. Reserve only server-selected compatible Delivery compartment.
5. Persist intent; open once; show exact physical compartment; observe opening.
6. Place package and close. Customer confirms placement on phone after correlated close.
7. Backend commits `CREATED → AT_ORIGIN`; kiosk shows "Package deposited" only after confirmed session read.
8. Print/show non-secret receipt when configured; clear personal/session details on exit. Confirmed deposit creates pickup demand.

Does not fit: user removes parcel and closes current door; backend must reconcile the prior attempt/claim before another selection. Same-size retry is a **new authorized attempt**, not an automatic reopen. Upgrade requests server quote using the applicable rate policy, customer pays difference on phone, then backend authorizes a larger door. No downgrade, guessed dimensions, hardcoded fee or larger door before payment. LARGE too small: retain parcel and guide to another service. These are proposed real APIs, not current development endpoints.

### Inbound driver picks up from origin

1. Driver chooses Pick up; phone authenticates assigned inbound run/arrived exact stop and approves site/action.
2. Show assigned remaining parcels; server creates one retrieval session for one occupied compartment.
3. Journal and execute one authorized door cycle; driver removes that parcel and scans its primary label on phone.
4. Wrong/revoked/unassigned parcel: no custody commit; guide replacement/assistance, preserving any uncertain state.
5. Observe close, driver attests removal, backend validates evidence/current assignment and commits `AT_ORIGIN → INBOUND_CUSTODY` exactly once.
6. Proceed to next assigned parcel; stop completion derives from all parcels resolved. Missing parcels remain recorded at origin unless separately resolved.

The existing `/runs/{run_id}/scans` inbound mutation is **not a prepare-door call**. Its current immediate custody transfer cannot be called before removal to obtain authorization. Add an additive session path/evidence policy with compatibility tests rather than silently changing mobile consumers. Kiosk and phone counts must come from server projection, not button clicks.

### Outbound driver delivers to destination (first software integration)

1. Driver arrives via existing mobile run flow; terminal POST `/devices/me/pairings` body `{"workflow":"FINAL_DEPOSIT"}` with stable idempotency key.
2. Render exact returned `scene_payload` (`ZPXPAIR:{id}:{uuid}`) as QR. Driver scans and approves through `/pairings/{id}/approve` with location/action.
3. Phone resolves/scans active label and prepares `/runs/{run}/stops/{stop}/final-deposits` with package/pairing/label/current versions and quoted run `If-Match`.
4. Terminal polls `/devices/me/commands`; validate device locker, address/profile, approved session, ownership generation and expiry. Journal before actuation.
5. Submit durable `DISPATCH_RECORDED → OPEN_OBSERVED → CLOSE_OBSERVED` reports; each body contains exactly `command_id`, `event_id`, `event_type`. Evidence acknowledgement alone does not transfer custody.
6. Phone POST `/runs/{run}/stops/{stop}/final-deposits/{session}/confirm` with `placed:true`, current package/run versions, stable idempotency key and `If-Match`.
7. Backend commits `OUTBOUND_CUSTODY → AT_DESTINATION`; confirmed own-session projection lets terminal show "Delivery recorded". Existing command poll becoming empty is **not** proof of success.

Pairing is consumed by preparation; current polling does not provide a confirmed delivery state. Command DTO lacks parcel SI/display code: do not derive customer-facing door labels from electrical addresses or invent completion data. Simulator/harness may show explicit synthetic values; production needs the small server projection described below.

### Recipient collects

1. Choose Pick up and explicit Delivery mode. Scan valid one-use package/location/action grant; transferable bearer grant permitted by canonical policy.
2. Server validates grant and destination/claim/device; creates authorized retrieval session.
3. Journal/open assigned compartment; recipient removes parcel and closes. Recipient does not need to scan the package label.
4. Collect removal attestation through supported actor/bearer session, submit correlated evidence, backend commits `AT_DESTINATION → COLLECTED` and consumes grant once.
5. Show "Package collected" only after authoritative confirmation. Revoked/expired/replayed grant and SI-only input fail before open.

Legacy credentials stay in explicit Apartment services. The Delivery grant format remains unimplemented; define a distinguishable grammar with the backend before scan auto-routing. Never try the same secret against both APIs.

## 6. UI specification

Visual direction: clean, calm, readable at arm's length. Use native WinForms controls and small custom panels; the HTML preview is a design aid, not the production runtime. No WebView/browser dependency on Windows 7.

| Token | Specification |
| --- | --- |
| Font | Segoe UI; fallback system sans. Design pixels at 96 DPI; convert to points and test WinForms DPI scaling |
| Title / section / body / caption | 30 / 22 / 18 / 14 px at 800×600; 36 / 24 / 20 / 16 px when space permits |
| Surface / canvas | `#FFFFFF` / `#F3F6FA` |
| Text / secondary text | `#14243B` / `#516178` |
| Primary action | `#1858C8` with white text; darker press/focus variant |
| Success | `#17633F` with `#E8F5ED` surface, check icon and explicit words |
| Warning / error | `#855000` on `#FFF3D8` / `#A52332` on `#FFF0F1`; never color alone |
| Spacing / corners | 8 px base; 16/24/32 px groups; 12 px panel corners; 8 px control corners |
| Touch controls | At least 56×56 px; primary buttons 64 px high; 12–16 px separation |
| Header / footer | 56 / 64 px; single-line ZPX DELIVERY + Home header, persistent status/Help footer; fit actual working area |
| Content | 24 px margins, no horizontal scroll; title, instruction, content, action; collapse two columns to one below 900 px |

Home has three equal cards. Driver role chooser has two equal cards. Pairing uses a genuine locally generated QR in production (220–280 px with quiet zone), visible site/action and expiry. Preview uses a clearly labelled non-scannable placeholder. Expiry comes from server UTC, not a fixed UI countdown reset. Regenerate only by new scene after expiry, with new business request identity.

Door panel highlights body/row/column from validated configuration and server compartment code; electrical addresses appear only in staff diagnostics. Layout must support mixed sizes/body positions; the prototype's regular grid is illustrative. Unknown mapping blocks the new workflow.

Scan fields have accessible labels, retained focus while idle, Enter terminator and duplicate-in-flight guard. Reuse scanner device handling but scope scans to active screen/session; scanner bursts cannot enter a hidden field or trigger a second open. Mask access grants and clear on session end; SI can remain on receipt. Manual entry is labelled and offered only for a supported credential/label type. Do not shorten opaque tokens for submission.

Use keyboard Tab/Shift+Tab, visible focus, descriptive accessible names and real button roles. Screen reader status announcements must distinguish opening, observed open/close and server confirmation. Text wrapping must retain full primary instructions. Audio cues supplement text; they never replace it. Progress transitions can fade within 150 ms; no flashing or animation required to understand state.

## 7. Errors, timeouts and privacy

| Condition | User-visible behavior | Allowed action |
| --- | --- | --- |
| Offline before new transaction | "This service is temporarily unavailable" | Help/Home; retry connectivity, no new authorization |
| Lost network after authorized dispatch | "Close the door. Your session is waiting to sync" | Retain evidence/session, replay HTTP only |
| Wrong site/role/label or stale version | Specific correction without another user's details | Refresh authority/re-scan when safe |
| Full locker | Driver: "Keep this package with you"; customer: "No suitable compartment available" | Exception/support; no silent destination substitution |
| QR expires | "Pairing expired" | New scene only before command dispatch |
| Door stays closed/no fresh open observation | "We could not confirm the door opened" | UNKNOWN/help; no automatic retry open |
| Door left open | "Please close compartment …" | Continue observation; do not complete/abandon physical session |
| Journal unwritable/corrupt | "This service needs attention" | Block new opens; staff reference |
| Confirmation fails after close | "Waiting for confirmation" or "Needs assistance" | Refresh/retry same semantic operation; no reopen |
| Server refusal before dispatch | Do not actuate; show reason/help | Retain journal refusal and refresh/reconcile |

Proposed idle policy: 90 seconds before an idle pre-dispatch session expires, warning during final 20 seconds. Server expiry wins if earlier. During dispatch/open/unknown/pending-sync, never reset the operation to idle or drop evidence. After successful confirmation, clear actor details after 20 seconds or Done. Any handoff of UI control while pending must first persist recovery and safely remove personal data; the door remains unavailable.

Log technical references/state/time, not access grants, signing keys, full labels, recipient contacts or driver auth tokens. Help shows configured support contact and non-secret operation reference. Do not invent a support phone number or claim an operator has been notified without confirmed support integration.

## 8. Implementation architecture and local workflow

1. Preserve `terminal452` as reference. Create a local Git clone from it into `E:\development\terminal48` and feature branch `codex/terminal48-net48`; do not copy binaries, caches or secret configs into releases. Record source commit provenance. Keep design documentation in ZPX, linked from terminal48 README/current status.
2. Retain original `Zippora` host/solution and useful forms/services. Retarget shipped managed projects and runtime declarations to **v4.8**, update reference-assembly package/import consistently, choose architecture from native drivers. Rename display product to ZPX Terminal48 only after baseline regression; preserve installer/watchdog expectations until reviewed.
3. Resolve build dependencies. Current source references a missing `ZipporaService` project, external logging/data/DirectX/SQLite DLLs and NuGet packages. No watchdog use was found in inspected Zippora C# references; confirm packaging/service responsibility before dropping a build dependency. Never create a fake service stub. Inspect/provision missing proprietary binaries; no invented replacements.
4. Add Delivery client/DTOs/coordinator under existing host, separate configuration/state, disabled by default. Reuse legacy scanner/printer/native controller where verified. Add new WinForms screens per catalogue using the same process. Avoid rewriting legacy forms as part of this change.
5. Add transactional SQLite journal distinct from task.db, outbox and single command gate. Intercept `BoxHelper.OpenBox` and direct maintenance `OpenLocker` calls; `Manage/frmOprMain.cs` contains direct opens. There must be no bypass that permits conflicting physical commands.
6. Implement simulator adapter and final-deposit integration first. Production V1/V2 adapters stay disabled until actual profiles/parser observations are verified. V1 preserves raw door address; V2 already adds one internally, so client adds zero offsets. V2 also reads legacy global cabinet metadata today: explicitly provide validated type/mapping through the adapter, never inject Delivery flags into legacy caches.
7. Add companion mobile final-deposit UI and signed own-session projection. Implement other backend sessions with existing custody services, regenerate OpenAPI/types and run consumer regression tests before enabling their screens.
8. Record milestones in both current-status files, review targeted diffs/builds/tests, keep user changes. No commit/push requested. Physical commissioning/deployment requires separate owner authorization per repository decision.

Proposed files, introduced only when their slice needs them:

```text
terminal48/
  Zippora/Zippora.sln                  existing host, migrated net48
  Zippora/Delivery/DeliveryApiClient.cs independent HTTP/signing/DTO boundary
  Zippora/Delivery/WorkflowCoordinator.cs
  Zippora/Delivery/CommandGate.cs       sole authorized actuation entry
  Zippora/Delivery/JournalStore.cs      separate transactional journal/outbox
  Zippora/Delivery/Views/              native screens from catalogue
  Zippora/Hardware/                   narrow V1/V2/simulator adapters
  tests/                             contract, journal and workflow tests
  docs/CURRENT_STATUS.md              exact local continuation
```

Delivery signing: Ed25519 with private key stored outside repository/release under DPAPI and ACL. Select/pin crypto dependency only after net48/Win7/native checks. Canonical UTF-8 bytes are uppercase method, full API path, Unix timestamp, UUID nonce and lowercase SHA256 of **exact raw body bytes**, joined by LF without trailing newline. Serialize once; send those bytes. Fresh nonce/signature on transport retry, stable business/event IDs. Bounded async requests, cancellation, 2-second foreground poll initially with 5/10/30-second capped error backoff; no UI-thread network/serial sleeps. No certificate-validation bypass.

Journal before actuation; persist immutable command ID/payload/address/profile/generation/expiry, boot/sequence and event outbox. Deduplicate by command ID. UNKNOWN retains claim and blocks reuse. On restart an intent with uncertain send outcome never actuates again; reconcile through server/operator and fresh correlated observations. Report already-recorded facts after expiry; deny a fresh open after expiry.

Current backend dispatch report revalidates authorization. Submit the durable dispatch-intent event and require server acceptance **before physical send**; record local actual send outcome separately. The event receipt is authorization revalidation, not hardware ACK or proof of actuation. A lost event response is retried with the same event UUID; cancellation/expiry before send blocks send. Power loss before/after send remains ambiguous despite server receipt, so recovery still requires UNKNOWN/reconciliation. Test this ordering explicitly; do not send first and discover revocation afterwards.

## 9. Backend additions to make the UI truthful

Prefer the smallest additive contracts in the existing API; paths below are **proposals**, not implemented endpoints.

| Addition | Required properties and ownership |
| --- | --- |
| Signed own-session read, e.g. GET `/devices/me/locker-sessions/{id}` | Device owns session; minimal SI, compartment display code/layout link, workflow/status, expiry, versions and `custody_confirmed`; no credentials/contact details. This fills an immediate final-deposit UI gap |
| ORIGIN_DEPOSIT pairing/preparation/confirmation | Authenticated paid sender/current label, exact origin/size, claim/command, device events and customer attestation; reuse existing Shipping/custody/payment domain |
| Size adjustment and abandoned-attempt reconciliation | Quote/pay difference before larger claim; safe old-claim release only after evidence/no-dispatch or resolved empty attempt |
| INBOUND_PICKUP session | Assigned driver/current run/stop, occupied origin claim, one compartment command, removed label + close + attestation, one atomic custody transfer; preserve current scan-client contract |
| RECIPIENT_PICKUP grant/session | One-use scoped/revocable grant, bearer policy, device/compartment authorization, close/removal attestation and atomic collection |
| Audited recovery | Unknown command/held or occupied claim, operator identity/reason/evidence, no fabricated observation or blind reopen |

Proposed contracts require source/controller/migration/contract/test review in ZPX. No direct production DB editing or duplicate terminal-owned inventory. No new physical workflow can be enabled merely by adding a UI flag.

## 10. Ordered delivery stages and exit criteria

| Stage | Deliverable | Exit evidence |
| --- | --- | --- |
| 0 — Design | This document and offline screen preview | Walk through all four roles and error states, verify minimum-size layout and implemented/proposed API labels |
| 1 — Build baseline | Local terminal48 clone, focused net48 retarget, sanitized config | Release build using installed MSBuild; native/package/watchdog dependency record; feature-off legacy fixture regression |
| 2 — Client | Delivery signing/DTOs, real QR/polling, read-only config | C# known-key PHP vectors, Unicode/exact-byte/empty body tests, errors/cross-device/expiry/address fixtures |
| 3 — Journal/gate | Durable outbox, simulator and intercepted actuation | Duplicate/restart/response-loss/unwritable journal/UNKNOWN tests; one controller owner |
| 4 — Final deposit | Existing backend + companion app/harness + session projection | Disposable fixture E2E through server-confirmed AT_DESTINATION, idempotent confirmation, wrong destination denied |
| 5 — Sending | Real sender session + payment adjustment | Paid-size/upgrade/no-fit/recovery tests, confirmed AT_ORIGIN creates demand |
| 6 — Driver pickup | Real retrieval session + compatible scan domain | Wrong/missing/duplicate parcel, assignment change, exactly one custody transfer, origin claim release |
| 7 — Recipient | Real one-use grant collection | SI-only/replay/revoked/wrong-site denial, confirmed COLLECTED, bearer policy |
| 8 — Pilot | Reviewed package and designated test compartment | Actual OS/TLS/native/scanner/printer/profile/parser tests, operator authorization, rollback and legacy exclusion |

Stage 0 must exist before terminal implementation. Stages 1–4 may proceed within the software-development request; physical tests remain separately gated. No timeline estimate is asserted until dependency restore and native availability are known.

## 11. QA and exact continuation

- UI: all role entries, phone instructions, available-soon messages, QR expiry, scan validation, correct site/size/parcel, spatial door label, no fake success, recoverable error paths.
- Layout: 800×600, 1024×768, 1280×800; 100/125/150% Windows DPI; long names/non-ASCII; keyboard/focus; contrast and arm's-length touch checks. A browser preview validates design only; repeat on WinForms.
- API: implemented route/DTO fixtures and signing vectors, semantic retry, wrong actor/device/site/label, stale package/run/config/generation and expiry.
- Durability: kill before journal/send/observation/HTTP ack; journal unwritable/corrupt; duplicate poll; no automatic repeat open; preserved outbox/recovery.
- Legacy: feature-off apartment deposit/pickup/manual dropoff and scanner/printer/service-type screens; TP8 envelope/host intact; one serial owner.
- Physical: parser split/coalesced/CRC/wrong-address/stale frames and polarity; no assumed occupancy sensor; actual V1/V2 controller verification before enabling adapter.

Design milestone contains documentation and an offline prototype, saved before terminal source edits. Prototype interaction is synthetic, with no API, serial, payment or custody writes. All 17 screen layouts were checked at 800×600 and corrected to fit; all four journeys plus empty scan validation and size adjustment were exercised. Stage 1 subsequently started in a Terminal452-derived local Git checkout at `E:\development\terminal48`, branch `codex/terminal48-net48`: three projects/runtime declarations now target v4.8 and all three Release builds pass. x86/x64 smoke checks verify framework targets, synthetic legacy cabinet DTO/envelope boundaries and native SQLite transactions/persistence. Existing NuGet versions are preserved; missing SQLite/ZIP dependencies now use pinned official packages, and updater reuses the existing CodeSite compatibility source. Missing watchdog reference and legacy package security advisories remain. Full kiosk/legacy UI, Windows7, Delivery and hardware validation are still pending. Follow that repository's `docs/CURRENT_STATUS.md` and `docs/BUILD_VALIDATION.md` for exact commands/continuation. Backend session projection is the first additional contract needed for a truthful final-deposit terminal UI.
