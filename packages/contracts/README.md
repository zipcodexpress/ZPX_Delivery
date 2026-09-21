# Shared API types

Purpose: compile-time request/response definitions for customer, driver and operations clients.
Audience: developers. Status: implemented type generation; runtime client and business API pending.
Owner: unassigned. Last reviewed: 2026-09-18.

The only editable wire contract is `docs/handoff/contracts/openapi.json`. Run `npm run contracts:generate` after an approved contract change. Commit the generated `generated/api.ts` with that change. Do not edit generated types directly.

`npm run contracts:check` validates the OpenAPI schema/references, operation IDs and required path parameters, regenerates in memory to reject stale output, and compiles the generated types. `npm run check` includes this gate, so CI fails when contract and generated code diverge. External references are disabled for reproducibility.

Clients can import `paths`, `components` and `operations` as TypeScript types from `generated/api.ts`. These definitions do not make endpoints callable or implement authentication, runtime payload validation, request transport or custody rules. The existing PHP service still exposes health endpoints only. Validation is a structural contract gate, not proof of domain correctness or security enforcement.
