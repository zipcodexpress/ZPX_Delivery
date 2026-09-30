-- Live zipcodexpress MySQL 8.0.12 cabinet schema, inspected 2026-09-29.
-- Keep the existing Delivery model identities/FKs; rename their physical catalog
-- to the legacy names and expose simple writable compatibility views for callers.
DROP VIEW cabinet_body_box;
DROP VIEW cabinet_body_model;
DROP VIEW cabinet_box_model;

ALTER TABLE locker_box_models RENAME TO cabinet_box_model;
ALTER TABLE cabinet_box_model RENAME COLUMN id TO model_id;
ALTER TABLE cabinet_box_model RENAME COLUMN name TO model_name;
ALTER TABLE cabinet_box_model ADD COLUMN legacy_model_id BIGINT UNIQUE,
 ADD COLUMN size_cat VARCHAR(16) CHECK (size_cat IN ('small','middle','large','x-large')),
 ADD COLUMN length NUMERIC(10,2), ADD COLUMN width NUMERIC(10,2),
 ADD COLUMN height NUMERIC(10,2), ADD COLUMN model_price NUMERIC(10,2);
-- Migration metadata backfill is the sole exception to model immutability.
-- The migration runner executes this file transactionally, so failure restores the trigger.
DROP TRIGGER tr_protect_locker_box_model ON cabinet_box_model;
UPDATE cabinet_box_model SET
 size_cat=CASE size_class WHEN 'SMALL' THEN 'small' WHEN 'MEDIUM' THEN 'middle'
  WHEN 'LARGE' THEN 'large' WHEN 'XLARGE' THEN 'x-large' ELSE NULL END,
 length=CASE WHEN dimensions_source_unit='MM' THEN depth_mm ELSE NULL END,
 width=CASE WHEN dimensions_source_unit='MM' THEN width_mm ELSE NULL END,
 height=CASE WHEN dimensions_source_unit='MM' THEN height_mm ELSE NULL END,
 model_price=CASE WHEN legacy_price_raw ~ '^[0-9]+(\.[0-9]{1,2})?$'
  THEN legacy_price_raw::NUMERIC(10,2) ELSE NULL END;
CREATE TRIGGER tr_protect_locker_box_model BEFORE UPDATE OR DELETE ON cabinet_box_model
 FOR EACH ROW EXECUTE FUNCTION protect_locker_box_model();

ALTER TABLE locker_body_models RENAME TO cabinet_body_model;
ALTER TABLE cabinet_body_model RENAME COLUMN id TO model_id;
ALTER TABLE cabinet_body_model RENAME COLUMN name TO model_name;
ALTER TABLE cabinet_body_model ADD COLUMN legacy_model_id BIGINT UNIQUE;

ALTER TABLE locker_body_model_slots RENAME TO cabinet_body_box;
ALTER TABLE cabinet_body_box RENAME COLUMN id TO body_box_id;
ALTER TABLE cabinet_body_box RENAME COLUMN display_row TO "row";
ALTER TABLE cabinet_body_box RENAME COLUMN display_column TO "column";
ALTER TABLE cabinet_body_box RENAME COLUMN door_address TO addr;
ALTER TABLE cabinet_body_box ADD COLUMN legacy_body_box_id BIGINT UNIQUE,
 ADD COLUMN create_time BIGINT;
ALTER TABLE cabinet_body_box DROP CONSTRAINT locker_body_model_slots_door_address_check;
ALTER TABLE cabinet_body_box ADD CONSTRAINT cabinet_body_box_addr_range CHECK (addr BETWEEN 0 AND 255);

-- Trigger functions refer to the renamed physical columns.
CREATE OR REPLACE FUNCTION protect_locker_body_model() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR OLD.status='READY' OR NEW.status<>'READY'
    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
    OR NEW.code IS DISTINCT FROM OLD.code OR NEW.version IS DISTINCT FROM OLD.version
    OR NEW.model_name IS DISTINCT FROM OLD.model_name THEN
  RAISE EXCEPTION 'Ready body models are immutable; create a new version' USING ERRCODE='42501';
 END IF;
 RETURN NEW;
