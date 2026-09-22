-- Hub receiving indexes and session constraints.
ALTER TABLE receiving_sessions ADD CHECK(status IN ('OPEN','CLOSED'));
ALTER TABLE receiving_items ADD CHECK(disposition IN ('RECEIVED','SHORT','DAMAGED','EXTRA'));
CREATE INDEX ix_receiving_sessions_hub ON receiving_sessions(hub_id, status);
CREATE INDEX ix_receiving_items_session ON receiving_items(session_id);
