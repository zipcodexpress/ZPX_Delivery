-- Preserve the exact first-dispatched electrical address for delayed signed telemetry.
ALTER TABLE device_commands ADD COLUMN command_payload JSONB;
