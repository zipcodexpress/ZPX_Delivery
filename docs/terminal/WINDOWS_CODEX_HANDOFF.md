# Windows terminal development handoff

Prepared 2026-10-01 for a Windows Codex developer using Visual Studio 2026. This is an implementation handoff, not evidence of a working Windows build or physical integration. Start with [CODEX_START_PROMPT.md](CODEX_START_PROMPT.md).

## 1. Outcome and inspected baseline

Extend [terminal_452](https://github.com/zipcodexpress/terminal_452) so the existing WinForms kiosk can use ZPX_Delivery through a separate, feature-gated Delivery client while preserving working TP8 apartment, deposit, pickup, scanner, printer, service-type and controller behavior. One process owns the serial transport. Do not globally retarget the legacy API host.

- Delivery inspected: `main`, commit `1b0b018` (PRs #44 and #45 merged), clean working tree before this documentation change.
- Terminal inspected: local `master`, commit `0494a99453effd69be548f1a69ebe5a782768ace`, clean working tree. Recheck Windows checkout rather than assuming these commits are latest.
- `Zippora/Zippora.csproj` currently targets **v4.5.2**, with AnyCPU configurations, external/native dependencies and a reference to the absent `ZipporaService/ZipporaService.csproj`. No terminal source was modified by this handoff.
- Delivery terminal APIs have backend tests; the C# terminal does not yet call them. No C# build, deserialization, Windows 7 launch or physical locker test was performed on this Mac.

## 2. Runtime decision: Windows 7 needs 4.8, not 4.8.1

**.NET Framework 4.8.1 does not support Windows 7.** The newest compatible release for normal Windows 7 **SP1** is .NET Framework **4.8**. Check the actual edition: Embedded/Compact/POSReady images need separate verification; do not assume desktop compatibility.

| Machine / artifact | Required approach |
| --- | --- |
| Development PC | Modern Windows supported by the installed VS 2026 release; install .NET desktop development workload and .NET Framework 4.8 developer/targeting pack. |
| Current locker PC | Verify Windows 7 SP1, bitness, patch level, .NET runtime, controller/scanner/printer drivers and native DLLs. Deploy/test a **net48 / v4.8** terminal artifact. |
| Future modern kiosk | The net48 artifact can be tested on a later compatible 4.x runtime. A net481-specific artifact is optional future work after the kiosk OS changes. |

VS 2026 lists .NET Framework 4.8 as a development target, but its platform compatibility table **does not list Windows 7 as a supported target**. Thus net48 is runtime-compatible in principle, not a Microsoft-supported VS2026→Windows7 deployment guarantee. Prove the artifact on the actual test PC. Do not claim supported Windows 7 deployment just because it compiles. If build tooling prevents this target, evaluate a separately supported net48 compiler/toolchain; do not switch to net481 to hide the problem.

Do not change App.config's runtime SKU to disguise net481 as net48, install unofficial runtime backports, or use APIs/native packages requiring a newer OS. .NET Framework 4.x versions are in-place updates, not side-by-side runtimes. Retarget all shipped managed dependencies consistently; validate initializer/updater and native DLL bitness. Choose x86/x64 from actual driver and SQLite/DirectX dependencies, not from the development PC's bitness. Preserve a known working legacy artifact before migration.

Windows 7 is out of support. Treat it as an isolated pilot constraint and record an OS upgrade path before a production rollout. Verify TLS 1.2, trusted certificates, clock synchronization and endpoint connectivity on that PC; never bypass certificate validation or downgrade device authentication.

Microsoft sources, checked 2026-10-01:
- [Framework system requirements](https://learn.microsoft.com/en-us/dotnet/framework/get-started/system-requirements)
- [VS 2026 targeting and compatibility](https://learn.microsoft.com/en-us/visualstudio/releases/2026/compatibility)
- [Framework 4.8 developer pack](https://dotnet.microsoft.com/en-us/download/dotnet-framework/net48)

## 3. Read these specifications in order

Current code/tests take precedence over older aspirational contracts. Read both repositories' AGENTS.md if present, then their current status, branch, git status and recent history. Preserve all uncommitted work.

1. [ADR 0009](../decisions/0009-terminal-452-locker-setup.md): terminal_452 is the Phase 1 base; it supersedes ADR 0002's Android/reference-only choice.
2. [Phase 1 end-to-end flow](../phase1_END_TO_END_DELIVERY_FLOW.md): customer, driver, custody, size/payment and recipient rules.
3. [Terminal workflow specification](../handoff/docs/04_TERMINAL.md) and [device protocol specification](../handoff/docs/09_API_AND_DEVICE_PROTOCOL.md): command gate, durable intent, evidence and recovery. Their proposed generic APIs are not all implemented.
4. [Locker parity implementation plan](../ZPX_Locker_Parity_Handoff_2026-09-29/02_IMPLEMENTATION_PLAN.md) and [acceptance tests](../ZPX_Locker_Parity_Handoff_2026-09-29/03_ACCEPTANCE_TESTS.md), particularly C01/C02 and terminal/hardware gates.
5. [TP8 API reference](../integrations/TP8_TERMINAL_API_REFERENCE.md) and [full inventory](../integrations/TP8_CABINET_API_INVENTORY.md): preserve legacy calls; do not clone every TP8 endpoint into Delivery.
6. [OpenAPI](../handoff/contracts/openapi.json), `apps/api/route/api.php`, current services and integration tests. Distinguish implemented routes from contracts awaiting implementation.

Inspect terminal `Zippora/Service/ExpressBoxService.cs`, `Common/JsonHelper.cs`, cabinet DTOs, `Controller/BoxHelper.cs`, controller factory and V1/V2 implementations, every direct serial actuation path, configuration loading, scanner hooks and updater. The current factory enables `v1`, `v2`, `test`; V4 source existence does not mean it is enabled.

## 4. Implemented API boundary

Base is `/api/delivery/v1`. Responses are direct JSON objects, **not** legacy `ret/msg/data`. IDs are opaque strings; do not substitute MySQL IDs or convert every ID to an integer. Read current response bodies on errors (`code`, `message`, `request_id`, `retryable`) rather than assuming every historical specification's error envelope is implemented.

| Route below base | Caller | Actual purpose |
| --- | --- | --- |
| `GET /devices/me/cabinet-config` | Signed terminal | Bound active Delivery-only cabinet projection; body/box IDs and explicit electrical addresses. |
| `GET /devices/me/box-models` | Signed terminal | Same cabinet revision and model summary. |
| `POST /devices/me/pairings` | Signed terminal + Idempotency-Key | Create `FINAL_DEPOSIT` scene only. Body `{"workflow":"FINAL_DEPOSIT"}`. |
| `GET /devices/me/pairings/{pairing_id}` | Signed terminal | Poll own scene: PENDING/APPROVED/CONSUMED/EXPIRED/CANCELLED. |
| `POST /pairings/{pairing_id}/approve` | Authenticated assigned driver + Idempotency-Key | Approve scanned `scene_payload` at `location_id` with workflow FINAL_DEPOSIT. Browser writes additionally require CSRF. |
| `POST /runs/{run_id}/stops/{stop_id}/final-deposits` | Authenticated driver + Idempotency-Key + If-Match | Prepare claimed compartment and command for arrived outbound stop. |
| `GET /devices/me/commands` | Signed terminal | Poll stable authorized OPEN commands for enrolled terminal; polling alone does not open a door or change custody. |
| `POST /devices/me/command-events` | Signed terminal | Ordered durable event submission, deduplicated by event UUID. |
| `POST /runs/{run_id}/stops/{stop_id}/final-deposits/{session_id}/confirm` | Same authenticated driver + Idempotency-Key + If-Match | Placement attestation after three signed events; server transfers custody to AT_DESTINATION. |

Read source: `apps/api/src/Custody/DeviceCommands.php`, `Pairings.php`, `FinalDeposit.php`, their HTTP controllers, and `apps/api/tests/device-commands.php`, `final-deposit.php`. Preparation body requires `package_id`, `pairing_id`, active `label_payload`, `expected_package_version`, `expected_revision`. Confirmation requires `placed:true` and both versions; If-Match is the quoted run revision. Never store a driver password/token as the terminal's device identity.

Pairing response includes `scene_payload` in form `ZPXPAIR:{pairing_id}:{scene_uuid}`. Render that **exact returned opaque payload** in the QR. ID alone is insufficient. Approval comes from the driver/app, not a terminal self-approval. Scenes currently expire after ten minutes. The React Native driver app still needs this pairing/preparation/confirmation UI; a local authenticated test harness can exercise it meanwhile. Do not describe existing mobile final-deposit integration as complete.

Command response has `items[]`: `command_id` UUID, `session_id` string, `action`, `address.{locker_id,board_address,door_address}`, `protocol_profile`, `ownership_generation`, `expires_at`. Current poll emits OPEN for FINAL_DEPOSIT only; an enum's QUERY alternative does not prove a deployed QUERY command flow. Parse RFC3339 and actual PostgreSQL timestamp variants safely; expire conservatively, never guess from locale.

Events accept exactly `command_id`, `event_id`, `event_type`. Submit `DISPATCH_RECORDED` → `OPEN_OBSERVED` → `CLOSE_OBSERVED`; ambiguous dispatch/observation uses `UNKNOWN`. Local raw frames, times, boot/sequence and assurance belong in the journal: these are **not extra accepted request fields** today. Replay the same event ID/body with fresh signed transport headers. Event receipt never transfers custody. Backend records these as signed terminal reports with `physical_hardware_verified=false`; physical observations still require pilot evidence.

Configuration has `revision`, `boxConfig.cabinetId`, `boxConfig.cabinets[]` (body rows), `boxModels[]`. It intentionally returns boxes `isAllocable:"0"`, `blocked:1` and model counts zero. This is a structural projection, not a legacy allocation feed: **do not load these flags into legacy operational caches or clear them to permit local allocation**. Authorized commands, not configuration flags, select a Delivery door. Keep displaySequence, raw sequence, row/column, lockAddr and boxAddr independent. Never create a second operational cabinet inventory.

## 5. Signed terminal requests

All implemented device endpoints above use Ed25519. Provision a distinct per-device key; server stores the base64 public key in existing `device_credentials`. Terminal stores the private key protected by Windows DPAPI/appropriate ACLs outside source and release archives. Select and pin a crypto library only after verifying net48, Windows 7 and native architecture compatibility. There is no implemented public self-enrollment API; use reviewed local fixture/provisioning tooling, not credentials copied from TP8.

Headers: `X-Device-Key-Id`, `X-Device-Timestamp` (10-digit Unix seconds), `X-Device-Nonce` (fresh lowercase UUID), `X-Device-Signature` (base64 detached signature). Canonical UTF-8 bytes, LF separators, no trailing newline:

```text
{UPPERCASE_METHOD}\n{FULL_PATH}\n{TIMESTAMP}\n{NONCE}\n{LOWERCASE_SHA256_OF_RAW_BODY_BYTES}
```

Sign the path including `/api/delivery/v1` and actual pairing ID, excluding scheme/host. Existing endpoints need no query parameters; do not invent unsigned query options. Serialize JSON **once**, hash those exact bytes, then send those same bytes; empty GET body hashes as SHA256(empty). Timestamp skew is limited to 60 seconds. Every network retry gets a new nonce/timestamp/signature, while semantic Idempotency-Key/event UUID and payload remain unchanged. Do not reserialize or issue a new business key because a response was lost.

Enrolled device must be active, correctly bound, with valid nonrevoked credential and permitted locker capabilities. Config/pairing/final-deposit gates require active DELIVERY_ONLY sites without legacy links. Importing historical cabinets or changing a capability flag is not commissioning. Use disposable local backend fixtures from the tests for software integration; their simulator profiles are not physical profiles. No blanket disabling of server checks to make a pilot work.

## 6. Ordered Windows implementation slices

### A. Build and legacy baseline

Create a terminal feature branch, e.g. `codex/delivery-terminal-net48`; record both repository commits. Restore dependencies and compile Zippora.exe without launching hardware control. Inspect the missing watchdog reference: owner previously clarified ZipporaService supervises/restarts the executable; isolate an unused build reference only after verifying consumers. Do not fabricate an empty service to hide missing behavior. Record actual restore/build errors and native dependencies.

Migrate the shipped projects to v4.8 in a focused change, retaining original artifact/config backups. Do not rewrite WinForms into MAUI/WinUI or move legacy business flows into Delivery. Build Release with the verified architecture. First launch with simulated controllers and sanitized configuration; confirm the entire feature-off legacy baseline.

### B. Separate Delivery client and contract tests

Add a Delivery namespace/client/config host; keep ExpressBoxService and TP8 host/token semantics intact. Implement DTOs, exact-byte signing, typed error handling, QR pairing and read-only config/catalog. Validate against sanitized synthetic fixtures and actual C# deserialization; do not treat matching JSON property names as proof of legacy DTO compatibility. Keep new configuration in separate state.

Add bounded HTTP timeouts, async calls outside the UI thread, cancellation on session exit and controlled polling/backoff. Missing backend functionality disables only that feature and explains the state; it never falls back to a legacy allocator or synthetic endpoint.

### C. Durable command journal and single controller gate

Reuse the existing serial owner through a narrow adapter. Use a transactional local journal distinct from legacy task.db; prove persistence before actuation, dedup by command ID, immutable payload/address/profile/generation, event outbox and restart recovery. Journal write failure blocks opening. Queue one physical cycle at a time and associate fresh address/time-correlated observations; stale/global cached OPEN/CLOSED flags are insufficient.

For an accepted fresh command: validate enrollment/config/address/profile/generation/expiry; persist open intent; execute once through the established controller adapter; persist actual dispatch result; report DISPATCH_RECORDED, then only genuine correlated OPEN/CLOSE observations. If the dispatch outcome is uncertain, journal/report UNKNOWN and block reuse pending reconciliation. Pre-send server refusal means no actuation; power loss after intent can still be ambiguous. The current event contract has no separate lease/ready acknowledgment—do not infer one from a poll or manufacture observations to satisfy its order.

V1 passes the source door address through; V2 adds one inside its existing OpenLocker implementation. Do not add another +1 in the API client. Verify actual profile mapping, board/channel range, reserved channels, CRC, frame boundaries and door-state polarity before any physical adapter is enabled. Never pass SIMULATED_24 directly to real hardware.

No automatic reopen on timeout, HTTP failure, duplicate poll, restart or confirmation failure. Already-dispatched evidence may be queued/retried after expiry; fresh actuation after expiry is denied. Unknown commands/claims stay unavailable; the complete operator reconciliation workflow is still backend work. Feature-off regression and shared command interception must include direct maintenance/controller calls, not just BoxHelper.

### D. Final-deposit simulator integration

Use disposable enrolled device/site/package/run fixtures. Device creates QR; assigned authenticated driver approves; driver prepares current parcel; terminal polls and journals authorized command; simulator supplies ordered observations; terminal delivers events; driver confirms placement; backend alone changes custody. Show waiting, expired pairing, wrong site, failure, syncing and reconciliation states honestly. Door closure does not prove a parcel was placed; driver confirmation is separate.

Coordinate driver/mobile UI changes in Delivery as a separate reviewed slice. Terminal cannot make user-authenticated preparation/confirmation calls with its device key. Test with a harness until those screens exist.

### E. Sender deposit and recipient pickup after backend support

Build feature-gated screens from the Phase 1 spec, but do not enable transactions yet. Real origin deposit needs actor pairing, active label, paid size reconciliation, compartment claim, command/evidence and sender attestation. Recipient pickup needs one-time grant/device authorization, commanded door/evidence, removal attestation and COLLECTED custody. SI is an identifier, never a door credential. Grant formats must be distinguishable from legacy codes; never try a secret against both services.

The development-only `/development/origin-deposits*` routes are synthetic and cannot drive real hardware. Generic `/devices/me/heartbeat` and `/devices/me/events` appear in OpenAPI but have no matching implemented route in the inspected checkout; use the implemented command-events route. Coordinate missing backend services, contract generation and tests with the Delivery developer; no new MySQL order writes or local allocation shortcuts.

### F. Owner-authorized physical pilot

Only after A–D pass and the owner authorizes a designated empty test compartment. Confirm the actual Windows 7 image, runtime, native dependencies, hardware profile and scanner/printer configuration. Prepare a reversible release folder, known-good rollback and local logs. Do not overwrite/start alongside the live controller process. Test read-only status first, then one explicitly selected door. Shared LEGACY/DELIVERY allocation remains disabled until both allocators prove exclusion and acknowledgment. A physical locker already used by TP8 does not become Delivery-only because it is in the home network.

## 7. Acceptance evidence to deliver

| Check | Required evidence |
| --- | --- |
| Windows build | Exact VS/MSBuild/runtime/package versions, commands, configuration/architecture, clean restore and Release build results; shipped target is v4.8. |
| Windows 7 launch | OS edition/SP1/bitness/runtime/native DLL evidence and log of actual startup/TLS/QR/scanner tests. Compile on Windows 11 is insufficient. |
| Legacy regression | Feature disabled: login/config, legacy deposit/pickup/manual dropoff and relevant service-type screens unchanged; no Delivery network calls or second serial writer. |
| DTO/signing | PHP/C# known-key test vectors, byte hashes, empty/Unicode POST bodies, wrong path/key/timestamp/nonce, revoked identity and cross-device denial. No real secrets in fixtures. |
| Configuration | Separate model IDs/physical IDs, repeated model slots, raw/display sequence, row/column and electrical address; V2 offset once; missing/drifted/unverified/shared mappings fail closed. |
| Durable replay | Duplicate poll/event, response loss, power loss before/after send, journal corruption/unwritable disk, restart with open door; no blind re-actuation, preserved unacknowledged facts. |
| Hardware parser | Split/coalesced/corrupt/stale/wrong-address frames, CRC and polarity vectors, disconnect, timeouts; UNKNOWN is not CLOSED. |
| Final deposit | Full device+driver simulator flow and backend custody result; wrong driver/stop/revision, expired scene/command, close without open and event conflicts rejected. |
| Physical pilot | Separate owner authorization and recorded selected address/controller, observed open/close, scanner/printer results, rollback and uncertainty handling. Never mark this passed from software tests. |

Backend validation uses `npm run contracts:check`, `npm run check`, `npm run test-db` (disposable PostgreSQL via repository tooling); inspect prerequisites first. Terminal tests should run without a serial device and with outbound TP8/production traffic disabled. A named mock host or in-process fake is acceptable; never silently substitute a mock for live verification.

Deliver source changes, sanitized example configuration, build/test instructions, net48 release packaging/prerequisite list, results and remaining blockers. Keep terminal docs/CURRENT_STATUS.md within 200 lines with exact continuation. Do not commit/push or deploy unless requested. Terminal production configuration already contains populated legacy API key/secret fields in tracked files: do not print/copy them into this handoff or release, and arrange audited rotation/externalization before publication without breaking a live installation.

## 8. Working across the Mac backend and Windows terminal

Clone Delivery alongside terminal_452 on Windows for specifications/contracts; it need not host PostgreSQL on the locker PC. Record both commit SHAs in integration results. API origin is an explicit separate Delivery setting. `localhost:8000` on Windows refers to Windows, not the Mac; verify the Mac listener, scoped firewall and a dedicated nonproduction network endpoint before configuring the terminal. Do not expose PostgreSQL or forward router ports for this task. Use TLS/trust for real device testing; any deliberate local HTTP fixture testing remains isolated and cannot justify disabling certificate checks.

Exchange sanitized request/response fixtures and test reports through source control or a reviewed handoff, never device private keys, passwords, recipient data or DB dumps. New endpoint work belongs in Delivery; terminal firmware/transport/UI work belongs in terminal_452. Coordinate contracts and run both sides' tests before claiming seamless integration.

## 9. Known blockers and first action

Windows 7 cannot run net481; VS2026 does not certify Windows7 targeting; actual controller profile/native architecture, build restoration and terminal DTO parity remain unverified. Sender deposit, terminal recipient pickup, driver mobile final-deposit UI, complete unknown-state recovery, real enrollment operations and shared-allocator exclusion remain incomplete.

**Start with slice A, then B and C.** Continue simulated final-deposit integration without a physical locker. Report a concrete blocking build/dependency issue with logs rather than stopping at another plan. Do not bypass an ownership or physical evidence gate to progress.
