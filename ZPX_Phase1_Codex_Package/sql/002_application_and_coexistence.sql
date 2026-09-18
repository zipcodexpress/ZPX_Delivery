-- Revision 3 application/coexistence additions; NEW delivery database only.
-- Apply after 001_delivery_core.sql. DDL not executed against MySQL in this review.

CREATE TABLE auth_credentials (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT NOT NULL UNIQUE, password_hash VARCHAR(255), password_changed_at DATETIME(6), disabled_at DATETIME(6),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE user_contacts (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT NOT NULL, kind ENUM('EMAIL','PHONE') NOT NULL, value_ciphertext TEXT NOT NULL, lookup_hmac BINARY(32) NOT NULL, verified_at DATETIME(6), key_version INT NOT NULL, UNIQUE(kind, lookup_hmac),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE user_addresses (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT NOT NULL, kind ENUM('PROFILE','RETURN','BILLING') NOT NULL, address_ciphertext TEXT NOT NULL, country_code CHAR(2) NOT NULL, key_version INT NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE auth_sessions (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT NOT NULL, session_hash BINARY(32) NOT NULL UNIQUE, client_kind ENUM('BROWSER','NATIVE') NOT NULL, refresh_hash BINARY(32) UNIQUE, expires_at DATETIME(6) NOT NULL, revoked_at DATETIME(6),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE verification_challenges (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT, purpose VARCHAR(40) NOT NULL, target_hmac BINARY(32) NOT NULL, secret_hash BINARY(32) NOT NULL, attempts INT NOT NULL DEFAULT 0, expires_at DATETIME(6) NOT NULL, consumed_at DATETIME(6),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE external_identity_links (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT NOT NULL, provider VARCHAR(40) NOT NULL, external_subject VARCHAR(160) NOT NULL, proof_reference VARCHAR(160) NOT NULL, verified_at DATETIME(6) NOT NULL, expires_at DATETIME(6), UNIQUE(provider, external_subject),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE scoped_role_grants (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT NOT NULL, role_id BIGINT NOT NULL, organization_id BIGINT NOT NULL, location_id BIGINT, granted_by BIGINT NOT NULL, expires_at DATETIME(6),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (user_id) REFERENCES users(id),
 FOREIGN KEY (role_id) REFERENCES roles(id),
 FOREIGN KEY (organization_id) REFERENCES organizations(id),
 FOREIGN KEY (location_id) REFERENCES locations(id),
 FOREIGN KEY (granted_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE controller_boards (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 locker_id BIGINT NOT NULL, legacy_body_id VARCHAR(80), board_address INT NOT NULL, protocol_profile VARCHAR(40) NOT NULL, display_sequence INT NOT NULL, serial_config JSON NOT NULL, UNIQUE(locker_id, board_address), CHECK(board_address BETWEEN 0 AND 255),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (locker_id) REFERENCES lockers(id)
) ENGINE=InnoDB;

CREATE TABLE device_credentials (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 device_id BIGINT NOT NULL, key_id VARCHAR(80) NOT NULL UNIQUE, public_key TEXT, secret_reference VARCHAR(255), valid_from DATETIME(6) NOT NULL, expires_at DATETIME(6), revoked_at DATETIME(6),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (device_id) REFERENCES locker_devices(id)
) ENGINE=InnoDB;

CREATE TABLE legacy_location_links (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 location_id BIGINT NOT NULL UNIQUE, source_system VARCHAR(64) NOT NULL, legacy_cabinet_id VARCHAR(80) NOT NULL, source_revision VARCHAR(80) NOT NULL, UNIQUE(source_system, legacy_cabinet_id),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (location_id) REFERENCES locations(id)
) ENGINE=InnoDB;

CREATE TABLE legacy_compartment_links (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 compartment_id BIGINT NOT NULL UNIQUE, source_system VARCHAR(64) NOT NULL, legacy_box_id VARCHAR(80) NOT NULL, UNIQUE(source_system, legacy_box_id),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (compartment_id) REFERENCES compartments(id)
) ENGINE=InnoDB;

CREATE TABLE ownership_manifests (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 locker_id BIGINT NOT NULL, generation BIGINT NOT NULL, manifest_hash BINARY(32) NOT NULL, signature_reference VARCHAR(255) NOT NULL, state ENUM('DRAFT','FROZEN','AWAITING_ACK','ACTIVE','SUPERSEDED') NOT NULL, issued_by BIGINT NOT NULL, activated_at DATETIME(6), UNIQUE(locker_id, generation),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (locker_id) REFERENCES lockers(id),
 FOREIGN KEY (issued_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE compartment_ownership (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 compartment_id BIGINT NOT NULL UNIQUE, manifest_id BIGINT NOT NULL, owner ENUM('LEGACY','DELIVERY','FROZEN') NOT NULL, generation BIGINT NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (compartment_id) REFERENCES compartments(id),
 FOREIGN KEY (manifest_id) REFERENCES ownership_manifests(id)
) ENGINE=InnoDB;

CREATE TABLE ownership_acknowledgments (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 manifest_id BIGINT NOT NULL, participant VARCHAR(100) NOT NULL, manifest_hash BINARY(32) NOT NULL, acknowledged_at DATETIME(6) NOT NULL, UNIQUE(manifest_id, participant),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (manifest_id) REFERENCES ownership_manifests(id)
) ENGINE=InnoDB;

CREATE TABLE terminal_pairing_sessions (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 scene_uuid CHAR(36) NOT NULL UNIQUE, device_id BIGINT NOT NULL, actor_user_id BIGINT, workflow VARCHAR(40) NOT NULL, status ENUM('PENDING','APPROVED','CONSUMED','EXPIRED','CANCELLED') NOT NULL, expires_at DATETIME(6) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (device_id) REFERENCES locker_devices(id),
 FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE pickup_grants (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 package_id BIGINT NOT NULL, user_id BIGINT NOT NULL, location_id BIGINT NOT NULL, grant_hash BINARY(32) NOT NULL UNIQUE, action VARCHAR(32) NOT NULL, expires_at DATETIME(6) NOT NULL, consumed_at DATETIME(6),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (package_id) REFERENCES packages(id),
 FOREIGN KEY (user_id) REFERENCES users(id),
 FOREIGN KEY (location_id) REFERENCES locations(id)
) ENGINE=InnoDB;

CREATE TABLE service_waves (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 hub_id BIGINT NOT NULL, service_date DATE NOT NULL, code VARCHAR(40) NOT NULL, intake_cutoff DATETIME(6) NOT NULL, departure_at DATETIME(6) NOT NULL, UNIQUE(hub_id, service_date, code),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (hub_id) REFERENCES hubs(id)
) ENGINE=InnoDB;

CREATE TABLE run_revisions (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 run_id BIGINT NOT NULL, revision INT NOT NULL, snapshot JSON NOT NULL, reason VARCHAR(500) NOT NULL, published_by BIGINT NOT NULL, driver_acknowledged_at DATETIME(6), UNIQUE(run_id, revision),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (run_id) REFERENCES route_runs(id),
 FOREIGN KEY (published_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE shipment_movements (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 package_id BIGINT NOT NULL, sequence_no INT NOT NULL, kind ENUM('INBOUND','OUTBOUND','RETURN') NOT NULL, origin_location_id BIGINT NOT NULL, destination_location_id BIGINT NOT NULL, run_id BIGINT, state VARCHAR(32) NOT NULL, UNIQUE(package_id, sequence_no),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (package_id) REFERENCES packages(id),
 FOREIGN KEY (origin_location_id) REFERENCES locations(id),
 FOREIGN KEY (destination_location_id) REFERENCES locations(id),
 FOREIGN KEY (run_id) REFERENCES route_runs(id)
) ENGINE=InnoDB;

CREATE TABLE package_events (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 package_id BIGINT NOT NULL, event_uuid CHAR(36) NOT NULL UNIQUE, event_type VARCHAR(64) NOT NULL, actor_user_id BIGINT, details JSON NOT NULL, occurred_at DATETIME(6) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (package_id) REFERENCES packages(id),
 FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE scan_evidence (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 scan_event_id BIGINT NOT NULL, locker_session_id BIGINT, device_event_id BIGINT, assurance_level VARCHAR(40) NOT NULL, actor_attestation JSON,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (scan_event_id) REFERENCES scan_events(id),
 FOREIGN KEY (locker_session_id) REFERENCES locker_sessions(id),
 FOREIGN KEY (device_event_id) REFERENCES device_events(id)
) ENGINE=InnoDB;

CREATE TABLE support_claims (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 package_id BIGINT NOT NULL, opened_by BIGINT NOT NULL, category VARCHAR(40) NOT NULL, state VARCHAR(32) NOT NULL, description TEXT NOT NULL, resolution JSON,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (package_id) REFERENCES packages(id),
 FOREIGN KEY (opened_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE artifact_references (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 package_id BIGINT, object_key VARCHAR(500) NOT NULL UNIQUE, kind VARCHAR(40) NOT NULL, content_sha256 BINARY(32) NOT NULL, created_by BIGINT, retention_until DATETIME(6),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (package_id) REFERENCES packages(id),
 FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE pricing_policies (
 id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 organization_id BIGINT NOT NULL, code VARCHAR(64) NOT NULL, version INT NOT NULL, rules JSON NOT NULL, effective_at DATETIME(6) NOT NULL, retired_at DATETIME(6), UNIQUE(organization_id, code, version),
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY (organization_id) REFERENCES organizations(id)
) ENGINE=InnoDB;

ALTER TABLE locations ADD site_mode ENUM('LEGACY_ONLY','HYBRID_PARTITIONED','DELIVERY_ONLY') NOT NULL DEFAULT 'DELIVERY_ONLY';
ALTER TABLE compartments ADD controller_board_id BIGINT NULL, ADD door_address INT NULL, ADD display_row INT NULL, ADD display_column INT NULL,
 ADD FOREIGN KEY (controller_board_id) REFERENCES controller_boards(id), ADD UNIQUE(controller_board_id, door_address),
 ADD CHECK(door_address BETWEEN 0 AND 255);
-- Nullable until topology commissioning; service must reject activation without complete mapping.
ALTER TABLE packages ADD disposition VARCHAR(32) NOT NULL DEFAULT 'NORMAL';
ALTER TABLE route_runs ADD wave_id BIGINT NULL, ADD FOREIGN KEY(wave_id) REFERENCES service_waves(id);
ALTER TABLE locker_sessions ADD pairing_id BIGINT NULL, ADD expected_package_version INT NOT NULL DEFAULT 0,
 ADD ownership_generation BIGINT NULL, ADD actor_attested_at DATETIME(6), ADD version INT NOT NULL DEFAULT 0,
 ADD FOREIGN KEY(pairing_id) REFERENCES terminal_pairing_sessions(id);
ALTER TABLE device_commands ADD payload_hash BINARY(32) NULL, ADD dispatched_at DATETIME(6), ADD ownership_generation BIGINT NULL;
ALTER TABLE device_events ADD boot_id CHAR(36) NULL, ADD boot_sequence BIGINT NULL, ADD UNIQUE(device_id, boot_id, boot_sequence);
CREATE INDEX ix_claims_state_expiry ON compartment_claims(state, expires_at);
CREATE INDEX ix_events_package_time ON package_events(package_id, occurred_at);
CREATE INDEX ix_sessions_package_state ON locker_sessions(package_id, status);
