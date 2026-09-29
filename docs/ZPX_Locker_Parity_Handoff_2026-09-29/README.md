# ZPX locker setup parity — local implementation handoff

Date: September 29, 2026. Requested by Richard Zhu.

## What this package is

A code-grounded gap review and implementation specification for the local ZPX_Delivery checkout. It is **not an applied patch, a completed feature, or proof of physical locker compatibility**. No repository, database, production endpoint or terminal was modified. No application tests or physical door tests were run in this review.

Desktop Commander listed the connected computer as offline. The local checkout, uncommitted changes and deployed versions could not be inspected. GitHub was reviewed read-only; local Git remains authoritative under AGENTS.md [S01].

The requested `zipcodexpress/zpx-patform` returned 404; `zpx-platform` also returned 404. The accessible `zpxadmin-php8x` was used as an explicitly identified legacy admin reference, alongside `zpxapi_tp8` and `terminal_452`. Its equivalence to the unavailable platform repository has NOT been established.

## Owner's required outcome

The operator must be able to use the existing configuration sequence:

    Box Model → Body Model → Body Box Layout → Locker Set / Bodies
              → Sequence and Controller Addresses → Location Binding

Use `terminal_452` as the Phase 1 terminal base. Add Send and the required Delivery interactions without replacing or changing the behavior of existing legacy workflows. This supersedes the reference-only Windows direction in ADR 0002; retain that decision's valid custody, ownership and single-controller safeguards [S08].

## Files

- `01_GAP_REVIEW.md`: verified current behavior, gaps and legacy-to-Delivery mapping.
- `02_IMPLEMENTATION_PLAN.md`: bounded local changes, configuration authority, API/terminal integration and draft superseding decision.
- `03_ACCEPTANCE_TESTS.md`: setup, API compatibility, coexistence and physical verification requirements.
- `CODEX_LOCAL_PROMPT.md`: copy-ready instructions for the local coding agent.
- `fixtures/locker_address_mapping.json`: synthetic configuration and expected six-door mapping, NOT a captured live API response.
- `SOURCES.json`: reviewed source paths, file blob hashes and coverage limits. References such as [S03] resolve here.

## Start here

Give the local coding agent `CODEX_LOCAL_PROMPT.md`. It must inspect current local code, AGENTS.md, docs/CURRENT_STATUS.md and the working tree before editing. Implement the setup-parity slices first; do not restart an Android terminal project or wait for a live locker to implement draft administration.

Keep this package in a new documentation folder such as `docs/locker-parity/2026-09-29/`. This is a suggested destination, not a claim that files were placed in the repository. Merge relevant handoff facts into the existing status file; do not replace it with this review.

## Delivery status

| Work | Status |
|---|---|
| Targeted GitHub source comparison | Completed, within SOURCES.json coverage |
| Owner requirements and local implementation plan | Documented |
| Synthetic fixture consistency | Validated as an artifact only |
| Local source modification / migrations / integration tests | Not performed |
| Existing Windows terminal build and regression | Not performed |
| Deployment / physical commissioning | Not performed or authorized by this package |
