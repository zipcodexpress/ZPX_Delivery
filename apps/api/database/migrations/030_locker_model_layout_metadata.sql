-- Model metadata and draft electrical positions. These do not commission a device.
ALTER TABLE locker_box_models
 ADD COLUMN is_allocable BOOLEAN NOT NULL DEFAULT FALSE,
 ADD COLUMN legacy_size_category VARCHAR(80),
 ADD COLUMN legacy_price_raw VARCHAR(80),
 ADD COLUMN dimensions_source_unit VARCHAR(16) NOT NULL DEFAULT 'UNVERIFIED'
  CHECK (dimensions_source_unit IN ('MM','UNVERIFIED'));

ALTER TABLE locker_body_model_slots
 ADD COLUMN door_address INT CHECK (door_address BETWEEN 1 AND 255);
CREATE UNIQUE INDEX locker_body_model_slots_door_unique
 ON locker_body_model_slots(body_model_id,door_address) WHERE door_address IS NOT NULL;

-- The original slot trigger deliberately rejected all UPDATEs. Draft correction now
-- allows UPDATE, while READY layouts and changes of parent/organization remain blocked.
CREATE OR REPLACE FUNCTION protect_locker_model_slots() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE body_id BIGINT;
BEGIN
 body_id := CASE WHEN TG_OP='INSERT' THEN NEW.body_model_id ELSE OLD.body_model_id END;
 IF (SELECT status FROM locker_body_models WHERE id=body_id)='READY' THEN
  RAISE EXCEPTION 'Ready body layouts are immutable; create a new version' USING ERRCODE='42501';
 END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 IF TG_OP='UPDATE' AND (NEW.body_model_id IS DISTINCT FROM OLD.body_model_id
  OR NEW.organization_id IS DISTINCT FROM OLD.organization_id) THEN
  RAISE EXCEPTION 'Slot ownership cannot change' USING ERRCODE='42501';
 END IF;
 RETURN NEW;
END $$;
GRANT UPDATE ON locker_body_model_slots TO zpx_runtime;
