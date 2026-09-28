# Admin functional specification

Status: development design, not implementation evidence. Baseline and reading order: [README](README.md).
Requirement IDs F01–F12 map to [tasks and tests](BACKLOG.md).

## Shared behavior

- Desktop-first responsive ThinkPHP-rendered administration sharing the delivery backend. Left navigation, page title/breadcrumb, authorized scope filter, search/filter bar, paginated list and detail view. Preserve the scan-focused React hub/driver screens.
- Use explicit server routes `/admin/<module>` and `/admin/<module>/<id>`; deep links must survive refresh and back/forward navigation. `/admin` is never handled by an SPA fallback. Existing operations tab entry points continue to work or link to the new admin.
- Filters appear in the URL; use explicit Apply for expensive searches. Page size defaults to 25 and never exceeds 100. Sort only allowlisted fields with a stable ID tie-breaker. Display times in the selected location timezone with an explicit zone label.
- Every page has loading, empty, no-results, permission-denied and recoverable-error states. Forms retain input after validation errors; errors identify fields and provide a correlation ID for unexpected failures. Support keyboard navigation, visible focus, labeled controls and status text beyond color.
- Forms use Save/Cancel and warn about unsaved edits. After a concurrent edit, show a conflict and reload option; never overwrite silently. Critical transitions show resource, consequences and a required reason before submission.
- Permissions returned by the server drive navigation and buttons; the API independently enforces the same action and row scope. Hidden buttons are not authorization.
- Common detail tabs: Summary, Relationships, Activity. Add module-specific tabs only where data exists. Activity is append-only and paginated, with actor, reason, time, operation/correlation ID and redacted change summary.
- No ordinary delete for referenced customers, sites, lockers, partners, grants or financial history. Use suspend/archive/retire; hard deletion of an unreferenced draft requires a dedicated future policy and is not in the first release.
- Export is a separate capability; include selected filters, generated time and scope. Recheck access at job execution and download, expire files, protect CSV formula cells, and log download. Never export secrets, full payment credentials or unrelated customer contacts.

## Access model

Roles below are proposed admin profiles; map them to named capabilities in ADM-01. Existing ADMIN, DISPATCHER and hub grants retain their existing behavior until explicitly migrated.

| Profile | Allowed scope and functions | Explicit exclusions |
|---|---|---|
| Network administrator | Network-wide site/partner/user configuration and staff grants | No implicit payout approval or direct physical custody override |
| Network operations | Authorized regions/sites, driver review, routes, exceptions | Commercial terms, unrestricted customer exports and access administration |
| Customer support | Assigned/network support cases and necessary customer/parcel information | Editing verified contacts, marking payment successful or manually marking delivery complete |
| Hub supervisor | Assigned hub staff, equipment and existing operational actions | Other hubs, network grants and partner settlement |
| Maintenance technician | Assigned locker diagnostics and maintenance tasks | Customer directory, finance and unsupervised remote opening |
| Finance preparer | Assigned statements, earnings and reconciliation preparation | Approving own statement/payout |
| Finance approver | Explicit finance scope and approval capability | Altering immutable earning evidence; self-approval |
| Partner administrator | Own memberships/resource views and change requests | Network ADMIN; granting capabilities outside delegated limits |
| Partner operator | Currently assigned sites/lockers and minimal operational data | Other partners and customer-wide history |
| Partner finance | Own agreements, statements and dispute submission | Editing rates, payout confirmation and other beneficiary lines |
| Auditor | Explicit read-only scope with redacted audit trail | Mutations and exports unless separately granted |

Capability families: `admin.access`, `customers.read`, `customers.restrict`, `drivers.read`, `drivers.review`, `sites.read`, `sites.manage`, `lockers.read`, `lockers.configure`, `lockers.commission`, `partners.read`, `partners.manage`, `staff.grant`, `carriers.manage`, `hub.assets.manage`, `operations.dispatch`, `support.manage`, `finance.prepare`, `finance.approve`, `audit.read`, `exports.create`.

Authorize action AND resource relationship. A partner membership alone grants no sites; active ownership/operation/host relationships and an explicit grant determine access. Revenue entitlement grants only relevant financial visibility. Region assignment never implies access to competing partners. Read access to customer PII must come from a service/support need, not merely asset ownership.

