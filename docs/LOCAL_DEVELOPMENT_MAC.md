# Local development on an M4 Mac and external SSD

Purpose: run the first development increment locally.
Audience: Richard and ZPX developers. Status: implemented setup; first physical M4/Docker run pending.
Owner: unassigned. Last reviewed: 2026-09-18.

## Verified SSD setup (2026-09-19)

The checkout is `/Volumes/Document/Workspace/projects/Development/ZPX_Delivery`,
on branch `feature/P1.1-postgresql-foundation`. The Document volume is external,
writable APFS. Colima is the local Docker engine; its existing storage is under
`/Volumes/Document/Workspace/containers/colima`. Node 24.19.0 is installed through
the existing SSD-based nvm installation. Existing files and credentials are preserved.

In a new terminal:

```bash
cd /Volumes/Document/Workspace/projects/Development/ZPX_Delivery
source "$HOME/.nvm/nvm.sh"
nvm use
# If the container engine is stopped:
colima start
python3 scripts/dev.py up
```

Use `python3 scripts/dev.py test-db` for PostgreSQL integration tests and
`python3 scripts/dev.py smoke` for HTTP readiness. Stop this project's services
with `python3 scripts/dev.py down`; this retains database volumes. PostgreSQL
is internal to the Compose network, with no host database port.

The setup runner resolves the actual filesystem mount before calling `diskutil`;
passing the nested checkout directory directly fails on this Mac.
The historical setup descriptions below are superseded where they mention MySQL,
the P0.1 branch, or Docker Desktop as the only available container engine.

### Synthetic development data

Run `python3 scripts/dev.py seed` explicitly to create the local synthetic dataset.
It uses the runtime database role and requires the development database and environment.
Six generated account passwords are printed once after a successful commit; reruns
preserve data and passwords and do not redisplay them. Keep that output private.
The prepared local output is stored in the Git-ignored `.local/seed-credentials.txt`
with owner-only access. Run `demo-accounts` below to add their synthetic contacts for interactive sign-in.

Local site codes have a `local:` namespace. Twenty locker sites and one hub use
synthetic addresses; all forty compartments remain frozen and lack commissioned
hardware mappings. Ten draft, unpaid parcels have a 6/4 destination split. No payment,
shipping label, custody event or physical door operation is simulated as completed.

API dependencies are pinned in `apps/api/composer.lock`; container builds install them.
Direct PHP development uses `composer install --working-dir=apps/api`. Run
`php apps/api/tests/http.php` for ThinkPHP routing and error tests without a database.
`test-db` rebuilds its image before testing so it cannot silently test old source.

### Accounts and verification inbox

`python3 scripts/dev.py init` now adds missing `AUTH_ENCRYPTION_KEY` and
`AUTH_LOOKUP_KEY` values without rotating existing keys. `ZPX_ORGANIZATION_ID` selects
the single network organization; this checkout is configured to its synthetic seed.
Preserve these keys alongside database backups: existing encrypted contacts depend on them.

After `up` and the initial synthetic seed, `python3 scripts/dev.py demo-accounts`
adds local-only demo contacts without changing passwords or grants. Examples:
`customer.local@example.invalid` and `admin.local@example.invalid`. Use the corresponding
identity's existing password in `.local/seed-credentials.txt`. The first command is
additive and subsequent runs preserve existing contacts. Customer demo phone is
`+12025550191`; all demo numbers are fictional and no message is sent to a provider.

The customer page supports registration, sign-in and contact verification. Request a
code on the page, then run `python3 scripts/dev.py inbox` to refresh the owner-only
`.local/verification-inbox.json` file. The CLI prints only the filename/count, never
codes. This is a development delivery substitute, not proof of live SMS/email delivery.

`test-db` now uses a fresh uniquely named temporary stack and cleans only that stack's
volume after completion. It does not add test accounts/audits to the developer database.
See [identity evidence and limits](verification/P1.2-identity.md).

