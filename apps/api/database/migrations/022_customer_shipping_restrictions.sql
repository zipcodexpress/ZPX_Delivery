-- Service-specific restrictions preserve login, tracking and existing parcel custody.
CREATE TABLE customer_shipping_restrictions (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  organization_id BIGINT NOT NULL REFERENCES organizations(id),
  user_id BIGINT NOT NULL,
  reason VARCHAR(500) NOT NULL CHECK (length(btrim(reason)) BETWEEN 10 AND 500),
  created_by BIGINT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  revoked_by BIGINT,
  revoked_at TIMESTAMPTZ,
  revoke_reason VARCHAR(500),
  FOREIGN KEY (user_id,organization_id) REFERENCES users(id,organization_id),
  FOREIGN KEY (created_by,organization_id) REFERENCES users(id,organization_id),
  FOREIGN KEY (revoked_by,organization_id) REFERENCES users(id,organization_id),
  CHECK ((revoked_at IS NULL AND revoked_by IS NULL AND revoke_reason IS NULL)
      OR (revoked_at IS NOT NULL AND revoked_by IS NOT NULL AND length(btrim(revoke_reason)) BETWEEN 10 AND 500))
);
CREATE UNIQUE INDEX ux_customer_shipping_restriction_active
  ON customer_shipping_restrictions(user_id) WHERE revoked_at IS NULL;
CREATE INDEX ix_customer_shipping_restrictions_org_user
  ON customer_shipping_restrictions(organization_id,user_id,created_at DESC);
GRANT SELECT, INSERT, UPDATE ON customer_shipping_restrictions TO zpx_runtime;
GRANT USAGE, SELECT ON SEQUENCE customer_shipping_restrictions_id_seq TO zpx_runtime;

-- Optimistic state changes for driver suspension/reactivation.
ALTER TABLE drivers ADD COLUMN version INT NOT NULL DEFAULT 0 CHECK (version >= 0);
