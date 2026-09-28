-- Preserve historical manifest items when an uncollected parcel is explicitly
-- released from a partly collected inbound run. Receiving excludes RELEASED.
ALTER TABLE manifest_items DROP CONSTRAINT manifest_items_state_check;
ALTER TABLE manifest_items ADD CONSTRAINT manifest_items_state_check
  CHECK (state IN ('EXPECTED','LOADED','UNLOADED','SHORT','RETURNED','RELEASED'));
