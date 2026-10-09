# Current Development Status

Date: 2026-10-09. Agent: Codex. Branch: `codex/mac-lan-docs`.
Baseline: `origin/main` at `0adf837`; preserves local documentation commit `845339a`.
This publication is documentation-only relative to remote main. No runtime, host configuration, database, deployment or hardware changes are included.

## Current objective and branch reconciliation

- Publish Mac LAN PostgreSQL notes and reconcile this handoff with the terminal/mobile implementation already on main. Earlier detailed checkpoints remain in Git history.
- PRs #44 (terminal APIs) and #45 (mobile) are merged. Later application changes in `0adf837` are already on remote main, not new changes proposed here.
- Seven apparently unmerged local `feature/P4.2-outbound-driver-delivery` commits have identical non-documentation content to consolidated commit `76f40eb` (PR #20). No duplicate PR is needed.
- Eight older remote branches contain only leftover merge commits whose trees match parents already in main; no content recovery PR is needed for those commits.
- Separate follow-up: `codex/destination-device-events`, `codex/destination-pairing` and `codex/destination-device-protocol` share three commits outside main (`9917c23`, `5d6f34a`, `d4dc062`). Newer Pairings, DeviceCommands and FinalDeposit replace much of the old design. Do not merge these branches wholesale or restore their parallel API.
- Evaluate porting boot/sequence deduplication, observation timestamps, frame hashes and reported address/ownership-generation correlation into the current command-event flow with focused tests. These old telemetry checks are not all present in the newer endpoint. No telemetry implementation is included here.

## Mac database and Windows development access

- Homebrew PostgreSQL 17 runs independently of Colima. Owner-approved Mac listener: `localhost,192.168.86.203:5432`; HBA permits only `zpx_delivery_dev` / `zpx_runtime` from `192.168.86.0/24` using `scram-sha-256`. No public rule, trust authentication or remote superuser/migrator access was added. TLS was not configured; direct access is limited to the approved home LAN.
- Credentials/application keys remain in ignored `.env.dev`; never print or commit them. Mac must remain awake/on LAN; reserve its address or update the listener after DHCP changes. Terminals use the Delivery API, not direct DB access.
- October 1 checks verified authenticated SELECT via loopback/LAN IP and HBA denial for another database. Later Windows handoffs report successful shared-Mac API/database use. These are prior results, not fresh October 9 connectivity checks.
- Private backups: `.local/postgres-backups/lan-config-20261001-164401/`. Scope/rollback: [Mac development notes](LOCAL_DEVELOPMENT_MAC.md). The macOS application firewall was already disabled and was not changed.
- Windows `scripts/windows-api.ps1` serves loopback 8000 against shared Mac and never seeds/migrates. Prior HTTPS test endpoint: `https://192.168.86.225:8443/`; restart `scripts/start-windows-test-server.ps1` as administrator after reboot. Its proxy preserves methods/body/auth/nonce; TLS private key stays nonexportable in the Windows certificate store. See [Windows development notes](LOCAL_DEVELOPMENT_WINDOWS.md). Availability was not rechecked here.
- All destructive/fixture testing uses disposable databases, never shared Mac data. Windows disposable PostgreSQL 17.11 is a separate loopback-only cluster under LOCALAPPDATA/ZPX/PostgreSQL17; PHP 8.3.35 and locked Composer dependencies were used there.
- Migrations 038/039/040 were reported applied to shared Mac. Runtime remains restricted; migrations used an authorized administrator connection with `SET ROLE zpx_migrator`, not relaxed HBA access. Do not rerun imports/commissioning merely to reconcile Git.

## Implemented application state

- Origin deposit, assigned inbound pickup, recipient single-use-grant collection and final carrier deposit are implemented. Preparation never transfers custody; ordered dispatch/open/close evidence plus paired actor attestation is required. Unknown door state retains custody/recovery gates.
- Config authentication supports the TP8 MD5 formula and `ret/msg/data` 24-hour token envelope at `/cabinet/zippora/getAccessToken`. Tokens are hashed, scoped to active bound Delivery-only cabinet/device, expire and invalidate on key rotation. Nonce replay checks and existing Ed25519 clients remain supported.
- Device routes support approved-pairing label scan, own-session status/confirmation, declined deposit and selected-model retry. Scan checks current label, actor/site and exact arrived assigned carrier stop. Retry reuses the verified hash and reruns eligibility/allocation. Declined deposit releases only HELD reservation and preserves parcel custody/version.
- Inbound arrival supports assigned acknowledged runs with current origin custody/pickup assignment; arrival alone does not transfer custody. Last confirmed inbound parcel completes the stop/revises the run. Existing outbound guards remain.
- Mobile customer/carrier Use locker includes QR camera/manual scene entry, authenticated inspect/approve, recipient grant/parcel selection, terminal-scanned sending/carrier parcels, foreground resume and observed-closure actor confirmation. `/terminal-companion` is a development fallback. Physical-phone/camera/locker end-to-end acceptance remains pending.
- Admin codes come from scoped `cabinet_admin_card` records through authenticated `/cabinet/zippora/getAdminCardList`; runtime has SELECT only. Terminal checks the live server at login and before opening, without stale-roster fallback. Imported codes do not automatically synchronize future legacy changes.
- Immutable cabinet/body/box models, protected historical references and ownership gates remain. Only verified DELIVERY_ONLY allocation is supported; shared-cabinet allocation stays gated.

## Latest recorded terminal and test inventory

These are October 6 Windows handoff results, not a fresh audit of the separate Terminal48 checkout or physical equipment.

- Terminal48 was on `codex/terminal48-net48`, baseline `0494a99`, with uncommitted work/no upstream. Preserve that checkout and inspect its current Windows status before edits/publication. Original Terminal452/zpxapi_tp8 checkouts and default legacy Main remain preserved.
- Terminal48 reuses original V1/V2 serial controller, scanner and cabinet drawing: single serial owner, durable SQLite intents/observations, no automatic reopen, native deposit Yes/No, model retry and restart reconciliation. Printer omitted. Preview mode never initializes API/device/serial/journal.
- Latest recorded private package: `E:/development/terminal48/.local/releases/zippora_del-20261006-143721.zip`, superseding earlier packages. It includes ivory/forest UI across 14 states and waiting-pairing Home/Help/Locker-status navigation with Resume pairing; active-door/recovery gates remain.
- Operational cabinet **103** maps to legacy **10191**, site/location **24**, locker **23**, protected reference **98**. Owner-approved test activation made 32 physical compartments AVAILABLE with verified model versions and ACTIVE DELIVERY ownership generation 1 / manifest 4. Board 2/raw 6 is display-only controller geometry, never a parcel door. Source MySQL historical parcels/references were preserved. Activation is not legacy-parcel migration or completed end-to-end acceptance.
- Operational cabinet **102** maps to legacy **10123**, site/location **23**, locker **22**, reference **30**. Two verified Small compartments use board 1/doors 8 and 9 (depth 400/width 80/height 20 mm); controller slot 7 excluded. Weight 100000g is the application ceiling, not a measured hardware rating. This earlier setup remains separate from cabinet 103.
- Config/layout availability hints are not allocation authority or proof of physical emptiness. Use current ownership/claims and verify designated physical compartments before tests. Public shipping stays disabled for unspecified lab addresses. Do not reset inventories, claims, legacy parcels or private configs.
- Reuse private Windows config/settings without displaying credentials. Latest cabinet-103 instructions supersede older cabinet-102 examples. Install under the same Windows operating account, preserve config/journals and close other COM owners. See that repository's `docs/WINDOWS7_DELIVERY_INSTALL.md` and `CURRENT_STATUS.md`.

## Validation and remaining gates

- This documentation publication: two-file diff against origin/main reviewed; whitespace, relative links, conflict-marker, 63-line status limit and common credential-pattern checks passed. No application tests, connectivity, migrations or hardware checks were run for this documentation-only change.
- Prior Windows handoff reports root checks at 122 API operations / 14 tests, contract/generated-type consistency, TypeScript/mobile, PHP lint and disposable PostgreSQL/native HTTP integration passing. Coverage includes tokens/signatures, nonce replay, rotation, scanner replay, confirmation, declined deposit/model retry, inbound arrival authorization/revisions and stop completion. Android/iOS Expo exports passed there.
- Prior Terminal48 Release/Delivery builds, native layout/safety/navigation smoke, V1/V2 synthetic-frame checks, package manifests and packaged HTTPS/config/SQLite diagnostics passed. Latest screens fit 800x600 / 784x521. Owner reported earlier Windows7 admin/controller/door/recovery checks; newest UI/pairing navigation and complete parcel flows still need remote acceptance.
- Current x86 smoke remained blocked by Windows access denial/disappearing output; older passes are not current validation. Missing watchdog project and inherited log4net 2.0.8 / Newtonsoft.Json 12.0.1 advisories remain. Full default-legacy regression and Windows7/kiosk/DPI/TLS/hardware validation are incomplete.
- Mobile real-device camera/network/locker evidence, durable offline scan recovery, broader payment/quote/label/claim integration and field custody acceptance remain unfinished. Prior Mac standalone iOS build hit an Expo JSI/Swift issue; Android emulator installation lacked storage. Do not claim production readiness.

## Exact continuation

1. Review/merge the documentation PR from `codex/mac-lan-docs`, then synchronize local main without dropping preserved commit `845339a`. Do not merge/delete old branches merely because Git lists them as unmerged.
2. Take telemetry follow-up on a fresh branch from updated main: compare old checks with current contracts/consumers, keep the current API and add focused disposable tests for selected additions.
3. On Windows inspect separate Terminal48 worktree/handoff, privately install the approved package preserving cabinet-103 config/journal and verify pairing > Home/Help > Resume plus customer/carrier parcels. This documentation task does not authorize deployment or physical actuation.
4. Physical-phone tests need a reachable `EXPO_PUBLIC_API_URL`; loopback is unreachable from a phone. Exercise all four workflows, declined deposit/model retry and observed door/fault/restart recovery before pilot readiness.

Important source references: Custody `PhysicalSessions.php`, `FinalDeposit.php`, `DeviceCommands.php`, `Pairings.php`, `CabinetConfigAuth.php`, `LegacyOccupancyReview.php`; API tests `terminal-workflows.php`, `device-commands.php`, `final-deposit.php`, `legacy-occupancy-review.php`; mobile `Locker.tsx`/`pairing.ts`; OpenAPI/generated contracts; [terminal design](terminal/TERMINAL48_DESIGN.md) and [UI handoff](terminal/TERMINAL48_UI_HANDOFF.md).
