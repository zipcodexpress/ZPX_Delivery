# Terminal48 — Quiet confidence

Date: 2026-10-06. Approved design; native rollout implemented and locally validated.

User approved this direction and requested it on all pages. Apply it to the existing native .NET4.8 shell, including disabled/offline workflows, code login, manager, passive status, QR pairing, size retry, open/closed/confirmed/recovery and Help. Preserve all command/auth/custody handlers. Validate both client sizes and real three-tower geometry before packaging. No database or physical operation is part of this visual rollout.

[Open the interactive design](terminal48-elegant-preview.html). Use the controls above the terminal to switch screens, compare800×600/784×521, simulate connection loss and advance sample parcel states. Any four or more digits enter the prototype manager; production must retain the real cabinet-scoped code API. All states are synthetic. No network, credentials, database or serial operations exist in the prototype.

## Direction and goals

An approachable parcel kiosk with an ivory canvas, deep green actions, restrained outlines and a clear, quiet hierarchy. Retain ZPX recognition, the one-line banner, original cabinet identity, ZIP, persistent connection indicators, code-only admin login and verified Terminal452 geometry. Pickup receives the strongest emphasis on Home. Sending and driver services remain equal-size, clearly visible alternatives. The whole task card is a touch target.

The user requested design first, then approved rollout to all pages. The prototype remains an offline design artifact. Its style is now implemented in the .NET4.8 UI without altering the32 available test compartments.

## Audit and changes

| Existing issue | Severity | Design change | Reason |
| --- | --- | --- | --- |
| Dark header, repeated dark buttons and heavy geometry compete | Major | White62px header, one primary dark card, light surfaces | Action hierarchy is easier to read |
| Cabinet facts and technical messages consume the welcome area | Major | Identity/ZIP in header, live connection states in persistent footer | Preserve status without interrupting task choice |
| Admin selection and action controls have equal visual emphasis | Major | Large diagram plus a selected-door inspector | Make the target and resulting action explicit |
| Compact main-tower cells are difficult to touch | Major | Keep original proportions; provide44px tower selectors and44px door selector | Every door remains reachable without accurately tapping tiny cells |
| Authentication screen feels like a utility form | Minor | Balanced two-column keypad and concise instructions | Code-only access remains familiar and simple |
| Unknown door state can be mistaken for occupancy | Major | Separate Door and Parcel space values; label legend “Sensor status · not parcel occupancy” | Closed never implies empty |

## Screen specification

| Screen | Main content and action |
| --- | --- |
| Home | “Your day, delivered.”; three full-card actions: Pick up, Send a parcel, Driver services; permanent Help and Admin |
| Admin access | Masked numeric code, Delete/Clear, Enter locker manager; inline invalid-code or offline error; no email/password fields |
| Locker manager | Three proportional towers; main tower controller cell; selected tower border and selected door fill; touch-sized door selector; distinct Open door/Open tower actions |
| Pickup / Send | Three concise instructions, QR/manual pairing region and waiting state; preserve current phone approval contract |
| Driver services | Collect parcels or Deliver parcels; continue through assigned-route approval |
| Open door | Large tower/door identity and one physical instruction; wait for observed closure |
| Confirmation | Explicit collected/deposited question after closure; “Not yet” or “Yes, confirm” |
| Complete | Confirmation following the server’s accepted custody result; Back to Home |
| Recovery | Explain the interrupted opening, require admin verification, do not offer automatic repeat opening |
| Help | Original cabinet ID/ZIP, API and controller connections, site assistance instruction |

Prototype pairing code is decorative and QR is explicitly a placeholder. Production must render the existing issued scene/QR and expiry, never a hard-coded example. “Not yet” is an illustrative return in this design; native implementation must preserve the existing decline, claim-release and fresh-size retry contract, and cannot discard an active pickup session.

## Visual system

| Token | Value / use |
| --- | --- |
| Canvas | `#F5F6F1` warm ivory |
| Primary | `#173E35` deep green; primary actions/selected compartment |
| Text | `#233D35` |
| Secondary text | `#65746E` |
| Surface | `#FFFFFF` |
| Border | `#DBE2D8` |
| Soft green | `#E8EEE4` |
| Warning/open | `#AD4C2C` text and warm tinted fill |
| Error/offline | `#A33B36`; always accompanied by text |
| UI type | Segoe UI, with Arial fallback; existing Windows fonts, no downloads |
| Welcome/state heading | Georgia regular34–39px; UI headings24–29px Segoe UI semibold |
| Body / action text | 14–16px; native small-screen secondary text may be12–13px |
| Spacing | Primarily8/16/24px; compact controls use6/12px where necessary |
| Rounding | Cards14px, buttons9px, cells3px |

Measured contrast: primary/white11.82:1; secondary text/canvas4.52:1; green/soft surface5.98:1; warning/white5.46:1. Verify actual native rendering, disabled states and system high-contrast settings during implementation.

