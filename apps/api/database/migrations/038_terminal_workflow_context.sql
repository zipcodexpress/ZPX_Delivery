-- Preserve assigned run/stop and one-use pickup-grant context through a door cycle.
ALTER TABLE locker_sessions ADD COLUMN workflow_context JSONB NOT NULL DEFAULT '{}';
