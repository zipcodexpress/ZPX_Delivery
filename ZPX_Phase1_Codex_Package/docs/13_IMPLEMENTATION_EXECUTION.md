# Implementation execution contract

Revision 4. This document turns the architecture into an initial work order. Paths below are targets to create in the separate project; they do not imply application code exists in this package.

## Authority and consistency

User requirements and verified physical behavior constrain the design. Docs 02–10 define domain and safety behavior; contracts/openapi.json defines the wire contract. The two SQL files are proposed schema inputs requiring executable migration review. Neither a permissive schema nor an omitted endpoint bypasses a domain rule. Resolve a contradiction in all affected files with a recorded decision and regression test before implementing the affected behavior. Doc 01 and evidence files describe reviewed source, not proof of the deployed versions.

Retain this handoff under docs/handoff in the new repository, copy its AGENTS.md rules into the applicable project instructions, and keep contract generation pointed at one canonical OpenAPI file. Do not maintain divergent editable copies. Record decisions in docs/decisions and commands/results in docs/verification. Keep specification revision separate from application release version.

## M0 — reproducible project and executable contracts

Tasks P0.1, P0.2 and the software portions of P0.3. Complete before business features depend on an untested schema.

| Work item | Target output | Evidence required |
|---|---|---|
| Toolchain | docs/decisions/0001-toolchains.md, dependency manifests and lockfiles | Exact PHP/Composer, Node/package manager, mobile SDK and MySQL versions; documented Windows terminal build lane |
| Local environment | deployment/local configuration and example environment | Fresh startup creates only a disposable delivery database; missing secrets fail clearly; no embedded credentials |
| API | apps/api health check, config, test runner, error envelope | Healthy database dependency succeeds; unavailable dependency fails predictably |
| Web/mobile | customer-web, operations-web and mobile runnable shells | Web production builds and mobile typecheck plus Android development build; iOS availability reported separately |
| Simulator | simulators/locker with deterministic event script and tests | Valid open/close, wrong address, stale frame, timeout and crash-after-dispatch scenarios; no physical serial device needed |
| Database | Versioned migrations derived from SQL 001 and 002 | Both execute in order on empty MySQL; constraints inspected; FK/uniqueness tests; second migration invocation is safe through migration tracking |
| API contract | OpenAPI lint and generated client check | External semantic validator passes; generated client compilation; failures fail CI |
| Verification | docs/verification/M0.md | Exact commands, versions, results and unresolved terminal dependencies; no fabricated successful builds |

Implement a project command runner exposing `check`, `test-db`, `test-simulator`, `test-e2e` and `build`. These names are required capabilities to create, not commands supplied by this Markdown package. Document their actual invocation in the project README and wire them into CI. Linux CI must cover API, web, contract and database; Windows CI covers terminal compatibility; mobile lanes state the platforms actually built. A terminal dependency blocker is recorded separately and does not waive the working simulator requirement.

## M1 — first complete custody slice

Implement P1.1–P3.3 in dependency order. Keep each change reviewable; do not put all applications into one untestable PR.

| Increment | Implementation targets | Completion test |
|---|---|---|
| Identity and topology | API auth, RBAC, device enrollment, location/compartment ownership; fixture loader | A second customer cannot read shipment one; wrong device/site denied; two reservations for last eligible door yield one winner |
| Shipment and label | Shipment/package service, fake payment adapter, PDF/QR label, customer web/mobile views | Verified synthetic customer obtains stable SI and printable label before deposit; replacement revokes old label without changing package |
| Origin deposit | Terminal UI flow and durable journal, pairing, session service, occupancy and event transaction | Open+close+actor attestation commits once; crash/retry creates neither duplicate custody nor a second automatic open |
| Driver pickup | Assigned inbound run, driver scan screen and cached route, same terminal session engine | Every parcel scanned; driver cannot open unassigned parcel; pending evidence remains pending after reconnect |
| Hub receipt | Operations receiving screen and receiving service | Hub independently scans four of five picked-up parcels; four transfer to hub and one remains outstanding with driver |

