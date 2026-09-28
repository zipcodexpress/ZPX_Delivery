-- Explicit origin routing is required when an organization has more than one hub.
-- Existing one-hub organizations continue to use the sole active hub until mapped.
CREATE TABLE origin_hub_routes (
  origin_location_id BIGINT PRIMARY KEY REFERENCES locations(id),
  hub_id BIGINT NOT NULL REFERENCES hubs(id),
  version INT NOT NULL DEFAULT 1 CHECK (version > 0),
  updated_by BIGINT NOT NULL REFERENCES users(id),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX ix_origin_hub_routes_hub ON origin_hub_routes(hub_id);
ALTER TABLE driver_availability ADD COLUMN location_updated_at TIMESTAMPTZ;
GRANT SELECT, INSERT, UPDATE, DELETE ON origin_hub_routes TO zpx_runtime;
