-- Durable hub receiving discrepancies and single-session lifecycle.
ALTER TABLE exceptions
 ADD COLUMN organization_id BIGINT REFERENCES organizations(id),
 ADD COLUMN hub_id BIGINT REFERENCES hubs(id),
 ADD COLUMN driver_id BIGINT REFERENCES drivers(id),
 ADD COLUMN receiving_session_id BIGINT REFERENCES receiving_sessions(id),
 ADD COLUMN notes VARCHAR(1000),
 ADD COLUMN resolved_by BIGINT REFERENCES users(id),
 ADD COLUMN resolved_at TIMESTAMPTZ,
 ADD COLUMN resolution_code VARCHAR(64);

UPDATE exceptions e SET organization_id=s.organization_id
FROM packages p JOIN shipments s ON s.id=p.shipment_id
WHERE p.id=e.package_id AND e.organization_id IS NULL;
ALTER TABLE exceptions ALTER COLUMN organization_id SET NOT NULL;

CREATE UNIQUE INDEX ux_receiving_discrepancy ON exceptions(receiving_session_id, package_id, code)
 WHERE receiving_session_id IS NOT NULL;
CREATE INDEX ix_exceptions_hub_status ON exceptions(hub_id, status, created_at DESC);

GRANT SELECT, INSERT, UPDATE, DELETE ON exceptions TO zpx_runtime;
