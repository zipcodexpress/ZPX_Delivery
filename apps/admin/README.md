# Delivery admin site

`apps/admin` is the ThinkPHP presentation and access layer for the delivery system. The existing `apps/api` application loads `routes.php`, renders these Think templates, and shares its PostgreSQL connection, identity sessions, CSRF checks, and domain services. The API Docker image copies this sibling folder; there is no separate admin database or login store.

Open `http://localhost:8000/admin` in local development. The first release includes the dashboard, shipment list, pending-driver review, pickup route assignment, and uncollected-run recovery. It follows the sidebar/topbar, card, table, and form style of `zpxadmin-tp8` without importing apartment-specific models or permissions.

The access endpoint is `GET /api/delivery/v1/admin/access`. Its capability response is a navigation hint; every server action rechecks identity and the existing service's authorization. Only a network-wide `ADMIN` grant opens this site. Location grants appear as scoped hints but confer no network admin power. Partner, site, contact and finance tables are deliberately deferred to their corresponding tasks in `docs/admin/BACKLOG.md`.

Source ownership: `routes.php` mounts HTML pages, `src/` contains thin page adapters and access resolution, `view/` contains presentation only, and `public/admin.css` is served through `/admin-style`. Business rules stay in the delivery services under `apps/api/src/`.
