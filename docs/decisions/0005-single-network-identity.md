# 0005 — one ZPX network and scoped staff access

Purpose: define pilot identity boundaries. Audience: developers and operators.
Status: Accepted. Owner: Richard. Date: 2026-09-19.

Richard selected one ZPX organization for the pilot. Customers may use eligible public
ZPX sites; staff permissions remain limited by assigned organization/site/hub. The
server chooses the configured organization during registration. Public registration
cannot select an organization, staff role, verified-contact state or legacy membership.

Registration creates only a CUSTOMER grant. Scope checks use current database grants,
not roles copied into browser state. Expired grants and disabled users are rejected.
Composite foreign keys prevent grants from referring to another organization's user,
site or issuer. Apartment eligibility still requires the separately specified property
membership or explicit visitor policy; this decision does not make all apartments public.

Both email and phone must be verified before shipping eligibility. The first identity
increment uses encrypted local verification delivery; real email/SMS integration awaits
provider selection and credentials outside Git/chat. No production notification is sent.

Browser sessions use HttpOnly, SameSite=Strict cookies and session-bound CSRF tokens.
Cookies are Secure except in explicit local development. Native access tokens last
15 minutes; refresh rotates within a seven-day family lifetime. Reusing a refresh
credential revokes the family, including descendants. Browser sessions last eight hours.
These are engineering defaults for development and must be reviewed before production.

References: [OWASP session management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html),
[CSRF prevention](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html),
[PHP Sodium](https://www.php.net/sodium).
