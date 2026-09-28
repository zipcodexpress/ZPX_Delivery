# Admin development handoff

Purpose: implementation specification for ZPX network administration. Audience: Codex, Qwen and human developers.
Status: ready for incremental software development; application features described here are not yet implemented unless explicitly marked existing.
Date: 2026-09-28. Product owner: Richard; implementation owner: unassigned.
Baseline: delivery `338046c`, apartment reference `119d080`.

## Read in this order

1. Repository `AGENTS.md` and [current status](../CURRENT_STATUS.md).
2. [Global console and full menu catalog](GLOBAL_CONSOLE.md): system-wide visibility, all menu items, linked detail workspaces and global requirements G01–G06. Then [functional specification](FUNCTIONS.md) for underlying actions and lifecycle rules.
3. [ThinkPHP presentation](THINKPHP_PRESENTATION.md): controllers, templates, authentication and coexistence with current screens.
4. [Data and API specification](DATA_AND_API.md): extensions, endpoint plan, first-slice payloads and migration requirements.
5. [Implementation backlog](BACKLOG.md): bounded tasks, dependencies and acceptance evidence.
6. Read [reuse analysis](../ADMIN_NETWORK_DESIGN.md) only for design rationale; inspect the source relevant to the selected task.

## Design decisions for this handoff

- ThinkPHP 8 renders the new admin pages under `/admin` using server templates in the existing delivery backend. Richard explicitly confirmed ThinkPHP-rendered pages like `zpxadmin-tp8`. Existing React customer/driver/hub workflows remain accessible; no new React admin is required.
- Existing ThinkPHP API, PostgreSQL 17, identity sessions, CSRF, transactions, outbox and append-only evidence are retained.
- One ZPX customer/network identity. Partners are business entities within the network, not replacements for `organizations`.
- Global ZPX administrators receive explicitly granted network-wide read views across people, contacts, locations, hardware, shipments/tracking and money. Global visibility never silently grants financial approval, credential access or physical custody override. Restricted partner/local staff views use the same console with scoped data.
- Site, operational location, physical locker, equipment owner, operator and revenue beneficiary are separate concepts.
- One locker per existing operational location remains the initial rule. A site can contain multiple locations; a locker can contain multiple cabinet modules.
- Partner capabilities are scoped server-side. New partner roles do not receive existing network-wide ADMIN grants.
- Initial partner roles: LOCKER_OWNER, SITE_HOST, LOCKER_OPERATOR, HUB_OPERATOR and CARRIER. A partner may hold several. ZPX can be represented as an internal partner for asset relationships.
- Initial partner users may view scoped resources and submit change requests. ZPX staff publish hardware configurations, activate sites and approve commercial terms.
- Admin provisioning development may proceed independently using synthetic data. This handoff defines that workstream; it does not make P5 physical completion unnecessary or activate new commercial terms.
- Preserve decision 0008's equipment custody model: no per-scan scanner tracking and no foreign key added to historical printer references.
- This handoff supersedes only decision 0008's proposed React admin presentation location. Keep its asset ledger and hub-scoping rules. The [ThinkPHP presentation specification](THINKPHP_PRESENTATION.md) defines the new page boundary and required session-cookie migration.
- New admin concepts are specified here; the existing custody flow and its physical evidence requirements remain authoritative.

## Existing versus planned

Existing: admin shipment inspection, pending driver approvals, pickup route assignment and wholly uncollected pickup recovery; hub receiving/dispatch and driver workflows have their own surfaces. Check current source before claiming a screen or API is complete.

Planned: general customer/site/locker administration, partner grants, carrier agreements, equipment administration and partner finance. Merely having a schema table or OpenAPI operation does not mean a working feature exists.

The single executable API source remains `docs/handoff/contracts/openapi.json`; `scripts/contracts.mjs` generates `packages/contracts/generated/api.ts`. This handoff is a design input. Add concrete endpoint schemas to that source in the same PR as each implementation; do not create a competing OpenAPI file or hand-edit generated clients.

## Start Codex with this prompt

> Implement ADM-01 from docs/admin/BACKLOG.md, followed by the next dependency-ready task only when the requested work scope permits. Follow AGENTS.md and read docs/CURRENT_STATUS.md first. Preserve existing work. Read docs/admin/README.md and the selected task's references rather than loading the whole repository. Build the admin using ThinkPHP-rendered pages as specified in docs/admin/THINKPHP_PRESENTATION.md, sharing the existing delivery services, PostgreSQL database and identity. Do not copy the apartment database, credentials, resident balances or auth implementation. For each slice, update canonical OpenAPI and generated types when applicable, add additive migrations and meaningful scoped tests, run the relevant checks, and update CURRENT_STATUS.md. Report task IDs, actual results, limitations and the next dependency. No real locker commands, production data changes or live payments are part of this software task.

## Business decisions that do not block the first slices

Live agreements require named beneficiaries, actual rates, earning milestones, refund/loss allocation and payout procedures. Sites require confirmed eligibility/access and operating calendars. Hardware activation requires commissioned mappings and device evidence. Store unknown values as missing configuration and show the reason an action is unavailable; never fabricate operational defaults.

No new application code, executable migrations or wire-contract changes are included in this documentation handoff.
