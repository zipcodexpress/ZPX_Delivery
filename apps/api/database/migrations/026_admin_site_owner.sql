-- Property owner and site host are separate commercial relationships.
ALTER TABLE network_partner_roles DROP CONSTRAINT network_partner_roles_role_code_check;
ALTER TABLE network_partner_roles ADD CONSTRAINT network_partner_roles_role_code_check
 CHECK(role_code IN ('HOST','SITE_OWNER','CARRIER','LOCKER_OWNER','LOCKER_OPERATOR','HUB_OPERATOR'));
ALTER TABLE installation_sites ADD COLUMN owner_partner_id BIGINT;
ALTER TABLE installation_sites ADD CONSTRAINT site_owner_partner_network_fk
 FOREIGN KEY(owner_partner_id,organization_id) REFERENCES network_partners(id,organization_id);
CREATE INDEX ix_installation_sites_owner ON installation_sites(owner_partner_id);
