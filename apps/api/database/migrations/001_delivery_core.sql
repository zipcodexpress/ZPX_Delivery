-- PostgreSQL canonical migration. New delivery database only.
CREATE TABLE organizations (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE users (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  organization_id BIGINT NOT NULL, external_auth_id VARCHAR(160) NOT NULL UNIQUE, display_name VARCHAR(160) NOT NULL, status VARCHAR(24) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id)
);

CREATE TABLE roles (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE user_roles (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id BIGINT NOT NULL, role_id BIGINT NOT NULL, UNIQUE(user_id, role_id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (role_id) REFERENCES roles(id)
);

CREATE TABLE locations (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  organization_id BIGINT NOT NULL, code VARCHAR(40) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL, kind VARCHAR(24) NOT NULL, address_text VARCHAR(500) NOT NULL, latitude DECIMAL(10,7), longitude DECIMAL(10,7), timezone VARCHAR(64) NOT NULL DEFAULT 'America/Chicago', access_policy JSONB, operating_hours JSONB, status VARCHAR(24) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id)
);

CREATE TABLE lockers (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  location_id BIGINT NOT NULL UNIQUE, external_locker_id VARCHAR(160) UNIQUE, capabilities JSONB,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (location_id) REFERENCES locations(id)
);

CREATE TABLE locker_devices (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  locker_id BIGINT NOT NULL, external_device_id VARCHAR(160) NOT NULL UNIQUE, key_reference VARCHAR(160), status VARCHAR(24) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (locker_id) REFERENCES lockers(id)
);

CREATE TABLE compartments (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  locker_id BIGINT NOT NULL, code VARCHAR(40) NOT NULL, width_mm INT NOT NULL, height_mm INT NOT NULL, depth_mm INT NOT NULL, max_weight_g INT NOT NULL, status VARCHAR(24) NOT NULL, UNIQUE(locker_id, code),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (locker_id) REFERENCES lockers(id)
);

CREATE TABLE hubs (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  location_id BIGINT NOT NULL UNIQUE, status VARCHAR(24) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (location_id) REFERENCES locations(id)
);

CREATE TABLE hub_staff (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  hub_id BIGINT NOT NULL, user_id BIGINT NOT NULL, UNIQUE(hub_id, user_id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hub_id) REFERENCES hubs(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE drivers (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id BIGINT NOT NULL UNIQUE, engagement_type VARCHAR(24) NOT NULL, status VARCHAR(24) NOT NULL, verification_reference VARCHAR(160),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE vehicles (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  organization_id BIGINT NOT NULL, code VARCHAR(40) NOT NULL UNIQUE, max_weight_g INT NOT NULL, max_volume_mm3 BIGINT NOT NULL, max_packages INT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id)
);

CREATE TABLE driver_shifts (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  driver_id BIGINT NOT NULL, vehicle_id BIGINT NOT NULL, starts_at TIMESTAMPTZ NOT NULL, ends_at TIMESTAMPTZ NOT NULL, CHECK(ends_at > starts_at),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (driver_id) REFERENCES drivers(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
);

CREATE TABLE shipments (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  organization_id BIGINT NOT NULL, sender_user_id BIGINT NOT NULL, public_reference VARCHAR(64) NOT NULL UNIQUE, origin_location_id BIGINT NOT NULL, destination_location_id BIGINT NOT NULL, service_level VARCHAR(32) NOT NULL, order_status VARCHAR(24) NOT NULL, payment_status VARCHAR(24) NOT NULL, version INT NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id),
  FOREIGN KEY (sender_user_id) REFERENCES users(id),
  FOREIGN KEY (origin_location_id) REFERENCES locations(id),
  FOREIGN KEY (destination_location_id) REFERENCES locations(id)
);

CREATE TABLE shipment_parties (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  shipment_id BIGINT NOT NULL, party_role VARCHAR(24) NOT NULL, user_id BIGINT, contact_encrypted TEXT NOT NULL, UNIQUE(shipment_id, party_role),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (shipment_id) REFERENCES shipments(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE packages (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  shipment_id BIGINT NOT NULL, package_uuid UUID NOT NULL UNIQUE, sequence_no INT NOT NULL, width_mm INT NOT NULL, height_mm INT NOT NULL, depth_mm INT NOT NULL, weight_g INT NOT NULL, state VARCHAR(32) NOT NULL, custodian_type VARCHAR(24) NOT NULL, custodian_ref VARCHAR(80) NOT NULL, current_location_id BIGINT, version INT NOT NULL DEFAULT 0, UNIQUE(shipment_id, sequence_no), CHECK(weight_g > 0 AND width_mm > 0 AND height_mm > 0 AND depth_mm > 0),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (shipment_id) REFERENCES shipments(id),
  FOREIGN KEY (current_location_id) REFERENCES locations(id)
);

CREATE TABLE shipping_identifiers (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  package_id BIGINT NOT NULL UNIQUE, si VARCHAR(100) NOT NULL UNIQUE, destination_location_id BIGINT NOT NULL, policy_version INT NOT NULL DEFAULT 1,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (destination_location_id) REFERENCES locations(id)
);

CREATE TABLE package_labels (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  package_id BIGINT NOT NULL, label_version INT NOT NULL, token_hash BYTEA NOT NULL UNIQUE, status VARCHAR(24) NOT NULL, print_artifact_ref VARCHAR(500), replaced_label_id BIGINT, reason VARCHAR(500), UNIQUE(package_id, label_version),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (package_id) REFERENCES packages(id)
);

CREATE TABLE label_print_jobs (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  label_id BIGINT NOT NULL, requested_by BIGINT NOT NULL, printer_reference VARCHAR(160), status VARCHAR(24) NOT NULL, copies INT NOT NULL DEFAULT 1,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (label_id) REFERENCES package_labels(id),
  FOREIGN KEY (requested_by) REFERENCES users(id)
);

CREATE TABLE route_templates (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  organization_id BIGINT NOT NULL, hub_id BIGINT NOT NULL, code VARCHAR(64) NOT NULL UNIQUE, kind VARCHAR(24) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id),
  FOREIGN KEY (hub_id) REFERENCES hubs(id)
);

CREATE TABLE route_template_stops (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  template_id BIGINT NOT NULL, location_id BIGINT NOT NULL, sequence_no INT NOT NULL, UNIQUE(template_id, sequence_no),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (template_id) REFERENCES route_templates(id),
  FOREIGN KEY (location_id) REFERENCES locations(id)
);

CREATE TABLE route_runs (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  organization_id BIGINT NOT NULL, template_id BIGINT, hub_id BIGINT NOT NULL, driver_id BIGINT NOT NULL, vehicle_id BIGINT NOT NULL, kind VARCHAR(24) NOT NULL, state VARCHAR(24) NOT NULL, revision INT NOT NULL DEFAULT 1, planned_start TIMESTAMPTZ NOT NULL, planned_end TIMESTAMPTZ NOT NULL, departed_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (organization_id) REFERENCES organizations(id),
  FOREIGN KEY (template_id) REFERENCES route_templates(id),
  FOREIGN KEY (hub_id) REFERENCES hubs(id),
  FOREIGN KEY (driver_id) REFERENCES drivers(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
);

CREATE TABLE route_run_stops (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  run_id BIGINT NOT NULL, location_id BIGINT NOT NULL, sequence_no INT NOT NULL, window_start TIMESTAMPTZ, window_end TIMESTAMPTZ, state VARCHAR(24) NOT NULL, UNIQUE(run_id, sequence_no), UNIQUE(id, run_id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (run_id) REFERENCES route_runs(id),
  FOREIGN KEY (location_id) REFERENCES locations(id)
);

CREATE TABLE manifests (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  run_id BIGINT NOT NULL, revision INT NOT NULL, state VARCHAR(24) NOT NULL, UNIQUE(run_id, revision), UNIQUE(id, run_id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (run_id) REFERENCES route_runs(id)
);

CREATE TABLE manifest_items (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  manifest_id BIGINT NOT NULL, run_id BIGINT NOT NULL, package_id BIGINT NOT NULL, stop_id BIGINT NOT NULL, state VARCHAR(24) NOT NULL, UNIQUE(manifest_id, package_id), FOREIGN KEY(manifest_id, run_id) REFERENCES manifests(id, run_id), FOREIGN KEY(stop_id, run_id) REFERENCES route_run_stops(id, run_id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (package_id) REFERENCES packages(id)
);

CREATE TABLE active_allocations (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  package_id BIGINT NOT NULL UNIQUE, manifest_item_id BIGINT NOT NULL UNIQUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (manifest_item_id) REFERENCES manifest_items(id)
);

CREATE TABLE hub_slots (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  hub_id BIGINT NOT NULL, code VARCHAR(40) NOT NULL, destination_location_id BIGINT, run_id BIGINT, kind VARCHAR(24) NOT NULL, UNIQUE(hub_id, code),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hub_id) REFERENCES hubs(id),
  FOREIGN KEY (destination_location_id) REFERENCES locations(id),
  FOREIGN KEY (run_id) REFERENCES route_runs(id)
);

CREATE TABLE receiving_sessions (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  hub_id BIGINT NOT NULL, inbound_run_id BIGINT NOT NULL, receiver_user_id BIGINT NOT NULL, status VARCHAR(24) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hub_id) REFERENCES hubs(id),
  FOREIGN KEY (inbound_run_id) REFERENCES route_runs(id),
  FOREIGN KEY (receiver_user_id) REFERENCES users(id)
);

CREATE TABLE receiving_items (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  session_id BIGINT NOT NULL, package_id BIGINT NOT NULL, disposition VARCHAR(24) NOT NULL, UNIQUE(session_id, package_id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (session_id) REFERENCES receiving_sessions(id),
  FOREIGN KEY (package_id) REFERENCES packages(id)
);

CREATE TABLE staging_assignments (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  package_id BIGINT NOT NULL UNIQUE, slot_id BIGINT NOT NULL, outbound_run_id BIGINT NOT NULL, assigned_by BIGINT NOT NULL, routing_revision INT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (slot_id) REFERENCES hub_slots(id),
  FOREIGN KEY (outbound_run_id) REFERENCES route_runs(id),
  FOREIGN KEY (assigned_by) REFERENCES users(id)
);

CREATE TABLE routing_stickers (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  package_id BIGINT NOT NULL, run_id BIGINT NOT NULL, routing_revision INT NOT NULL, printed_by BIGINT NOT NULL, status VARCHAR(24) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (run_id) REFERENCES route_runs(id),
  FOREIGN KEY (printed_by) REFERENCES users(id)
);

CREATE TABLE containers (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  hub_id BIGINT NOT NULL, code VARCHAR(64) NOT NULL UNIQUE, kind VARCHAR(24) NOT NULL, status VARCHAR(24) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hub_id) REFERENCES hubs(id)
);

CREATE TABLE container_items (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  container_id BIGINT NOT NULL, package_id BIGINT NOT NULL UNIQUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (container_id) REFERENCES containers(id),
  FOREIGN KEY (package_id) REFERENCES packages(id)
);

CREATE TABLE locker_sessions (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  package_id BIGINT NOT NULL, compartment_id BIGINT NOT NULL, actor_user_id BIGINT, action VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, expires_at TIMESTAMPTZ NOT NULL, credential_hash BYTEA, evidence_policy VARCHAR(64) NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (compartment_id) REFERENCES compartments(id),
  FOREIGN KEY (actor_user_id) REFERENCES users(id)
);

CREATE TABLE compartment_claims (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  compartment_id BIGINT NOT NULL UNIQUE, package_id BIGINT NOT NULL UNIQUE, session_id BIGINT NOT NULL, state VARCHAR(24) NOT NULL, expires_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (compartment_id) REFERENCES compartments(id),
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (session_id) REFERENCES locker_sessions(id)
);

CREATE TABLE device_commands (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  session_id BIGINT NOT NULL, device_id BIGINT NOT NULL, command_uuid UUID NOT NULL UNIQUE, status VARCHAR(24) NOT NULL, expires_at TIMESTAMPTZ NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (session_id) REFERENCES locker_sessions(id),
  FOREIGN KEY (device_id) REFERENCES locker_devices(id)
);

CREATE TABLE device_events (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  device_id BIGINT NOT NULL, command_id BIGINT, external_event_id VARCHAR(100) NOT NULL, occurred_at TIMESTAMPTZ NOT NULL, evidence JSONB NOT NULL, UNIQUE(device_id, external_event_id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (device_id) REFERENCES locker_devices(id),
  FOREIGN KEY (command_id) REFERENCES device_commands(id)
);

CREATE TABLE scan_events (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  operation_uuid UUID NOT NULL UNIQUE, package_id BIGINT, actor_user_id BIGINT NOT NULL, run_id BIGINT, action VARCHAR(40) NOT NULL, result_code VARCHAR(64) NOT NULL, received_at TIMESTAMPTZ NOT NULL, client_occurred_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (actor_user_id) REFERENCES users(id),
  FOREIGN KEY (run_id) REFERENCES route_runs(id)
);

CREATE TABLE custody_events (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  package_id BIGINT NOT NULL, operation_uuid UUID NOT NULL UNIQUE, package_version INT NOT NULL, actor_user_id BIGINT, event_type VARCHAR(40) NOT NULL, previous_custodian_type VARCHAR(24) NOT NULL, previous_custodian_ref VARCHAR(80) NOT NULL, new_custodian_type VARCHAR(24) NOT NULL, new_custodian_ref VARCHAR(80) NOT NULL, location_id BIGINT, evidence JSONB NOT NULL, occurred_at TIMESTAMPTZ NOT NULL, UNIQUE(package_id, package_version),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (actor_user_id) REFERENCES users(id),
  FOREIGN KEY (location_id) REFERENCES locations(id)
);

CREATE TABLE exceptions (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  package_id BIGINT NOT NULL, run_id BIGINT, code VARCHAR(64) NOT NULL, status VARCHAR(24) NOT NULL, recorded_by BIGINT NOT NULL, resolution JSONB,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (run_id) REFERENCES route_runs(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
);

CREATE TABLE pricing_quotes (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  shipment_id BIGINT NOT NULL, policy_version VARCHAR(40) NOT NULL, amount_cents BIGINT NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'USD', expires_at TIMESTAMPTZ NOT NULL, CHECK(amount_cents >= 0),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (shipment_id) REFERENCES shipments(id)
);

CREATE TABLE payments (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  shipment_id BIGINT NOT NULL, provider VARCHAR(40) NOT NULL, provider_reference VARCHAR(160) NOT NULL, amount_cents BIGINT NOT NULL, status VARCHAR(24) NOT NULL, UNIQUE(provider, provider_reference),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (shipment_id) REFERENCES shipments(id)
);

CREATE TABLE payment_events (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  provider VARCHAR(40) NOT NULL, provider_event_id VARCHAR(160) NOT NULL, payment_id BIGINT, payload_reference VARCHAR(500), UNIQUE(provider, provider_event_id),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (payment_id) REFERENCES payments(id)
);

CREATE TABLE refunds (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  payment_id BIGINT NOT NULL, amount_cents BIGINT NOT NULL, status VARCHAR(24) NOT NULL, operation_uuid UUID NOT NULL UNIQUE, CHECK(amount_cents > 0),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (payment_id) REFERENCES payments(id)
);

CREATE TABLE driver_pay_entries (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  driver_id BIGINT NOT NULL, run_id BIGINT, policy_version VARCHAR(40) NOT NULL, amount_cents BIGINT NOT NULL, kind VARCHAR(24) NOT NULL, operation_uuid UUID NOT NULL UNIQUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (driver_id) REFERENCES drivers(id),
  FOREIGN KEY (run_id) REFERENCES route_runs(id)
);

CREATE TABLE notifications (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  package_id BIGINT NOT NULL, event_reference VARCHAR(100) NOT NULL, channel VARCHAR(24) NOT NULL, status VARCHAR(24) NOT NULL, provider_reference VARCHAR(160), UNIQUE(event_reference, channel),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (package_id) REFERENCES packages(id)
);

CREATE TABLE idempotency_records (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  scope VARCHAR(160) NOT NULL, request_key VARCHAR(100) NOT NULL, payload_hash BYTEA NOT NULL, response_status INT NOT NULL, response_body JSONB NOT NULL, expires_at TIMESTAMPTZ NOT NULL, UNIQUE(scope, request_key),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE outbox_events (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  event_uuid UUID NOT NULL UNIQUE, aggregate_type VARCHAR(40) NOT NULL, aggregate_id BIGINT NOT NULL, event_type VARCHAR(64) NOT NULL, payload JSONB NOT NULL, published_at TIMESTAMPTZ, attempts INT NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE audit_events (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  actor_user_id BIGINT, action VARCHAR(80) NOT NULL, entity_type VARCHAR(40) NOT NULL, entity_id VARCHAR(80) NOT NULL, reason VARCHAR(500), details JSONB,
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (actor_user_id) REFERENCES users(id)
);

CREATE INDEX ix_packages_state ON packages(state, current_location_id);
CREATE INDEX ix_custody_package_time ON custody_events(package_id, occurred_at);
CREATE INDEX ix_runs_driver_state ON route_runs(driver_id, state, planned_start);
CREATE INDEX ix_outbox_pending ON outbox_events(published_at, id);


CREATE UNIQUE INDEX ux_package_active_label ON package_labels(package_id) WHERE status = 'ACTIVE';
