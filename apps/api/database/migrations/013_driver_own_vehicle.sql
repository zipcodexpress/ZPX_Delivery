-- Driver owns their own vehicle (like Uber). Add verification fields.
ALTER TABLE drivers ADD COLUMN vehicle_license_plate VARCHAR(20),
 ADD COLUMN vehicle_vin VARCHAR(30),
 ADD COLUMN vehicle_registration_state VARCHAR(2),
 ADD COLUMN vehicle_registration_expiry DATE,
 ADD COLUMN vehicle_insurance_provider VARCHAR(120),
 ADD COLUMN vehicle_insurance_policy VARCHAR(100),
 ADD COLUMN vehicle_insurance_expiry DATE,
 ADD COLUMN vehicle_photo_reference VARCHAR(500),
 ADD COLUMN verification_status VARCHAR(24) NOT NULL DEFAULT 'PENDING' CHECK(verification_status IN ('PENDING','VERIFIED','REJECTED','EXPIRED'));
