CREATE TABLE customer_payment_profiles (
 user_id BIGINT PRIMARY KEY REFERENCES users(id),
 merchant_reference VARCHAR(20) NOT NULL UNIQUE,
 provider_profile_id VARCHAR(20) UNIQUE,
 created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
GRANT SELECT,INSERT,UPDATE ON customer_payment_profiles TO zpx_runtime;
