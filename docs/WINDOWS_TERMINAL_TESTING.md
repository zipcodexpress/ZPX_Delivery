# Windows terminal test procedure

**Superseded by [decision 0002](decisions/0002-new-terminal-platform.md).** This procedure was based on an incorrect migration assumption. The old terminal is reference-only. Do not request Windows screenshots, legacy builds or Zippora.exe testing for the new project. Build a new terminal with Android as the preferred direction. The remaining text is historical and is not an active work order.

Purpose: explain how Richard can help test the Windows terminal while development uses an M4 Mac.
Audience: owner and terminal developer. Status: planned; no new terminal binary is ready yet.
Owner: engineering prepares the build; Richard operates the test device. Last reviewed: 2026-09-18.

## Owner clarification

Richard confirmed ZipporaService only supervises/restarts Zippora.exe. Focus Phase 1 on Zippora.exe. The watchdog service is not a required delivery module or source request. Historical handoff observations about the missing project remain an accurate source-review record, but this clarification supersedes treating its source as a development prerequisite. Engineering must inspect and isolate any build-time reference, then actually compile the executable before claiming independence. Automatic restart behavior can be verified separately during commissioning.

## Where each component runs

- M4 Mac/external SSD: source checkout, API/database, customer and operations web apps, and normalized locker simulator.
- Windows build environment: compile/test the C# terminal application and its native dependencies. The Mac is not expected to run the Windows executable.
- Separate Windows test terminal: run the prepared terminal executable, first against synthetic services, then against a designated test locker/controller.

## What Richard can do now

1. Identify a spare Windows terminal or an agreed maintenance test device. Do not install this unfinished software over a live apartment terminal.
2. Provide a screenshot of Windows System information showing edition/version and System type (32/64-bit), with identifiers redacted if needed.
3. Provide scanner and printer model names if known. A photo of the controller board model and existing serial configuration is enough to start; no need to identify protocols by guesswork.
4. Confirm whether the Windows terminal and Mac can be on the same local network. No inbound router port forwarding is requested.

No new executable is available in PR 4, so there is nothing to install or test on Windows yet. No programming or Visual Studio setup is required from Richard at this stage.

## What engineering must prepare before asking for a test

- A compiled terminal build matched to the Windows version/bitness, with all distributable dependencies and startup instructions.
- Separate test configuration and generated test credentials; no production database/device configuration.
- A deliberate dev-network connection method. The current Docker ports bind to 127.0.0.1 and are reachable only on the Mac; entering the Mac IP on Windows will not work yet. Provide a scoped test-network configuration or staging endpoint and verify authentication/connectivity before hardware testing.
- Simulator-first test script for send, scan, open/close evidence and interrupted/retried operations, with expected results and log collection instructions.
- Rollback instructions and a designated empty test compartment before enabling any physical controller commands.

## Hardware test after software builds

Richard will scan a synthetic parcel label on the Windows terminal, follow the prepared UI, verify the selected empty test compartment and report the observed door behavior. Engineering checks address mapping, controller responses, scanner formatting, label printing and recovery logs. Start with one device and empty compartment. Apartment/public coexistence and the ten-parcel driver/hub rehearsal follow only after their separate gates pass.

Development continues with the simulator until this Windows build and test setup exist. A passing Mac simulator test is not evidence of a working Windows terminal or real lock controller.
