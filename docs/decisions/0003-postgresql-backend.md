# 0003 — PostgreSQL delivery backend foundation

Purpose: record Richard's database selection and its implementation boundary.
Audience: developers/owner. Status: accepted architecture; runtime evidence in verification notes.
Owner: Richard (database choice). Last reviewed: 2026-09-18.

PostgreSQL replaces MySQL for the new delivery application. Legacy apartment databases are unchanged. `apps/api/database/migrations` is now the executable database authority. The MySQL SQL in `docs/handoff/sql` is historical design input; do not run it for this application or maintain a second active schema.

Use PostgreSQL 17, UTF-8, deterministic C locale for identifiers, `delivery` schema, BIGINT identity primary keys, native UUID public/event identifiers, TIMESTAMPTZ instants, JSONB structured evidence and BYTEA 32-byte hashes. Monetary values remain integer cents. Database/public IDs retain the existing API representation; public identifiers and secret authorizations remain distinct.

Migrations have a checksum ledger, session advisory lock and per-file transaction. Changing an applied migration fails closed. New schemas use a dedicated migration owner; API receives only runtime credentials. Immutable history has both privilege restrictions and rejecting triggers. Active labels use a partial unique index. Readiness verifies migration checksums rather than assuming a table count proves a healthy schema.

This increment implements framework-neutral PHP infrastructure compatible with the planned PHP backend: connection, migrator, transaction boundary, transactional outbox append and HTTP health/error handling. It does not claim a completed ThinkPHP installation, authenticated business API or background delivery worker. Domain modules are described in the architecture document; business routes will be added against the canonical contract with authorization tests. No framework migration is implied by the database choice.

The Compose image uses the official `postgres:17-bookworm` family tag; immutable digest and M4 execution remain to be recorded. Only the migration job receives migration credentials. The database exposes no host port; the health/bootstrap API is development-only.

Previous MySQL data volumes are left intact and not automatically converted or deleted. Startup creates a separate PostgreSQL volume. This is a new development schema, not a production data migration. Backups/data conversion from any populated environment require a separate reviewed procedure.

References: [identity columns](https://www.postgresql.org/docs/17/ddl-identity-columns.html), [partial indexes](https://www.postgresql.org/docs/17/indexes-partial.html), [locking](https://www.postgresql.org/docs/17/explicit-locking.html).
