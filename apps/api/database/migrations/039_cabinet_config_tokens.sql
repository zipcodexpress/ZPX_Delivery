-- Terminal452-compatible cabinet config authentication; keep existing device signing.
CREATE TABLE cabinet_access_tokens (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 cabinet_id BIGINT NOT NULL REFERENCES cabinet(cabinet_id),
 device_id BIGINT NOT NULL REFERENCES locker_devices(id),
 token_hash BYTEA NOT NULL UNIQUE,
 credentials_hash BYTEA NOT NULL,
 expires_at TIMESTAMPTZ NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
GRANT SELECT,INSERT,UPDATE,DELETE ON cabinet_access_tokens TO zpx_runtime;
GRANT USAGE,SELECT ON SEQUENCE cabinet_access_tokens_id_seq TO zpx_runtime;
ALTER TABLE cabinet_body DROP CONSTRAINT cabinet_body_protocol_profile_check;
ALTER TABLE cabinet_body ADD CONSTRAINT cabinet_body_protocol_profile_check
 CHECK(protocol_profile IN ('UNVERIFIED','SIMULATED_24','TERMINAL452_V1','TERMINAL452_V2'));
