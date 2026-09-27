# Portal review and next development plan — 2026-09-23

## Follow-up repair checkpoint (same date)

The findings below describe the original `a7b36b6` baseline. The subsequent repair commit resolved F1–F7 and the admin/driver-form parts of F8. The retained local API was rebuilt without deleting development data. A new development smoke check now fails when the API is too old to support resumable receiving. Driver management is organization-scoped, approval grants the DRIVER role, and approval/rejection are covered by disposable-database tests. Receiving sessions can be discovered and resumed; the driver portal can accept dispatch calls and shows a stable manifest denominator.

Live localhost browser verification completed this synthetic chain: resume inbound receiving after reload → receive and close five packages → stage five → create dispatch → outbound driver accepts → load five unique packages → depart. A duplicate fourth-stage scan was refused, and departure remained blocked at 4/5. At 5/5 the outbound run became `IN_PROGRESS`. ADMIN sign-in shows the new Driver approvals tab and an empty pending queue. CUSTOMER sign-in shows ten seeded shipments and a usable new-shipment form. No real locker, parcel, payment, or recipient handoff was exercised.

Remaining work is substantial: automate this browser journey, finish ordered stop progression and final deposit/pickup, implement exception/recovery and return flows, then design dispatcher/supervisor and full administration access. Do not treat the current outbound departure as completed delivery. See `docs/CURRENT_STATUS.md` for the exact live continuation point and latest tests.

## Scope and conclusion

Reviewed local branch `feature/P4.2-outbound-driver-delivery` at `a7b36b6`.
Read current source/tests, AGENTS.md, CURRENT_STATUS.md, recent history and relevant planning notes.
User identified http://localhost:5174/ and authorized the existing private seed credentials.
Application code was not changed. Both pre-existing untracked planning files were preserved.

The project has a substantial, passing backend foundation, but is not a complete operational delivery product.
There are three distinct problems: the local API is stale, several portal workflows are incomplete,
and driver management contains correctness and organization-isolation defects absent from current tests.
Do not treat passing builds or the command named `test-e2e` as proof that the portals work end to end.

## Verification results

| Check | Result | Meaning |
| --- | --- | --- |
| `npm run check` | PASS: contracts, TypeScript, 9 Node tests | 74 canonical operations validate; no browser journey coverage |
| `npm run build` | PASS: both web apps | Frontends compile |
| `npm run test-db` | PASS | Fresh disposable PostgreSQL stack; existing HTTP/domain suites pass |
| `python3 -m unittest discover -s tests -p 'test_*.py'` | PASS: 9 tests | Development-runner unit tests, including mocked health checks |
| `npm run test-e2e` | PASS: six service/proxy health checks | Foundation smoke only, not parcel delivery E2E |
| `python3 docs/handoff/tests/validate_handoff.py` | PASS | Documentation fixture/reference checks |
| Targeted driver-management probes | Four defects reproduced | Approval SQL, rejection argument order, cross-org listing, cross-org service rejection |
| `git diff --check` | PASS | Documentation whitespace |

Initial sandbox runs could not access diskutil/Docker or bind the simulator listener; approved reruns passed.
These initial environment restrictions were not application regressions.
Diagnostic probes used a separately named disposable Docker database and removed that database afterward.
The probes did not modify the user's existing driver, parcel, or dispatch records.
No external payment, mail, production service, or physical locker was exercised.

## Browser observations on the running local stack

| Account/page | Observed result |
| --- | --- |
| DRIVER-OUT sign-in | Successful |
| DRIVER-OUT Runs | No assigned runs; no available dispatch/accept control |
| DRIVER-OUT Profile | Loads; contact fields blank despite verified login contacts, since driver profile fields are separate |
| DRIVER-OUT Wallet / Transactions | Loads zero balances / empty transaction history |
| HUB-STAFF Receiving | `Endpoint not implemented.` appears on initial load |
| HUB-STAFF Staging | `Unsupported method.`; slots not displayed |
| HUB-STAFF Dispatch | `Unsupported method.`; empty ready-slot selector |
| HUB-STAFF Exceptions | `Endpoint not implemented.` alongside misleading empty-state text |
| HUB-STAFF History | Loads an empty scoped shipment queue |
| ADMIN | Opens shipment workspace; no driver approval, staff, assets, shift, or routing workspace |
| ADMIN shipment details | Existing synthetic parcel and inbound custody timeline load |
| DRIVER-IN | Existing run and five loaded manifest items load; sidebar says `5/0`, details say `5/5` |

