# 0001 — initial development toolchains

Purpose: record actual pins and remaining M0 decisions. Audience: developers.
Status: Provisional; M0 is incomplete. Owner: unassigned. Last reviewed: 2026-09-18.

The user selected an M4 Mac and external SSD for the local checkout. Use external APFS and native arm64 containers where supplied by the image. Do not force amd64 emulation or put the repository on the internal drive by default.

Node 24.19.0 is pinned in `.nvmrc` and the Compose web/simulator services. React 19.3.0, Vite 8.3.0 and TypeScript 7.0.2 are exact direct pins with transitive versions in the root npm lockfile. They were installed and used for the recorded Linux build. Python setup uses only standard library modules; cloud tests ran Python 3.12.14.

The local bootstrap uses PHP 8.3 CLI and MySQL 8.4 image families, both from official Docker images. These family tags are not immutable pins. Record tested image digests/architectures and PHP/Composer versions after the Docker build lane passes; do not call dependency selection final before then. PHP's built-in web server is for development only.

The production business API is still planned as ThinkPHP 8. No Composer dependency graph has been adopted in this increment; the small PHP health endpoint does not represent a completed ThinkPHP skeleton. Fleetbase reuse, mobile/native SDKs and Windows terminal toolchains remain open M0 work. This avoids treating untested framework scaffolds as completed deliverables.

Official image references: [PHP](https://hub.docker.com/_/php), [MySQL](https://hub.docker.com/_/mysql). Validate actual selected manifests on both development and deployment architectures before locking them.
