-- Historical source IDs are evidence, not an operational inventory assignment.
ALTER TABLE cabinet_box ADD CONSTRAINT cabinet_legacy_box_unbound_check
 CHECK (legacy_box_id IS NULL OR compartment_id IS NULL);
ALTER TABLE cabinet_body_model ADD CONSTRAINT cabinet_legacy_body_model_draft_check
 CHECK (legacy_model_id IS NULL OR status='DRAFT');

CREATE FUNCTION protect_legacy_cabinet_reference() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.status='REFERENCE' THEN
  RAISE EXCEPTION 'Historical cabinet references are immutable; reconcile into new operational records'
   USING ERRCODE='42501';
 END IF;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
CREATE TRIGGER tr_protect_legacy_cabinet_reference BEFORE UPDATE OR DELETE ON cabinet
 FOR EACH ROW EXECUTE FUNCTION protect_legacy_cabinet_reference();