Grant operations cannot exceed the issuer's delegable scope; no self-elevation, no removal of the last active network access administrator, and no partner grant of finance approval. Expiry/revocation applies immediately on the next request, including cached lists and download jobs. Audit denied privileged operations without storing submitted secrets.

## F01 — Overview and navigation

Route `/admin`. Show scoped counts for pickup demand overdue, receiving discrepancies, unavailable lockers, pending driver reviews and finance holds. Link each metric to its filtered list. Each card shows freshness and unavailable state; absent data is not zero. Do not display simulated device availability as live. Provide a task queue by priority and age before adding charts.

Acceptance: a site-restricted operator cannot infer another site's counts; an unavailable source shows a clear unknown state; existing four admin tabs remain reachable.

## F02 — Customers

Route `/admin/customers`. Columns: ID, display name, masked contacts, verification indicators, account/service status and creation date. Filters: ID/contact search, verification and status. Detail: profile, eligibility, shipments, support cases and redacted audit history. Only authorized support can reveal necessary contacts; log the reveal.

Actions: add/remove a reasoned SHIPPING restriction, view shipment/custody history, create support case, initiate the existing verification/recovery process. No setting `verified=true`, editing password hashes, reading pickup tokens or changing payment success. A shipping restriction stops new shipments but retains authenticated tracking, support and safe completion of existing custody. Broader account security suspension uses the existing identity policy and a separate reconciliation path.

Customer registration remains self-service; staff invites do not create verified customer contacts. Partner users do not receive the customer directory. A customer using several partners remains one network identity.

## F03 — Drivers and fleet

Routes `/admin/drivers`, `/admin/vehicles`. Reuse pending approval/rejection. Add all-driver list: ID, name, status, carrier affiliation, vehicle, service area and active run count. Detail includes onboarding evidence references/expiry, current assignment, availability consent/freshness, incidents and authorized compensation summary.

Actions: approve/reject, suspend/reactivate with reason, assign carrier affiliation, configure eligible service areas, manage vehicle capacity and qualification records. Suspension prevents new offers/acceptance; assigned packages remain accounted for and require an exception/recovery workflow. It must not drop active allocations or transfer custody. Existing wholly uncollected pickup recovery rules remain unchanged; partially collected runs cannot use it.

Fleet fields: code, carrier/owning party, active driver assignment, max packages/weight/volume, status and relevant compliance-document references. Do not expose identification documents in routine exports. Preserve driver consent for location sharing; admins cannot toggle it on for drivers.

## F04 — Installation sites and operational locations

Routes `/admin/sites` and `/admin/locations`. Site list: code, name, type, city/state, timezone, host, status, location count. Location list: code, site, capabilities, assigned hub, site_mode and operational status.

Site detail tabs: General, Access/calendar, Locations, Host/contacts, Activity. Required create fields: code, name, site_type, structured address, timezone. Coordinates and host may be missing in DRAFT but are required for activation; location routing coordinates remain authoritative during the initial migration.

Site types: APARTMENT, CONVENIENCE_STORE, SHOPPING_MALL, SCHOOL, COMPANY_BUILDING, HUB, OTHER. OTHER requires a description. A hub can also be a capability of a location within another site type.

| Type | Form additions |
|---|---|
| APARTMENT | Property manager, optional building/unit directory, visitor policy, membership requirement and access instructions |
| CONVENIENCE_STORE | Staffed hours, assistance/printing capability, entrance and after-hours policy |
| SHOPPING_MALL | Zone/floor/entrance, security contact, parking/loading instructions and holiday closures |
| SCHOOL | Required membership/recipient policy, delivery window, closure calendar and designated receiving staff |
| COMPANY_BUILDING | Employer eligibility, reception/loading point, visitor handling and business calendar |
| HUB | Hub location, receiving/staging capacity, operating windows, supervisor and dispatch cutoff |

Location capabilities: ORIGIN_DEPOSIT, DESTINATION_PICKUP, HUB_RECEIVING, HUB_DISPATCH, LABEL_PRINTING. Eligibility policy is PUBLIC, VERIFIED_MEMBERS_ONLY or STAFF_ONLY; a selected type does not imply eligibility. Reject activation of membership-restricted service if its verification method is not implemented/configured. Existing legacy apartment membership remains behind a reviewed adapter.

