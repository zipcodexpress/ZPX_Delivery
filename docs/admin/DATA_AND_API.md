# Admin data and service contracts

Status: implementation design. References: [functions](FUNCTIONS.md), [backlog](BACKLOG.md).
ThinkPHP 8 remains the HTTP and application framework. Use existing PostgreSQL/PDO transaction infrastructure and existing identity services; do not import legacy MySQL queries or a second authentication stack.

## 1. Integration rules

Executable API source: `docs/handoff/contracts/openapi.json`; generated types: `packages/contracts/generated/api.ts`. The first path is intentional: `scripts/contracts.mjs` still reads it. Do not relocate the contract in an admin feature PR.

Current runtime routes include driver approval/rejection, driver pay recording, pickup route assignment and pickup recovery. The canonical contract currently includes some unimplemented admin creation operations and does not enumerate every implemented driver-management route. ADM-01 must reconcile runtime/contract differences with behavior-preserving schemas and tests before adding new callers. Do not interpret presence in either file as proof of full functionality.

Preserve existing `POST /admin/locations`, `/admin/role-grants`, `/admin/drivers`, `/admin/vehicles` and `/admin/pricing-policies` definitions where compatible. Add optional fields or new explicit operations instead of changing existing required meanings. Existing `LocationCreate` requires code, name, site_mode, address and printer_available; `StaffGrant` requires user_id, role_code and reason.

## 2. Shared wire conventions for new operations

- Base `/api/delivery/v1`. IDs are positive decimal strings, never JavaScript numbers. Versions are nonnegative integers; amounts are integer minor units with currency.
- Reads use GET; create and explicit transition operations use POST. Proposed updates use `POST /<resource>/{id}/update` to fit current clients; no state changes on GET. All routes enforce method explicitly even if registered with `Route::any`.
- Browser requests use existing HttpOnly session and CSRF checks. Enforce Origin and current role/scope on every request. No independent admin password/session database.
- Create/update schemas reject unknown fields. Server assigns network, actor, status, audit IDs, versions and creation time. Editable fields are explicitly whitelisted.
- Mutation headers: `Idempotency-Key` (existing 16–100 character bounds), `X-CSRF-Token` for browser, and `If-Match: "<version>"` on updates/transitions. Resource changes increment version atomically. Creates do not require If-Match.
- Authorize every retry, then look up the completed idempotency result before rejecting a stale version caused by that same successful request. Reusing a key with different payload is 409. Key scope includes network, actor and operation/resource; preserve existing implementation conventions.
- New resource response: `{ "item": { ... }, "version": 0 }`; create returns 201 plus Location. Read/update/transition returns 200. Deferred work returns 202 with job ID. Keep existing endpoint envelopes unchanged.
- Lists accept `limit` (1–100, default 25), opaque `cursor`, allowlisted `sort`, and documented filters. Return `{ "items": [], "next_cursor": null }`. Cursor binds sort/filter/scope and stable tie-breaker; reject malformed cursor. Counts are separate authorized queries, not unbounded totals on every page.
- Error envelope extends the canonical Error only when needed: code, message, correlation_id, retryable, optional current_version/recovery_action and proposed field_errors. 401 missing/expired session; 403 insufficient capability; 404 unknown or out-of-scope object; 409 invalid transition/conflicting operation; 412 stale version; 422 invalid fields; 428 missing required If-Match; 429 rate limit. Response bodies do not leak hidden object existence.
- Reason: trimmed 10–500 characters on grant, status, financial, hardware and relationship changes. Public identifiers/codes: 3–40 ASCII letters/digits/underscore/hyphen, normalized uppercase; display names: 1–160 characters; descriptive notes: at most 2,000. Do not log raw contact secrets or unrestricted request bodies.
- Audit write, resource mutation and outbox insertion share a transaction. No network provider call while holding business-row locks. Worker retries use stable operation identity.

## 3. First implementation slice: concrete field contracts

### GET /admin/access

Returns `{ "capabilities": ["sites.read"], "scopes": [{"kind":"NETWORK","id":"1"}], "policy_version": 1 }`. This is a navigation hint only; authorization is reevaluated on every service call. Partner roles are never translated into network ADMIN. Policy version changes when grants/relationships change; cached hints expire on signout/revocation/403.

