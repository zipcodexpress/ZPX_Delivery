# ThinkPHP admin presentation

Status: development design. This specifies Richard's request to use ThinkPHP for the admin site, taking `zpxadmin-tp8` as the structural reference. It replaces the earlier proposed React admin expansion in this workstream, while retaining existing React customer/driver/hub interfaces.

## Runtime and proposed file map

Reuse `apps/api` as the ThinkPHP HTTP application; admin HTML and delivery JSON endpoints share the same application services/database. No second deployment, database or independent login system is required initially.

| Proposed path | Responsibility |
|---|---|
| `apps/api/route/admin.php` | Explicit `/admin` page and form-action routes, loaded once by ThinkPHP |
| `apps/api/src/Http/Admin/` | Thin page/form controllers and shared session/capability guard |
| `apps/api/src/Admin/` | New administration use cases and scoped query services where existing domain services do not suffice |
| `apps/api/src/Presentation/` | Escaped view models, rendering helper, pagination and form-error mapping |
| `apps/api/view/admin/` | Layout, login, navigation, reusable table/form partials and module templates |
| `apps/api/public/admin-assets/` | Scoped CSS and minimal JavaScript for progressive enhancement |
| `apps/api/tests/` | Service/HTTP/template tests integrated with existing disposable test setup |

The current delivery composer manifest has ThinkPHP but no view package. ADM-02 must select a PHP 8.3/ThinkPHP 8.1-compatible `topthink/think-view` version, lock it in Composer and verify render behavior. The apartment app already uses think-view; inspect its rendering pattern, but do not copy all legacy dependencies/assets. No ORM migration is needed: reuse delivery PDO services.

## Request handling

Authenticated GET -> identity/session guard -> capability and resource scope -> application query -> explicit safe view model -> escaped template. Templates contain presentation only, no SQL, no scope decisions and no credentials. Send `Cache-Control: no-store` for authenticated admin HTML.

HTML POST -> identity + Origin/CSRF -> capability -> validation + expected version/idempotency -> same application service used by JSON controller -> 303 redirect on success. Failed validation rerenders escaped form data with field errors; a stale version is a conflict, not a blind retry. GET cannot mutate state. Avoid making server-side HTTP calls back into the same API to reuse business logic.

HTML form hidden fields carry CSRF, expected_version and idempotency_key. Treat them as untrusted; controllers validate them and pass semantic equivalents to the shared service. A same-payload retry preserves the key; a changed form payload uses a new key. JSON endpoints retain their documented headers. Neither controller may bypass audit/idempotency just because its input is an HTML form.

Use ordinary forms and server pagination first. Small JavaScript may enhance confirmation dialogs, layout preview and async lookups. All interpolated text is escaped by default; raw HTML requires a reviewed safe source. Protect external links and file downloads. Use the existing security headers, a compatible CSP and no third-party CDN dependency for essential admin controls.

## Important existing cookie constraint

`apps/api/src/Http/IdentityController.php::cookie` currently issues `zpx_delivery_session` with `Path=/api/delivery/v1`. Browsers will not send that cookie to `/admin`. A template controller cannot simply assume the existing login is visible.

ADM-02 must migrate this cookie to `Path=/` on the same trusted delivery origin, retaining HttpOnly, SameSite=Strict, Secure outside explicit local development and the existing expiry/session storage. Clear the old API-path cookie with an expired Set-Cookie header when issuing the root cookie and on logout. Also expire the root cookie on logout. Emit multiple Set-Cookie headers correctly; do not concatenate them into one comma-separated header.

Previously logged-in users with only the narrow cookie must sign in again to obtain the root cookie. Do not silently manufacture a second identity or redirect in a loop. Test cookies with the same name on both paths and ensure the old one cannot shadow the new credential. Session revocation and native bearer authentication remain unchanged. Review same-origin application trust before exposing the root cookie; no untrusted apps should share this origin.

`GET /admin/login` renders a minimal ThinkPHP sign-in page; POST calls the existing identity login logic with the same rate limits/session handling, using a dedicated page adapter. Reuse existing pre-login CSRF protections or add a narrowly scoped login-CSRF mechanism with Origin checks and tests; do not disable CSRF globally. Post-login return targets must be validated relative `/admin` paths, never arbitrary external URLs. An authenticated non-admin receives an explicit no-access page and a link back to their permitted application.

## Coexistence with current React applications

During local development, ThinkPHP serves admin at the configured backend origin (currently port 8000); existing Vite applications keep their configured ports. Links use explicit configured origins and no untrusted return URL. Test session behavior at those actual origins/ports; do not assume a browser test passing at one URL covers another.

Production routing reserves `/admin` and `/admin-assets` for ThinkPHP/static assets, `/api/delivery/v1` for JSON, and existing customer/operations frontend routes for their current deploy configuration. Do not move the existing React application under a new prefix without separately configuring its asset base, redirects and tests. New admin navigation may link to the existing configured operations URL for receiving/dispatch until parity pages are deliberately developed.

ADM-02 migrates or links the existing four admin functions one at a time. Keep JSON endpoints and existing React behavior compatible. Only remove an old admin page after the new ThinkPHP page passes the same acceptance flow; deletion is not required for the first admin release.

## Reuse from zpxadmin-tp8

Adapt its controller -> service -> repository -> presentation organization, grouped menus, allowlisted sorting/filter normalization, property/cabinet concepts and form error flow. The delivery equivalents must use its own domain services and permission model. Use stable URL paths instead of copying `/?r=...` routing, legacy table names, configured superadmin bypass or apartment-specific assumptions.

## Definition of done

PHP lint and actual template rendering; escaped-content test; auth redirect/denial test; cookie migration/login/logout test; CSRF and stale-form test; browser completion of old admin flows; link/refresh/back-navigation verification. Existing React build and relevant operations browser tests still pass. ThinkPHP is the admin framework and page renderer, not merely a proxy in front of a newly created React admin.
