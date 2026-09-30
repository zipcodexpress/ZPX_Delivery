# Phase 1 terminal_452 and locker setup

Status: accepted owner decision, 2026-09-29. Supersedes the terminal-platform choice in [0002](0002-new-terminal-platform.md); its custody, authorization, single-controller, and safe-retry requirements remain in force.

Phase 1 uses `terminal_452` as the terminal base. Existing legacy screens, cabinet API contracts, service-type behavior, and serial controller ownership must keep working. Android is a future option.

Locker administration follows Box Model → Body Model → Body Box Layout → cabinet assembly with explicit body sequence and controller address → final location binding. Extend the existing ThinkPHP/PostgreSQL admin and hardware inventory. A draft is configuration only; binding does not activate a locker or shipping.

No production schema reset, automatic equipment takeover, independent competing allocation of one door, or live door test is authorized. Shared-cabinet allocation stays disabled until both systems enforce and acknowledge the ownership boundary.
