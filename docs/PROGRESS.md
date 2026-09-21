# Development progress

Purpose: milestone/session summary; task status belongs in GitHub Issues/Projects.
Audience: ZPX owner, developers and Codex.
Status: PostgreSQL foundation and local identity slice verified; M0/M1 remain incomplete.
Owner: unassigned. Last reviewed: 2026-09-19.

## Current development

Foundation: branch `feature/P1.1-postgresql-foundation`, [PR 6](https://github.com/zipcodexpress/ZPX_Delivery/pull/6),
[P1.1 issue 5](https://github.com/zipcodexpress/ZPX_Delivery/issues/5).
Richard approved PostgreSQL + ThinkPHP + React; Fleetbase evaluation is no longer a prerequisite.
See [platform decision](decisions/0004-platform-baseline.md).

## Implemented and verified locally

- Authenticated shell Git/GitHub CLI checkout on the external writable APFS Document SSD.
- Colima with SSD storage, Node 24.19.0, PostgreSQL 17.11 and PHP 8.3.33 arm64 containers.
- ThinkPHP 8.1.4 HTTP lifecycle, explicit routes, safe JSON failures and locked Composer dependencies.
- Canonical 73-table PostgreSQL schema plus checksum ledger and separate migration/runtime credentials.
- Additive ownership migration binds compartments to the correct locker and manifest generation.
- Development-only transactional seed loader: six synthetic identities, twenty locker sites,
  one hub, two drivers, forty frozen compartments and ten unpaid draft parcels.
- Repeat seed preserves edited records and credentials. Failures roll back all seeded records.
- Two-process last-compartment contention test proves one winner without overwriting its claim.
- React customer/operations foundation shells and authenticated durable synthetic locker simulator.
- Contract/type checks, web builds, Python setup tests, ThinkPHP routing/error tests,
  PostgreSQL constraints/permissions/rollback/concurrency tests and six HTTP readiness checks.

See [local setup](LOCAL_DEVELOPMENT_MAC.md), [Mac environment evidence](verification/P1.1-mac-local-2026-09-19.md)
and [backend architecture](BACKEND_DATABASE_ARCHITECTURE.md).
Local generated credentials remain in Git-ignored `.local/seed-credentials.txt` with owner-only access.
The customer page now supports registration, sign-in and contact verification; operations has staff sign-in.
See [identity evidence](verification/P1.2-identity.md). Synthetic demo contacts are added
with `python3 scripts/dev.py demo-accounts`, preserving their generated passwords.

## Next dependency

Richard confirmed one ZPX-operated network: customers may use eligible public
sites; staff access is scoped by site/hub. Identity work continues on
`feature/P1.2-identity`, based on the verified foundation. No customer may choose
an organization or staff role during public registration.

P1.2 now includes registration, encrypted contacts, local verification, cookie/native sessions,
CSRF, refresh replay protection and scoped-role primitives. Real verification delivery awaits
Richard's email/SMS provider decision. Password recovery, device enrollment and legacy
account linking remain P1.2 work. Next comes P1.3 authorized topology/reservations,
then shipment/payment/labels and custody workflows.
The database reservation test is not a completed reservation service or physical door test.
Native mobile/Android kiosk apps, protocol fixtures, providers and hardware commissioning
remain outstanding. Use synthetic data/fake providers until actual configuration is approved.

## Historical evidence

Earlier MySQL and P0.1 results are retained in [M0 evidence](verification/M0-foundation.md)
and [contract evidence](verification/M0-contracts.md). They are not PostgreSQL results.
The old terminal is reference-only per [decision 0002](decisions/0002-new-terminal-platform.md).
No production access, physical door commands, PR merge or production deployment occurred.