### POST /admin/sites

Required: code, name, site_type, address, timezone. Optional: host_partner_id, contact_name, contact_email, contact_phone, notes. Example:

```json
{
  "code": "AUS-MALL-01",
  "name": "Synthetic Austin Mall",
  "site_type": "SHOPPING_MALL",
  "address": {"line1":"Synthetic test address","city":"Austin","state":"TX","postal_code":"00000","country_code":"US"},
  "timezone": "America/Chicago"
}
```

Address is an admin-specific schema; reconcile it with existing Address rather than silently changing that schema. Required address keys shown above, optional line2. Validate timezone against installed IANA data. US-only rollout uses two-letter state code; reject invalid codes. Code unique per network. Referenced host must be same-network and have SITE_HOST role. Missing host allowed only while DRAFT. Create result contains server-assigned id, status DRAFT and version 0. Do not fabricate coordinates or activate child locations.

### GET /admin/sites and GET /admin/sites/{id}

List filters: q (code/name, at most 160 chars), site_type, status, state, city, host_partner_id. Sort name/code/created_at, with id tie-breaker. List projection: id, code, name, site_type, city, state, timezone, status, host_partner_id, location_count, version. Detail adds address, authorized contact projection, notes and relationship links. Do not expand all locations/activity in the detail response; use paginated child reads.

### POST /admin/sites/{id}/update

Optional editable fields: name, site_type, address, timezone, contact_name/email/phone, notes; at least one field required, plus reason. Null clears only optional contact/notes fields. Code, status and host relationship are not editable through this endpoint. DRAFT sites may update type/timezone; active sites require a dedicated reviewed calendar/access impact flow before such changes. Restrict the initial endpoint to DRAFT for address/type/timezone changes. Every accepted update checks If-Match and increments version once.

### POST /admin/sites/{id}/transitions

Body `{ "action": "ACTIVATE|SUSPEND|REACTIVATE|ARCHIVE", "reason": "..." }`. Enforce F04 lifecycle and active host/configuration requirements. Suspend stops new commitments through service eligibility checks, not only a UI badge. Archive requires inactive child locations, no current installation and no unresolved work. Return actual state; guards fail with 409 and safe blocker summary.

### POST /admin/locations

Keep original required fields. Add optional site_id, timezone, latitude, longitude and capabilities in canonical OpenAPI. New admin wizard supplies site_id. Legacy-compatible caller omitting site_id gets a dedicated DRAFT site backfilled from supplied location data in the same transaction; response remains compatible. Do not change existing IDs or autoactivate hardware. Location timezone/entrance coordinates remain canonical for routing during initial site adoption.

### POST /admin/role-grants

Retain existing network/location grant API for authorized network staff. Partner grant creation is a separate `POST /admin/partner-grants` contract with user_id, partner_id, role_profile, allowed site/location IDs, expires_at and reason. Reject arbitrary network_id and capabilities not delegable by the issuer. Do not add a partner ID to StaffGrant and assume existing `requireRole` understands it.

## 4. API work map

These paths are planned, except the explicitly reused operations. Each task must add full request/response schemas, permission and error examples to canonical OpenAPI before implementing its page. The map is not a claim of implemented endpoints.

