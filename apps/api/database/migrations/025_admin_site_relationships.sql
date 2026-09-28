-- Site commercial metadata is independent of routing locations and locker hardware.
ALTER TABLE installation_sites
 ADD COLUMN host_partner_id BIGINT,
 ADD COLUMN contract_reference VARCHAR(160),
 ADD COLUMN starts_on DATE,
 ADD COLUMN ends_on DATE,
 ADD CONSTRAINT site_host_partner_network_fk FOREIGN KEY(host_partner_id,organization_id) REFERENCES network_partners(id,organization_id),
 ADD CONSTRAINT site_contract_period CHECK(ends_on IS NULL OR starts_on IS NOT NULL AND ends_on>=starts_on);
CREATE INDEX ix_installation_sites_host ON installation_sites(host_partner_id);

CREATE TABLE network_contacts (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 organization_id BIGINT NOT NULL REFERENCES organizations(id),
 display_name VARCHAR(160) NOT NULL,
 role_title VARCHAR(100),
 email_ciphertext TEXT,
 phone_ciphertext TEXT,
 status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE' CHECK(status IN ('ACTIVE','ARCHIVED')),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 UNIQUE(id,organization_id),
 CHECK(email_ciphertext IS NOT NULL OR phone_ciphertext IS NOT NULL)
);
CREATE TABLE site_contact_assignments (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 organization_id BIGINT NOT NULL REFERENCES organizations(id),
 site_id BIGINT NOT NULL,
 contact_id BIGINT NOT NULL,
 role_code VARCHAR(32) NOT NULL CHECK(role_code IN ('PROPERTY_MANAGER','SITE_HOST','SITE_STAFF','SECURITY','ACCESS_ASSISTANCE','EMERGENCY','MAINTENANCE')),
 is_primary BOOLEAN NOT NULL DEFAULT false,
 starts_on DATE NOT NULL DEFAULT CURRENT_DATE,
 ends_on DATE,
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 FOREIGN KEY(site_id,organization_id) REFERENCES installation_sites(id,organization_id),
 FOREIGN KEY(contact_id,organization_id) REFERENCES network_contacts(id,organization_id),
 CHECK(ends_on IS NULL OR ends_on>=starts_on)
);
CREATE UNIQUE INDEX ux_site_primary_contact ON site_contact_assignments(site_id,role_code) WHERE is_primary AND ends_on IS NULL;
CREATE INDEX ix_site_contacts_site ON site_contact_assignments(site_id,id);
GRANT SELECT,INSERT,UPDATE ON network_contacts,site_contact_assignments TO zpx_runtime;
GRANT USAGE,SELECT ON SEQUENCE network_contacts_id_seq,site_contact_assignments_id_seq TO zpx_runtime;
