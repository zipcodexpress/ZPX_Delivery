-- Administrative site records do not replace routing locations or change existing custody references.
CREATE TABLE installation_sites (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 organization_id BIGINT NOT NULL REFERENCES organizations(id),
 code VARCHAR(40) NOT NULL,
 name VARCHAR(160) NOT NULL,
 site_type VARCHAR(32) NOT NULL CHECK(site_type IN ('APARTMENT','CONVENIENCE_STORE','SHOPPING_MALL','SCHOOL','COMPANY_BUILDING','HUB','OTHER')),
 address JSONB NOT NULL,
 timezone VARCHAR(64) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'DRAFT' CHECK(status IN ('DRAFT','ACTIVE','SUSPENDED','ARCHIVED')),
 overdue_grace_days INT CHECK(overdue_grace_days BETWEEN 0 AND 365),
 overdue_daily_cents INT CHECK(overdue_daily_cents BETWEEN 0 AND 1000000),
 overdue_cap_cents INT CHECK(overdue_cap_cents BETWEEN 0 AND 100000000),
 version INT NOT NULL DEFAULT 0 CHECK(version >= 0),
 created_by BIGINT,
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 UNIQUE(organization_id,code), UNIQUE(id,organization_id),
 FOREIGN KEY(created_by,organization_id) REFERENCES users(id,organization_id),
 CHECK((overdue_grace_days IS NULL AND overdue_daily_cents IS NULL AND overdue_cap_cents IS NULL)
    OR (overdue_grace_days IS NOT NULL AND overdue_daily_cents IS NOT NULL AND overdue_cap_cents IS NOT NULL))
);
ALTER TABLE locations ADD COLUMN site_id BIGINT;
ALTER TABLE locations ADD COLUMN overdue_grace_days INT CHECK(overdue_grace_days BETWEEN 0 AND 365),
 ADD COLUMN overdue_daily_cents INT CHECK(overdue_daily_cents BETWEEN 0 AND 1000000),
 ADD COLUMN overdue_cap_cents INT CHECK(overdue_cap_cents BETWEEN 0 AND 100000000),
 ADD CONSTRAINT location_overdue_complete CHECK((overdue_grace_days IS NULL AND overdue_daily_cents IS NULL AND overdue_cap_cents IS NULL)
    OR (overdue_grace_days IS NOT NULL AND overdue_daily_cents IS NOT NULL AND overdue_cap_cents IS NOT NULL));
ALTER TABLE locations ADD CONSTRAINT locations_site_org_fk FOREIGN KEY(site_id,organization_id) REFERENCES installation_sites(id,organization_id);
CREATE INDEX ix_locations_site ON locations(site_id);

-- Backfill one draft site per existing location. Existing location IDs, status and routing remain unchanged.
DO $$ DECLARE loc RECORD; new_site BIGINT; BEGIN
 FOR loc IN SELECT id,organization_id,code,name,kind,address_text,timezone FROM locations ORDER BY id LOOP
  INSERT INTO installation_sites(organization_id,code,name,site_type,address,timezone)
   VALUES(loc.organization_id,'LEGACY-'||loc.id,loc.name,
     CASE WHEN loc.kind='HUB' THEN 'HUB' ELSE 'OTHER' END,
     jsonb_build_object('line1',loc.address_text,'city','','region','','postal_code','','country_code','US'),loc.timezone)
   RETURNING id INTO new_site;
  UPDATE locations SET site_id=new_site WHERE id=loc.id;
 END LOOP;
END $$;

-- Inventory labels are distinct from live controller mappings and ownership manifests.
CREATE TABLE locker_body_modules (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 locker_id BIGINT NOT NULL REFERENCES lockers(id),
 code VARCHAR(40) NOT NULL,
 display_sequence INT NOT NULL CHECK(display_sequence > 0),
 status VARCHAR(16) NOT NULL DEFAULT 'DRAFT' CHECK(status IN ('DRAFT','ACTIVE','INACTIVE')),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 UNIQUE(locker_id,code), UNIQUE(locker_id,display_sequence), UNIQUE(id,locker_id)
);
CREATE TABLE locker_box_modules (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 locker_id BIGINT NOT NULL,
 body_module_id BIGINT NOT NULL,
 code VARCHAR(40) NOT NULL,
 display_sequence INT NOT NULL CHECK(display_sequence > 0),
 status VARCHAR(16) NOT NULL DEFAULT 'DRAFT' CHECK(status IN ('DRAFT','ACTIVE','INACTIVE')),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 FOREIGN KEY(body_module_id,locker_id) REFERENCES locker_body_modules(id,locker_id),
 UNIQUE(locker_id,code), UNIQUE(body_module_id,display_sequence), UNIQUE(id,locker_id)
);
ALTER TABLE compartments ADD COLUMN box_module_id BIGINT;
ALTER TABLE compartments ADD CONSTRAINT compartments_box_module_locker_fk
 FOREIGN KEY(box_module_id,locker_id) REFERENCES locker_box_modules(id,locker_id);
CREATE INDEX ix_compartments_box_module ON compartments(box_module_id);

-- Existing encrypted address rows remain valid; labels and archiving support an account address book.
ALTER TABLE user_addresses ADD COLUMN label VARCHAR(80), ADD COLUMN archived_at TIMESTAMPTZ;
CREATE INDEX ix_user_addresses_active ON user_addresses(user_id,id) WHERE archived_at IS NULL;

GRANT SELECT,INSERT,UPDATE ON installation_sites,locker_body_modules,locker_box_modules TO zpx_runtime;
GRANT USAGE,SELECT ON SEQUENCE installation_sites_id_seq,locker_body_modules_id_seq,locker_box_modules_id_seq TO zpx_runtime;
