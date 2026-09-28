-- Partner identities are a registry only. No row confers parcel, site, finance, or admin access.
CREATE TABLE network_partners (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  organization_id BIGINT NOT NULL REFERENCES organizations(id),
  code VARCHAR(40) NOT NULL CHECK (code ~ '^[A-Z][A-Z0-9-]{2,39}$'),
  display_name VARCHAR(160) NOT NULL CHECK (length(btrim(display_name)) BETWEEN 2 AND 160),
  legal_name VARCHAR(160) CHECK (legal_name IS NULL OR length(btrim(legal_name)) BETWEEN 2 AND 160),
  kind VARCHAR(16) NOT NULL CHECK (kind IN ('INTERNAL','EXTERNAL')),
  status VARCHAR(16) NOT NULL CHECK (status IN ('DRAFT','ACTIVE','SUSPENDED','ARCHIVED')),
  version INT NOT NULL DEFAULT 0 CHECK (version >= 0),
  created_by BIGINT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (organization_id,code), UNIQUE (id,organization_id),
  FOREIGN KEY (created_by,organization_id) REFERENCES users(id,organization_id)
);
CREATE UNIQUE INDEX ux_network_internal_partner ON network_partners(organization_id) WHERE kind='INTERNAL';

CREATE TABLE network_partner_roles (
  partner_id BIGINT NOT NULL,
  organization_id BIGINT NOT NULL,
  role_code VARCHAR(24) NOT NULL CHECK (role_code IN ('HOST','CARRIER','LOCKER_OWNER','LOCKER_OPERATOR','HUB_OPERATOR')),
  created_by BIGINT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (partner_id,role_code),
  FOREIGN KEY (partner_id,organization_id) REFERENCES network_partners(id,organization_id),
  FOREIGN KEY (created_by,organization_id) REFERENCES users(id,organization_id)
);

-- Establish the internal registry identity for existing networks without assigning assets or privileges.
INSERT INTO network_partners(organization_id,code,display_name,kind,status)
  SELECT id,'ZPX-INTERNAL','Internal operations','INTERNAL','ACTIVE' FROM organizations;

GRANT SELECT, INSERT, UPDATE ON network_partners TO zpx_runtime;
GRANT USAGE, SELECT ON SEQUENCE network_partners_id_seq TO zpx_runtime;
GRANT SELECT, INSERT ON network_partner_roles TO zpx_runtime;
