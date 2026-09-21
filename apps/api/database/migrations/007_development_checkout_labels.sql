-- Local test provider is explicitly distinguished from live financial activity.
ALTER TABLE shipments ADD COLUMN development_only BOOLEAN NOT NULL DEFAULT false;
ALTER TABLE payments ADD COLUMN quote_id BIGINT REFERENCES pricing_quotes(id);
ALTER TABLE package_labels ADD COLUMN token_ciphertext TEXT,
 ADD COLUMN expires_at TIMESTAMPTZ;
CREATE UNIQUE INDEX ux_pending_shipment_payment ON payments(shipment_id) WHERE status='PENDING';
