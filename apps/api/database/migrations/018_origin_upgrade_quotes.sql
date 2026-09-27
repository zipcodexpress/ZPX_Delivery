-- Keep the original paid rate-card version and measured parcel size on each upgrade quote.
ALTER TABLE pricing_quotes
  ADD COLUMN purpose VARCHAR(24) NOT NULL DEFAULT 'SHIPMENT',
  ADD COLUMN target_size_class VARCHAR(8),
  ADD COLUMN measured_width_mm INT,
  ADD COLUMN measured_height_mm INT,
  ADD COLUMN measured_depth_mm INT,
  ADD COLUMN measured_weight_g INT,
  ADD CHECK (purpose IN ('SHIPMENT','ORIGIN_UPGRADE')),
  ADD CHECK (
    (purpose='SHIPMENT' AND target_size_class IS NULL AND measured_width_mm IS NULL AND measured_height_mm IS NULL AND measured_depth_mm IS NULL AND measured_weight_g IS NULL)
    OR (purpose='ORIGIN_UPGRADE' AND target_size_class IN ('MEDIUM','LARGE') AND measured_width_mm>0 AND measured_height_mm>0 AND measured_depth_mm>0 AND measured_weight_g>0)
  );
