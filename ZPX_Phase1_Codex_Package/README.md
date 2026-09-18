# ZPX Delivery Network — Production Development Handoff

Revision 4 • 2026-09-17 • Austin hub-and-spoke pilot

This revision replaces the earlier Phase 1 handoff. It is grounded in a read-only review of four private repositories at the revisions in `evidence/repositories.json`. The existing apartment business platform remains separate. Existing apartment lockers may participate through a controlled compatibility layer; public lockers use the same hardware-facing terminal capabilities.

**Readiness: ready to begin implementation with simulators and separate development databases. Not approved for live locker rollout.** Actual terminal build dependencies, installed hardware variants, physical evidence behavior and deployment topology remain explicit commissioning gates. No application was deployed, no repository was changed, and no physical door was commanded during this review.

## Start here

Use [CODEX_KICKOFF_PROMPT.md](CODEX_KICKOFF_PROMPT.md) to start a development session. [AGENTS.md](AGENTS.md) supplies project instructions. [Implementation execution contract](docs/13_IMPLEMENTATION_EXECUTION.md) defines the first milestones, completion evidence and contract-extension tasks. Revision 4 adds these execution materials and clarifies scan concurrency; it does not claim an additional source-code audit or application implementation.

1. `CODEX_START_HERE.md` — scope, target source layout and ordered tasks.
2. `docs/01_REPOSITORY_REVIEW.md` — verified findings and exact source references.
3. `docs/02_ARCHITECTURE_AND_COEXISTENCE.md` — separate platforms and safe shared hardware.
4. `docs/03_PRODUCT_AND_IDENTITIES.md` — sending, receiving, accounts, SI and labels.
5. `docs/04_TERMINAL.md` — hardware adapter, kiosk screens and command journal.
6. `docs/05_MOBILE_AND_WEBSITE.md` — native mobile, driver routes, customer website.
7. `docs/06_HUB_AND_ADMIN.md` — receiving, sorting, routing and operations.
8. `docs/07_BACKEND_AND_STATE_MACHINES.md` — module boundaries and transaction rules.
9. `docs/08_DATABASE.md` — new-platform data dictionary and migration ownership.
10. `docs/09_API_AND_DEVICE_PROTOCOL.md` and `contracts/openapi.json` — wire contracts.
11. `docs/10_SECURITY_AND_DEPLOYMENT.md` — isolation, builds, rollout and recovery.
12. `docs/11_BACKLOG_AND_ACCEPTANCE.md` — implementable tasks and test gates.
13. `docs/12_READINESS_AND_OPEN_ITEMS.md` — remaining evidence needed before live use.

`sql/001_delivery_core.sql` and `sql/002_application_and_coexistence.sql` are sequential proposed migrations for a NEW empty delivery database, never the apartment database. Execute them first in M0 to find schema defects; add transactional feature tests in M1/PR 1. `sql/legacy_bridge_design.md` describes a separately reviewed legacy change, not an executable production migration.

`fixtures/pilot.json` contains synthetic scenario data, not real addresses or live configuration. `tests/validate_handoff.py` checks package consistency only. The earlier Python state-machine demo is intentionally not carried forward as production code.

The reviewed native driver/customer app does not exist among the supplied repositories; it is a new deliverable. UI/framework reuse does not imply shared authentication, sessions, payment balances or databases with apartment operations.
