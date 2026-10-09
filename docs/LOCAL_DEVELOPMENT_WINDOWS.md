# Windows development API with the shared Mac database

The Windows API uses the existing `zpx_delivery_dev` database on
`192.168.86.203:5432`. It must use `zpx_runtime`, never the postgres administrator.
This setup does not initialize, seed, reset or migrate the shared database.

Private `.env.dev` must contain the existing Mac configuration:

```dotenv
DB_HOST=192.168.86.203
DB_PORT=5432
DB_NAME=zpx_delivery_dev
DB_USER=zpx_runtime
DB_PASSWORD=<existing runtime password>
AUTH_ENCRYPTION_KEY=<existing Mac key>
AUTH_LOOKUP_KEY=<existing Mac key>
ZPX_ORGANIZATION_ID=<existing organization ID>
```

Copy these values privately; do not put them in Git or chat. The auth keys must
match the Mac to decrypt existing identity records and locate existing accounts.
Health readiness alone does not verify identity configuration or device enrollment.

PHP 8.3.35 is installed locally at `%LOCALAPPDATA%\ZPX\PHP83\php.exe` with
the required extensions; the official archive SHA-256 was verified. Locked
Composer dependencies are installed in ignored `apps/api/vendor`. On another PC,
install PHP 8.3+ with PDO PostgreSQL, sodium, mbstring, GD, OpenSSL and curl,
then install the API's locked Composer dependencies. Do not commit the runtime.

From the repository root in PowerShell:

```powershell
./scripts/windows-api.ps1 -Action Status
./scripts/windows-api.ps1
```

The development server listens on `http://127.0.0.1:8000`; keep the terminal open.
Pass `-Php <path>` to use another PHP installation, or `-Port <port>` if needed.
`/health/live` verifies routing; `/health/ready` verifies connectivity and every
migration checksum against the shared database. The runner refuses to start if
schema verification fails. SQL migrations use LF through `.gitattributes` so
Windows checkouts retain the same checksums as macOS and Linux.

The server binds to loopback and is for local development. It does not replace
the Mac's API or expose a new public server. Shared writes made through authorized
API workflows affect both developers; database tests must use disposable databases.

Terminal48's native shell starts with
`E:\development\terminal48\scripts\start-delivery-shell.ps1`.
It queries readiness here; enrolled-device transactions remain separate work.
