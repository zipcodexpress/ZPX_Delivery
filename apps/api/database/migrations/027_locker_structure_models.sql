-- Network-scoped, immutable size models and versioned body layouts. A model describes inventory,
-- never controller addressing, delivery ownership, commissioning or physical custody.
CREATE TABLE locker_box_models (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 organization_id BIGINT NOT NULL REFERENCES organizations(id),
 code VARCHAR(40) NOT NULL,
 version INT NOT NULL CHECK(version > 0),
 name VARCHAR(120) NOT NULL,
 width_mm INT NOT NULL CHECK(width_mm > 0),
 height_mm INT NOT NULL CHECK(height_mm > 0),
 depth_mm INT NOT NULL CHECK(depth_mm > 0),
 max_weight_g INT NOT NULL CHECK(max_weight_g > 0),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 UNIQUE(organization_id,code,version), UNIQUE(id,organization_id)
);

CREATE TABLE locker_body_models (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 organization_id BIGINT NOT NULL REFERENCES organizations(id),
 code VARCHAR(40) NOT NULL,
 version INT NOT NULL CHECK(version > 0),
 name VARCHAR(120) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'DRAFT' CHECK(status IN ('DRAFT','READY')),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 UNIQUE(organization_id,code,version), UNIQUE(id,organization_id)
);

CREATE TABLE locker_body_model_slots (
 id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 organization_id BIGINT NOT NULL REFERENCES organizations(id),
 body_model_id BIGINT NOT NULL,
 box_model_id BIGINT NOT NULL,
 display_row INT NOT NULL CHECK(display_row BETWEEN 1 AND 1000),
 display_column INT NOT NULL CHECK(display_column BETWEEN 1 AND 1000),
 created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
 FOREIGN KEY(body_model_id,organization_id) REFERENCES locker_body_models(id,organization_id),
 FOREIGN KEY(box_model_id,organization_id) REFERENCES locker_box_models(id,organization_id),
 UNIQUE(body_model_id,display_row,display_column)
);

ALTER TABLE locker_body_modules ADD COLUMN body_model_id BIGINT REFERENCES locker_body_models(id);
ALTER TABLE compartments ADD COLUMN box_model_id BIGINT REFERENCES locker_box_models(id);

CREATE FUNCTION protect_locker_body_model() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR OLD.status='READY' OR NEW.status<>'READY'
    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id
    OR NEW.code IS DISTINCT FROM OLD.code OR NEW.version IS DISTINCT FROM OLD.version
    OR NEW.name IS DISTINCT FROM OLD.name THEN
  RAISE EXCEPTION 'Ready body models are immutable; create a new version' USING ERRCODE='42501';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER tr_protect_locker_body_model BEFORE UPDATE OR DELETE ON locker_body_models
 FOR EACH ROW EXECUTE FUNCTION protect_locker_body_model();

CREATE FUNCTION protect_locker_model_slots() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' OR (SELECT status FROM locker_body_models WHERE id=NEW.body_model_id)='READY' THEN
  RAISE EXCEPTION 'Ready body layouts are immutable; create a new version' USING ERRCODE='42501';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER tr_protect_locker_model_slots BEFORE INSERT OR UPDATE OR DELETE ON locker_body_model_slots
 FOR EACH ROW EXECUTE FUNCTION protect_locker_model_slots();

CREATE FUNCTION protect_locker_box_model() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 RAISE EXCEPTION 'Box models are immutable; create a new version' USING ERRCODE='42501';
END $$;
CREATE TRIGGER tr_protect_locker_box_model BEFORE UPDATE OR DELETE ON locker_box_models
 FOR EACH ROW EXECUTE FUNCTION protect_locker_box_model();

GRANT SELECT,INSERT,UPDATE ON locker_body_models TO zpx_runtime;
GRANT SELECT,INSERT ON locker_box_models,locker_body_model_slots TO zpx_runtime;
GRANT USAGE,SELECT ON SEQUENCE locker_body_models_id_seq,locker_box_models_id_seq,locker_body_model_slots_id_seq TO zpx_runtime;
