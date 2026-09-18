# M0 foundation — initial development increment

Purpose: implementation evidence, not a completion claim for M0. Audience: reviewers/developers.
Status: Partial. Owner: unassigned. Last reviewed: 2026-09-18.

Task: [P0.1 / P0.2 issue 3](https://github.com/zipcodexpress/ZPX_Delivery/issues/3).
Base: planning branch commit 0c0f477b8bc6fb2c48a193cdf59e9859f09325aa, with main at 8b58ee5913bddd43335207a70e15f8afb8d8f1ba.

Implemented: external-APFS setup guard, generated private local configuration, Docker local service definition, PHP liveness/database readiness endpoint, React customer/operations shells, persistent authenticated normalized locker simulator, tests and CI. Current handoff moved to docs/handoff in a dedicated commit; original archives retained. No live services or hardware accessed.

Commands actually run in Linux cloud workspace (Node 24.19.0, Python 3.12.14):

- `npm install --ignore-scripts --no-audit --no-fund`: installed dependencies and generated lockfile.
- `npm run check`: TypeScript check passed; all 5 Node simulator tests passed.
- `npm run build`: both customer and operations Vite builds passed.
- `python3 -m unittest discover -s tests -p 'test_*.py'`: all 3 setup tests passed.
- `python3 docs/handoff/tests/validate_handoff.py`: static check passed, 54 operations / 74 schemas / 73 draft tables.
- `bash -n scripts/mac-ssd-checkout.sh`: syntax check passed.

Continuation verification: clean `npm ci --ignore-scripts --no-audit --no-fund`, check, both builds, all eight tests and static handoff validation passed again. Both Vite development servers returned HTTP 200 with their ZPX shell over loopback. This checks page serving only; database-backed readiness remains unverified locally. Corrected Compose bind/build paths to resolve from the root selected by the development runner's `--project-directory`.

Tests cover replay after expiry, conflicting idempotency payload, ownership/generation/expiry denial, timeout/wrong-address/crash-held claims, correlated close, parallel HTTP duplicate requests, durable service restart, APFS/external path checks and no credential overwrite. Simulator events are synthetic normalized evidence, not board CRC/frame parsing or actual physical custody.

Docker and PHP are not installed in the cloud editing workspace. An attempted package installation failed because the environment cannot perform required OS privilege changes; it was not worked around.

GitHub Actions subsequently verified implementation commit `fbcae6ee96639cc8d3e8086e2e50ad80970f1a6d`: [Foundation run 35401756927](https://github.com/zipcodexpress/ZPX_Delivery/actions/runs/35401756927). Both `checks` and `local-stack` jobs passed. Evidence includes PHP lint, clean npm installation/check/build, Compose configuration, actual container startup, MySQL initialization from both SQL files with 73 tables, and HTTP readiness of the API, customer page, operations page, both API proxies and simulator. The database test completed at 2026-09-18 22:30:58 UTC. These are Linux CI results; physical M4/ARM64 and locker hardware validation remain pending. Schema initialization does not prove domain constraints or concurrent custody correctness.

Remaining M0: semantic OpenAPI validation/client generation, tested immutable image/toolchain pins, business-framework/reuse decision and build, mobile shells/native builds, actual serial protocol fixture tests, deployed-writer audit and terminal dependency recovery. Remaining P1.1: tracked migrations, restricted runtime DB role, domain and concurrency tests. Production deployment, complete shipping screens and ten-parcel E2E remain unimplemented.
