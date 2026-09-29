# Global admin console: menus, records and workflows

Purpose: complete functional scope for Richard's system-wide ZPX administration console.
Status: development specification; menus and functions below are planned unless the existing handoff explicitly identifies implementation evidence.
Owner: Richard (product); engineering owner unassigned. Updated: 2026-09-28.
Framework: ThinkPHP 8 server-rendered pages, shared delivery services, PostgreSQL and existing identity. This extends [FUNCTIONS](FUNCTIONS.md), [DATA_AND_API](DATA_AND_API.md) and [BACKLOG](BACKLOG.md).

## 1. Global visibility and operating model

The ZPX global console must answer: who is involved, where the service happens, which equipment is involved, where each parcel is now, who is accountable, what money moved or is owed, and what needs action.

Introduce an explicit `network.overview` capability with NETWORK scope for global ZPX users. Global read profiles include network-wide operational records, relationship navigation, masked people/contact records and financial summaries through explicit read capabilities. Detailed personal/financial data and write/approve/export actions still require their respective capabilities. NETWORK means the configured ZPX network, not unrestricted access to other `organizations`.

Global scope is selected by default for an authorized ZPX administrator. Partner, state/city, site, location and hub are optional narrowing filters. Restricted accounts can select only their granted subset. Filters do not create permission. Provide a visible scope breadcrumb and a one-click return to the user's permitted overall view.

Account identity, staff employment, driver eligibility, site contacts and partner ownership are different relationships. One person may be a customer, driver and hub staff member; the console shows those roles without duplicating their login or granting the union of every person's roles to their viewers.

Every new page uses the ThinkPHP route/template/service boundary in [THINKPHP_PRESENTATION](THINKPHP_PRESENTATION.md). Do not create a generic SQL-browser UI or an unrestricted database editor to satisfy 'everything'.

## 2. Complete menu catalog

All routes below are HTML pages beginning with `/admin`. Group labels need not be clickable. Each list has a detail page and contextual links; do not duplicate entities merely to show them under different menus. Existing routes in FUNCTIONS remain aliases where paths differ.

