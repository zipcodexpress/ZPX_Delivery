# New database, legacy mapping and constraints

## Ownership

Run 001 then 002 only in new empty delivery DB. 001 carries forward the initial 49-table scaffold; 002 adds missing account/contact/address/session, device topology/ownership, route wave/revision, movement/evidence, claims and operational-policy records. Both are design DDL; no MySQL daemon was available for execution here. PR 1 must execute and revise migrations on chosen actual server before treating them as release artifacts. There are no cross-database foreign keys or copied production resident records.

Use one ZPX organization for the pilot; customer users belong to that operating organization, not to a separate organization per apartment. Staff roles additionally scoped by hub/location. Future multitenancy requires extending compound tenant foreign keys; current DDL relies on mandatory organization-scope service checks and negative access tests. Do not call it database-enforced tenant isolation.

## Key relationships and mapping

| Existing source | New reference | Rule |
|---|---|---|
| cabinet.cabinet_id | lockers.external_locker_id + legacy_location_links | Read mapping; new UUID/IDs independent |
| cabinet_body.body_id / addr / sequence | controller_boards legacy_body_id / board_address / display_sequence | Do not confuse sequence with electrical address |
| cabinet_box.box_id / body_id / addr | compartments + legacy_compartment_links | Stable physical tuple unique; display row/column distinct |
| cabinet_body_box layout template | Import template reference only | Not an installed physical compartment instance |
| member.member_id | external_identity_links | Verified account link only; no bulk sync/password copy |
| o_member_apartment | eligibility assertion with expiry | Existing residency is not a new delivery account |
| o_store.store_id | Legacy display/reference only | New package is NOT an o_store record |
| z_deliver/cargo_code | Historical reference | Do not reuse as new shipment/SI ledger |

## Table families

- Identity: users, roles, user_roles; auth_credentials, user_contacts, user_addresses, auth_sessions, verification_challenges, external_identity_links, scoped_role_grants. Contact values encrypted; normalized contact lookup HMAC stored separately; key rotation/version policy required. external_auth_id may hold local identity UUID; does not require an external identity vendor.
- Physical network: locations, lockers, controller_boards, compartments, locker_devices, device_credentials, legacy_location_links, legacy_compartment_links, ownership_manifests, compartment_ownership, ownership_acknowledgments. Ownership table is new projection of operations-issued configuration; not legacy availability cache.
- Shipment: shipments, shipment_parties snapshot, packages, shipping_identifiers, package_labels, label_print_jobs, package_events, shipment_movements, pickup_grants. Immutable package identity; labels replaceable; origin/destination snapshot fixed after deposit.
- Routes/hub: route_templates/stops, route_runs/stops, manifests/items, active_allocations, service_waves, run_revisions, hub_slots, receiving_sessions/items, staging_assignments, routing_stickers, containers/items. Inbound and outbound movements distinct; container scan not bulk custody.
- Device/custody: locker_sessions, compartment_claims, device_commands/events, scan_events, custody_events, scan_evidence, terminal_pairing_sessions. Immutable observations versus mutable current state separated.
- Operations: exceptions, support_claims, artifact_references, driver_shifts, drivers, vehicles, pricing_policies/quotes, payments/events/refunds, driver_pay_entries, notifications, idempotency_records, outbox_events, audit_events.

## Invariants

One active label via generated unique key. One active allocation per package. One active compartment claim per parcel and per door. Unique hardware tuple locker/board_address/door_address. Unique package version in custody events, separate package-events sequence where no custody changes. One event ID per device, one command UUID, one idempotency scope/key. Same-run stop/manifest composite foreign keys in 001. Ownership generation must match terminal/API and legacy acknowledgment before activation (service validation).

Current authority constraints: device belongs to locker, board belongs to same locker as compartment, hub slot/run/destination agrees, user role scope agrees, allocation item refers to same package, grant/session package matches command. Some cross-entity equalities are service-enforced, not all expressible through draft FKs; add integration tests and optionally strengthen compound keys during PR 1. Never ignore these because a simple ID foreign key passes.

Physical projection with polymorphic custodian_ref is a service-validated reference. Keep enums centralized and generate constraints/status validation consistently. Mandatory statuses in doc 07 and API contracts take precedence over permissive VARCHAR draft columns. Global unique IDs use case-sensitive collation; token hashes are binary. Use UTC DATETIME(6), America/Chicago for service schedule conversion, grams/mm and integer cents. Enforce length and positivity in API plus schema checks.

## Migration/release rules

Create new DB with utf8mb4 and explicit identifier comparison semantics; fresh installs 001→002. Document schema version and digest. Do not apply 001 to old apartment tables with same names. Old database additive ownership projection/guards are separately designed in sql/legacy_bridge_design.md and implemented only after actual schema export and deployed writers inventory. A repository model is not proof of all DB indexes, defaults, triggers or production table definitions.

Restricted runtime role cannot update/delete custody/scan/device/audit history. Normal roles cannot overwrite ownership or bulk release occupied doors. Append compensating events; no cascading deletes of physical history. Retention/purge for contact PII is policy-controlled without deleting required custody identity references.

MySQL validation gate: empty migration, upgrade/rollback plan, unique active-label duplicate test, allocation race, expired-before-dispatch vs unknown-after-dispatch reservation behavior, manifest revision concurrency, webhook replay, rollback between event/projection/outbox, restricted-role write denial. SQLite is not a substitute for MySQL concurrency tests.
