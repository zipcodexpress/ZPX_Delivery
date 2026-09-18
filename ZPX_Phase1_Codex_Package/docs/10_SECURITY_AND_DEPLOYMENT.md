# Security, deployment and rollout

## Environment design

Separate dev, staging and production for delivery: independent DB, object storage, queue namespace, auth issuer/audience, payment credentials, notification sender and enrolled terminal identities. No development terminal points at real locker commands. Legacy bridge dev uses synthetic fixtures/read-only snapshots. New cloud build uses Linux API/worker/web CI; terminal uses Windows build lane; mobile uses Android and macOS/iOS build lanes. Pin tested dependencies/SDKs in lockfiles in PR 0 and verify their supported deployment environments before release.

Use containerized API/worker and MySQL in local integration environment. Run migrations as a separate release job; API runtime role cannot alter schema/history. Storage holds protected label PDFs, photos and driver document references; downloads are authorized short-lived links. Do not reuse production repository configuration/keys in new environment. Existing source may include deployment configuration; review does not authorize connecting to production services.

## Auth and privacy

Use established backend password hashing and secure random tokens, not legacy client MD5. Verified contacts and challenge rate limits; rotated native refresh sessions; CSRF defense for browser; strict object-scope checks for every package/run/site. Devices get per-device credentials, revocation, nonce/timestamp replay protection and approved locker binding. Legacy API key remains inside compatibility adapter only where required, never sent to new customer app.

Public labels identify parcels but expose no pickup secret or recipient PII. Tracking lookup returns minimal public information unless authenticated. Avoid full tokens/contact data in logs; restrict photo/label access. Driver sees only operationally required identity and location; hub staff only their hub. Signing/journal keys stored through OS/configured secret storage, not bundled source. Use device enrollment/rotation runbook and lost-phone session revocation.

## CI gates

API: unit/domain, MySQL migration and concurrency tests, OpenAPI validation, authorization matrix, outbox/idempotency, static analysis and dependency audit. Web: type/build, screen tests and E2E with simulator. Mobile: camera permission/scanner/native build, secure storage/session rotation, interrupted-network recovery. Terminal: Windows compile, adapter CRC/address/parser fixtures, journal crash tests, all command entry paths through gate. No live-door actions in CI.

## Deployment order for shared pilot

1. Establish inventory/deployed-code fingerprint, OS/board profile, access permissions and backup baseline.
2. Deploy backend/admin compatibility guards in disabled mode; deploy terminal host with central gate and existing apartment regression suite.
3. Prove legacy deposit/pickup still works with delivery feature off; prove guard blocks forbidden test commands in simulator.
4. Commission chosen empty doors through freeze/drain/inspect/ownership-generation ACK protocol. Start with one supervised apartment site and one public site, not all 20.
5. Enable new workflow only for staff test accounts; execute complete route/hub/recipient run and failure drills.
6. Monitor reconciliation, journal backlog, rejected opens and legacy availability; gradually enable remaining approved sites.

## Rollback

Disable new shipment acceptance and new sessions first. Existing delivery packages and in-flight doors remain protected; keep compatible gate alive to complete/return/reconcile them. Do not restore old terminal or ownership generation while delivery doors hold parcels. Drain, physically inspect, freeze and transfer ownership using a NEW signed generation; only then remove delivery capability. DB down migrations that destroy custody or package records are prohibited during occupied operation. Backups restore through rehearsed reconciliation, not blind database rewind while physical parcels moved.

## Operational thresholds (initial configuration, tune during rehearsal)

Alert immediately on owner mismatch, unauthorized door request or multiple active custodians; mark device stale after configurable heartbeat threshold and stop new sessions. Alert on unacknowledged physical event backlog and unresolved post-dispatch timeout. Set actual time thresholds after hardware latency tests; defaults are development-only. Dashboard shows pending/unknown explicitly. Operator resolves unknown occupancy with physical inspection and audited action, never bulk reset.

## Release evidence

Produce build hashes/version, SBOM/dependency records, MySQL test logs, physical scan/door matrix, rollback rehearsal and signed site commissioning checklist. Jurisdictional policies, insurance, prohibited items, privacy retention and refund terms are business approval gates; this source review makes no compliance claim.
