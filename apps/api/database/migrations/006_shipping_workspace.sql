-- Additive shipping/recipient proof foundation. Applied migrations remain unchanged.
ALTER TABLE shipments
 ADD CONSTRAINT shipment_sender_org_fk FOREIGN KEY(sender_user_id,organization_id) REFERENCES users(id,organization_id),
 ADD CONSTRAINT shipment_origin_org_fk FOREIGN KEY(origin_location_id,organization_id) REFERENCES locations(id,organization_id),
 ADD CONSTRAINT shipment_destination_org_fk FOREIGN KEY(destination_location_id,organization_id) REFERENCES locations(id,organization_id);
ALTER TABLE shipment_parties ADD COLUMN email_lookup BYTEA CHECK(email_lookup IS NULL OR octet_length(email_lookup)=32),
 ADD COLUMN phone_lookup BYTEA CHECK(phone_lookup IS NULL OR octet_length(phone_lookup)=32);
ALTER TABLE verification_challenges ADD COLUMN shipment_id BIGINT REFERENCES shipments(id);
ALTER TABLE pricing_quotes ADD COLUMN shipment_version INT CHECK(shipment_version>=0);
CREATE INDEX ix_shipments_sender_cursor ON shipments(organization_id,sender_user_id,id DESC);
CREATE INDEX ix_shipment_recipient_user ON shipment_parties(user_id,shipment_id) WHERE party_role='RECIPIENT';
