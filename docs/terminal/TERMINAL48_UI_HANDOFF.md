# Terminal48 UI handoff

The implemented native shell is in `E:/development/terminal48/Zippora/DeliveryShell.cs`.
UI UX Designer component/redesign guidance and Figma use/generate-design guidance
were applied. Figma canvas tools were unavailable in this session; this document
and rendered native frames are the handoff, not a generated Figma file.

## Current legacy-parity flow (2026-10-02)

The user requires the working Terminal452 setup and config authentication. After actual door closure, show **Yes, I deposited** and **No, I didn't**. No retains the parcel with the carrier and returns to large compartment-size buttons; selecting another size prepares a fresh eligible attempt without rescanning or repricing. Printer omitted.

The native config-token client, V1/V2 controller adapter, COM scanner and cabinet drawing reuse the original implementation. Scanner authorization, decisions and size retry are API tested. Real hardware/site binding is pending; screenshots are synthetic visual references.

Updated native frames: `E:/development/terminal48/docs/delivery-shell-closed.png` and `E:/development/terminal48/docs/delivery-shell-size.png`. The 800x600 closed and size states were rendered and inspected; size choices use 56px buttons.

## Frames and components

- Physical status (2026-10-05): header Locker status action while idle; active parcel session navigation gate retained. Read-only original cabinet drawing geometry, compact180px board bodies, red Open/green Closed/gray Unknown text and accessible per-board descriptions. API health and controller/scanner state are separate. Stale or missing replies become Unknown; status never means parcel occupancy. Legacy address editing, selection and door-opening controls are removed from this view. Native synthetic reference E:/development/terminal48/docs/terminal-locker-status.png; full test diagram fits784x521 above footer. Real physical replies/Windows7/DPI remain operator validation.

- Native client: up to800 × 600, fitted within the display working area including window chrome; single-line56px ZPX DELIVERY/Locker status/Home header and64px status footer. Slogan removed per user's2026-10-04 screenshot feedback. Shorter screens scroll content only; footer remains visible. Admin Manager integration remains separate.
- Main content: 26–28px side margins, 16px card gaps; three home task cards.
- Primary actions: 48–56px high, clear verb labels; persistent Home and Help.
- Typeface: Segoe UI; heading 24–26pt, task title 18pt, body 11–13pt,
  metadata 9–11pt, compartment 38pt.
- Colors: ink `#1A2B3C`, muted text `#5B6B7B`, action `#C24318`,
  page `#F4F7FA`, white surfaces, confirmed green `#197153`.
- Use text plus color for API state, recovery, simulation and success.
- Pairing: full scene QR, copy alternative, three steps, site/action approval
  on the private phone; never show customer passwords on the terminal.
- Operation: one exact compartment, parcel SI, specific placement/removal
  instruction. Door closed prompts phone confirmation; only confirmed custody
  displays success. Missing command/offline/unknown outcomes prompt assistance.

## Figma recreation

Create 800 × 600 frames for Welcome, Send, Pickup, Driver, Help, Pairing,
Open, Closed awaiting confirmation, Confirmed and Recovery. Use shared header,
footer, task-card, action-button and compartment-panel components. Give buttons
default/hover/pressed/disabled states and preserve 48px minimum touch targets.
Include prototype connections through the phone authorization boundary; a door
close must lead to awaiting confirmation, not directly to success.

Native reference PNGs are under `E:/development/terminal48/docs/`:
`delivery-shell-home.png`, `delivery-shell-pairing.png`,
`delivery-shell-closed.png`, `delivery-shell-confirmed.png`,
`delivery-shell-recovery.png`. Workflow frames explicitly contain synthetic data
and a DESIGN PREVIEW badge. They are visual examples, not transaction evidence.

Layout bounds and button heights are checked by BuildSmoke. Home, pairing,
confirmation and recovery renders were inspected at native size. The browser
companion login also rendered without console errors. Actual kiosk DPI,
screen-reader behavior, phone login/refresh interactions and hardware operation
still require field validation.
