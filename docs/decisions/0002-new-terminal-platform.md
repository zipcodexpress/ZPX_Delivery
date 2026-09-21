# 0002 — New terminal application; legacy terminal is reference only

Purpose: correct terminal scope following Richard's explicit clarification.
Audience: developers and project owner. Status: accepted scope; Android implementation/toolchain provisional.
Owner: Richard (product direction). Last reviewed: 2026-09-18.

## Decision

The previous Windows terminal is reference material for how the locker works, API interaction patterns, scanner/door behavior and the protocol for opening doors and checking status. It is not a migration target or a runtime/build dependency for this project. Neither Zippora.exe nor ZipporaService needs to be rebuilt, ported or supplied to start development. Richard permits Android and expects to use it in the future; use Android as the preferred new-terminal direction while keeping the hardware protocol adapter separate from kiosk screens and delivery workflows.

This supersedes historical C# WinForms, Windows compatibility-build and missing-service prerequisites in the development plan and revision 4 handoff. Those documents retain source-review history; their legacy platform requirements must not govern new implementation. No new terminal code was implemented by this decision.

## Architecture implications

- New kiosk application handles sending, customer pickup and the required carrier/driver interactions through the new API and enrolled-device authorization.
- A platform-specific transport adapter implements verified controller frames, addressing, checksums and door status. Do not assume Android has access to Windows DLLs or that every USB/serial controller is supported.
- Keep the command gate, durable journal, ownership checks and timeout/reconciliation rules independent of the screen framework. A platform change does not permit scan-only custody or blind reopen on retry.
- Start with simulated hardware and automated protocol fixtures; choose Android language/framework, SDK and transport dependencies after inspecting the hardware interface. No final Android device support is claimed yet.
- Customer/driver mobile remains a separate role-based product from the fixed kiosk, even if contract types or other suitable modules can be shared.

## Existing apartment sites

Reference-only source does not remove physical coexistence requirements. At shared sites, deployment must explicitly decide whether the new kiosk replaces existing software and supplies necessary apartment workflows, or interoperates through an approved single-controller arrangement. Never run independent old/new command writers against the same compartments. Fixed ownership, legacy-service continuity and physical commissioning remain gates; an old executable build is not a gate for new software development.

## Testing and owner inputs

Continue software work using the Mac backend and simulator; add Android emulator/build tests once scaffolded. Later test a new application on the selected Android kiosk connected to a designated empty test locker. Do not request a Windows System screenshot or installation of a legacy executable as a prerequisite.

When hardware selection is needed, ask for the controller connection type (USB, RS-232/RS-485 or network), controller model/protocol settings, scanner/printer connections and any proposed Android screen/controller model. These determine transport support. No hardware purchase or live-site replacement is authorized by this document.
