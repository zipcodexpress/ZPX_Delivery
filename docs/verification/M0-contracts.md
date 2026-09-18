# M0 contract validation increment

Purpose: record P0.1 contract evidence. Audience: developers/reviewers.
Status: local checks passed; GitHub CI result to be recorded after publication. Owner: unassigned. Last reviewed: 2026-09-18.

Related: issue 3 and draft PR 4. Canonical input: docs/handoff/contracts/openapi.json; no wire payload or endpoint changes in this increment.

Implemented external OpenAPI schema/reference validation with @apidevtools/swagger-parser 13.0.0, local-reference-only enforcement, unique operation IDs and required path parameter checks, deterministic definitions from openapi-typescript 7.13.0, stale-generated-file detection and a strict generated-type compilation gate. The existing CI `npm run check` invokes these checks.

TypeScript changed from provisional 7.0.2 to 5.9.3 to satisfy the generator's declared TypeScript 5 peer dependency. An initial incompatible install failed as expected; no dependency checks were bypassed. The lockfile records the exact compatible graph.

Commands run in Node 24.19.0 / Python 3.12.14 Linux editing workspace:

- `node scripts/contracts.mjs generate`: generated shared API definitions.
- `npm run check`: contract validation and consistency pass; generated types and web source compile; 9 Node tests pass (4 contract and 5 simulator).
- `npm run build`: both customer and operations production builds pass.
- `python3 -m unittest discover -s tests -p 'test_*.py'`: 3 setup tests pass.

Contract regression tests verify 54 operations, deterministic generation, missing response descriptions, unresolved schema references, external references, duplicate operation IDs and missing required path parameters. The source contract required no corrections to pass these checks.

Limitations: generated TypeScript is compile-time only. No runtime request transport, payload validator, authenticated business endpoint, native mobile build or physical serial protocol has been implemented by this change. Domain semantics and role/custody rules still need executable backend tests. Previous foundation Docker evidence is linked in M0-foundation.md and is not presented as a new run for this increment.
