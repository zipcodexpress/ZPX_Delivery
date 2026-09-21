-- Driver management: approval workflow, compensation and profile.
ALTER TABLE drivers ADD COLUMN phone VARCHAR(20),
 ADD COLUMN email VARCHAR(254),
 ADD COLUMN applied_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 ADD COLUMN approved_at TIMESTAMPTZ,
 ADD COLUMN approved_by BIGINT,
 ADD COLUMN rejection_reason VARCHAR(500),
 ADD FOREIGN KEY(approved_by) REFERENCES users(id);
ALTER TABLE drivers ADD CHECK(status IN ('PENDING','ACTIVE','SUSPENDED','INACTIVE'));
ALTER TABLE driver_shifts ADD COLUMN compensation_cents BIGINT NOT NULL DEFAULT 0 CHECK(compensation_cents >= 0),
 ADD COLUMN compensation_policy VARCHAR(64) NOT NULL DEFAULT 'FIXED_SHIFT',
 ADD COLUMN settled_at TIMESTAMPTZ,
 ADD COLUMN settlement_reference VARCHAR(100);
CREATE INDEX ix_drivers_status ON drivers(status);
CREATE INDEX ix_driver_pay_driver ON driver_pay_entries(driver_id, created_at DESC);
