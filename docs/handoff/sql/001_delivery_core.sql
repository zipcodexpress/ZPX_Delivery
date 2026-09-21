-- ZPX Phase 1 draft. MySQL 8; validate on target server before use.
-- Fresh logistics schema only; never run against an existing ZPX schema unreviewed.
-- UTC timestamps. Foreign keys use explicit table-level constraints.

CREATE TABLE organizations (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;

CREATE TABLE users (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT NOT NULL, external_auth_id VARCHAR(160) NOT NULL UNIQUE, display_name VARCHAR(160) NOT NULL, status VARCHAR(24) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB;

CREATE TABLE roles (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL UNIQUE,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;

CREATE TABLE user_roles (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL, role_id BIGINT NOT NULL, UNIQUE(user_id, role_id),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE locations (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT NOT NULL, code VARCHAR(40) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL, kind VARCHAR(24) NOT NULL, address_text VARCHAR(500) NOT NULL, latitude DECIMAL(10,7), longitude DECIMAL(10,7), timezone VARCHAR(64) NOT NULL DEFAULT 'America/Chicago', access_policy JSON, operating_hours JSON, status VARCHAR(24) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB;

CREATE TABLE lockers (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  location_id BIGINT NOT NULL UNIQUE, external_locker_id VARCHAR(160) UNIQUE, capabilities JSON,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (location_id) REFERENCES locations(id)
) ENGINE=InnoDB;

CREATE TABLE locker_devices (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  locker_id BIGINT NOT NULL, external_device_id VARCHAR(160) NOT NULL UNIQUE, key_reference VARCHAR(160), status VARCHAR(24) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (locker_id) REFERENCES lockers(id)
) ENGINE=InnoDB;

CREATE TABLE compartments (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  locker_id BIGINT NOT NULL, code VARCHAR(40) NOT NULL, width_mm INT NOT NULL, height_mm INT NOT NULL, depth_mm INT NOT NULL, max_weight_g INT NOT NULL, status VARCHAR(24) NOT NULL, UNIQUE(locker_id, code),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (locker_id) REFERENCES lockers(id)
) ENGINE=InnoDB;

CREATE TABLE hubs (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  location_id BIGINT NOT NULL UNIQUE, status VARCHAR(24) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (location_id) REFERENCES locations(id)
) ENGINE=InnoDB;

CREATE TABLE hub_staff (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  hub_id BIGINT NOT NULL, user_id BIGINT NOT NULL, UNIQUE(hub_id, user_id),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (hub_id) REFERENCES hubs(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE drivers (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL UNIQUE, engagement_type VARCHAR(24) NOT NULL, status VARCHAR(24) NOT NULL, verification_reference VARCHAR(160),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE vehicles (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT NOT NULL, code VARCHAR(40) NOT NULL UNIQUE, max_weight_g INT NOT NULL, max_volume_mm3 BIGINT NOT NULL, max_packages INT NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB;

CREATE TABLE driver_shifts (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  driver_id BIGINT NOT NULL, vehicle_id BIGINT NOT NULL, starts_at DATETIME(6) NOT NULL, ends_at DATETIME(6) NOT NULL, CHECK(ends_at > starts_at),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (driver_id) REFERENCES drivers(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
) ENGINE=InnoDB;

CREATE TABLE shipments (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT NOT NULL, sender_user_id BIGINT NOT NULL, public_reference VARCHAR(64) NOT NULL UNIQUE, origin_location_id BIGINT NOT NULL, destination_location_id BIGINT NOT NULL, service_level VARCHAR(32) NOT NULL, order_status VARCHAR(24) NOT NULL, payment_status VARCHAR(24) NOT NULL, version INT NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (organization_id) REFERENCES organizations(id),
  FOREIGN KEY (sender_user_id) REFERENCES users(id),
  FOREIGN KEY (origin_location_id) REFERENCES locations(id),
  FOREIGN KEY (destination_location_id) REFERENCES locations(id)
) ENGINE=InnoDB;

CREATE TABLE shipment_parties (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shipment_id BIGINT NOT NULL, party_role VARCHAR(24) NOT NULL, user_id BIGINT, contact_encrypted TEXT NOT NULL, UNIQUE(shipment_id, party_role),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (shipment_id) REFERENCES shipments(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE packages (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shipment_id BIGINT NOT NULL, package_uuid CHAR(36) NOT NULL UNIQUE, sequence_no INT NOT NULL, width_mm INT NOT NULL, height_mm INT NOT NULL, depth_mm INT NOT NULL, weight_g INT NOT NULL, state VARCHAR(32) NOT NULL, custodian_type VARCHAR(24) NOT NULL, custodian_ref VARCHAR(80) NOT NULL, current_location_id BIGINT, version INT NOT NULL DEFAULT 0, UNIQUE(shipment_id, sequence_no), CHECK(weight_g > 0 AND width_mm > 0 AND height_mm > 0 AND depth_mm > 0),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (shipment_id) REFERENCES shipments(id),
  FOREIGN KEY (current_location_id) REFERENCES locations(id)
) ENGINE=InnoDB;

CREATE TABLE shipping_identifiers (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  package_id BIGINT NOT NULL UNIQUE, si VARCHAR(100) NOT NULL UNIQUE, destination_location_id BIGINT NOT NULL, policy_version INT NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (destination_location_id) REFERENCES locations(id)
) ENGINE=InnoDB;

CREATE TABLE package_labels (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  package_id BIGINT NOT NULL, label_version INT NOT NULL, token_hash BINARY(32) NOT NULL UNIQUE, status VARCHAR(24) NOT NULL, active_package_id BIGINT GENERATED ALWAYS AS (CASE WHEN status = 'ACTIVE' THEN package_id ELSE NULL END) STORED, print_artifact_ref VARCHAR(500), replaced_label_id BIGINT, reason VARCHAR(500), UNIQUE(package_id, label_version), UNIQUE(active_package_id),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (package_id) REFERENCES packages(id)
) ENGINE=InnoDB;

CREATE TABLE label_print_jobs (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  label_id BIGINT NOT NULL, requested_by BIGINT NOT NULL, printer_reference VARCHAR(160), status VARCHAR(24) NOT NULL, copies INT NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (label_id) REFERENCES package_labels(id),
  FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE route_templates (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT NOT NULL, hub_id BIGINT NOT NULL, code VARCHAR(64) NOT NULL UNIQUE, kind VARCHAR(24) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (organization_id) REFERENCES organizations(id),
  FOREIGN KEY (hub_id) REFERENCES hubs(id)
) ENGINE=InnoDB;

CREATE TABLE route_template_stops (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  template_id BIGINT NOT NULL, location_id BIGINT NOT NULL, sequence_no INT NOT NULL, UNIQUE(template_id, sequence_no),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (template_id) REFERENCES route_templates(id),
  FOREIGN KEY (location_id) REFERENCES locations(id)
) ENGINE=InnoDB;

CREATE TABLE route_runs (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  organization_id BIGINT NOT NULL, template_id BIGINT, hub_id BIGINT NOT NULL, driver_id BIGINT NOT NULL, vehicle_id BIGINT NOT NULL, kind VARCHAR(24) NOT NULL, state VARCHAR(24) NOT NULL, revision INT NOT NULL DEFAULT 1, planned_start DATETIME(6) NOT NULL, planned_end DATETIME(6) NOT NULL, departed_at DATETIME(6),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (organization_id) REFERENCES organizations(id),
  FOREIGN KEY (template_id) REFERENCES route_templates(id),
  FOREIGN KEY (hub_id) REFERENCES hubs(id),
  FOREIGN KEY (driver_id) REFERENCES drivers(id),
  FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
) ENGINE=InnoDB;

CREATE TABLE route_run_stops (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT NOT NULL, location_id BIGINT NOT NULL, sequence_no INT NOT NULL, window_start DATETIME(6), window_end DATETIME(6), state VARCHAR(24) NOT NULL, UNIQUE(run_id, sequence_no), UNIQUE(id, run_id),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (run_id) REFERENCES route_runs(id),
  FOREIGN KEY (location_id) REFERENCES locations(id)
) ENGINE=InnoDB;

CREATE TABLE manifests (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id BIGINT NOT NULL, revision INT NOT NULL, state VARCHAR(24) NOT NULL, UNIQUE(run_id, revision), UNIQUE(id, run_id),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (run_id) REFERENCES route_runs(id)
) ENGINE=InnoDB;

CREATE TABLE manifest_items (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  manifest_id BIGINT NOT NULL, run_id BIGINT NOT NULL, package_id BIGINT NOT NULL, stop_id BIGINT NOT NULL, state VARCHAR(24) NOT NULL, UNIQUE(manifest_id, package_id), FOREIGN KEY(manifest_id, run_id) REFERENCES manifests(id, run_id), FOREIGN KEY(stop_id, run_id) REFERENCES route_run_stops(id, run_id),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (package_id) REFERENCES packages(id)
) ENGINE=InnoDB;

CREATE TABLE active_allocations (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  package_id BIGINT NOT NULL UNIQUE, manifest_item_id BIGINT NOT NULL UNIQUE,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (manifest_item_id) REFERENCES manifest_items(id)
) ENGINE=InnoDB;

CREATE TABLE hub_slots (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  hub_id BIGINT NOT NULL, code VARCHAR(40) NOT NULL, destination_location_id BIGINT, run_id BIGINT, kind VARCHAR(24) NOT NULL, UNIQUE(hub_id, code),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (hub_id) REFERENCES hubs(id),
  FOREIGN KEY (destination_location_id) REFERENCES locations(id),
  FOREIGN KEY (run_id) REFERENCES route_runs(id)
) ENGINE=InnoDB;

CREATE TABLE receiving_sessions (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  hub_id BIGINT NOT NULL, inbound_run_id BIGINT NOT NULL, receiver_user_id BIGINT NOT NULL, status VARCHAR(24) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (hub_id) REFERENCES hubs(id),
  FOREIGN KEY (inbound_run_id) REFERENCES route_runs(id),
  FOREIGN KEY (receiver_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE receiving_items (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT NOT NULL, package_id BIGINT NOT NULL, disposition VARCHAR(24) NOT NULL, UNIQUE(session_id, package_id),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (session_id) REFERENCES receiving_sessions(id),
  FOREIGN KEY (package_id) REFERENCES packages(id)
) ENGINE=InnoDB;

CREATE TABLE staging_assignments (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  package_id BIGINT NOT NULL UNIQUE, slot_id BIGINT NOT NULL, outbound_run_id BIGINT NOT NULL, assigned_by BIGINT NOT NULL, routing_revision INT NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (slot_id) REFERENCES hub_slots(id),
  FOREIGN KEY (outbound_run_id) REFERENCES route_runs(id),
  FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE routing_stickers (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  package_id BIGINT NOT NULL, run_id BIGINT NOT NULL, routing_revision INT NOT NULL, printed_by BIGINT NOT NULL, status VARCHAR(24) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (run_id) REFERENCES route_runs(id),
  FOREIGN KEY (printed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE containers (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  hub_id BIGINT NOT NULL, code VARCHAR(64) NOT NULL UNIQUE, kind VARCHAR(24) NOT NULL, status VARCHAR(24) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (hub_id) REFERENCES hubs(id)
) ENGINE=InnoDB;

CREATE TABLE container_items (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  container_id BIGINT NOT NULL, package_id BIGINT NOT NULL UNIQUE,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (container_id) REFERENCES containers(id),
  FOREIGN KEY (package_id) REFERENCES packages(id)
) ENGINE=InnoDB;

CREATE TABLE locker_sessions (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  package_id BIGINT NOT NULL, compartment_id BIGINT NOT NULL, actor_user_id BIGINT, action VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, expires_at DATETIME(6) NOT NULL, credential_hash BINARY(32), evidence_policy VARCHAR(64) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (compartment_id) REFERENCES compartments(id),
  FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE compartment_claims (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  compartment_id BIGINT NOT NULL UNIQUE, package_id BIGINT NOT NULL UNIQUE, session_id BIGINT NOT NULL, state VARCHAR(24) NOT NULL, expires_at DATETIME(6),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (compartment_id) REFERENCES compartments(id),
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (session_id) REFERENCES locker_sessions(id)
) ENGINE=InnoDB;

CREATE TABLE device_commands (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  session_id BIGINT NOT NULL, device_id BIGINT NOT NULL, command_uuid CHAR(36) NOT NULL UNIQUE, status VARCHAR(24) NOT NULL, expires_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (session_id) REFERENCES locker_sessions(id),
  FOREIGN KEY (device_id) REFERENCES locker_devices(id)
) ENGINE=InnoDB;

CREATE TABLE device_events (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  device_id BIGINT NOT NULL, command_id BIGINT, external_event_id VARCHAR(100) NOT NULL, occurred_at DATETIME(6) NOT NULL, evidence JSON NOT NULL, UNIQUE(device_id, external_event_id),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (device_id) REFERENCES locker_devices(id),
  FOREIGN KEY (command_id) REFERENCES device_commands(id)
) ENGINE=InnoDB;

CREATE TABLE scan_events (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  operation_uuid CHAR(36) NOT NULL UNIQUE, package_id BIGINT, actor_user_id BIGINT NOT NULL, run_id BIGINT, action VARCHAR(40) NOT NULL, result_code VARCHAR(64) NOT NULL, received_at DATETIME(6) NOT NULL, client_occurred_at DATETIME(6),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (actor_user_id) REFERENCES users(id),
  FOREIGN KEY (run_id) REFERENCES route_runs(id)
) ENGINE=InnoDB;

CREATE TABLE custody_events (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  package_id BIGINT NOT NULL, operation_uuid CHAR(36) NOT NULL UNIQUE, package_version INT NOT NULL, actor_user_id BIGINT, event_type VARCHAR(40) NOT NULL, previous_custodian_type VARCHAR(24) NOT NULL, previous_custodian_ref VARCHAR(80) NOT NULL, new_custodian_type VARCHAR(24) NOT NULL, new_custodian_ref VARCHAR(80) NOT NULL, location_id BIGINT, evidence JSON NOT NULL, occurred_at DATETIME(6) NOT NULL, UNIQUE(package_id, package_version),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (actor_user_id) REFERENCES users(id),
  FOREIGN KEY (location_id) REFERENCES locations(id)
) ENGINE=InnoDB;

CREATE TABLE exceptions (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  package_id BIGINT NOT NULL, run_id BIGINT, code VARCHAR(64) NOT NULL, status VARCHAR(24) NOT NULL, recorded_by BIGINT NOT NULL, resolution JSON,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (run_id) REFERENCES route_runs(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE pricing_quotes (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shipment_id BIGINT NOT NULL, policy_version VARCHAR(40) NOT NULL, amount_cents BIGINT NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'USD', expires_at DATETIME(6) NOT NULL, CHECK(amount_cents >= 0),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (shipment_id) REFERENCES shipments(id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shipment_id BIGINT NOT NULL, provider VARCHAR(40) NOT NULL, provider_reference VARCHAR(160) NOT NULL, amount_cents BIGINT NOT NULL, status VARCHAR(24) NOT NULL, UNIQUE(provider, provider_reference),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (shipment_id) REFERENCES shipments(id)
) ENGINE=InnoDB;

CREATE TABLE payment_events (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(40) NOT NULL, provider_event_id VARCHAR(160) NOT NULL, payment_id BIGINT, payload_reference VARCHAR(500), UNIQUE(provider, provider_event_id),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;

CREATE TABLE refunds (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT NOT NULL, amount_cents BIGINT NOT NULL, status VARCHAR(24) NOT NULL, operation_uuid CHAR(36) NOT NULL UNIQUE, CHECK(amount_cents > 0),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB;

CREATE TABLE driver_pay_entries (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  driver_id BIGINT NOT NULL, run_id BIGINT, policy_version VARCHAR(40) NOT NULL, amount_cents BIGINT NOT NULL, kind VARCHAR(24) NOT NULL, operation_uuid CHAR(36) NOT NULL UNIQUE,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (driver_id) REFERENCES drivers(id),
  FOREIGN KEY (run_id) REFERENCES route_runs(id)
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  package_id BIGINT NOT NULL, event_reference VARCHAR(100) NOT NULL, channel VARCHAR(24) NOT NULL, status VARCHAR(24) NOT NULL, provider_reference VARCHAR(160), UNIQUE(event_reference, channel),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (package_id) REFERENCES packages(id)
) ENGINE=InnoDB;

CREATE TABLE idempotency_records (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(160) NOT NULL, request_key VARCHAR(100) NOT NULL, payload_hash BINARY(32) NOT NULL, response_status INT NOT NULL, response_body JSON NOT NULL, expires_at DATETIME(6) NOT NULL, UNIQUE(scope, request_key),
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;

CREATE TABLE outbox_events (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_uuid CHAR(36) NOT NULL UNIQUE, aggregate_type VARCHAR(40) NOT NULL, aggregate_id BIGINT NOT NULL, event_type VARCHAR(64) NOT NULL, payload JSON NOT NULL, published_at DATETIME(6), attempts INT NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;

CREATE TABLE audit_events (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT, action VARCHAR(80) NOT NULL, entity_type VARCHAR(40) NOT NULL, entity_id VARCHAR(80) NOT NULL, reason VARCHAR(500), details JSON,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE INDEX ix_packages_state ON packages(state, current_location_id);
CREATE INDEX ix_custody_package_time ON custody_events(package_id, occurred_at);
CREATE INDEX ix_runs_driver_state ON route_runs(driver_id, state, planned_start);
CREATE INDEX ix_outbox_pending ON outbox_events(published_at, id);
