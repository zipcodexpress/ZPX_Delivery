-- Driver inbound collection indexes and run state constraints.
CREATE INDEX ix_manifest_items_run_package ON manifest_items(run_id, package_id);
CREATE INDEX ix_scan_events_run_action ON scan_events(run_id, action);
CREATE INDEX ix_packages_origin_state ON packages(state) WHERE state IN ('CREATED','AT_ORIGIN');
ALTER TABLE route_runs ADD CHECK(state IN ('DRAFT','PUBLISHED','ACKNOWLEDGED','IN_PROGRESS','COMPLETED','CANCELLED'));
ALTER TABLE manifest_items ADD CHECK(state IN ('EXPECTED','LOADED','UNLOADED','SHORT','RETURNED'));
