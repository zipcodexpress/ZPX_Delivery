# ZPX Delivery

Project documentation and implementation starting point for the ZPX locker-to-locker delivery application.

**Status:** specification handoff; application implementation has not started. Simulator-based development is permitted by the handoff; live hardware rollout has separate commissioning gates.

## Start here

1. Read the [current Phase 1 handoff](ZPX_Phase1_Codex_Package/README.md), revision 4 dated 2026-09-17.
2. Follow its [development entry point](ZPX_Phase1_Codex_Package/CODEX_START_HERE.md) and [implementation execution contract](ZPX_Phase1_Codex_Package/docs/13_IMPLEMENTATION_EXECUTION.md).
3. Use the [backlog and acceptance criteria](ZPX_Phase1_Codex_Package/docs/11_BACKLOG_AND_ACCEPTANCE.md) and [open items](ZPX_Phase1_Codex_Package/docs/12_READINESS_AND_OPEN_ITEMS.md).
4. Follow the [documentation workflow](docs/DOCUMENTATION_GUIDE.md) when changing requirements or implementation.

## Source material

| Location | Purpose |
| --- | --- |
| `ZPX_Phase1_Codex_Package/` | Current Phase 1 baseline, domain rules, API contract, draft SQL and verification tooling |
| `ZPX_Delivery_Codex_Implementation_Package/` | Earlier design and broader routing reference; reconcile differences against the current baseline before implementation |
| Root ZIP files | Original handoff archives, retained unchanged for provenance; do not edit or regenerate for routine updates |
| `docs/decisions/` | Project decisions and reasons |
| `docs/verification/` | Commands actually run, results and limitations |

Original package paths are preserved for this initial import. Before application scaffolding, perform the handoff's requested move to `docs/handoff/` as a dedicated change, updating all links and validation paths. Move files rather than creating competing editable copies.

The canonical API input for now is [contracts/openapi.json](ZPX_Phase1_Codex_Package/contracts/openapi.json). The earlier API outline is reference material. Draft SQL must be reviewed and tested against a disposable delivery database before adoption as application migrations.

## Package validation

With Python 3 installed:

```powershell
python -X utf8 ZPX_Phase1_Codex_Package/tests/validate_handoff.py
```

This checks handoff consistency, not application correctness, executable database migrations or hardware readiness.

## Development updates

Use a feature branch and pull request for each reviewable task. Include the backlog task ID, update affected documentation alongside code, and record actual verification results. See [CONTRIBUTING.md](CONTRIBUTING.md).
