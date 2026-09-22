# Hub management and asset custody

Purpose: fix the scope, data model and sequencing for hub management before any
implementation begins. Audience: developers and reviewers.
Status: Accepted for development. Owner: unassigned. Reviewed: 2026-09-21.

Richard asked for a hub management application covering locations, staff and
assets such as scanners and label printers, with in/out transactions retained so
the network can answer who did what with which asset, and where. This record
captures the four decisions taken at that point. No code exists yet.

Nothing in the current system models an asset. `locker_devices`, `device_events`
and `device_commands` describe locker controller hardware and its protocol
traffic, not handheld equipment held by people. `label_print_jobs.printer_reference`
is a free-text `VARCHAR(160)` with no entity behind it. The canonical contract
already specifies five admin endpoints — `/admin/locations`, `/admin/role-grants`,
`/admin/drivers`, `/admin/vehicles` and `/admin/pricing-policies` — and none are
implemented; `route/api.php` exposes only the driver approval endpoints. `ADMIN`
and `DISPATCHER` users have no management surface at all and currently fall
through `Account.tsx` into the customer `Shipping` component inside
operations-web.

Sequencing. Hub management is backlog item P7.1 ("Admin policies, staff/vehicles,
finance export, claims and reports"), which depends on P5.2. It is deferred and
will not be pulled forward. Development continues in dependency order through
P4.2, P5.1 and P5.2 first. The consequence is that hub staff operate the P3.3
receiving and P4.1 dispatch flows without system-tracked equipment
accountability; scanner and printer custody remains a manual matter until P7.1.

Asset model: custody chain only. Each asset is an entity with a type, identifier,
owning hub and lifecycle status. Movement is recorded in an append-only ledger of
issue, return, transfer, repair and retirement events carrying the acting user,
the hub or location, a timestamp and a reason. This mirrors `custody_events`,
which already models previous custodian, new custodian, location and actor for
parcels, so the parcel and equipment audit trails read the same way. Per-asset
usage metering is explicitly out of scope.

Integration: separate ledger. `scan_events` and `label_print_jobs` are not
altered. No asset column is added, no historical row is backfilled, and
`printer_reference` stays free text rather than becoming a foreign key. Richard
confirmed on 2026-09-21 that tying an individual scan to the scanner that
produced it is not required, so this is a settled non-requirement rather than an
open limitation. The ledger answers who held a given scanner, at which hub, and
when; it does not answer which scanner produced a given scan, and does not need
to.

The binding audit requirement is narrower, and the implemented flows already meet
it. Every scan must record the staff member who performed it and the hub where it
happened. `scan_events.actor_user_id` is `NOT NULL`, so staff attribution is
structural rather than conventional, and all four insert sites — inbound pickup,
rejected driver scan, hub receive and outbound load — pass the acting user. Hub
attribution is recorded directly on the matching `custody_events` row as
`location_id` and `new_custodian_ref`, and the two rows share an
`operation_uuid`, so the hub for any scan is recoverable by join without adding a
column. "Where" in this requirement means which hub, not a geolocation and not a
specific locker. The asset ledger must preserve both attributions and must not
weaken them.

Interface: an admin area inside operations-web, gated to `ADMIN` and
`HUB_SUPERVISOR`. No third Vite workspace, port or deployment target is created.
This reuses the existing session cookie, CSRF handling, `api` client and build
pipeline, and it gives `ADMIN` and `DISPATCHER` a real landing surface they do
not have today.

Two invariants apply when this is built. The new ledger tables must be added to
the append-only protection in migration 003, which revokes `UPDATE` and `DELETE`
from `zpx_runtime` across `custody_events`, `scan_events`, `audit_events` and the
other evidence tables; an editable asset history would defeat the purpose of
recording it. Authorization must follow the location-scoped grant rule
established in commit `7f09fed`: `HUB_STAFF` and `HUB_SUPERVISOR` grants are
scoped to a hub location, so a service must resolve the caller's hub through
`hub_staff → hubs → locations` and pass that location to `requireRole`. A bare
`requireRole($user, 'HUB_STAFF')` silently rejects every seeded grant.

Location and staff management should implement the already-specified
`/admin/locations` and `/admin/role-grants` contracts rather than inventing
parallel endpoints. `LocationCreate` requires `code`, `name`, `site_mode`,
`address` and `printer_available`, and creates an inactive location pending
hardware ownership commissioning; `StaffGrant` requires `user_id`, `role_code`
and an audited `reason`. Generated clients come from the canonical contract and
must not be hand-edited.

Refused scans are now journaled. `Transaction::run` rolls back on any throwable,
so the original `Custody::recordRejectedScan` wrote a `scan_events` row that was
discarded along with the transaction refusing the scan; refused driver pickups
never actually persisted, and the suite missed it because its only `scan_events`
assertion checked an accepted row. `Zpx\Custody\ScanJournal` now stages a refusal
inside the transaction and writes it once the rollback is done. Driver pickup and
all eight hub receiving refusal paths are covered, so a staff member from another
hub attempting to scan into a session they do not own is recorded with their user
id. A refusal staged before the session resolves carries a null `run_id`, because
the caller-supplied id is untrusted until it matches the session.

`HubDispatch::stageScan` remains uncovered, deliberately. It writes no
`scan_events` row even on success, so journaling only its failures would produce
a trail containing nothing but refusals. Staging attribution already exists
through `staging_assignments.assigned_by` and `audit_events`. If staging is ever
to appear in the scan journal, accepted and refused stage scans should be added
together rather than one without the other.

Affected documents: `docs/handoff/docs/11_BACKLOG_AND_ACCEPTANCE.md` (P7.1 scope
and acceptance), the canonical OpenAPI contract under `/admin/*`,
`docs/CURRENT_STATUS.md`, and migration 003 when the ledger tables are added.

Verification needed: none at this time, because nothing is implemented. When
built, the acceptance evidence must include cross-hub denial tests for every
asset and staff operation, a test proving the runtime database role cannot update
or delete a ledger row, a test proving each ledger row records both the acting
staff member and the hub, and contract conformance for `/admin/locations` and
`/admin/role-grants`.