| Requirement | Reads | Creates/updates/transitions |
|---|---|---|
| F01 | `/admin/access`, `/admin/overview` | None |
| F02 | `/admin/customers`, `/{id}`, `/{id}/activity` | `/admin/customers/{id}/restrictions` and `/restrictions/{restriction_id}/revoke` |
| F03 | `/admin/drivers`, `/{id}`, existing `/admin/drivers/pending`; `/admin/vehicles` | Existing approve/reject; new driver transitions/affiliations; preserve specified driver/vehicle create; vehicle update |
| F04 | `/admin/sites`, `/{id}`, `/{id}/locations`; `/admin/locations`, `/{id}` | Site create/update/transitions; location create/update/access-policy/calendar; reuse pickup route assign |
| F05 | `/admin/locker-models`, `/{id}/versions`; `/admin/lockers`, `/{id}`, layout/devices/checks/history child reads | Model/version create/publish; locker/module provision; configuration proposal; commissioning/transitions; ownership-transfers/relocations |
| F06 | `/admin/partners`, `/{id}`, memberships/relationships/requests | Partner create/update/transitions; partner-grants/revoke; relationship periods; change-request submit/resolve |
| F07 | `/admin/carriers`, `/{id}/drivers`, `/{id}/site-permissions` | Carrier profile; driver affiliation and site-permission create/revoke |
| F08 | `/admin/hubs`, `/{id}/staff`, `/{id}/assets`; `/admin/assets/{id}/events` | Hub/staff/slot changes; asset create and events |
| F09 | Existing `/operations/shipments` and detail/tracking; existing pickup recovery; new `/admin/support-cases` | Existing pickup-release; support case create/update/transitions/notes |
| F10 | `/admin/agreements`, `/admin/earnings`, `/admin/statements`, `/admin/payouts` and scoped details | Agreement versions/review/activate; statement close/approve; disputes; manual payout record/confirm |
| F11 | `/admin/staff`, `/admin/grants`, `/admin/audit` | Invitations, existing role grants, grant revoke |
| F12 | `/admin/reports/{report_code}`, `/admin/exports/{id}` | `/admin/exports`; download only after scope recheck |

`/{id}` and action suffixes in a row are relative to that row's named resource. Explicitly register every final route; no generic controller executing arbitrary method names from request input.

## 5. Additive data model

Common new mutable entity columns: id bigint identity, organization_id, version integer >=0, created_at/updated_at timestamptz and creating actor. All IDs exposed as strings. Code uniqueness is network-scoped. Include `(organization_id,id)` keys and matching composite foreign keys where needed to prevent cross-network associations. Use explicit references instead of unconstrained type/ref pairs for access/finance links.

| Entity family | Minimum fields/relationships | Important constraints |
|---|---|---|
| `installation_sites` | code/name/type, structured postal address, timezone, status, contacts/notes | Same-network identity; type/status allowlists |
| Existing `locations` extension | nullable site_id during backfill, then required; preserve address/timezone/coordinates/status | Existing shipment/routing IDs unchanged; location service attributes remain authoritative |
| Site profiles/calendars | site_id, validated type-specific metadata; weekly windows and date exceptions | No permissions in arbitrary JSON; explicit access-policy fields and membership provider reference |
| `network_partners`, partner_roles | code/legal/display name, contacts, status; role memberships | Network-consistent role assignment; internal ZPX partner allowed |
| Partner memberships/grants | user, partner, profile, scope, effective/expiry/revoked time, issuer/reason | Distinct from existing scoped_role_grants; typed child rows for permitted sites/locations; no empty scope means global |
| Site host periods | site_id, partner_id, from/to | One primary active host in initial release; non-overlap |
| Locker ownership/operation periods | locker_id, partner_id, from/to, reason, actor | One legal owner and one lead operator at any instant initially; separate histories; no retroactive edits |
| Installation history | locker_id, location_id, installed_at, removed_at, commissioned evidence | One active installation per locker and per locker location; consistent with current locker.location_id projection |
| Model/version/layout, cabinet modules | template status/version; dimensions/positions; installed module references | Published template immutable; one installed compartment belongs to one module; preserve board/door uniqueness |
| Carrier profile/affiliations | partner_id, driver_id, valid interval, site permission | Carrier role required; initial one primary commercial affiliation per driver at an instant |
| Customer restrictions | user_id, restriction_type, start/end, reason, issuer/revoker | Does not rewrite user identity or physical custody |
| Hub assets/events | owning hub, tag/type/status/version; event actor/hub/from/to/reason | One current custodian; immutable ledger with protected UPDATE/DELETE privileges |
| Support/change requests | scoped resource refs, assignee, status/version, reason/notes | No direct mutation of parcel state through case transitions |
| Agreement/version/rules | beneficiary, component, typed scope, interval, rate/base/trigger/hold/refund policies | Immutable approved versions; no ambiguous overlapping terms for a component/resource |
| Earning sources/journal | service obligation, source event, agreement snapshot, beneficiary, amount/currency, reversal link | Exactly one posting per semantic service obligation/component; balanced entries; immutable evidence |
| Statements/lines/payout attempts | partner/currency/period, line refs, approvals, external reference, reconciliation state | An earning line in at most one closed statement; distinct preparer/approver; stable payout identity |

