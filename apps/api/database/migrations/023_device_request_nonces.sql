-- Retain signed device request nonces long enough to reject replay across restarts.
CREATE TABLE device_request_nonces (
  device_id BIGINT NOT NULL REFERENCES locker_devices(id),
  nonce UUID NOT NULL,
  observed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY(device_id,nonce)
);
CREATE INDEX ix_device_request_nonces_observed ON device_request_nonces(observed_at);
GRANT SELECT,INSERT,DELETE ON device_request_nonces TO zpx_runtime;