Calendar: weekly local-time windows plus date-specific replacements/closures. Date exceptions override weekly windows; reject overlapping windows. Split overnight windows at midnight for deterministic storage. Evaluate current location timezone, including DST. Location windows may narrow site windows; an override cannot silently reopen a closed site.

Site states: DRAFT -> ACTIVE -> SUSPENDED -> ARCHIVED, with guarded reactivation. Location activation separately requires complete routing/access configuration and locker commissioning for locker functions. Activating a site never automatically activates its children. Suspension stops new commitments but exposes existing parcels/runs for managed completion; archive requires no active installations or work.

Actions: create/edit draft, add location, configure access/calendar, activate/suspend/archive, link host, assign origin hub through existing route service. Changing hub routing affects future offers; existing runs keep their hub. Existing shipment location IDs and historical addresses must not be rewritten.

## F05 — Locker catalog, inventory and commissioning

Routes `/admin/locker-models` and `/admin/lockers`. Catalog has manufacturer, model code, cabinet dimensions, version, module layout, compartment dimensions/weight limit and display positions. Installed locker has asset code, serial, model version, location, legal owner/operator, modules, controllers/devices and lifecycle.

Layout editor: table/grid of module position and compartment coordinates, size and controller/channel. Keep visual positions separate from physical addresses. Validate duplicate positions, channel collisions, dimensions and controller ownership. Catalog templates contain mapping placeholders; actual installed addresses require verified mapping.

Template states DRAFT -> PUBLISHED -> RETIRED. Published versions are immutable; clone into a new draft. Instantiation copies a version into physical records with source references, so a catalog edit never changes a deployed locker.

Locker detail tabs: Summary, Layout, Devices, Commissioning, Ownership/operator, Maintenance, Activity. Show stale telemetry explicitly. Occupied, reserved, disabled, unknown and free are distinct states; free requires current validated state, not a heartbeat.

Locker lifecycle: DRAFT -> INSTALLED -> COMMISSIONING -> ACTIVE; ACTIVE -> MAINTENANCE or SUSPENDED; return to ACTIVE requires fresh checks; RETIRED is terminal. Survey/enrollment/mapping are checklist steps, not additional conflicting status values. This refines the conceptual lifecycle in the analysis.

Commissioning checks: enrolled device, authenticated configuration acknowledgment, verified physical mapping, safe compartment-use ownership generation, site policy, terminal recovery evidence and responsible approver. Software-only tests produce SIMULATED evidence and cannot satisfy a production activation gate. Store evidence references and versions; no secrets in evidence artifacts.

Actions: provision, instantiate modules, submit/publish mapping, commission, suspend, create work order, replace device credential via dedicated enrollment flow, transfer ownership and relocate. No generic Open All or clear-occupancy button. Remote recovery, if added later, must use the existing session/command/evidence protocol and a separate reviewed contract.

Initial relocation/ownership transfer requires no packages/claims, active sessions, pending commands or unresolved custody. Freeze new work, lock and recheck before commit; preserve histories and recommission after relocation. Use a new operational location when the physical service point changes so past shipment references do not move. More permissive occupied-asset transfers are deferred.

## F06 — Partners and territories

Route `/admin/partners`. Fields: network ID (server-owned), code, legal/display names, business contacts, role set, status and service areas. No raw banking secrets; payment-provider references live in restricted finance records. States DRAFT -> ACTIVE -> SUSPENDED -> OFFBOARDED.

Tabs: Summary, People/access, Sites/assets, Service areas, Agreements, Statements, Requests, Activity. Service areas initially use named state/city/postal-code sets for administration; do not replace current pickup-distance eligibility or imply road routing.

Actions: onboard, assign owner/operator/host relationships with effective dates, invite staff, request changes, suspend and offboard. Change requests move OPEN -> APPROVED/APPLIED or REJECTED; approval rechecks the resource version and authorization. An approved request may never bypass commissioning.

Activation requires contact, defined roles and explicit grants. Suspension prevents new assignments and privilege issuance; task-specific custody resolution remains available through network operations. Offboarding requires no active ownership/operation assignments, unresolved custody or unreconciled settlement obligations, while history stays readable to authorized network staff.

## F07 — Carriers

Route `/admin/carriers`; a carrier is a partner with the CARRIER role plus a carrier profile, not a duplicate company identity. Columns: partner, service area, status and active driver count. Tabs: Drivers, Vehicles, Site access, Agreements and Activity.

