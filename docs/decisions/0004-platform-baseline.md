# 0004 — proceed with ThinkPHP and React

Purpose: close the open framework/reuse decision. Audience: developers.
Status: Accepted. Owner: Richard. Date: 2026-09-19.

Richard explicitly selected continuing with PostgreSQL, ThinkPHP and React instead
of evaluating Fleetbase first. Build the separate delivery application on that
baseline. Fleetbase evaluation is no longer a prerequisite. PostgreSQL migrations
remain canonical; do not replace their checksum ledger with a second migration system.

Use ThinkPHP 8 for HTTP application lifecycle and routing while retaining explicit
PDO transactions and the existing database privileges. React/TypeScript remains the
web stack. The Android terminal decision and hardware commissioning gates are unchanged.

The framework package and transitive PHP dependencies are recorded in the API
Composer lockfile, resolved for the PHP 8.3 container. Follow the upstream
[ThinkPHP 8 package structure](https://github.com/top-think/think/blob/8.x/composer.json).
This decision does not approve public rollout, real payment capture or physical commands.