END $$;

CREATE VIEW locker_box_models WITH (security_invoker=true) AS
 SELECT model_id AS id,organization_id,code,version,model_name AS name,
 width_mm,height_mm,depth_mm,max_weight_g,created_at,size_class,is_allocable,
 legacy_size_category,legacy_price_raw,dimensions_source_unit FROM cabinet_box_model;
CREATE VIEW locker_body_models WITH (security_invoker=true) AS
 SELECT model_id AS id,organization_id,code,version,model_name AS name,status,created_at
 FROM cabinet_body_model;
CREATE VIEW locker_body_model_slots WITH (security_invoker=true) AS
 SELECT body_box_id AS id,organization_id,body_model_id,box_model_id,
 "row" AS display_row,"column" AS display_column,created_at,addr AS door_address
 FROM cabinet_body_box;
GRANT SELECT,INSERT,UPDATE,DELETE ON locker_box_models,locker_body_models,locker_body_model_slots TO zpx_runtime;

-- The source permits zero-based controller/door addresses and body sequence.
-- 0 is configuration metadata, never proof that a physical channel is safe.
ALTER TABLE cabinet_body DROP CONSTRAINT cabinet_body_display_sequence_check;
ALTER TABLE cabinet_body ADD CONSTRAINT cabinet_body_display_sequence_check
 CHECK (display_sequence BETWEEN 0 AND 1000);
ALTER TABLE cabinet_body DROP CONSTRAINT cabinet_body_addr_check;
ALTER TABLE cabinet_body ADD CONSTRAINT cabinet_body_addr_check CHECK (addr BETWEEN 0 AND 255);
ALTER TABLE cabinet_body ALTER COLUMN body_name TYPE VARCHAR(512);
ALTER TABLE cabinet_body ADD COLUMN legacy_body_id BIGINT UNIQUE;

ALTER TABLE cabinet_box DROP CONSTRAINT cabinet_box_addr_check;
ALTER TABLE cabinet_box ADD CONSTRAINT cabinet_box_addr_check CHECK (addr BETWEEN 0 AND 255);
ALTER TABLE cabinet_box DROP CONSTRAINT cabinet_box_status_check;
ALTER TABLE cabinet_box ADD CONSTRAINT cabinet_box_status_check CHECK (status IN (0,1,3));
ALTER TABLE cabinet_box ADD COLUMN legacy_box_id BIGINT UNIQUE;

ALTER TABLE cabinet ADD COLUMN legacy_cabinet_id BIGINT UNIQUE,
 ADD COLUMN state VARCHAR(64),ADD COLUMN city VARCHAR(64),
 ADD COLUMN address VARCHAR(256),ADD COLUMN zipcode VARCHAR(16),
 ADD COLUMN latitude NUMERIC(10,2),ADD COLUMN longitude NUMERIC(10,2),
 ADD COLUMN api_key VARCHAR(64),ADD COLUMN api_secret VARCHAR(64),
 ADD COLUMN service_type VARCHAR(16) CHECK (service_type IN ('ziplocker','zippora','store','share','asset')),
 ADD COLUMN create_time BIGINT,ADD COLUMN address_url TEXT;

COMMENT ON TABLE cabinet_box_model IS 'Canonical box model catalog; legacy numeric dimensions retain provenance and are not assumed to be millimeters.';
COMMENT ON TABLE cabinet_body_model IS 'Canonical body model catalog. READY layouts are immutable.';
COMMENT ON TABLE cabinet_body_box IS 'Canonical body-box layout; row, column and door addr are independent.';
COMMENT ON COLUMN cabinet.api_secret IS 'Legacy API secret field. Never copy a live secret into local development.';
