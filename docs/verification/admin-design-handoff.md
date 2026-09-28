# Admin design handoff verification

Date: 2026-09-27. Agent: Codex. Application baseline: `338046c`; apartment reference: `119d080`.
Scope: documentation only; no application, database or production changes.

## Evidence inspected

- Current AGENTS/status/history, admin reuse analysis and existing domain decisions.
- Delivery route registration, identity cookie creation and role checks, API reply/controller handling, Composer manifest and contract generation source.
- Current canonical LocationCreate/StaffGrant schemas and runtime/admin route differences.
- Apartment page controller, rendering structure and allowlisted filtering patterns, plus source evidence preserved in the reuse analysis.

## Checks performed

- Python document check: five admin documents have valid local links and balanced fenced blocks; twelve ADM task IDs and twenty-four acceptance cases are present; CURRENT_STATUS remains under 200 lines. PASS.
- `git diff --check`: PASS before staging.
- Manual consistency review: presentation choice updated to confirmed ThinkPHP templates; decision 0008 amended narrowly; earlier analysis marked superseded for presentation; existing canonical OpenAPI path preserved.

## Important implementation findings

- Current session cookie Path is `/api/delivery/v1`; `/admin` needs the documented migration and dual-path cookie clearing tests.
- Delivery Composer lacks the view package; ADM-02 must add/lock a compatible ThinkPHP view renderer.
- Some admin contracts and runtime routes differ; ADM-01 requires reconciliation rather than assuming contract completeness.

No runtime or hardware tests were run: documents describe proposed behavior, not a finished admin application. Live agreements and physical commissioning remain external activation gates. Start implementation at ADM-01 using `docs/admin/README.md`.
