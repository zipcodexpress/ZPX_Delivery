-- Driver opt-in and short-lived inbound offers. A demand remains one package so
-- its assignment and custody can be reconciled independently.
ALTER TABLE pickup_demands
  ADD COLUMN assigned_run_id BIGINT REFERENCES route_runs(id),
  ADD COLUMN pickup_deadline TIMESTAMPTZ NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL '24 hours'),
  ADD COLUMN version INT NOT NULL DEFAULT 1;
CREATE INDEX ix_pickup_demands_deadline ON pickup_demands(status,pickup_deadline);

CREATE TABLE driver_availability (
  driver_id BIGINT PRIMARY KEY REFERENCES drivers(id),
  status VARCHAR(16) NOT NULL CHECK (status IN ('AVAILABLE','OFFLINE')),
  latitude DECIMAL(10,7), longitude DECIMAL(10,7),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK ((latitude IS NULL AND longitude IS NULL) OR (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180))
);
CREATE TABLE driver_offers (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  driver_id BIGINT NOT NULL REFERENCES drivers(id),
  origin_location_id BIGINT NOT NULL REFERENCES locations(id),
  hub_id BIGINT NOT NULL REFERENCES hubs(id),
  status VARCHAR(16) NOT NULL CHECK (status IN ('OFFERED','ACCEPTED','EXPIRED','CANCELLED')),
  offered_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMPTZ NOT NULL,
  accepted_run_id BIGINT REFERENCES route_runs(id),
  request_key VARCHAR(100),
  CHECK (expires_at > offered_at)
);
CREATE INDEX ix_driver_offers_driver ON driver_offers(driver_id,status,expires_at);
CREATE TABLE driver_offer_items (
  offer_id BIGINT NOT NULL REFERENCES driver_offers(id),
  demand_id BIGINT NOT NULL REFERENCES pickup_demands(id),
  demand_version INT NOT NULL,
  PRIMARY KEY (offer_id,demand_id)
);
CREATE TABLE inbound_offer_runs (
  run_id BIGINT PRIMARY KEY REFERENCES route_runs(id)
);
GRANT SELECT, INSERT, UPDATE, DELETE ON driver_availability, driver_offers, driver_offer_items, inbound_offer_runs TO zpx_runtime;
GRANT USAGE, SELECT ON SEQUENCE driver_offers_id_seq TO zpx_runtime;