Provide seeded demo accounts through a development-only seed command, with generated credentials printed locally once. Do not include fixed usable passwords in the repository. Fixtures are synthetic; location coordinates, operating hours and rates are explicitly demo data. No real address should be inferred from an Austin location code.

## M2 — sorting, routing and recipient completion

Implement P4.1–P5.2 after M1. Use destination-specific staging slots and dispatcher-published ordered stops. Driver preferences may suggest a run; they do not authorize self-assignment or override capacity/access constraints. Phase 1 permits dispatcher ordering with map estimates; an unavailable map provider leaves the last published route visible, with estimates marked unavailable. No optimization solver is required for the initial pilot.

Ten outbound parcels for two lockers require ten accepted distinct scans. Nine plus a duplicate is nine. Departure needs exact loaded-manifest equality. The app groups six at stop one and four at stop two and shows pending, accepted and unresolved items. Reject wrong-location actions before opening. Failed delivery retains driver custody until independent hub receipt or another explicitly authorized custody transfer. Recipient pickup requires a separate grant and physical evidence.

Complete T01–T12 and T15–T18 with automated simulator/API tests where possible. Perform the physical portions of T16 only on commissioned hardware; do not substitute a simulated pass. Preserve the full T01–T20 checklist for launch.

## Scan, retry and concurrency details

- The label resolver returns current package version and permitted actions without changing custody. Use that version in `expected_package_version`; do not increment it optimistically in the client.
- `If-Match` refers to the aggregate in the resource path: shipment, package, run or locker session. For run operations it is the quoted run revision. A scan also supplies matching `run_revision` and the independent package version. Run revision identifies published manifest/assignment/order, not a counter incremented by every scan; package locks and uniqueness serialize scans and maintain current counts.
- Authenticate and scope-check every retry. Then resolve an existing idempotency record before evaluating stale version preconditions for a new operation. Same key and canonical payload returns the original result; changed payload conflicts. A lost response must not make a successful action fail solely because its own prior commit advanced a version.
- A different scan event for an already loaded package is recorded as a duplicate, cannot increment counts, and cannot create a second transfer. A package assigned to a different run is a conflict. Client event UUID persists across transport retries.
- A route revision after scanning invalidates departure until dispatcher and driver reconcile changed items. Never silently discard already held packages. The revised manifest and unresolved custody must be visible together.
- Device evidence may advance session versions while the user reads the screen. A stale attestation refreshes the session and retries with a new command identity only if still required; it never issues another open command.

## Terminal and app coordination

The terminal creates a scene; the authenticated app approves it and creates the package-scoped locker session. Driver pickup/final-deposit scans reference that session; the server verifies its package, actor, action and run. The terminal receives commands only through its enrolled-device endpoint. Do not put the customer's bearer credential on the shared kiosk.

This defines the first app-assisted vertical slice, not every final terminal screen. Terminal-only label entry, pickup-code entry and locally captured actor attestation require explicit device-scoped delegated-action contracts before those UI paths ship. Extend OpenAPI with bound pairing/grant, package/session, action and expiry; constrain those routes to the enrolled terminal's site and approved actor. No device credential alone may act as any customer. Include those contract additions in P3.1 and P5.1 respectively, with denial tests. Existing apartment carrier/pickup screens retain their legacy authority and pass through the same physical command gate.

## Completion and rollout boundary

Software development may proceed with synthetic sites and provider adapters. Real apartment testing additionally requires P0.3, P6.1 and the ownership generation acknowledgments. Public-locker commissioning also requires verified hardware mapping and recovery, even without a legacy partition. P6.2–P8.1 cover commissioning, operational policies, monitoring, restore and staffed rehearsal.

Every task report contains: task ID, implemented behavior, changed paths, migration/contract changes, commands actually run, results, remaining blockers and next dependency. Track tasks as TODO, IN_PROGRESS, BLOCKED or DONE. Do not call an entire milestone done when one application is represented only by a mock.
