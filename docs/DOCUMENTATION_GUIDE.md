# Documentation workflow

## One place for each fact

Keep editable specifications in Git with the code. Use Markdown for prose, OpenAPI for wire contracts, versioned migrations for implemented database changes and tests for executable acceptance evidence. Link to the authoritative source instead of pasting the same rule into several documents. Preserve original ZIP files as historical inputs.

The current starting baseline is Phase 1 revision 4. The older delivery package is reference material, including future routing ideas; it does not automatically expand the Phase 1 scope. Resolve conflicts with a documented decision before implementing the affected behavior.

## Write for the reader

Begin each document with its purpose, audience, status, owner and last-reviewed date. Use statuses such as Draft, Accepted or Superseded. Assign an owner when work begins rather than inventing one. Define SI, custody, shipment, package and other domain terms consistently with the domain specification.

Describe the user's trigger, expected behavior, failure behavior and acceptance criteria. Prefer concrete examples and short diagrams for cross-service flows. Use relative links so navigation works locally and on GitHub. Keep secrets and customer data out of examples.

## Connect requirements to implementation

Use the existing P0.1-style task IDs in issues, pull requests and verification notes. Link each implementation to its specification section and acceptance test IDs. Keep task progress in GitHub Issues/Projects once adopted; the backlog document remains the scope and acceptance reference rather than a second independently maintained progress board.

Use TODO, IN_PROGRESS, BLOCKED and DONE. A blocker entry states the missing evidence or decision, its owner and the next action. A mock or simulator result does not prove physical hardware behavior.

## Record decisions

Use `docs/decisions/NNNN-short-title.md` for significant architecture, security, domain or contract decisions. Include context, options, the chosen approach, consequences and affected documents. Keep accepted decisions as history; supersede them with a linked new decision when direction changes.

Do not reserve `0001-toolchains.md` for a different decision: the execution contract requires that filename for the first toolchain decision.

## Update documentation in the same pull request

When behavior changes, update the relevant specification, contract, migration notes and tests in the same pull request. Explain a documentation-not-needed choice rather than checking a box without review. Generated clients come from the canonical contract; do not hand-edit generated files.

Review open questions and documentation at each milestone. After implementation exists, separate descriptions of current behavior from planned behavior, and add setup instructions, deployment/rollback runbooks and release notes as those capabilities become real. Keep specification revisions separate from application release versions; Git history replaces filenames such as `final-v2-new`.

## Communicate progress consistently

Use this format for developer or AI handoffs:

```text
Task ID and status:
Behavior implemented:
Changed paths and specification links:
API/database changes:
Commands run and results:
Blockers or decisions needed:
Next dependency:
```

Store milestone evidence in `docs/verification/`. Include exact commands, tool versions, observed outcomes and limitations. Never copy a previous successful result into a new report without rerunning the check.

## GitHub collaboration

Use the provided issue and pull request templates to make requirements and review evidence consistent. GitHub automatically displays repository templates when contributors create matching items; see [GitHub's template documentation](https://docs.github.com/en/communities/using-templates-to-encourage-useful-issues-and-pull-requests/about-issue-and-pull-request-templates).

As contributors join, add ownership rules and appropriate branch protection. Once runnable checks exist, make relevant checks required before merging. Keep large recordings and generated binaries in suitable artifact storage; link to them from verification notes instead of repeatedly committing them.
