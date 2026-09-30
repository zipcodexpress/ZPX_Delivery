-- A copied ZipcodeXpress cabinet is historical reference, never a bindable setup.
ALTER TABLE cabinet DROP CONSTRAINT cabinet_status_check;
ALTER TABLE cabinet ADD CONSTRAINT cabinet_status_check
 CHECK (status IN ('DRAFT','BOUND','REFERENCE'));
ALTER TABLE cabinet DROP CONSTRAINT cabinet_check;
ALTER TABLE cabinet ADD CONSTRAINT cabinet_check CHECK (
 (status IN ('DRAFT','REFERENCE') AND bound_location_id IS NULL AND bound_locker_id IS NULL
  AND bind_key IS NULL AND bind_hash IS NULL)
 OR (status='BOUND' AND bound_location_id IS NOT NULL AND bound_locker_id IS NOT NULL
  AND bind_key IS NOT NULL AND bind_hash IS NOT NULL));
ALTER TABLE cabinet ADD CONSTRAINT cabinet_reference_source_check
 CHECK (status <> 'REFERENCE' OR legacy_cabinet_id IS NOT NULL);

-- The MySQL dimensions are raw numbers with unverified units; do not invent mm or weight.
ALTER TABLE cabinet_box_model ALTER COLUMN width_mm DROP NOT NULL;
ALTER TABLE cabinet_box_model ALTER COLUMN height_mm DROP NOT NULL;
ALTER TABLE cabinet_box_model ALTER COLUMN depth_mm DROP NOT NULL;
ALTER TABLE cabinet_box_model ALTER COLUMN max_weight_g DROP NOT NULL;
ALTER TABLE cabinet_box_model ADD CONSTRAINT cabinet_box_model_verified_dimensions_check
 CHECK (dimensions_source_unit <> 'MM' OR
  (width_mm IS NOT NULL AND height_mm IS NOT NULL AND depth_mm IS NOT NULL
   AND max_weight_g IS NOT NULL));

COMMENT ON COLUMN cabinet.legacy_cabinet_id IS 'Source MySQL cabinet ID; REFERENCE rows are never bound to Delivery inventory.';
COMMENT ON COLUMN cabinet_box.legacy_box_id IS 'Source MySQL box ID; imported boxes have no operational compartment.';
