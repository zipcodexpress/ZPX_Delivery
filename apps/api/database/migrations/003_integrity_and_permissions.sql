-- Tighten cross-entity identity and physical inventory constraints.
ALTER TABLE controller_boards ADD UNIQUE(id, locker_id);
ALTER TABLE compartments ADD FOREIGN KEY(controller_board_id, locker_id) REFERENCES controller_boards(id, locker_id);
ALTER TABLE manifest_items ADD UNIQUE(id, package_id);
ALTER TABLE active_allocations ADD FOREIGN KEY(manifest_item_id, package_id) REFERENCES manifest_items(id, package_id);
ALTER TABLE locker_sessions ADD UNIQUE(id, package_id, compartment_id);
ALTER TABLE compartment_claims ADD FOREIGN KEY(session_id, package_id, compartment_id) REFERENCES locker_sessions(id, package_id, compartment_id);
ALTER TABLE package_labels ADD UNIQUE(id, package_id);
ALTER TABLE package_labels ADD FOREIGN KEY(replaced_label_id, package_id) REFERENCES package_labels(id, package_id);
ALTER TABLE package_labels ADD CHECK(label_version > 0), ADD CHECK(status IN ('ACTIVE','REVOKED'));
ALTER TABLE packages ADD CHECK(version >= 0), ADD CHECK(sequence_no > 0),
 ADD CHECK(state IN ('CREATED','AT_ORIGIN','INBOUND_CUSTODY','AT_HUB','STAGED','OUTBOUND_CUSTODY','AT_DESTINATION','COLLECTED','RETURN_CUSTODY'));
ALTER TABLE compartments ADD CHECK(width_mm > 0 AND height_mm > 0 AND depth_mm > 0 AND max_weight_g > 0);
ALTER TABLE vehicles ADD CHECK(max_weight_g > 0 AND max_volume_mm3 > 0 AND max_packages > 0);
ALTER TABLE route_runs ADD CHECK(planned_end > planned_start), ADD CHECK(revision > 0);
ALTER TABLE route_run_stops ADD CHECK(sequence_no > 0);
ALTER TABLE payments ADD CHECK(amount_cents >= 0);
ALTER TABLE outbox_events ADD CHECK(attempts >= 0);
ALTER TABLE idempotency_records ADD CHECK(response_status BETWEEN 100 AND 599);
CREATE UNIQUE INDEX ux_active_ownership_manifest ON ownership_manifests(locker_id) WHERE state = 'ACTIVE';
CREATE INDEX ix_shipments_sender ON shipments(sender_user_id, created_at DESC);
CREATE INDEX ix_packages_shipment ON packages(shipment_id);
CREATE INDEX ix_outbox_unpublished ON outbox_events(id) WHERE published_at IS NULL;
CREATE INDEX ix_sessions_user_expiry ON auth_sessions(user_id, expires_at) WHERE revoked_at IS NULL;

-- Validate all fixed-size binary hashes after conversion from MySQL BINARY(32).
DO $$ DECLARE col RECORD; BEGIN
 FOR col IN SELECT table_name, column_name FROM information_schema.columns WHERE table_schema='delivery' AND data_type='bytea' LOOP
  EXECUTE format('ALTER TABLE delivery.%I ADD CHECK (octet_length(%I) = 32)', col.table_name, col.column_name);
 END LOOP;
END $$;

CREATE FUNCTION reject_history_change() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Append-only history cannot be changed' USING ERRCODE = '42501'; END;
$$;
DO $$ DECLARE history TEXT; BEGIN
 FOREACH history IN ARRAY ARRAY['custody_events','scan_events','device_events','audit_events','package_events','payment_events','driver_pay_entries','scan_evidence'] LOOP
  EXECUTE format('CREATE TRIGGER immutable_history BEFORE UPDATE OR DELETE OR TRUNCATE ON delivery.%I FOR EACH STATEMENT EXECUTE FUNCTION delivery.reject_history_change()', history);
 END LOOP;
END $$;

REVOKE ALL ON ALL TABLES IN SCHEMA delivery FROM PUBLIC;
REVOKE ALL ON ALL SEQUENCES IN SCHEMA delivery FROM PUBLIC;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA delivery FROM PUBLIC;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA delivery TO zpx_runtime;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA delivery TO zpx_runtime;
REVOKE INSERT, UPDATE, DELETE ON schema_migrations FROM zpx_runtime;
REVOKE UPDATE, DELETE ON custody_events, scan_events, device_events, audit_events, package_events, payment_events, driver_pay_entries, scan_evidence, shipping_identifiers FROM zpx_runtime;
REVOKE INSERT, UPDATE, DELETE ON ownership_manifests, compartment_ownership, ownership_acknowledgments FROM zpx_runtime;
-- No default grants: every future migration must explicitly review runtime privileges.
