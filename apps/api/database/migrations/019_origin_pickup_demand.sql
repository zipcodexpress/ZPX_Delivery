-- Only an evidence-confirmed origin deposit creates the first pickup demand.
CREATE TABLE pickup_demands (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  package_id BIGINT NOT NULL UNIQUE REFERENCES packages(id),
  origin_location_id BIGINT NOT NULL REFERENCES locations(id),
  status VARCHAR(24) NOT NULL DEFAULT 'OPEN' CHECK (status IN ('OPEN','ASSIGNED','RESOLVED')),
  created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX ix_pickup_demands_origin_status ON pickup_demands(origin_location_id,status);
GRANT SELECT, INSERT, UPDATE, DELETE ON pickup_demands TO zpx_runtime;
GRANT USAGE, SELECT ON SEQUENCE pickup_demands_id_seq TO zpx_runtime;