The families describe bounded schema work, not a requirement to create all tables in one migration. Use existing domain entities where semantics match; add only the selected task's structures.

## 6. Scope resolution and compatibility

Identity continues to authenticate the current user. A new focused admin authorization service resolves named capability + resource + active relationships. Existing `Identity::requireRole(user, role, location)` remains for current callers; do not broaden it to accept any partner role as ADMIN. New resource queries receive a resolved scope, never raw client partner IDs alone.

Network staff can be granted network scope; other grants enumerate permitted partner resources/sites. Lists intersect grant scope, active relationships and requested filters before sorting/counting. Joined details and exports use the same policy. An owner/operator transfer invalidates affected authorization caches and jobs; finance history remains visible according to beneficiary entitlement, not current asset possession.

`lockers.location_id` remains the current installation projection for existing services. Installation changes update that projection and append history in one guarded transaction. A moved asset uses a different location ID. Historical parcel evidence already referencing the old location remains unchanged. Keep `compartment_ownership` LEGACY/DELIVERY/FROZEN meaning unchanged.

Site and location suspension must be checked where new shipments/offers/reservations are accepted; retrospective filtering of the admin screen is insufficient. Do not interrupt recovery/read access needed to resolve existing parcels. Any change to existing consumers includes targeted regression tests.

## 7. Financial determinism

Before live activation, each agreement must explicitly define calculation basis, trigger, allocation and reversal policy. Synthetic USD fixtures may use fixed amounts and basis points; no example becomes a default commercial rate.

Bind a service obligation to package/leg, service component, beneficiary and agreement version when the assignment/reservation is authorized. Store its immutable snapshot. Deduplication uses obligation + component + beneficiary; event-ID uniqueness alone is insufficient because two events might describe the same completed service. Uniqueness and transactional posting prevent replay and concurrent duplicates.

For a percent rule use integer arithmetic: amount_minor = floor((basis_minor * rate_bps + 5000) / 10000) for nonnegative amounts (half-up rounding). Reverse the exact original amount for a full reversal; define cumulative partial reversal limits so rounding cannot refund more than the original. Each currency journal balances independently. Real-time estimates are labeled estimates until qualifying service evidence exists.

Initial active term scopes must not overlap for the same component/resource; no automatic precedence or stacking. Multiple beneficiaries require explicit allocation lines that sum within the approved service pool. Null/expired/no matching agreement creates a held configuration exception, not a zero-value paid earning. Synthetic and real data have separate processing eligibility enforced server-side.

Close statements under locks so concurrent workers cannot select the same earning twice. Approver must differ from preparer; if no second approver exists, leave approval blocked. Unknown payout outcome is reconciled by stable external reference before any retry. Manual payout confirmation records a real external event but does not initiate payment. No embedded provider credentials or raw bank-account storage in admin forms.

## 8. Migration and rollout procedure

1. Inspect current highest migration and consumers at implementation time; allocate the next migration number, never reuse 001–021 or modify their checksums.
2. Add new tables/nullable references with appropriate privileges. Seed an internal partner and draft sites deterministically for existing locations; do not infer legal ownership or host from location names.
3. Backfill all same-network references; report unresolved rows and keep activation blocked. Do not invent external partner data.
4. Verify counts, composite FKs, uniqueness, existing location/locker IDs and current custody unchanged on a disposable clone/fixture. Make migrations and backfill reruns safe through the existing migrator.
5. Enable new admin reads, then guarded writes. Preserve previous consumers while new constraints become required. Add new evidence tables to append-only runtime protection.
6. Roll back application routes/feature exposure if needed; do not drop tables holding audit/financial data. Database recovery is a reviewed forward migration or verified restore, not destructive automatic down SQL.

## 9. Required fixtures

