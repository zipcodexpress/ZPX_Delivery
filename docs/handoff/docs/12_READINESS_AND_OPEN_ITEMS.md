# Readiness and unresolved evidence

## Ready now

Source-backed integration map, independent platform architecture, fixed-partition coexistence design, application responsibilities, label/SI/scan policies, proposed new DB migrations, core OpenAPI contract, device/journal rules, task dependencies and acceptance gates. Codex can start P0/P1 and simulator-based vertical slices without a live locker or final site addresses.

## Not claimed

This is not production-certified code, a compiled terminal, verified live schema, complete security audit or a working driver app. The supplied repositories' default branches are review references; no proof was provided that their exact revisions run on current sites. We did not test physical protocols, execute MySQL DDL, contact production endpoints or publish code. Contract validation in this handoff is static only.

## Release gates with owners and safe development defaults

| Item | Why needed | Development default / gate |
|---|---|---|
| Installed terminal OS/CPU/bitness and DLLs | Native printing/SQLite/audio and runtime compatibility | Simulator + Windows CI inventory; blocks hardware rollout, not API work |
| ZipporaService project/reference | Referenced from terminal csproj, absent in tree | Isolate host build dependency or obtain source; no claim full terminal compiles |
| Actual controller profile and serial settings per site | V1/V2 differ, alternate vendor constants exist | Explicit profile fixtures; no auto guessing |
| Door observation reliability and parcel sensor availability | Closure not proof of parcel presence | Door-plus-actor evidence; physical validation before accepting real custody |
| Legacy DB schema-only export and all live writers | Source models don't establish indexes/triggers/other services | Read-only mapping fixtures; compatibility deployment blocked until audited |
| Current production branch/deployment config | Default branch may differ | Pinned source review; deployed-version inventory before patching |
| Apartment ownership allocation and property access policy | Partitioning reduces apartment capacity and controls who can enter | No doors enabled until selected/approved and drained |
| Actual locations/hub/hours/cutoffs | Scheduling and access promises | Synthetic 20-site fixture, no customer promises |
| Printer/scanner equipment and home-print trial | Label readability and first-mile labeling | Generated PDF plus manual scan fixture; physical trials before launch |
| Payment/notification provider and live rates | Monetary and communication effects | Fake/sandbox adapters; no live billing |
| Mobile app IDs/signing/distribution and device matrix | Installation and secure storage behavior | Local builds; no app-store publishing assumed |
| Retention, claims, insurance, allowed items and refund policy | Operating rules | Config placeholders, business review before public launch |

## Decisions already made for implementation

Separate new DB and applications; assigned drivers; hub-and-spoke; primary per-package scans; one parcel per delivery compartment; static partition at shared sites; same electrical protocols; one terminal command gate; new authentication; no automatic resident copy; SI public and pickup grant secret; online authority with durable post-dispatch evidence recovery. No need to reopen these in every task unless new evidence conflicts.

## First information request when implementation reaches hardware

Ask for missing ZipporaService or explanation of unused project reference, one representative terminal hardware/OS configuration, actual serial settings/controller vendor, and schema-only legacy export. Request only what blocks that task. Do not hold all software work for full operational data.

## Supersession

This revision supersedes the earlier handoff's assumptions that repository source is unavailable and that Phase 1 does not share apartment hardware. It also replaces the early in-memory demo as the development entry point; no demo boolean can authorize a production transfer. Existing Phase 1 business principles (hub, individual scans, stable SI, assigned drivers) remain.
