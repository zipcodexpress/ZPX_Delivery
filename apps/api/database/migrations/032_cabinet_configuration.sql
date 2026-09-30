-- ZipcodeXpress cabinet/body/box configuration shape. These rows are configuration
-- until final location binding; compartments remains Delivery's custody inventory.
CREATE TABLE cabinet (
 cabinet_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 organization_id BIGINT NOT NULL REFERENCES organizations(id),
 cabinet_name VARCHAR(120) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'DRAFT' CHECK(status IN ('DRAFT','BOUND')),
 version INT NOT NULL DEFAULT 1,
 bound_location_id BIGINT REFERENCES locations(id),
 bound_locker_id BIGINT REFERENCES lockers(id),
 bind_key VARCHAR(100),
 bind_hash CHAR(64),
 origin_setup_id BIGINT UNIQUE REFERENCES locker_setup_drafts(id),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 CHECK ((status='DRAFT' AND bound_location_id IS NULL AND bound_locker_id IS NULL AND bind_key IS NULL AND bind_hash IS NULL)
  OR (status='BOUND' AND bound_location_id IS NOT NULL AND bound_locker_id IS NOT NULL AND bind_key IS NOT NULL AND bind_hash IS NOT NULL))
);
CREATE TABLE cabinet_body (
 body_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 cabinet_id BIGINT NOT NULL REFERENCES cabinet(cabinet_id),
 body_model_id BIGINT NOT NULL REFERENCES locker_body_models(id),
 body_name VARCHAR(120) NOT NULL,
 direction VARCHAR(16),
 sequence VARCHAR(16) NOT NULL,
 display_sequence INT NOT NULL CHECK(display_sequence BETWEEN 1 AND 1000),
 addr INT CHECK(addr BETWEEN 1 AND 255),
 protocol_profile VARCHAR(40) NOT NULL DEFAULT 'UNVERIFIED'
  CHECK(protocol_profile IN ('UNVERIFIED','SIMULATED_24')),
 origin_setup_body_code VARCHAR(30),
 UNIQUE(cabinet_id,display_sequence), UNIQUE(cabinet_id,addr), UNIQUE(body_id,cabinet_id)
);
CREATE TABLE cabinet_box (
 box_id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 cabinet_id BIGINT NOT NULL,
 body_id BIGINT NOT NULL,
 box_model_id BIGINT NOT NULL REFERENCES locker_box_models(id),
 "row" INT NOT NULL CHECK("row" BETWEEN 1 AND 1000),
 "column" INT NOT NULL CHECK("column" BETWEEN 1 AND 1000),
 addr INT CHECK(addr BETWEEN 1 AND 255),
 compartment_id BIGINT UNIQUE REFERENCES compartments(id),
 -- Legacy operational flags are unknown until a verified commissioning path exists.
 status SMALLINT CHECK(status IN (0,1)),
 blocked SMALLINT CHECK(blocked IN (0,1)),
 create_time TIMESTAMPTZ NOT NULL DEFAULT now(),
 update_time TIMESTAMPTZ,
 FOREIGN KEY(body_id,cabinet_id) REFERENCES cabinet_body(body_id,cabinet_id),
 UNIQUE(body_id,"row","column"), UNIQUE(body_id,addr)
);

-- Preserve any setup data already made through migration 031; do not drop it.
INSERT INTO cabinet(organization_id,cabinet_name,status,version,bound_location_id,bound_locker_id,
 bind_key,bind_hash,origin_setup_id,created_at)
 SELECT organization_id,name,status,version,bound_location_id,bound_locker_id,
 bind_key,bind_hash,id,created_at FROM locker_setup_drafts;
INSERT INTO cabinet_body(cabinet_id,body_model_id,body_name,sequence,display_sequence,addr,
 protocol_profile,origin_setup_body_code)
 SELECT c.cabinet_id,b.body_model_id,b.name,b.display_sequence::text,b.display_sequence,
 b.controller_address,b.protocol_profile,b.code
 FROM locker_setup_bodies b JOIN cabinet c ON c.origin_setup_id=b.draft_id;
INSERT INTO cabinet_box(cabinet_id,body_id,box_model_id,"row","column",addr,compartment_id)
 SELECT b.cabinet_id,b.body_id,s.box_model_id,s.display_row,s.display_column,s.door_address,
  cp.id
 FROM cabinet_body b JOIN locker_body_model_slots s ON s.body_model_id=b.body_model_id
 JOIN cabinet c ON c.cabinet_id=b.cabinet_id
 LEFT JOIN compartments cp ON cp.locker_id=c.bound_locker_id
  AND cp.code=b.origin_setup_body_code||'-'||s.display_row||'-'||s.display_column;

-- Existing bound setups must map every staged box to the operational compartment.
DO $$ BEGIN
 IF EXISTS (SELECT 1 FROM cabinet_box x JOIN cabinet c ON c.cabinet_id=x.cabinet_id
            WHERE c.status='BOUND' AND x.compartment_id IS NULL) THEN
  RAISE EXCEPTION 'A bound legacy setup has an unmapped cabinet box';
 END IF;
END $$;

COMMENT ON TABLE locker_setup_drafts IS 'Historical setup drafts migrated to cabinet; no new writes.';
COMMENT ON TABLE locker_setup_bodies IS 'Historical setup bodies migrated to cabinet_body; no new writes.';
GRANT SELECT,INSERT,UPDATE ON cabinet TO zpx_runtime;
GRANT SELECT,INSERT,UPDATE,DELETE ON cabinet_box TO zpx_runtime;
GRANT SELECT,INSERT,DELETE ON cabinet_body TO zpx_runtime;
GRANT USAGE,SELECT ON SEQUENCE cabinet_cabinet_id_seq,cabinet_body_body_id_seq,cabinet_box_box_id_seq TO zpx_runtime;

-- Readable legacy names over the established model catalog; no second catalog.
CREATE VIEW cabinet_box_model AS SELECT id AS model_id,name AS model_name,
 CASE size_class WHEN 'SMALL' THEN 'small' WHEN 'MEDIUM' THEN 'middle'
 WHEN 'LARGE' THEN 'large' ELSE 'x-large' END AS size_cat,
 depth_mm AS length,width_mm AS width,height_mm AS height,is_allocable,
 legacy_price_raw AS model_price FROM locker_box_models;
CREATE VIEW cabinet_body_model AS SELECT id AS model_id,name AS model_name FROM locker_body_models;
CREATE VIEW cabinet_body_box AS SELECT id AS body_box_id,body_model_id,box_model_id,
 display_row AS "row",display_column AS "column",door_address AS addr
 FROM locker_body_model_slots;
GRANT SELECT ON cabinet_box_model,cabinet_body_model,cabinet_body_box TO zpx_runtime;
