-- Draft layout positions can be removed before publication. Ready layouts remain immutable.
CREATE OR REPLACE FUNCTION protect_locker_model_slots() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE body_id BIGINT;
BEGIN
 body_id := CASE WHEN TG_OP='INSERT' THEN NEW.body_model_id ELSE OLD.body_model_id END;
 IF TG_OP='UPDATE' OR (SELECT status FROM locker_body_models WHERE id=body_id)='READY' THEN
  RAISE EXCEPTION 'Ready body layouts are immutable; create a new version' USING ERRCODE='42501';
 END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
GRANT DELETE ON locker_body_model_slots TO zpx_runtime;