Actions: manage effective-dated driver affiliations and site permissions, suspend an agreement, view assigned runs. A commercial carrier agreement is not a door credential and does not make every affiliated driver eligible for every parcel. Server must check driver status, specific assignment, run/site and physical workflow at each action. Existing apartment carrier deposits remain in the legacy authority boundary.

## F08 — Hubs and equipment

Route `/admin/hubs`. Reuse current receiving, staging, outbound load/dispatch and discrepancies; add hub staff, slots/capacity, operating calendar and equipment inventory administration. Do not rewrite scan/custody services to implement forms.

Equipment fields: asset tag, type (SCANNER/PRINTER/OTHER), serial reference, owning hub, status and current custodian. Events ISSUE, RETURN, TRANSFER, SEND_TO_REPAIR, RETURN_FROM_REPAIR, RETIRE carry actor, hub, reason and evidence reference. One current custodian; lock/version check for concurrent issue. No retirement while issued. Runtime role cannot modify/delete ledger events. No per-scan usage linkage, per decision 0008.

## F09 — Operations and support

Routes `/admin/operations` and `/admin/support`. Reuse existing route/recovery endpoints and shipment inspection. Exception list: type, package/run/site, age, severity, assignee and status. Support cases OPEN -> IN_PROGRESS -> WAITING -> RESOLVED -> CLOSED, with reasoned reopening.

Actions: assign case, add note/evidence, link run/parcel, invoke an already-supported recovery, request refund review. Case closure does not change parcel custody. If a physical reconciliation operation is not implemented, expose the blocker and evidence requirements rather than a fake completion action. Partial-run recovery remains a separate development dependency.

## F10 — Finance and partner statements

Routes `/admin/finance/agreements`, `/earnings`, `/statements`, `/payouts` under the same finance prefix. Keep customer charges, driver earnings and partner earnings in separate views with explicit links.

Agreement fields: partner beneficiary, service component, applicable asset/location scope, validity, USD currency initially, FIXED_MINOR or PERCENT_BPS calculation, amount/rate, named price basis, earning trigger and approved refund/hold policy. Draft -> reviewed -> active -> expired/superseded. An author cannot approve their own commercial version. No active terms until required business policy is supplied.

Each earning shows package/service event, asset and beneficiary snapshot, agreement version, base/rate/rounding, gross adjustment and status. Distinguish accrued, held, available, included-in-statement and paid. Qualifying events must be confirmed service evidence; test-only events can generate only test-only entries excluded from live statements.

Statements: DRAFT -> CLOSED -> APPROVED -> PAYOUT_PENDING -> PAID; disputes create holds/adjustments. After CLOSED, line contents are immutable. Later corrections appear on a new statement. A payout of unknown outcome remains pending reconciliation and cannot be blindly retried with a new identity.

Initial payout workflow is recorded manual reconciliation, not a money-sending button: preparer records external reference/evidence, a different authorized approver confirms, then the system posts settlement once. Provider automation is deferred. Never call a payout successful from a button click alone.

A partner can see only its own lines and submit disputes. Earnings use contractual entitlement, not present-day ownership; past statements remain correct after an asset sale. Reports distinguish gross charges, beneficiary accruals, payouts and costs; unallocated charge balance is not net profit.

## F11 — Staff and audit

Routes `/admin/staff` and `/admin/audit`. Staff invitations bind intended role/scope, expire and are single-use; acceptance uses existing identity/contact verification. Grant list shows user, capability profile, scope, validity and issuer; revoke requires reason and preserves history.

Audit filters: actor, action, object, partner/site, time and correlation ID. Sensitive before/after values are redacted. New ledgers join existing append-only database protection. Network administrator cannot edit audit history from the UI.

## F12 — Reporting and scale

Reports: capacity/utilization by location and size, pickup aging, hub throughput/discrepancy, driver completion, partner earnings/reconciliation and maintenance downtime. Every measure defines event/time basis and freshness; avoid combining physical and synthetic outcomes.

Start with filtered SQL and bounded pages. Async exports use existing outbox/job patterns. Add aggregate tables only after measuring query plans. No mandatory new microservices, cache cluster, generic workflow builder or separate regional databases. Contract tests must include lists/counts/exports, not only detail endpoints.