Authenticated browser coverage was concentrated on operations as requested. Customer UI journeys,
supervisor/dispatcher accounts, mobile layouts, network-loss recovery, and physical delivery were not verified.
Local custody-changing actions were not needed to establish the above failures. Existing data was retained.

## Findings and repair requirements

### F1 — P1: local frontend and backend are different versions (environment, reproduced)

The web apps bind-mount the checkout, while the API copies PHP into its Docker image
(`deployment/compose.yaml`, `apps/api/Dockerfile`). Running API was created September 22.
SHA-256 comparison proved `route/api.php`, `src/Http/HubDispatchController.php`, and
`src/Http/DriverController.php` all differ from this checkout.
For example route hash in the running API starts `81a803d4`; checkout starts `82ec7400`.
This accounts for missing discrepancy routes and incompatible dispatch methods on the live hub pages.
Health checks still pass because they do not verify application routes or source revision.

Next: rebuild the local API/migration image, apply repository migrations to retained development data,
recreate the API with organization 1, and retest the same pages. Do not delete volumes or reseed as a repair.
The standard documented entry point is `ZPX_ORGANIZATION_ID=1 python3 scripts/dev.py up`.
Review migration status first and preserve private environment files. No restart/migration was performed in this review.
Add a small revision/build identity check so future frontend/backend drift is visible.

### F2 — P1: driver administration crosses organization boundaries (isolated reproduction)

`apps/api/src/Driver/Service.php:448` (`listPending`) queries all pending drivers without an organization predicate.
`Service.php:114` (`reject`) selects a driver only by ID. Authorizing the caller as ADMIN does not scope the target.
A probe created an admin in organization A and a pending synthetic applicant in B: A could list B's
applicant and, when calling the service with correct arguments, change that applicant to INACTIVE.
The current controller argument bug below blocks HTTP rejection; fixing it alone exposes this service defect.
No database row-level policy supplies the missing boundary.

Next: scope through `users.organization_id`, respect applicable admin location scope, and add
cross-organization negative HTTP/service tests before enabling approval/rejection UI.

### F3 — P1: driver approval cannot execute (isolated reproduction)

`apps/api/src/Driver/Service.php:84` filters `drivers.organization_id`, but drivers has no such column.
Calling approve on a seeded driver fails with PostgreSQL `42703`, undefined column, before status validation.
Next: join the target driver to its user for organization scope; test pending → active and invalid transitions.
Also complete/verify the associated role-grant and active-shift onboarding path: changing status alone
currently does not grant the DRIVER role needed by the workspace/services.

### F4 — P1: driver rejection controller swaps arguments (reproduced call shape)

`apps/api/src/Http/DriverManagementController.php:73` calls `reject($user, $input, $id, $key)`;
service signature is `reject(string $adminUser, string $driverId, array $input, string $key)`.
Executing that call shape produces TypeError before business validation. Controller catches only Failure.
Next: correct the call together with F2 and add an authenticated HTTP rejection test.

### F5 — P1: outbound dispatch has no usable driver acceptance journey (browser + source)

Hub UI creates dispatch calls, and backend supports available calls and acceptance, but
`packages/ui/DriverWorkspace.tsx` only loads assigned runs/profile/wallet/transactions.
No frontend call to `/hub/dispatch-calls/available` or its accept endpoint exists.
The outbound driver therefore cannot create their assigned outbound run through the portal.
The seed creates a shift only for DRIVER-IN; DRIVER-OUT also needs an active shift/vehicle before acceptance.
Existing shifts are time-limited and a seed rerun does not renew them.

Next: add available-dispatch list/accept/refresh using existing endpoints and a deliberate test shift setup.
Acceptance gate: hub dispatch → outbound driver accepts → manifest appears → unique scans → departure,
without SQL or manual API calls in the operator journey. Keep the existing duplicate/custody protections.

### F6 — P1: receiving session cannot be resumed after navigation (source-confirmed)

`packages/ui/HubReceiving.tsx:21` holds session only in component state. Hub tab changes unmount it
(`HubOperations.tsx:79`), as does page refresh. There is no UI load/resume path, although a status API exists.
Reopening the same run with a new request key encounters `SESSION_EXISTS`
(`apps/api/src/HubReceiving/Service.php:117`), so a normal navigation can strand work.
The current UI also requires operators to know internal hub/run IDs.