| Menu | Submenu / route suffix | Functions and primary actions | Delivery task |
|---|---|---|---|
| Overview | Network dashboard `/` | Global KPIs, map summary, service health, prioritized action queue | ADM-13 |
| Overview | Network map `/network-map` | Sites/hubs/lockers, capacity, last-known driver locations when authorized, geographic filters | ADM-13 |
| Overview | My work `/my-work` | Assigned cases, approvals, commissioning and finance reviews | ADM-13 |
| People | All people `/people` | One searchable directory across customer/driver/staff identities, role badges, relationships | ADM-14 |
| People | Customers `/customers` | Verification/status, shipping restrictions, orders and support | ADM-03 |
| People | Drivers `/drivers` | Approval, eligibility, affiliation, availability, assignments, earnings links | ADM-03/09 |
| People | Staff `/staff` | Hub/operations/support/maintenance/finance staff, invitations, scoped assignments | ADM-04/08 |
| People | Partner users `/partner-users` | Partner memberships and delegated access, expiration/revocation | ADM-04 |
| People | Contacts directory `/contacts` | Site/host/emergency/partner/carrier contacts and effective assignments | ADM-14 |
| Network & locations | Installation sites `/sites` | Property type, host, site policy, locations and contacts | ADM-05 |
| Network & locations | Service locations `/locations` | Locker/hub service points, capabilities, coordinates and status | ADM-05 |
| Network & locations | Service areas `/service-areas` | State/city/postal coverage, partner coverage and hub-service relationships | ADM-04/09 |
| Network & locations | Hours & access `/access-calendars` | Site/location calendars, closures, eligibility policies and entry instructions | ADM-05 |
| Network & locations | Location contacts `/location-contacts` | Contact directory filtered by site/location role; primary/backup contacts | ADM-14 |
| Lockers & hardware | All lockers `/lockers` | Inventory, location, owner/operator, capacity, status, device freshness | ADM-06/07 |
| Lockers & hardware | Models & layouts `/locker-models` | Versioned templates, cabinet/module layouts, compartment specifications | ADM-06 |
| Lockers & hardware | Compartments `/compartments` | Global search by locker/code, size, occupancy/claim, mapping and faults | ADM-15 |
| Lockers & hardware | Devices & connectivity `/devices` | Enrollment, last-seen, firmware metadata, revoked credentials and health | ADM-07/15 |
| Lockers & hardware | Commissioning `/commissioning` | Pending checklists, evidence, approval and activation blockers | ADM-07 |
| Lockers & hardware | Maintenance `/maintenance` | Work orders, outage impact, technician assignments and repair evidence | ADM-07 |
| Lockers & hardware | Installation & ownership history `/asset-history` | Relocation, ownership/operator changes and effective dates | ADM-06 |
| Shipments & tracking | All shipments `/shipments` | Sender/recipient, origin/destination, order/payment status, parcel count | ADM-16 |
| Shipments & tracking | All packages `/packages` | SI, state, current custodian, last confirmed location and active run | ADM-16 |
| Shipments & tracking | Tracking lookup `/tracking` | Search by order/SI/label reference, normalized custody timeline | ADM-16 |
| Shipments & tracking | Labels & printing `/labels` | Label versions, revoked/replaced status, authorized reprint and job failures | ADM-16 |
| Shipments & tracking | Delivery & recipient pickup `/recipient-pickups` | Destination deposit/pickup progress, grant metadata and expiry, notification outcome | ADM-16 |
| Shipments & tracking | Returns & exceptions `/shipment-exceptions` | Failed delivery, missing/ambiguous evidence, returns and linked cases | ADM-16 |
| Dispatch & drivers | Pickup demand `/pickup-demand` | Ready parcels grouped by origin/hub/window, overdue demand | ADM-16 |
| Dispatch & drivers | Driver offers `/driver-offers` | Offered/expired/accepted/declined opportunities and eligibility reason | ADM-16 |
| Dispatch & drivers | Routes & runs `/runs` | Inbound/outbound assignments, stops, manifest, scan progress and status | ADM-16 |
| Dispatch & drivers | Route setup `/pickup-routes` | Existing origin-to-hub assignment and routing configuration | ADM-02 |
| Dispatch & drivers | Pickup recovery `/pickup-recovery` | Existing release of wholly uncollected runs and reason/audit | ADM-02 |
| Dispatch & drivers | Fleet `/vehicles` | Capacity, vehicle status and current driver/carrier relationship | ADM-09 |
| Hubs & operations | All hubs `/hubs` | Hub configuration, capacity, staff and operational summary | ADM-08 |
| Hubs & operations | Receiving `/hub-receiving` | Link to existing authorized scan workflow, sessions and discrepancies | ADM-08 |
| Hubs & operations | Staging & sorting `/hub-staging` | Slots, parcels waiting, destination groups and reassignment guards | ADM-08 |
| Hubs & operations | Outbound dispatch `/hub-dispatch` | Runs, manifests, distinct scan progress and departure blockers | ADM-08 |
| Hubs & operations | Hub staff `/hub-staff` | Staff directory filtered to hub assignments and scope | ADM-08/14 |
| Hubs & operations | Equipment custody `/assets` | Scanner/printer issue/return/transfer/repair ledger | ADM-08 |
| Partners & carriers | All partners `/partners` | Roles, status, ownership/operation, contacts, agreements and statements | ADM-04 |
| Partners & carriers | Carriers `/carriers` | Carrier profiles, affiliations, service permissions and assignments | ADM-09 |
| Partners & carriers | Agreements `/agreements` | Commercial terms, effective versions, review and expiration | ADM-10 |
| Partners & carriers | Change requests `/partner-requests` | Requests, review, guarded application and rejection reasons | ADM-04 |
| Partners & carriers | Onboarding & offboarding `/partner-onboarding` | Required information and unresolved asset/custody/settlement gates | ADM-04 |
| Payments & finance | Finance overview `/finance` | Captures, refunds, holds, payables, reconciled payouts and discrepancies | ADM-17 |
| Payments & finance | Customer payments `/finance/payments` | Payment attempts, provider state/reference, amount, order and reconciliation | ADM-17 |
| Payments & finance | Refunds & disputes `/finance/refunds` | Request/review, provider result, linked original capture and dispute evidence | ADM-17 |
| Payments & finance | Pricing & quotes `/finance/pricing` | Size rate-card versions, quotes, origin upgrade charges and policy activation | ADM-17 |
| Payments & finance | Driver earnings `/finance/driver-earnings` | Existing earning/pay records, run basis and settlement status | ADM-17 |
| Payments & finance | Partner earnings `/finance/earnings` | Contractual beneficiary accruals, holds and reversals | ADM-10 |
| Payments & finance | Statements `/finance/statements` | Period closure, independent approval, adjustments and partner views | ADM-11 |
| Payments & finance | Payouts & reconciliation `/finance/payouts` | Manual external payout evidence, confirmation, unknown outcomes | ADM-11/17 |
| Payments & finance | Ledger & exports `/finance/ledger` | Immutable entries, source links, balanced totals by currency and scoped exports | ADM-10/12 |
| Support & communications | Cases & claims `/support` | Assignment, notes, evidence, claim type, aging and resolution | ADM-08 |
| Support & communications | Notifications `/notifications` | Shipment/recipient/driver message queue, delivery failures, retry eligibility | ADM-18 |
| Support & communications | Templates `/notification-templates` | Versioned templates, safe preview, approval and permitted variables | ADM-18 |
| Support & communications | Escalations `/escalations` | Operational urgency, responsible contact, acknowledgement and case link | ADM-18 |
| Reports | Network performance `/reports/network` | Volumes, custody-stage timing, service exceptions by place/partner | ADM-12 |
| Reports | People & operations `/reports/operations` | Driver/hub workload and completion, staff attribution, qualification expiry | ADM-12 |
| Reports | Locker utilization `/reports/lockers` | Size capacity, occupancy, dwell time and outage impact | ADM-12 |
| Reports | Financial & partner `/reports/finance` | Revenue/charges, reversals, obligations and payouts, explicitly labeled bases | ADM-12 |
| Reports | Exports `/exports` | Authorized queued jobs, status, expiry and download audit | ADM-12 |
| Administration | Roles & access `/access` | Profiles, grants, expiry, delegation and last-admin protection | ADM-01/04 |
| Administration | Audit log `/audit` | Actor/action/resource/change reason and sensitive-read history | ADM-12 |
| Administration | Integrations `/integrations` | Provider/environment status, failures and configuration completeness | ADM-18 |
| Administration | System health `/system-health` | Queue backlog, device/event processing, provider freshness and failed jobs | ADM-18 |
| Administration | Network settings `/settings` | Validated operational defaults, units/timezones, configuration history | ADM-18 |

