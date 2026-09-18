# Ordered development backlog and test plan

Tasks are dependency-ordered. Multiple application teams may work after contract freeze, but no agent delegation or repository changes were performed in this review.

[Execution contract](13_IMPLEMENTATION_EXECUTION.md) defines M0–M2, target files and completion evidence. M0 executes the draft SQL and semantic contract validation early; P1.1 adds domain constraints and transactional tests. P3.1 must specify and test device-scoped delegated kiosk actions before terminal-only sending ships; P5.1 does the same for terminal-only recipient code entry. App-assisted flows may precede those paths.

| ID | Dependencies | Concrete deliverable | Completion evidence |
|---|---|---|---|
| P0.1 | none | New project layout, exact runtime/SDK ADR, development env and CI | API/web/mobile skeleton builds; terminal build dependency inventory |
| P0.2 | none | Hardware simulator, protocol fixture set, synthetic ownership | V1/V2 address/CRC/parser tests; unknown profile rejects |
| P0.3 | none | Deployed-writer audit and legacy DB schema-only export comparison | Every allocation/open/reset writer catalogued; missing ZipporaService addressed |
| P1.1 | P0.1 | Execute new DB migrations 001/002, constraints and seed loader | MySQL tests, no connection to legacy prod DB |
| P1.2 | P1.1 | Auth, verified contacts, roles, device enrollment, account-link adapter | Cross-user/role/site access-denial tests |
| P1.3 | P1.1 | Topology/ownership and reservation service | Race for last compartment yields one winner |
| P2.1 | P1.2 | Shipment, quote, provider sandbox payment, SI/label | Stable identity, revoked-label failure, QR decode roundtrip |
| P2.2 | P2.1 | Customer web and native shipping/label/tracking | Verified-account → paid shipment → printable label |
| P3.1 | P0.2,P1.3,P2.1 | Terminal session/journal, pairing and origin deposit | Power-loss/duplicate callback/reconnect tests |
| P3.2 | P3.1 | Inbound driver app + assigned routes + per-item pickup | Five destinations all taken to hub, five distinct scans |
| P3.3 | P3.2 | Hub receiving and discrepancy workbench | Four of five receipt leaves one outstanding driver parcel |
| P4.1 | P3.3 | Sortation, stage slots, waves and route publishing | Wrong destination slot rejects; sticker revision matches |
| P4.2 | P4.1 | Outbound load and ordered-stop driver workflow | Ten scans; 6+4 grouping; 9+duplicate cannot depart |
| P5.1 | P4.2 | Final deposit and recipient claim/pickup | Wrong locker blocked before open; scan alone not delivered |
| P5.2 | P5.1 | Exceptions, hub return, quarantine and shift close | Driver custody retained until independent hub receiving |
| P6.1 | P0.3,P1.3 | Legacy backend/admin guards and terminal gate for every open path | Reset/maintenance paths cannot allocate/open delivery door |
| P6.2 | P6.1,P5.2 | Real hardware commissioning of one public + one apartment site | Physical mapping, no cross-system collision, legacy regression |
| P7.1 | P5.2 | Admin policies, staff/vehicles, finance export, claims and reports | Scoped roles, reliable reconciliation reports |
| P7.2 | P7.1 | Provider adapters, operational monitoring, backup/restore | Notification retry; payment replay/refund bounds |
| P8.1 | P6.2,P7.2 | Supervised full pilot rehearsal and rollback | All critical scenarios passed and signed off |

## Required cross-application scenarios

T01 customer gets SI and readable label before unattended deposit; invalid/expired quote/payment cannot authorize door.

T02 account registration at public site with no roster; linked apartment account cannot access other apartments automatically; unverified recipient cannot claim by email knowledge alone.

T03 sender label scan + scoped pairing + correct origin + compatible owned door succeeds; duplicate label on another parcel raises conflict.

T04 pickup five parcels for five destinations; driver scans each, follows inbound route to hub; hub receives four, fifth remains unresolved with driver.

T05 sort into wrong slot/run rejected, correct staging succeeds; replacing label preserves package/SI and rejects old QR; route sticker never transfers custody.

T06 ten packages to two final lockers; nine scans plus duplicate remains nine; wrong driver/run/hub rejected; complete ten permits 6+4 ordered-stop route.

T07 two drivers race loading one package, and two sessions race last compartment; one accepted allocation/transfer each, no overwritten custodian.

T08 wrong final locker rejected before actuation; correct scan without door evidence stays pending; valid close+attestation commits once.

T09 crash before send, after send, after physical close and before server response; no blind reopen, no premature free, events reconcile exactly once.

T10 late/replayed/out-of-order device callback or stale ownership generation; no extra custody/notification; no wrong door correlation.

T11 hub/driver device offline can view cached route, queued scan remains pending; no unauthorized departure/new opening.

T12 full/offline destination → explicit return task; package stays with driver until hub receiver scan; route completion cannot erase outstanding custody.

T13 legacy carrier and new sender operate simultaneously on partitioned site; legacy bulk reset, maintenance open and direct box update cannot free/open delivery doors.

T14 ownership update only after freeze/drain/physical check/all ACKs; network failure midway keeps frozen; rollback does not restore unsafe old terminal.

T15 recipient wrong/expired/replayed grant denied; valid physical pickup completes one parcel; notification failure retried independently.

T16 invalid CRC, fragmented frames, wrong-address response, protocol offset and scanner formatting validated on actual board/device matrix.

T17 valid provider webhook replay causes one paid state; invalid signature/amount rejected; duplicate refund idempotent and total refunds bounded.

T18 application DB role cannot rewrite custody/audit; tenant/user/hub scope tested; labels and logs contain no pickup secret/PII leakage.

T19 legacy apartment receiving/pickup unaffected when delivery feature disabled and when enabled on other owned doors.

T20 backup/restore with active physical parcels reconciles current custody, does not replay door commands or delete unacknowledged terminal journal.

## Test layers

Contract/schema tests first; domain unit tests; real MySQL transactional tests; API/web/mobile/terminal-simulator integration; physical hardware-in-loop; staffed pilot acceptance. The document validation script only verifies handoff files/refs/fixtures; it cannot substitute for any production behavior test above.