Next: list assigned arriving runs and open sessions, offer resume, and recover after reload.
Keep server lifecycle restrictions; do not permit duplicate receiving sessions as a workaround.
Acceptance gate: open → partial receive → switch tab/reload → resume same session → finish/close once.
This exact browser journey still needs verification after fixing the stale API.

### F7 — P2: driver progress denominator is remaining count (browser + source)

`apps/api/src/Custody/Service.php:86` counts only EXPECTED manifest items as `expected_count`.
`packages/ui/DriverWorkspace.tsx:320` renders loaded/expected as though expected were total.
Five loaded parcels therefore render 5/0; halfway loading can incorrectly look complete.
The detail panel correctly uses total manifest length. Successful scans refresh detail but not the run list.
Next: define total/remaining consistently without silently breaking consumers, refresh list after changes,
and cover empty, partial and fully loaded manifests.

### F8 — P2: portal role coverage and driver profile controls are incomplete (source + browser)

`packages/ui/Account.tsx:76–79` allows ADMIN/DISPATCHER/HUB_SUPERVISOR operations access but routes
only DRIVER and HUB_STAFF to specialized workspaces. Admin currently gets shipment visibility only.
Multi-role users have no workspace switch. Supervisor backend permissions also require deliberate review;
merely routing supervisors into the hub UI is not sufficient.
Driver profile save omits blank strings (`DriverWorkspace.tsx:100`), preventing removal of optional values;
Cancel leaves edited form state in place. Transactions drops `next_cursor`, hiding history after page one.
Next: explicitly define role capabilities, expose the minimum operational controls, then repair these forms.
Full P7 asset/staff administration need not block the smaller operational fixes above.

## What is solid and should be retained

- Shared identity, role checks, shipment/payment separation and canonical scan contracts.
- PostgreSQL constraints and existing scoped shipping/custody tests.
- Resolve-first inbound pickup, receiving discrepancy evidence, staging and outbound manifest foundations.
- Ten-package departure test: nine unique plus a duplicate cannot depart; all ten unique loads can.
- P4.2 intentionally stops before final deposit/recipient pickup; displayed ordered stops are not
  proof of a implemented stop-arrival, deposit, pickup or route-completion workflow.

## Recommended implementation sequence and completion gates

1. **Restore a coherent local stack.** Verify retained DB migrations, rebuild/recreate API, repeat browser
   checks for each seeded role, and record source revision. Gate: no missing-route/method errors on hub reads.
2. **Repair driver management and add missing regression coverage.** F2–F4 first; cover registration,
   approval/rejection, organization boundaries, retry semantics, role grants, profile validation and payouts.
   Gate: both positive and unauthorized HTTP cases pass against a disposable PostgreSQL database.
3. **Finish the existing operator journeys.** F5–F8, resumable receiving, active shifts, accurate counts,
   explicit role navigation, and recoverable errors. Gate: staff can complete inbound → hub → outbound departure
   entirely in the browser using fixtures, including refresh midway and duplicate/stale/wrong-destination scans.
4. **Add real browser regression tests to CI.** Keep foundation smoke but name it accurately; add seeded
   role-login/routing tests and the above workflow before declaring portal completion. Check console/API errors.
   Tests must not depend on expiring yesterday's shifts or manual knowledge of database IDs.
5. **Complete remaining P4.2 ordered-stop progression, then P5.1 deposit/pickup.** Specify authoritative
   stop transitions, destination validation, compartment claim/door evidence, recipient authorization,
   and replay/timeout recovery. Gate: demonstrated custody chain from sender to recipient with no fabricated handoffs.
6. **P5.2 exceptions/returns/shift close, then broader P7 administration and pilot readiness.** Include
   reconciliation, monitoring, provider configuration, terminal integration and physical-device tests.
   Gate: exception rehearsal and operational sign-off; backend unit tests alone do not authorize a live pilot.

Do not start a broad rewrite. Extend the existing controllers/services and portal components.
The immediate next task should be local stack synchronization plus F2–F4 regression fixes, followed by
one complete browser dispatch/receiving workflow. Current external PR/CI status was not re-queried.