Synthetic network with two partners in Austin, one partner in a second state/timezone, a mall with two locations, an apartment with restricted eligibility, a closed school, an internal hub and lockers owned/operated by different parties. Include a customer shipping across partners, an expired staff grant, a suspended driver with a held parcel, an occupied locker, a stale device, duplicate service events, a refund after statement close and an unknown payout result.

No imported apartment customers, production credentials or real banking data. Credentials are generated through existing development seed conventions.

## 10. Global console extensions (2026-09-28)

See [GLOBAL_CONSOLE](GLOBAL_CONSOLE.md) for G01–G06 and the complete menu registry. These additions expand the read surface without replacing existing domain tables or services.

| Requirement | Planned GET contracts (base /api/delivery/v1) | Owned mutation service |
|---|---|---|
| G01 | `/admin/search?query=`, `/admin/overview`, `/admin/network-map`, `/admin/my-work` | None; search and counts use capability-specific scope |
| G02 | `/admin/people`, `/admin/people/{id}`, `/admin/contacts`, `/admin/contacts/{id}`, typed contact-assignment child reads | Contact administration; identity grants remain separate |
| G03 | `/admin/compartments`, `/admin/compartments/{id}`, `/admin/devices`, `/admin/devices/{id}` | Existing locker configuration/commissioning services |
| G04 | `/admin/shipments`, `/admin/packages`, `/admin/packages/{id}/timeline`, `/admin/tracking`, `/admin/runs`, `/admin/pickup-demand`, `/admin/driver-offers`, `/admin/labels`, `/admin/recipient-pickups` | Existing shipping/custody/dispatch services; use existing operations contracts where projection is equivalent |
| G05 | `/admin/payments`, `/admin/payments/{id}`, `/admin/refunds`, `/admin/pricing-policies`, `/admin/quotes`, `/admin/driver-earnings` | Existing payment/pricing/driver services with new explicit admin authorization adapters |
| G06 | `/admin/notifications`, `/admin/notifications/{id}`, `/admin/notification-templates`, `/admin/integrations`, `/admin/system-health`, `/admin/settings` | Messaging/configuration use cases; no generic arbitrary retry or settings endpoint |

Define concrete schemas and action endpoints in each owning ADM task before implementation, keeping the current API as the sole executable source. HTML `/admin/finance/...` navigation does not require renaming the JSON endpoints above. Existing endpoints can be extended with compatible scoped projections rather than creating duplicates solely to match a menu.

Contact model: `network_contacts` has network ID, kind PERSON/TEAM, display_name, company_name, protected channels, timezone, optional linked_user_id, status and version. Separate `site_contact_assignments`, `location_contact_assignments`, `hub_contact_assignments` and `partner_contact_assignments` provide real same-network foreign keys, contact role, primary/backup, start/end and permitted purpose. Carrier uses its partner association. Enforce non-overlapping active primary assignments per resource/role with database constraints/locked transactions; do not rely solely on browser validation. A contact can be shared across assignments without exposing all assignments to every viewer.

Existing identity `user_contacts` remains authoritative for verified login/shipping contact channels. Network business-contact edits cannot modify verification or create user access. Backfill prior admin site/partner contact fields once into assignment records and remove competing write paths before enabling contact edits; retain compatibility response projections through the directory. Unknown/missing contacts become completeness warnings, never invented records.

Directory person ID is existing user ID; contacts without accounts stay in the contacts directory. Do not implement unsafe automatic user merge. Search normalization and contact reveal/export require scoped capability and audit. Typed assignment records carry provenance and allow logical archive, not deletion of history.

Financial read models join provider/payment/quote/driver earning records without relabeling their states. Refund request/approve/execute endpoints require capture balance locks, provider idempotency, cumulative refund constraints and authoritative outcome reconciliation. Quote/pricing changes create versions rather than changing an existing shipment's paid basis. Existing pricing remains size-only in Phase 1.

Tracking uses current package state plus append-only sources; display occurrence and ingestion time separately. Cross-domain timeline queries are bounded and redacted per event type. Outbox delivery status is not equivalent to business transition success or customer receipt.
