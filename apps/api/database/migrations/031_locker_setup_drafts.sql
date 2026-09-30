-- Unbound configuration drafts, not a second operational locker inventory.
CREATE TABLE locker_setup_drafts (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 organization_id BIGINT NOT NULL REFERENCES organizations(id),
 code VARCHAR(40) NOT NULL,
 name VARCHAR(120) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'DRAFT' CHECK(status IN ('DRAFT','BOUND')),
 version INT NOT NULL DEFAULT 1,
 bound_location_id BIGINT REFERENCES locations(id),
 bound_locker_id BIGINT REFERENCES lockers(id),
 bind_key VARCHAR(100),
 bind_hash CHAR(64),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 UNIQUE(organization_id,code),
 CHECK ((status='DRAFT' AND bound_location_id IS NULL AND bound_locker_id IS NULL AND bind_key IS NULL AND bind_hash IS NULL)
  OR (status='BOUND' AND bound_location_id IS NOT NULL AND bound_locker_id IS NOT NULL AND bind_key IS NOT NULL AND bind_hash IS NOT NULL))
);
CREATE TABLE locker_setup_bodies (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 draft_id BIGINT NOT NULL REFERENCES locker_setup_drafts(id),
 body_model_id BIGINT NOT NULL REFERENCES locker_body_models(id),
 code VARCHAR(30) NOT NULL,
 name VARCHAR(120) NOT NULL,
 display_sequence INT NOT NULL CHECK(display_sequence BETWEEN 1 AND 1000),
 controller_address INT NOT NULL CHECK(controller_address BETWEEN 1 AND 255),
 protocol_profile VARCHAR(40) NOT NULL DEFAULT 'UNVERIFIED'
  CHECK(protocol_profile IN ('UNVERIFIED','SIMULATED_24')),
 UNIQUE(draft_id,code), UNIQUE(draft_id,display_sequence), UNIQUE(draft_id,controller_address)
);
ALTER TABLE locker_body_modules ADD COLUMN display_name VARCHAR(120),
 ADD COLUMN controller_board_id BIGINT REFERENCES controller_boards(id);
CREATE UNIQUE INDEX locker_body_modules_controller_unique ON locker_body_modules(controller_board_id)
 WHERE controller_board_id IS NOT NULL;
GRANT SELECT,INSERT,UPDATE ON locker_setup_drafts TO zpx_runtime;
GRANT SELECT,INSERT,DELETE ON locker_setup_bodies TO zpx_runtime;
GRANT USAGE,SELECT ON SEQUENCE locker_setup_drafts_id_seq,locker_setup_bodies_id_seq TO zpx_runtime;
