# Local Codex task — restore legacy locker setup parity

Work in my actual local ZPX_Delivery checkout. Do not use a cloud checkout as the source of truth or make GitHub-connector commits. Preserve all current human/agent work.

## Product decision

Use the same locker setup workflow as the existing zpxadmin:

Box Model → Body Model → Body Box Layout (row, column, door address) → Locker Set / Cabinet Bodies (model, body name, sequence, controller address) → bind the assembled cabinet to a location.

Use terminal_452 as the actual Phase 1 terminal base. Add Send and the required Delivery interactions without replacing or changing the behavior of working legacy functions. This supersedes ADR 0002's reference-only Windows/new Android direction. Keep custody, authorization, single-controller, safe retry and coexistence safeguards.

## Startup

Read AGENTS.md and docs/CURRENT_STATUS.md; run git status, determine the current branch and read recent commits. Inspect local code before relying on the accompanying GitHub review. Keep CURRENT_STATUS.md at or below 200 lines and checkpoint each meaningful slice or blocker before context exhaustion.

Read 01_GAP_REVIEW.md and the relevant parts of 02_IMPLEMENTATION_PLAN.md from this package. SOURCES.json identifies inspected remote file blobs and limits; it is not evidence of the current local revision.

Locate my actual legacy platform/admin/API/terminal checkouts. The supplied zpx-patform GitHub link and alternate zpx-platform returned 404 during review; do not invent its composition. Inspect .gitmodules and pinned component revisions when present. Accessible zpxadmin-php8x is a reference, not proof that it equals the missing platform checkout.

## First implementation slice

Start with locker model and Body Box setup parity, not unrelated admin backlog or a new terminal application:

- Inspect apps/admin/src/LockerModels.php, SiteInventory.php, PageController.php, routes.php, locker_models.html, relevant migrations and existing tests.
- Reuse current versioned box/body models, layout slots, internal automatic box-module grouping, compartments, controller_boards, legacy links and ownership entities.
- Add missing size-category/allocability metadata and explicit Body Box door address, with validation and editor/preview changes. Preserve raw legacy price metadata without changing shipping tariffs.
- Keep display row/column/sequence separate from controller and door addresses. One slot creates one installed box, even when several slots use the same box model.
- Preserve model version immutability, authorization, CSRF and audit. Use a new additive PostgreSQL migration selected from the actual current migration sequence; do not edit applied checksummed migrations or run the provided MySQL DROP statements.
- Resolve/retain unit and direction provenance; never invent a conversion or unknown hardware capacity. Update the superseding terminal decision and affected status/docs as needed.
- Run targeted local database/browser/contract checks and report actual results. Do not claim tests passed unless executed.

## Next bounded slice

Implement unbound cabinet configuration assembly followed by final location binding. Prefer a small configuration-draft facility over a broad rewrite of runtime locker/location constraints. Reuse existing empty draft lockers when binding, and existing instantiation/linking logic. Binding must be transactional, idempotent, scoped, collision-checked and leave equipment inactive/frozen until commissioned.

Do not replace existing IDs, reset operational occupancy/blocked flags, automatically adopt a live cabinet, or change installed addresses from a model edit/display reorder. Occupied/held/unknown/pending-command equipment cannot be relocated or readdressed through ordinary setup forms.

## Terminal/API boundary

Preserve ExpressBoxService's old host/service routing and working /cabinet operations. Later add a separate Delivery client and feature-gated Send flow in terminal_452, using current Delivery device/command/session contracts and the existing controller transport. Complete the actual Zippora/Ziplocker override inventory and sanitized C# contract tests before claiming compatible serialization.

Do not run two allocators against one door. The inspected legacy allocator does not consult Delivery ownership manifests. Do not misuse blocked or a shared model's is_allocable as an assumed per-door partition. Shared-cabinet release needs an enforced boundary on both paths; otherwise keep Send disabled there or use dedicated Delivery equipment. Never open a real locker during this source/setup task.

Use 03_ACCEPTANCE_TESTS.md and the synthetic six-door fixture. Complete one reviewable slice at a time, checkpoint the exact continuation point, and avoid repo-wide AI review loops or an unrelated rewrite. Deliver code, tests, affected documentation and an honest list of remaining gates—not another plan in place of the first implementable slice.
