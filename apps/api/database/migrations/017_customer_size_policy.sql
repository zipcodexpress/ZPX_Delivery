-- Existing parcels remain nullable; new customer shipments declare a size class.
ALTER TABLE packages ADD COLUMN size_class VARCHAR(8)
  CHECK (size_class IN ('SMALL','MEDIUM','LARGE'));
