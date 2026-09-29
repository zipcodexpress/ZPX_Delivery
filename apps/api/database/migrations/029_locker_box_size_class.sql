-- Existing model dimensions do not prove a commercial size class; classify them explicitly later.
ALTER TABLE locker_box_models ADD COLUMN size_class VARCHAR(16) NOT NULL DEFAULT 'UNCLASSIFIED'
 CHECK (size_class IN ('UNCLASSIFIED','SMALL','MEDIUM','LARGE','XLARGE'));
