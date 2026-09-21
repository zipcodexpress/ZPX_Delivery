ALTER TABLE payments ADD COLUMN hosted_token_ciphertext TEXT,
 ADD COLUMN hosted_token_expires_at TIMESTAMPTZ,
 ADD COLUMN provider_transaction_id VARCHAR(80);
CREATE UNIQUE INDEX ux_payment_provider_transaction ON payments(provider,provider_transaction_id) WHERE provider_transaction_id IS NOT NULL;
ALTER TABLE outbox_events ADD COLUMN delivery_status VARCHAR(24) NOT NULL DEFAULT 'PENDING',
 ADD COLUMN locked_until TIMESTAMPTZ,
 ADD COLUMN next_attempt_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 ADD COLUMN last_error_code VARCHAR(64);
CREATE INDEX ix_outbox_delivery_ready ON outbox_events(delivery_status,next_attempt_at) WHERE published_at IS NULL;
