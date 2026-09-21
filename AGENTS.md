# AGENTS.md

## 1. Purpose

This repository may be developed by:

- OpenAI Codex
- Qwen Code
- Human developers using VS Code
- Other approved coding agents

The repository must remain understandable and safely continuable without access to any previous AI conversation.

---

## 2. Source of Truth

The local Git repository is the authoritative source of truth.

Use these sources in this order:

1. Current source code and tests
2. `AGENTS.md`
3. `docs/CURRENT_STATUS.md`
4. Git status and Git history
5. Permanent project documentation

Do not treat AI chat history, Codex Cloud state, Qwen conversation state, or unpublished remote workspace files as authoritative.

Git history is the permanent historical record.

`docs/CURRENT_STATUS.md` is the concise current handoff state.

---

## 3. Local-First Development

Normal development must occur in the local Git repository.

Preferred workflow:

`VS Code → Local Files → Codex/Qwen → Local Tests → Git → GitHub → Staging/Production`

GitHub is primarily for:

- remote backup
- pull requests
- CI
- review
- collaboration

Cloud servers are primarily for:

- staging
- production
- infrastructure-specific testing

Do not use an AI cloud workspace as the primary source of development state.

---

## 4. Mandatory Startup Procedure

Before making code changes, every AI agent must:

1. Read `AGENTS.md`.
2. Read `docs/CURRENT_STATUS.md` if present.
3. Run `git status`.
4. Run `git log --oneline -10`.
5. Identify the current branch.
6. Inspect relevant existing code and tests.
7. Check for uncommitted changes.
8. Preserve existing human or AI work unless the task requires changing it.

Do not start implementation based only on the user prompt.

---

## 5. Multi-Agent Development

Codex and Qwen may alternate development responsibility.

The next agent should assume it has no access to the previous agent's conversation.

Important information must therefore exist in:

- code
- tests
- Git commits
- `AGENTS.md`
- `docs/CURRENT_STATUS.md`
- permanent documentation when appropriate

Only one AI agent should actively modify the same feature/branch at a time.

Do not let Codex and Qwen simultaneously edit the same files or feature.

Separate branches may be used for independent or experimental work.

---

## 6. Human Changes Have Priority

Preserve manual changes made by the human developer.

If `git status` shows existing modifications:

1. Inspect them.
2. Understand them.
3. Do not silently revert or overwrite them.
4. Avoid unrelated formatting or cleanup.

If ownership is unclear, preserve the changes.

---

## 7. Git Rules

Use local Git for normal source-control operations.

Before editing:

`git status`

Before committing:

`git diff`

and when needed:

`git diff --staged`

Before pushing, verify:

- correct branch
- intended files only
- relevant tests completed
- no secrets or credentials
- no generated junk

Use clear commit messages, for example:

`feat(driver): add pickup scan endpoint`

`fix(delivery): validate locker assignment`

`test(driver): add dropoff workflow coverage`

Commit stable work before major AI-agent handoffs when practical.

Do not create unnecessary commits for every tiny edit.

---

## 8. Branch Rules

Use feature/fix branches for meaningful work.

Examples:

`feature/driver-delivery`

`feature/marketplace-regal`

`fix/package-state-transition`

For risky AI experiments, separate branches may be used:

`qwen/dropoff-implementation`

`codex/delivery-refactor`

Do not create branches unnecessarily for trivial work.

---

## 9. Architecture Rules

Inspect existing architecture before creating new architecture.

Reuse established:

- services
- DAOs/repositories
- models
- controllers
- validators
- authentication/authorization
- logging
- utilities
- API patterns
- test helpers

Prefer extending existing functionality over duplicating or rewriting it.

Do not perform broad architectural rewrites unless required by the task.

Keep changes as small and focused as safely possible.

---

## 10. Compatibility Rules

Avoid unnecessary breaking changes.

Before modifying:

- APIs
- database schemas
- enums
- workflow states
- authentication
- mobile contracts
- legacy integrations
- external interfaces

inspect current consumers and relevant tests.

If a breaking change is necessary:

1. Document it.
2. Update tests.
3. Update migration/compatibility logic.
4. Record current impact in `CURRENT_STATUS.md`.
5. Put permanent decisions in architecture documentation.

---

## 11. Database Safety

Before schema changes:

- inspect existing migrations
- inspect model relationships
- inspect compatibility requirements
- understand production impact

Prefer migrations over undocumented direct schema changes.

Never casually delete production data or remove fields.

Never embed production credentials in source code.

---

## 12. Security

Never commit:

- passwords
- API keys
- access tokens
- SSH/private keys
- production DB credentials
- `.env` secrets
- cloud credentials
- private user-data exports

Do not weaken authentication or authorization merely to make tests pass.

