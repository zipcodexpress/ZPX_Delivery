# Local development on an M4 Mac and external SSD

Purpose: run the first development increment locally.
Audience: Richard and ZPX developers. Status: implemented setup; first physical M4/Docker run pending.
Owner: unassigned. Last reviewed: 2026-09-18.

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
