-- Ownership must bind the same physical locker and generation on every reference.
-- Additive migration: never edit already-applied checksums.
ALTER TABLE compartments ADD CONSTRAINT compartments_id_locker_unique UNIQUE(id, locker_id);
ALTER TABLE ownership_manifests ADD CONSTRAINT manifests_identity_generation_unique UNIQUE(id, locker_id, generation),
 ADD CONSTRAINT manifests_positive_generation CHECK(generation > 0);
ALTER TABLE compartment_ownership ADD COLUMN locker_id BIGINT;
UPDATE compartment_ownership o SET locker_id=c.locker_id FROM compartments c WHERE c.id=o.compartment_id;
ALTER TABLE compartment_ownership ALTER COLUMN locker_id SET NOT NULL;
ALTER TABLE compartment_ownership
 ADD CONSTRAINT ownership_compartment_locker_fk FOREIGN KEY(compartment_id,locker_id) REFERENCES compartments(id,locker_id),
 ADD CONSTRAINT ownership_manifest_generation_fk FOREIGN KEY(manifest_id,locker_id,generation) REFERENCES ownership_manifests(id,locker_id,generation);