---

## 13. Scope Discipline

Stay within the requested task.

Avoid unrelated:

- refactoring
- renaming
- formatting
- dependency upgrades
- architecture changes

unless necessary for correctness or safety.

---

## 14. Testing

Every meaningful change should receive appropriate validation.

Use the strongest practical targeted validation:

- unit tests
- integration tests
- API tests
- linting
- static analysis
- type checking
- builds
- Docker tests
- manual verification when necessary

Prefer targeted tests over the entire test suite unless the change has broad impact.

Never claim a test passed unless it was actually run.

If a relevant test fails, document:

- failing test
- failure/error
- likely cause if known
- recommended next action

Do not hide relevant failures.

---

## 15. Taking Over Another Agent's Work

Before continuing another agent's implementation:

1. Read `CURRENT_STATUS.md`.
2. Review `git status`.
3. Review recent commits.
4. Inspect relevant diff/code.
5. Run targeted tests if needed.
6. Check architecture, security, compatibility, and regressions.

Do not assume another AI's code is correct.

Do not rewrite it merely because another AI created it.

Evaluate it based on requirements, code quality, and tests.

---

## 16. Token and Context Efficiency

Minimize unnecessary context consumption.

Start with:

- `AGENTS.md`
- `docs/CURRENT_STATUS.md`
- `git status`
- recent Git history
- files directly relevant to the task

Search narrowly before expanding scope.

Prefer:

- targeted `rg`/search
- specific source files
- specific tests
- `git diff`

over repository-wide scanning.

Do not unnecessarily load:

- `node_modules`
- `vendor`
- build output
- caches
- large logs
- DB dumps
- binaries
- generated files
- unrelated historical commits

Do not reread files already inspected in the current session unless they changed or the information is required again.

Once enough context exists to safely implement the task, stop gathering context and begin implementation.

Do not generate large planning documents for small tasks.

Do not repeatedly restate architecture already documented.

---

## 17. Avoid Wasteful Review Loops

Do not automatically run repository-wide AI reviews after every edit.

For small changes:

- inspect the diff
- run targeted tests

For major changes:

- perform broader review
- run appropriate integration tests
- verify architecture

Normally, one implementation pass plus targeted validation and one review pass is sufficient unless failures require another iteration.

---

## 18. CURRENT_STATUS.md

`docs/CURRENT_STATUS.md` is the shared operational handoff file.

It must describe the current state, not maintain a chronological diary.

It must remain at or below **200 lines**.

Normally aim for roughly **50–100 lines**.

Keep:

- current objective
- completed recent work
- in-progress work
- blockers
- important changed files
- test status
- current decisions
- exact continuation point
- next actions
- branch/commit information

Remove obsolete information already preserved in Git history.

Move permanent architecture rules to permanent documentation.

---

## 19. Status Checkpoints

Do not wait until the end of a session to update `CURRENT_STATUS.md`.

Update it after a meaningful milestone and whenever:

- a significant subtask is completed
- an important blocker is discovered
- an architecture/database decision is made
- stable work is ready for handoff
- switching between Codex and Qwen
- context/quota appears to be running low
- the session may end soon
- before starting a large context-expensive operation

If token, context, quota, or session pressure appears, updating `CURRENT_STATUS.md` takes priority over starting another task.

Do not checkpoint after every tiny edit.

Use logical development milestones.

---

## 20. Checkpoint Content

A checkpoint should concisely record:

- current objective
- completed work
- work in progress
- exact continuation point
- important files changed
- tests and results
- blockers
- important current decisions
- current branch
- latest relevant commit
- next recommended action
- current agent

If work is incomplete, state that clearly.

Never allow important development state to exist only in AI conversation context.

---

## 21. Before Large Operations

Before potentially expensive operations such as:

- broad repository review
- reading many files
- large refactors
- verbose full test suites
- reviewing extensive history
- generating code across many modules

ensure `docs/CURRENT_STATUS.md` reasonably reflects the current state first.

This protects handoff state if the session ends unexpectedly.

---

## 22. End-of-Session / Handoff Procedure

Before ending a meaningful session or handing work to another agent:

1. Save code locally.
2. Run relevant targeted tests.
3. Review `git diff`.
4. Run `git status`.
5. Update `docs/CURRENT_STATUS.md`.
6. Ensure it remains ≤200 lines.
7. Commit stable work when appropriate.
8. Record unresolved failures/blockers.
9. Record the exact next step.
10. Verify no secrets were committed.

A new developer or AI agent should be able to continue using only:

- `AGENTS.md`
- `docs/CURRENT_STATUS.md`
- Git history
- source code
- tests

If important knowledge exists only in AI chat history, the handoff is incomplete.