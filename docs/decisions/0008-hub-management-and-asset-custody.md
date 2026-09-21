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
`printer_reference` stays free text rather than becoming a foreign key. The
accepted limitation is that the system can state who held a given scanner at a
given time, but cannot state which scanner produced a given scan event. If
per-scan equipment attribution is later required for warranty, dispute or
maintenance purposes, this decision must be superseded rather than quietly
extended.

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

Affected documents: `docs/handoff/docs/11_BACKLOG_AND_ACCEPTANCE.md` (P7.1 scope
and acceptance), the canonical OpenAPI contract under `/admin/*`,
`docs/CURRENT_STATUS.md`, and migration 003 when the ledger tables are added.

Verification needed: none at this time, because nothing is implemented. When
built, the acceptance evidence must include cross-hub denial tests for every
asset and staff operation, a test proving the runtime database role cannot update
or delete a ledger row, and contract conformance for `/admin/locations` and
`/admin/role-grants`.