Menus should be grouped and collapsible, with favorites/recent pages added only after the core navigation works. During development hide unimplemented pages or label a roadmap link explicitly; do not show invented metrics or functioning-looking actions without backend support.

## 3. Common workspace and connected records

Header: ZPX identity, environment banner (LOCAL/STAGING/PRODUCTION), effective scope, global search, assigned-work count and signed-in profile. Test/synthetic records carry visible badges and are excluded from live finance reports by default. No environment switch may silently submit a production action.

Filters: date range with timezone and named event basis; partner owner/operator/beneficiary are separate filters; state/city/site/location/hub; lifecycle/service/payment status. Date filters must say whether they select creation, custody event, completion or payment time. Preserve filters and page position on return from detail.

Global search accepts exact person ID, shipment reference, SI, locker code/serial, site/location code, run/manifest ID, partner code and payment reference. Detect likely exact identifiers; show grouped bounded results and entity type badges, never raw label/pickup tokens. Name/email/phone search requires PII-search permission, normalization and rate limits. Searches and result counts use the same scope as details. No external search service is necessary initially.

Every result opens a stable detail URL. Detail header shows immutable ID, current status, freshness/version and permitted actions. Tabs display related records lazily with pagination. Breadcrumbs and links connect:

`Person -> Shipment -> Package -> Custody event -> Run/Hub/Locker -> Location -> Site -> Partner -> Agreement -> Earning -> Statement -> Payout`.

Relationships are navigational views, not duplicated storage. Cross-links may be hidden or rendered as restricted summaries if the viewer lacks permission on the destination record. Never widen scope merely because a related object is linked.

## 4. Overview dashboard and map definitions (G01)

| Widget | Meaning | Drill-down |
|---|---|---|
| Parcels in network custody | Distinct packages in current locker/hub/driver custody, excluding completed/returned-to-customer items; final enum mapping tested against code | Filtered package list |
| Pickup demand overdue | OPEN demand past operational pickup window, not every uncollected shipment | Demand list with origin and assigned hub |
| Hub work | Awaiting independent receipt, staging and outbound load counts from current states | Hub sessions/manifests |
| Locker availability | Eligible capacity by size from claims/ownership/health policy; show unknown capacity separately | Compartment list |
| Hardware attention | Offline/stale/disabled units under a configured freshness policy | Device/maintenance queue |
| Driver availability | Active, opted-in, eligible drivers with fresh consented location; separate assigned drivers | Driver and run lists |
| Delivery progress | Confirmed destination deposits and recipient retrievals over selected event period | Timeline-filtered packages |
| Financial attention | Unreconciled captures/refunds, held earnings and due payout reviews; never sum mixed currencies | Relevant finance work queues |
| Partner/site readiness | Pending agreements, contact/configuration omissions and commissioning gates | Onboarding/commissioning |

