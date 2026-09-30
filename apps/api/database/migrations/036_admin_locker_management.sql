-- Optimistic version for administrative edits to an uncommissioned locker.
ALTER TABLE lockers ADD COLUMN version INT NOT NULL DEFAULT 1 CHECK (version > 0);
