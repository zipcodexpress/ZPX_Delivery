#!/bin/sh
set -eu
# Executed only for a new PostgreSQL volume. Secrets are read from environment, never echoed.
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<'SQL'
\getenv runtime_password DB_PASSWORD
\getenv migration_password DB_MIGRATION_PASSWORD
SELECT format('CREATE ROLE zpx_migrator LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD %L', :'migration_password') \gexec
SELECT format('CREATE ROLE zpx_runtime LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD %L', :'runtime_password') \gexec
REVOKE ALL ON DATABASE zpx_delivery_dev FROM PUBLIC;
GRANT CONNECT ON DATABASE zpx_delivery_dev TO zpx_migrator, zpx_runtime;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
CREATE SCHEMA delivery AUTHORIZATION zpx_migrator;
GRANT USAGE ON SCHEMA delivery TO zpx_runtime;
ALTER ROLE zpx_runtime SET search_path TO delivery, pg_catalog;
ALTER ROLE zpx_migrator SET search_path TO delivery, pg_catalog;
SQL