Map clusters sites/locations and shows last-updated time. Layers: locker status/capacity, hubs, service areas and optionally authorized consented driver positions. Location pins identify the service point, not approximate customer home addresses. Unknown/stale driver positions are labeled and do not imply live movement. Use existing map tooling where suitable; no requirement to build road routing in admin.

Dashboard cards derive from the same predicates as their drill-down lists. Add tests comparing card counts and list counts on the same snapshot/fixture. If data is unavailable, show Unknown/Unavailable rather than 0.

## 5. People and contacts (G02)

### Unified people directory

Identity fields: existing user ID, display name, masked email/phone, verified flags, account state, created time and assigned roles. Lists filter by customer/driver/staff/partner role, status and authorized location affiliation. The same user appears once with multiple role badges. Do not join all role rows and accidentally duplicate totals.

Person detail tabs: Overview, Roles/assignments, Customer activity, Driver activity, Staff activity, Cases, Access/security and Audit. Only show a tab when the viewer has its corresponding permission. Staff activity means attributed operational events and assignments; it is not an employee surveillance or payroll system. Session revoke/recovery actions require explicit access administration permission; do not reveal hashes or session tokens.

Hub staff: hub/location, job role, supervisor, active assignment period, permitted workflows and training/document references if required. Operations/maintenance staff may cover several resources through explicit assignments. Keep login activation separate from employment/assignment lifecycle.

### Contact directory and assignment model

A contact can be a person without a login, a shared office contact or an existing user-linked contact. Contact records have display name, kind PERSON/TEAM, organization/company name, phone/email, preferred channel, optional linked_user_id, timezone and status. Login linkage is optional and must not confer access or automatically change verified account contact details.

Assign a contact to a site, location, hub, partner or carrier with typed links, role, primary/backup designation, effective dates, availability window, permitted contact purpose and private notes. Roles: PROPERTY_MANAGER, SITE_HOST, SITE_STAFF, SECURITY, ACCESS_ASSISTANCE, EMERGENCY, MAINTENANCE, HUB_SUPERVISOR, DISPATCH, PARTNER_BUSINESS, BILLING, CARRIER_OPERATIONS. Carrier links reference its partner identity consistently.

Support multiple contacts per site and one contact serving several locations. Enforce at most one active primary per resource/role, unless a later explicit multi-primary policy is adopted. Effective location contacts resolve location-specific assignment first, then site fallback; show whether a result is inherited. Never silently overwrite a site's contact when editing a location override.

Contact functions: create, edit, link/unlink with dates, appoint primary/backup, view assignments, archive and verify that required active-site roles retain coverage. Archive a sole required primary only after a replacement or a reasoned suspension workflow. Duplicate candidates are flagged by normalized channel; do not automatically merge contacts or login identities. Initial release uses manual resolution rather than destructive record merge.

PII rules: store only necessary business contacts; access/security instructions and emergency phone lists require appropriate operations scope. Audit reveals/exports. Expose contact actions without automatically sending messages. A notification request requires an explicit authorized workflow with recipient/purpose recorded.

## 6. Site, location and locker detail workspaces (G03)

| Record | Detail tabs and connected information |
|---|---|
| Installation site | Summary/type, Address/access, Calendar, Contacts, Service locations, Host/partners, Cases, Activity |
| Operational location | Capabilities/eligibility, Coordinates/entrance, Contacts with inheritance, Locker/hub, Capacity, Packages, Runs, Calendar, Commissioning |
| Locker | Asset/model, Layout/modules, Compartments, Devices/firmware, Current parcels/claims, Owner/operator, Installation history, Maintenance, Evidence |
| Compartment | Dimensions/code, Controller mapping, LEGACY/DELIVERY/FROZEN partition, Current claim/occupancy, Session/evidence, Fault/history |
| Device | Enrollment/status, Last-seen freshness, Configuration version/acknowledgment, Events/commands, Credential rotation metadata, Work orders |