## Diagram and interaction rules

- Pairing navigation correction: waiting for phone approval must not trap the user. Home/Help/status navigation pauses terminal polling and scanning while retaining the scene; Home offers Resume pairing. Starting another parcel workflow resumes the retained scene rather than silently replacing it. This is pause/resume, not server cancellation; a phone-created session must not be abandoned or opened in the background. Once a door/session is active, existing completion/recovery gates still apply.

- Geometry matches the actual10191 configuration: towers1/3 have8 doors each; main tower2 has16 doors plus controller display cell at raw6/display7. Preserve physical dimensions and raw-channel mapping.
- Selecting a tower highlights its heading/border and resets the door selector to a valid physical door. Selecting a door highlights only that door and updates its inspector.
- The diagram’s smallest cells are supplementary pointer targets. Tower selectors and the accessible door dropdown provide the minimum44px touch path to every door. Do not claim every small schematic cell is a44px target.
- Open tower confirmation names the selected tower and exact physical count; the main controller is excluded. Cancel has initial focus; Escape cancels; focus stays inside the confirmation.
- While an opening needs closure, disable further opening and sign-out. Keep server authorization, durable whole-target journal and current sensor checks in the native application.
- Use real sensor readings for Open/Closed/Unknown; expiry or disconnection becomes Unknown. Display parcel availability only from authorized current Delivery inventory; unavailable/stale occupancy becomes Unknown.
- Production admin errors must say “Code not recognised” or a useful connection error without exposing the code, record IDs or credentials. Preserve existing expiry/revocation checks.

## Layout and states

The kiosk frame is fixed to its client area; header/footer stay visible. At800×600 use62px header and58px footer. At784×521 use54px header/footer and compact spacing; the selected-door number is represented by its selector to make room for actions. All11 prototype screens fit both sizes without main-area scrolling. Retain native DPI/work-area fitting for Windows7 and test actual touch equipment separately.

States include default, hover, visible keyboard focus, selected, disabled, offline, opening/pending closure, empty code, waiting for phone, success and recovery. Offline status uses text and colour, disables admin sign-in/opening, and shows Unknown sensor/parcel values. No motion is needed; brief hover transitions respect reduced-motion settings. Production should preserve this behavior without requiring a browser/webview on Windows7.

## Native implementation handoff

1. Apply shared colours/type/button/surface styling in DeliveryShell. Render header identity and stable footer status without rebuilding active inputs on each poll.
2. Replace Home task layout and admin keypad styling while preserving navigation and code authentication handlers.
3. Restyle LockerStatusView and place the inspector beside the full-width diagram. Reuse DrawBoxHelper proportions, current board/door selection and MaintenanceOperation; preserve all verified command behavior.
4. Apply the same visual system to pairing, door, confirmation, recovery and Help screens. Preserve scanner handling, server custody decisions and session recovery.
5. Build Release/Delivery .NET4.8; run existing native smoke/14-state checks and capture actual geometry at both sizes. Package only after visual inspection and targeted workflow checks. Do not change API, database or serial behavior for styling.

## Validation

Native rollout: DeliveryShell.cs, DeliveryShell.Admin.cs and LockerStatusView.cs reuse the existing operation handlers. Georgia headings, forest actions, ivory canvas and neutral surfaces now cover all pages. Native banner stays56px/footer64px to retain tested Windows7 touch sizes. Tower/door dropdowns provide>=44px alternatives to schematic cells, all action buttons>=48px. Sensor text remains Unknown when stale; no synthetic parcel availability is shown. Small session mini-diagram removed in favour of an unambiguous large compartment code; full diagram remains on Locker status/admin. OS-native maintenance confirmation deliberately retains default Cancel/No and existing safety semantics.

Release/Delivery .NET4.8 builds and x64 BuildSmoke passed:14 native states at800x600/784x521, no main-content scrolling, original auth/revocation/durable maintenance/recovery/sensor/navigation tests and real32-door/1-controller geometry at both sizes. Captures: terminal48/.local/ui-elegant-native and .local/ui-legacy-towers/actual-10191-elegant-*.png. Private package140645 has0 hash mismatches and packaged HTTPS/config-token/config/SQLite diagnostic exit0; no serial ports opened. Existing watchdog/processor warnings and actual Windows7/DPI/touch acceptance remain. Root browser prototype and backend contracts unchanged by native styling.

`node scripts/check-terminal-design.mjs` uses installed Microsoft Edge (override with TERMINAL_PREVIEW_BROWSER). Passed11 screens ×2 sizes;32 physical door targets and1 controller cell; keypad entry; individual selection; main tower16-door confirmation; cancellation; pending-operation disabling; offline opening disabled. Saved images under ignored `.local/terminal-design/`; inspected Home, compact admin and compact manager. Initial manager inspector and compact Help overflow were corrected and checks rerun successfully. This validates the browser design, not native WinForms behavior.
