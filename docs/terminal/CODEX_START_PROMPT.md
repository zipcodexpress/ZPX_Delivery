# Copy this prompt into Codex on the Windows development PC

```text
Implement the Windows terminal integration, using terminal_452 as the base.
This is an implementation request, not a planning-only review.

Repositories:
- https://github.com/zipcodexpress/terminal_452
- https://github.com/zipcodexpress/ZPX_Delivery
Use existing local Windows clones or ask for their locations if unavailable.

First read ZPX_Delivery/docs/terminal/WINDOWS_CODEX_HANDOFF.md and its linked
specifications. Read AGENTS.md and current status in both repositories if present.
Verify each root, branch, git status and latest ten commits. Preserve all existing
work. Record the actual inspected commits; the handoff baseline is Delivery
1b0b018 and terminal 0494a99453effd69be548f1a69ebe5a782768ace.

Build with Visual Studio 2026 on a supported modern Windows development PC.
The physical locker PC currently runs Windows 7: verify edition, SP1, bitness,
runtime and native drivers. .NET Framework 4.8.1 CANNOT run on Windows 7.
Target .NET Framework 4.8 (v4.8/net48) for the deployable artifact, with the 4.8
developer pack. VS2026 does not officially list Windows7 as a supported target;
actual Windows7 launch/TLS/native dependency testing is required. Do not retarget
to net481 or alter runtime config to conceal a compatibility failure.

Work in a terminal feature branch. Follow handoff slices A through D in order:
resolve restore/build dependencies, preserve a feature-off legacy baseline,
make a focused net48 migration, add a separate signed Delivery client/DTOs,
implement a durable journal/outbox and command gate using the existing single
serial owner, then test final deposit using disposable backend fixtures and
simulated hardware. Keep TP8 host, API contracts and working legacy functions.
Inspect the watchdog reference rather than fabricating a stub service.

Use actual implemented /api/delivery/v1 routes. Generate exact-byte Ed25519
signatures with fresh transport nonces; keep semantic retry IDs stable. Device
identity is separate from driver authentication. Render returned scene_payload
in QR exactly. Keep display order, row/column, controller and door addresses
independent; V2 adds its offset once in the existing adapter.

Do not allocate doors from configuration, clear blocked flags, use historical
reference cabinets as live inventory, or introduce another operational inventory.
Do not blindly reopen after retry/restart/timeout. Ambiguous dispatch/evidence
enters UNKNOWN/reconciliation with a retained claim. Never fabricate OPEN/CLOSE
events or treat a command poll/door close alone as custody transfer.

Real origin deposit and recipient pickup APIs, driver mobile final-deposit UI
and complete recovery remain incomplete. Keep their screens feature-gated and
document any required backend contract changes; do not substitute synthetic
development routes or TP8 store writes. Shared-cabinet allocation remains gated
until both allocators enforce and acknowledge ownership exclusion.

Start coding after startup inspection. Run Windows build, C# DTO/signature,
journal/replay/parser and simulator integration tests; report actual results.
Do not touch production DBs, contact production TP8 services, send commands to
physical lockers, replace the live kiosk, or commit/push without my request.
Prepare physical pilot instructions only; wait for explicit pilot authorization.
Update terminal docs/CURRENT_STATUS.md at milestones and before context runs low,
keep it <=200 lines, and record exact continuation, commands and blockers.
```