Locker configuration follows the TP8 construction order: define versioned Small, Mid, Large and X-large box models with dimensions; place box models at row/column positions in a versioned body model; publish a ready body layout; then select ready body models in physical order for a locker bound to an installation site. The set creates its bodies and boxes atomically from those layouts. Generated boxes remain frozen until separately mapped, commissioned and approved; selecting a model never proves a door exists or changes package custody. Existing box models without an explicit class remain unclassified until a new version is defined.

Operational state, commercial ownership and physical evidence remain separate. A compartment UI must not offer arbitrary editing of occupancy or package state. A read view can show last confirmed evidence and an unresolved session together. Device credentials display key ID/expiry/revocation only, never secret material. Command replays require an implemented recovery protocol and must not be exposed as a generic Retry button.

The global compartment list allows the operator to answer 'where is capacity?', 'which door holds this package?' and 'which disabled compartments have unresolved parcels?' without opening every locker. Display location timezone, event timestamp and last synchronization to avoid implying that cached data is live.

## 7. Shipment, package and custody workspace (G04)

Separate shipment/order from physical package. A shipment may contain multiple packages with independent state and custody; never summarize all as delivered because one parcel completed. Preserve stable SI through relabeling/rerouting.

Shipment list columns: reference, sender/recipient summary, origin/destination, parcel count, order state, payment state, created/last-event time, unresolved exceptions. Package list: SI, shipment, size, physical state, custodian type/reference, last confirmed location, active run, age and next expected action. Sensitive people columns remain masked outside support scope.

Shipment detail tabs: Summary, People/contacts, Packages, Route/runs, Charges/refunds, Tracking, Notifications, Cases and Audit. Package detail tabs: Identity/labels, Current custody, Timeline, Sessions/evidence, Manifest/scans, Exceptions and Financial attribution.

Tracking timeline presents event ID, occurred_at, received_at, event source, actor, location/device, previous/new custody, correlation/operation ID and evidence class (CONFIRMED/SIMULATED/PENDING/REJECTED). Late events are ordered/explained without replacing newer confirmed state. A scan, command acknowledgment, door evidence and custody transfer are visibly different events. Show stale/ambiguous evidence and expected next transition explicitly.

Actions: authorized label reprint/replacement using existing policy, create support case, inspect run, invoke supported wholly-uncollected recovery and request a reviewed cancellation/refund. Implement missing custody transitions as separate domain work before enabling corresponding buttons. No 'force delivered', 'mark paid' or 'empty compartment' shortcuts.

Recipient pickup view shows grant status/expiry/consumption and notification progress, not usable pickup secrets. Renewal/revocation must preserve the existing parcel/recipient authorization model and require an implemented domain operation. Returns require a new tracked custody route, not a changed label that erases earlier custody.

Shipping address/destination changes after dispatch are not generic edits. Any future reroute operation must validate eligibility/capacity, version the manifest and preserve existing custody and labels according to domain policy.

## 8. Payment and finance workspace (G05)

Global finance must cover customer payments as well as driver and partner obligations. Keep the four levels distinguishable: quoted amount, captured/refunded amount, earned/payable amount and paid/reconciled amount.

| Screen | Essential information | Actions and guards |
|---|---|---|
| Payment transactions | Order/customer, attempt, environment, provider reference, currency, amount, status, failure reason, timestamps | View/reconcile through provider adapter; unknown result stays unknown; no manual success override |
| Refunds/disputes | Original capture, requested/approved/refunded amount, reason, requester/reviewer, provider state | Request/review/execute only via supported provider workflow; cumulative refund cannot exceed eligible captured balance |
| Pricing/quotes | Size class, published dimensions, policy version, validity, quoted fee and upgrade difference | Draft/review/activate future policy; existing quotes retain version; preserve size-only Phase 1 pricing |
| Driver earnings | Driver/run, basis, earned/reversed/paid amounts and reference | Reuse earning service; distinguish an accounting entry from actual external payment |
| Partner earnings | Beneficiary, service obligation/event, agreement, allocation and holds | Read/hold/dispute/linked adjustment under finance permissions |
| Statements/payouts | Period, currency, immutable lines, approvers, attempts and settlement evidence | Independent approval, stable identity and reconciliation; no double payment after timeout |
| Ledger/reconciliation | Original and adjustment entries, provider vs internal totals, differences | Drill down, investigate, record resolution; never rewrite original entries |

