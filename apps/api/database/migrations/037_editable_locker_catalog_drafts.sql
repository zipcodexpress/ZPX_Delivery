-- Allow audited correction of local catalog drafts while keeping historical and
-- published/installed model definitions immutable.
ALTER TABLE cabinet_box_model ADD COLUMN revision INT NOT NULL DEFAULT 1 CHECK (revision > 0);
ALTER TABLE cabinet_body_model ADD COLUMN revision INT NOT NULL DEFAULT 1 CHECK (revision > 0);

CREATE OR REPLACE FUNCTION protect_locker_box_model() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR OLD.legacy_model_id IS NOT NULL
    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
    OR NEW.code IS DISTINCT FROM OLD.code OR NEW.version IS DISTINCT FROM OLD.version
    OR NEW.legacy_model_id IS DISTINCT FROM OLD.legacy_model_id
    OR EXISTS (SELECT 1 FROM cabinet_body_box s JOIN cabinet_body_model b ON b.model_id=s.body_model_id
               WHERE s.box_model_id=OLD.model_id AND (b.status='READY' OR b.legacy_model_id IS NOT NULL))
    OR EXISTS (SELECT 1 FROM cabinet_box WHERE box_model_id=OLD.model_id)
    OR EXISTS (SELECT 1 FROM compartments WHERE box_model_id=OLD.model_id) THEN
  RAISE EXCEPTION 'Historical, published or installed box models are immutable; create an editable copy'
   USING ERRCODE='42501';
 END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION protect_locker_body_model() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR OLD.status='READY' OR OLD.legacy_model_id IS NOT NULL
    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
    OR NEW.code IS DISTINCT FROM OLD.code OR NEW.version IS DISTINCT FROM OLD.version
    OR NEW.legacy_model_id IS DISTINCT FROM OLD.legacy_model_id
    OR (NEW.status='READY' AND NEW.model_name IS DISTINCT FROM OLD.model_name)
    OR NEW.status NOT IN ('DRAFT','READY') THEN
  RAISE EXCEPTION 'Historical or ready body models are immutable; create an editable copy'
   USING ERRCODE='42501';
 END IF;
 RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION protect_locker_model_slots() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE body_id BIGINT;
BEGIN
 body_id := CASE WHEN TG_OP='INSERT' THEN NEW.body_model_id ELSE OLD.body_model_id END;
 IF EXISTS (SELECT 1 FROM cabinet_body_model WHERE model_id=body_id AND status='READY')
    OR (TG_OP='INSERT' AND EXISTS (SELECT 1 FROM cabinet_body_model WHERE model_id=body_id
        AND legacy_model_id IS NOT NULL) AND NEW.legacy_body_box_id IS NULL)
    OR (TG_OP<>'INSERT' AND EXISTS (SELECT 1 FROM cabinet_body_model WHERE model_id=body_id
        AND legacy_model_id IS NOT NULL)) THEN
  RAISE EXCEPTION 'Historical or ready body layouts are immutable; create an editable copy' USING ERRCODE='42501';
 END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 IF TG_OP='UPDATE' AND (NEW.body_model_id IS DISTINCT FROM OLD.body_model_id
  OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
  OR NEW.legacy_body_box_id IS DISTINCT FROM OLD.legacy_body_box_id) THEN
  RAISE EXCEPTION 'Slot ownership cannot change' USING ERRCODE='42501';
 END IF;
 RETURN NEW;
END $$;

GRANT UPDATE ON cabinet_box_model TO zpx_runtime;
