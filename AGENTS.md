# Repository instruction entry point

The current handoff is in ZPX_Phase1_Codex_Package/. References below to CODEX_START_HERE.md and docs/13_IMPLEMENTATION_EXECUTION.md refer to paths within that package. Read the root README.md and docs/DOCUMENTATION_GUIDE.md for repository navigation and documentation workflow.

# Instructions for implementation in the new ZPX delivery project

Scope: this handoff and the new delivery project. These instructions do not grant production access or override instructions in separately checked-out reference repositories.

- Read CODEX_START_HERE.md and docs/13_IMPLEMENTATION_EXECUTION.md first. The handoff contains specifications and draft migrations, not an existing implementation.
- Keep new delivery data and authentication separate from legacy apartment records. Shared hardware requires fixed compartment ownership and one command gate before any physical pilot.
- Preserve package identity across sorting, route changes, relabeling and returns. A label or SI is identification, never permission to open a door.
- Enforce role/site/assignment authorization on the server. Use transactional current-custody and occupancy updates, immutable event history, idempotency and an outbox.
- Never infer custody from a scan, cached door state or open-command acknowledgment alone when the workflow requires a physical locker transfer.
- Write migrations and meaningful tests with each feature. Use disposable synthetic databases/devices. Do not copy production users, credentials or customer data into fixtures.
- Keep wire contracts, migrations, domain rules and generated clients synchronized. Do not silently invent unspecified endpoint payloads; specify and test them in the same change.
- Record exact toolchains and lockfiles. Do not claim that absent native libraries, the missing ZipporaService project or untested hardware work.
- Continue independent software work when hardware evidence is unavailable. Report the precise blocked capability and evidence needed to unblock it.
- Use task IDs from doc 11 in changes and verification notes. Mark a task done only with its specified evidence; a placeholder page or mocked success response is not completion.