Payment-method views show provider-safe brand/last-four/token reference only when needed; do not display full PAN, CVV, bank secrets or reusable provider credentials. Reusing existing wallet terminology must not imply that stored card methods are a cash balance.

Configure report bases explicitly: captures minus refunds is net collected amount, not profit. Driver pay, partner accruals, payout fees, disputes and taxes are separately labeled; no unsupported profitability claim. Do not total currencies together or combine LOCAL_TEST/sandbox charges with production receipts.

## 9. Support, notifications and administration (G06)

Cases link any allowed customer/driver/parcel/run/site/locker/partner/payment, with an owner, severity, response target and state. Related data is independently scoped. Internal notes are never automatically sent to customers or partners. Claims and payment disputes are distinguishable case types with domain-specific resolution paths.

Notification history shows template version, purpose, masked destination, channel, enqueue/send/delivery/failure timestamps and provider reference. Retry only transient failures under the same notification identity; do not regenerate grants or send stale pickup credentials. Confirm audience and message preview for an explicit manual resend. Transport acceptance is not confirmed recipient delivery.

Template changes are versioned; preview with synthetic variables, restrict allowed variables, preserve the rendered version of already-sent notifications. SMS/push/integration controls remain unavailable when providers are not implemented. No bulk marketing or unrestricted broadcast feature is included.

Integration settings expose enabled environment, health, last success, failure category and missing configuration. Secret rotation uses an approved secret-management path, not plaintext settings. System health lists failed outbox/jobs, queue age and reconciliation backlog with safe payload summaries. A replay is allowed only through an idempotent domain-specific handler.

Settings are allowlisted and typed, with validation, version, effective time, author and reason. Changes to prices, grants, hardware mapping and agreements remain in their dedicated workflows. Admin must not expose arbitrary SQL, shell execution, environment editing or hidden framework debug tools.

## 10. Implementation model and menu permissions

Add a static menu registry in ThinkPHP presentation code: stable menu ID, parent, label, route name, read capability, feature readiness and sort order. Do not migrate the apartment `zp_menu` permission database. Server renders only authorized implemented items; leaf action buttons use action capabilities independently. Test route/menu mappings in ADM-02 and refresh them as modules ship.

Extend capability names with `network.overview`, `people.read`, `contacts.read/manage`, `packages.read`, `tracking.read`, `devices.read`, `payments.read/reconcile`, `refunds.request/approve`, `pricing.manage`, `notifications.read/resend`, `settings.manage` and `system.health.read`. NETWORK-scoped read grants give global visibility to the intended ZPX roles; no action capability follows automatically. Changes affecting money still require independent approval.

Global query/read services may aggregate existing domain modules, but mutations remain in the owning domain services. No global controller directly updates tables for convenience. New read endpoints and page controllers use the same scope resolver, pagination and redaction policy.

New model additions are contacts/typed assignments and optional saved work queues. Reuse users, drivers, hub_staff, contacts already in identity for login-specific data, shipments, packages, custody, payments, outbox and existing support entities where semantics match. Do not duplicate them for the admin console.

## 11. Release priorities

First usable global release: shared access/shell, overview with honest metrics, people directory, site/location contacts, locker/compartment inventory, shipment/package tracking, payment read/reconciliation and existing operational links. Read-only visibility may ship before new mutations, clearly labeled.

Second: complete guarded provisioning, staff/partner/carrier management, calendars, commissioning, maintenance, exception workflows and notification controls.

Third: partner agreements/earnings/statements, independent payout reconciliation, advanced reports and measured performance improvements. Real partner rollout still requires its isolation tests before access is granted. See ADM-13–ADM-18 and A25–A34 in BACKLOG for the global expansion acceptance gates.

## 12. End-to-end admin demonstration

1. A global ZPX operator opens the dashboard and sees records from two cities and two partners within the same network.
2. Search one SI; inspect the shipment, package custody timeline, current compartment and responsible driver/hub.
3. Follow the location link to its site and primary/backup operational contact without losing search scope.
4. Inspect the customer's payment attempt and its reconciled result; follow separate driver/partner earning links where permitted.
5. Create an internal support case linked to the parcel and location, assign a staff member, and inspect notification status without silently sending a message.
6. Switch to a restricted partner account and prove the same search/links cannot disclose the other partner's records, contacts or finance.
7. Confirm all custody, payment and hardware state changes occurred only through their existing validated services, not through the global view itself.
