-- Hub sorting, dispatch calls and driver pickup workflow.
CREATE TABLE dispatch_calls (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 hub_id BIGINT NOT NULL,
 slot_id BIGINT,
 outbound_run_id BIGINT,
 destination_location_id BIGINT NOT NULL,
 package_count INT NOT NULL DEFAULT 0,
 status VARCHAR(24) NOT NULL DEFAULT 'PENDING' CHECK(status IN ('PENDING','ACCEPTED','DECLINED','EXPIRED','CANCELLED','DISPATCHED')),
 called_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 expires_at TIMESTAMPTZ NOT NULL,
 driver_id BIGINT,
 confirmed_at TIMESTAMPTZ,
 expected_pickup_at TIMESTAMPTZ,
 actual_pickup_at TIMESTAMPTZ,
 FOREIGN KEY(hub_id) REFERENCES hubs(id),
 FOREIGN KEY(driver_id) REFERENCES drivers(id)
);
CREATE INDEX ix_dispatch_calls_status ON dispatch_calls(status, expires_at);
CREATE INDEX ix_dispatch_calls_driver ON dispatch_calls(driver_id);
ALTER TABLE route_runs ADD COLUMN dispatch_call_id BIGINT REFERENCES dispatch_calls(id),
 ADD COLUMN expected_pickup_at TIMESTAMPTZ,
 ADD COLUMN actual_pickup_at TIMESTAMPTZ;
ALTER TABLE hub_slots ADD COLUMN status VARCHAR(24) NOT NULL DEFAULT 'AVAILABLE' CHECK(status IN ('AVAILABLE','OCCUPIED','DISPATCHED'));
ALTER TABLE staging_assignments ALTER COLUMN outbound_run_id DROP NOT NULL;
GRANT SELECT, INSERT, UPDATE, DELETE ON dispatch_calls TO zpx_runtime;
GRANT USAGE, SELECT ON SEQUENCE dispatch_calls_id_seq TO zpx_runtime;