Current database/setup amendment: use branch `feature/P1.1-postgresql-foundation`. PostgreSQL replaces MySQL. `python3 scripts/dev.py init` preserves existing secrets and adds a missing migration secret; `up` creates a separate PostgreSQL volume and applies tracked migrations. Old MySQL volumes remain untouched. `python3 scripts/dev.py migrate` applies new migrations, and `test-db` now runs PostgreSQL integration tests with synthetic data. Earlier MySQL/empty-volume initialization descriptions below are historical; see [current architecture](BACKEND_DATABASE_ARCHITECTURE.md).

SSD context recovered from the September 16 “Reformat SSD for development” conversation: proposed name `Development`, path `/Volumes/Development`, APFS, GUID Partition Map. Use `/Volumes/Development/Developer/ZPX_Delivery` as the planned checkout. The retrieved conversation did not confirm formatting was completed; the setup checks actual disk metadata before proceeding. Substitute `Development` for YOUR_SSD_NAME below if that volume is mounted.

The Linux Docker setup, all 73 draft tables and HTTP readiness passed in [GitHub Actions](https://github.com/zipcodexpress/ZPX_Delivery/actions/runs/35401756927). This does not yet verify an M4 run. Development code is available in [draft PR 4](https://github.com/zipcodexpress/ZPX_Delivery/pull/4).

## Storage and prerequisites

Keep the repository at `/Volumes/<your SSD name>/Developer/ZPX_Delivery`. Replace the placeholder with the actual mounted volume name; quote the path if it contains spaces. This cloud session cannot mount, inspect or write to your Mac's SSD.

Use a writable external APFS volume. The setup checks the disk metadata and refuses internal/non-APFS storage; it never formats, erases or repartitions a drive. Back up data before independently changing disk format. The actual SSD name has not been supplied and is not hardcoded.

Install Git (or Xcode Command Line Tools), Python 3 and Docker Desktop for Apple Silicon. Open Docker Desktop before starting. GitHub Desktop or GitHub CLI can authenticate your private checkout; the chat connection does not authenticate your Mac. Native Node is optional for Docker-only startup; direct frontend/simulator development uses the version in `.nvmrc` and `npm ci`.

The repository is on the SSD, but Docker named volumes and images remain inside Docker Desktop's disk image wherever Docker is configured to store it. Moving that disk image is a separate Docker Desktop setting; this script does not move it. The same is true of Xcode/Android SDK caches.

## Obtain the development branch

In GitHub Desktop, clone `zipcodexpress/ZPX_Delivery` and choose the external SSD's Developer folder as the local path, then switch to `feature/P0.1-m4-foundation` while the implementation PR is unmerged.

Alternatively, after authenticating GitHub CLI:

```bash
gh auth login
mkdir -p "/Volumes/YOUR_SSD_NAME/Developer"
gh repo clone zipcodexpress/ZPX_Delivery "/Volumes/YOUR_SSD_NAME/Developer/ZPX_Delivery" -- --branch feature/P0.1-m4-foundation
cd "/Volumes/YOUR_SSD_NAME/Developer/ZPX_Delivery"
```

Replace YOUR_SSD_NAME first. If the target already contains a checkout, do not clone over it; inspect its status and switch/fetch normally. The optional `scripts/mac-ssd-checkout.sh` performs stronger pre-clone disk checks when you already have that script available.

## Start and stop

```bash
python3 scripts/dev.py doctor
python3 scripts/dev.py up
```

The startup generates private random local credentials once in `.env`, builds the PHP health service, initializes both draft SQL files into a new development MySQL volume, starts the two web shells and simulator, and waits for HTTP readiness. First run needs image/package downloads; rerun `smoke` if downloads exceed the initial wait. An existing `.env` is preserved.

| URL | Current behavior |
|---|---|
| http://localhost:5173 | Customer foundation page with actual API/database readiness |
| http://localhost:5174 | Hub/admin foundation page with actual API/database readiness |
| http://localhost:8000/health/ready | API checks all 73 draft tables are initialized |
| http://localhost:8090/health/live | Synthetic locker service liveness |

These are development shells, not operational shipping screens. No accounts, payments, parcel custody service, real door control or native mobile application are implemented in this increment.

```bash
python3 scripts/dev.py smoke
python3 scripts/dev.py test-db
python3 scripts/dev.py logs
python3 scripts/dev.py down
```

Stopping retains the database and simulator volumes. Draft SQL initializes only on an empty MySQL volume; edits to SQL will not automatically migrate existing volumes. Versioned application migrations and restricted runtime roles are still P1.1 work. Do not manually remove a data volume containing work you need. Local binds are loopback-only and MySQL has no host port.

## Direct source checks

```bash
npm ci --ignore-scripts
npm run check
npm run build
python3 -m unittest discover -s tests -p 'test_*.py'
python3 docs/handoff/tests/validate_handoff.py
```

`npm run test-e2e` currently invokes the foundation HTTP smoke check; it does not claim the ten-parcel acceptance journey. `npm run test-db` checks draft schema initialization, not full transactional correctness.

The simulator exposes authenticated `/state`, `/commands` and `/close` on the local development port. Use the generated SIMULATOR_TOKEN from your local environment as a Bearer token. Do not put it in browser code or commit it. It has a DELIVERY, LEGACY and FROZEN door and supports normal, timeout, wrong_address and crash_after_dispatch scenarios. It preserves unresolved claims after close/timeout; no reset/parcel confirmation endpoint is provided yet.

## Platform limitations and next work

Images are not forced to amd64: Docker selects the available host architecture. The M4 arm64 container run must be recorded before this is called Mac-validated. PHP/MySQL image digests are not yet locked and need verification during M0 completion. The cloud workspace ran Node and Python checks but has no Docker/PHP runtime.

The business API remains the planned ThinkPHP 8 application; the current PHP health bootstrap is intentionally limited to environment verification while the reuse decision remains open. iOS needs Xcode, Android needs its selected SDKs, and the existing WinForms terminal needs Windows build/hardware validation. The Mac runs the normalized locker simulator in the meantime.

## Updating your checkout

Commit or stash your local work first. Fetch the current development branch, or pull main after the implementation PR is merged. Rebuild with `python3 scripts/dev.py up` and follow migration notes from each PR. Never assume local changes or running services automatically sync back to GitHub.

## Shipping test flow

Sign in as `customer.local@example.invalid`. Sending includes a paid-test demo with
a printable test label; Receiving includes an explicitly claimed incoming draft.
The second customer is `recipient.local@example.invalid`; both can send and receive.
Passwords remain in the private seed credential file.

New shipment → choose different synthetic lockers → recipient details → parcel
measurements → save → test quote → test checkout → simulate success/failure.
After success, generate/reprint the 4×6 PDF. Test labels cannot authorize locker
access. Admin sees the same committed order history on the operations portal.
Hub staff only see parcels actually held by their assigned hub.

These two localhost ports share browser cookies. Signing into another role switches
the local session; use separate browser profiles if you want simultaneous accounts.
The customer portal explains when a staff-only account is signed in.

Authorize.net was selected for the provider integration. Its adapter is not active.
Do not put production keys or card details into this local test checkout.

## Private environment files

The local runner prefers `.env.dev` when present and otherwise uses `.env`. It never
automatically loads `.env.prod`. The prepared `.env.dev` puts existing local database
credentials first, then Authorize.net sandbox and SMTP email fields, then existing
application keys. Existing `.env` and `.local/authorize-net.env` remain preserved;
enter new provider credentials in `.env.dev`. These private files are Git-ignored.
Provider placeholders are configuration storage, not an active email/payment adapter.
A future `.env.prod` can use the same layout with separate production credentials and
an explicit deployment configuration; do not reuse development encryption or DB secrets.
