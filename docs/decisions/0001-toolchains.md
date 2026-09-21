# 0001 — initial development toolchains

Purpose: record actual pins and remaining M0 decisions. Audience: developers.
Status: Provisional; M0 is incomplete. Owner: unassigned. Last reviewed: 2026-09-18.

Database amendment: [decision 0003](0003-postgresql-backend.md) replaces the MySQL choice below with PostgreSQL 17. The PHP image now builds pdo_pgsql; application migrations and runtime configuration no longer target MySQL.

Terminal amendment: [decision 0002](0002-new-terminal-platform.md) replaces Windows terminal tooling as an M0 requirement. Android is preferred for a new terminal; legacy executable/service builds are out of scope. The historical Windows mention below does not apply to the current work order.

The user selected an M4 Mac and external SSD for the local checkout. Use external APFS and native arm64 containers where supplied by the image. Do not force amd64 emulation or put the repository on the internal drive by default.

Node 24.19.0 is pinned in `.nvmrc` and the Compose web/simulator services. React 19.3.0, Vite 8.3.0 and TypeScript 5.9.3 are exact direct pins with transitive versions in the root npm lockfile. The first foundation used TypeScript 7.0.2; this provisional choice was revised because openapi-typescript 7.13.0 declares a TypeScript 5 peer dependency. Installation of the incompatible combination was rejected; no force/legacy-peer-deps bypass was used. API validation uses @apidevtools/swagger-parser 13.0.0; generated types use openapi-typescript 7.13.0. Python setup uses only standard library modules; cloud tests ran Python 3.12.14.

The local bootstrap uses PHP 8.3 CLI and MySQL 8.4 image families, both from official Docker images. These family tags are not immutable pins. Record tested image digests/architectures and PHP/Composer versions after the Docker build lane passes; do not call dependency selection final before then. PHP's built-in web server is for development only.

The production business API is still planned as ThinkPHP 8. No Composer dependency graph has been adopted in this increment; the small PHP health endpoint does not represent a completed ThinkPHP skeleton. Fleetbase reuse, mobile/native SDKs and Windows terminal toolchains remain open M0 work. This avoids treating untested framework scaffolds as completed deliverables.

Official image references: [PHP](https://hub.docker.com/_/php), [MySQL](https://hub.docker.com/_/mysql). Validate actual selected manifests on both development and deployment architectures before locking them.
