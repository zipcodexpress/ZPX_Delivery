# Paste into the new delivery project's Codex session

Implement the ZPX Phase 1 Delivery Network using this handoff. Start work, not another planning-only document. Read AGENTS.md, CODEX_START_HERE.md, docs/13_IMPLEMENTATION_EXECUTION.md and the referenced contracts before editing application code.

Create the separate delivery project in the current authorized workspace. Treat the four existing ZPX repositories as reference sources; preserve apartment operations and the existing hardware protocols. Do not connect to production, actuate real doors, bill customers, publish apps or deploy legacy patches as part of this initial task.

Complete milestone M0 from docs/13_IMPLEMENTATION_EXECUTION.md: establish exact runtime versions and lockfiles, working API/web/mobile skeletons, a terminal simulator interface and CI, then validate the supplied OpenAPI and execute both proposed SQL migrations against a disposable MySQL database. Fix verified contract/schema defects with matching documentation updates. Record unavailable build dependencies accurately. A missing Windows/native terminal dependency must not block backend, web, mobile or simulator work.

Once M0 passes, continue M1 through the first simulated origin-deposit → assigned-driver pickup → independent hub-receipt slice. Each transfer needs the required actor and physical evidence. Keep stable shipping identifiers and one primary label per parcel. Implement transactional idempotency, per-package scans, ownership checks and recoverable device journals from the beginning. Use fake payment, notification and map adapters until production providers are configured.

Use docs/11_BACKLOG_AND_ACCEPTANCE.md for the remaining tasks and tests. Do not mark the ten-parcel/two-stop flow complete until all ten distinct scans and both six/four destination groups work. Report implemented paths, actual commands and results, remaining blockers and the next task. Never call a static specification check a successful application or hardware test.
