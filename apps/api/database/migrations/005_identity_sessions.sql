CREATE TABLE auth_rate_limits (
 scope_hash BYTEA NOT NULL CHECK(octet_length(scope_hash)=32),
 bucket BIGINT NOT NULL, hits INT NOT NULL CHECK(hits>0),
 PRIMARY KEY(scope_hash,bucket)
);
GRANT SELECT, INSERT, UPDATE, DELETE ON auth_rate_limits TO zpx_runtime;
ALTER TABLE auth_sessions ADD COLUMN family_id UUID NOT NULL DEFAULT gen_random_uuid(),
 ADD COLUMN refresh_expires_at TIMESTAMPTZ;
CREATE INDEX ix_auth_session_family ON auth_sessions(family_id);
ALTER TABLE verification_challenges ADD COLUMN public_id UUID NOT NULL DEFAULT gen_random_uuid() UNIQUE,
 ADD COLUMN contact_kind VARCHAR(5) CHECK(contact_kind IN ('EMAIL','PHONE')),
 ADD CHECK(attempts >= 0);
CREATE INDEX ix_challenge_expiry ON verification_challenges(expires_at);
ALTER TABLE users ADD CONSTRAINT users_id_org_unique UNIQUE(id,organization_id);
ALTER TABLE locations ADD CONSTRAINT locations_id_org_unique UNIQUE(id,organization_id);
ALTER TABLE scoped_role_grants
 ADD CONSTRAINT grants_user_org_fk FOREIGN KEY(user_id,organization_id) REFERENCES users(id,organization_id),
 ADD CONSTRAINT grants_location_org_fk FOREIGN KEY(location_id,organization_id) REFERENCES locations(id,organization_id),
 ADD CONSTRAINT grants_issuer_org_fk FOREIGN KEY(granted_by,organization_id) REFERENCES users(id,organization_id);
