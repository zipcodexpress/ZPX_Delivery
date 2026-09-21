# Terminal application and hardware integration

## Build strategy

One kiosk host/process owns serial port and command gate. At apartment sites preserve resident/carrier UI behind LegacyWorkflowAdapter and add isolated DeliveryWorkflow module; public sites hide apartment lookup screens. Do not run a second terminal executable controlling the same serial port. New backend/business domain is separate even though compatible kiosk host is shared.

Use existing C# WinForms host for compatibility work; new files proposed: Delivery/WorkflowCoordinator.cs, Delivery/DeliveryApiClient.cs, Delivery/CommandGate.cs, Delivery/JournalStore.cs, Delivery/OwnershipManifest.cs, Hardware/ILockerHardware.cs, Hardware/LegacyV1Adapter.cs, Hardware/LegacyV2Adapter.cs, Hardware/SimulatorAdapter.cs. These files do not yet exist. Native/runtime upgrade is a separate reviewed task, not an implicit prerequisite for writing the simulator/API.

## Adapter contract

`OpenDoor(PhysicalAddress, commandId)` returns local dispatch result only; `QueryDoor(PhysicalAddress)` returns OPEN/CLOSED/UNKNOWN plus observation timestamp and raw-frame hash; `ReceiveFrame(bytes)` emits parsed observations with protocol profile, board and door; `GetCapabilities()` reports query/ACK/printer/scanner availability. Business package IDs never alter raw hardware bytes.

V1 source emits prefix `16 67 00 03 01 board door CRC16` for open and `16 67 00 03 02 board DD CRC16` for query. Use existing CRC implementation and test vectors, not a guessed library default. V2 adds one to source door address. V4 is not factory-enabled in examined code. Query response bit positions, status polarity and serial settings must be captured from each actual controller family before enablement. No parcel-presence sensor was established by this review; door closure cannot prove contents.

Hardware does not echo new business command_id. Maintain correlation in terminal journal, serialize actuation/status cycles, reject stale responses, and record assurance honestly. A network command ID can deduplicate terminal dispatch but cannot prove the physical lock actuated only once across power loss. Exactly-once custody is server-enforced; unknown actuation enters reconciliation.

## Home and navigation

Home: Pick Up / Send a Package / Carrier or Driver Delivery; separate staff maintenance entry. Pick Up routes explicit legacy credential format/mode to legacy API, new access-grant format to delivery API. Prefix dispatch rejects ambiguity; never try the same secret against both APIs automatically. Send requires paired authenticated customer session plus primary label; carrier/driver requires authenticated assigned role. Apartment roster display remains inside legacy workflow only. Public site does not show a fictitious resident list.

Pairing: kiosk displays short-lived scene QR containing scene ID only; customer/driver app authenticates and approves specific site/action; backend creates terminal-scoped authorization. Poll until approved/expired/cancelled. Scene by itself grants nothing. App must display locker name before approval. Sender scans primary label at terminal; driver scans using phone, with package/action confirmation visible at terminal for active session.

## Deposit state machine

IDLE → AUTHENTICATED → LABEL_VALIDATED → RESERVED → OPEN_INTENT_DURABLE → OPEN_DISPATCHED → OPEN_OBSERVED → CLOSE_OBSERVED → ATTESTED → SERVER_CONFIRMED. Use COMMITTED_PENDING_SYNC if physical sequence completes without network. FAILED_PRE_DISPATCH may release reservation only when no command was dispatched. Any timeout after OPEN_DISPATCHED → RECONCILIATION_REQUIRED; compartment remains unavailable.

Pre-dispatch: verify actor session, SI origin/final destination according to action, assigned run, capacity, owner generation and current closed observation. Journal intent BEFORE send. One selected compartment opens at a time for delivery workflow; do not open a whole manifest. On observed close ask parcel placed/removed confirmation; send evidence to server. Hardware-supported occupancy adds assurance but cannot be assumed. No automatic reopen on commit/network failure (legacy frmOpen has reopen-on-commit-failure paths that must not be copied into new flows).

Pickup: authorize assigned parcel compartment, observe opening, scan removed parcel individually, close door, attest removal, then confirm custody. Wrong parcel scan blocks handoff and enters guided replace/reconcile path. Recipient scan is of pickup credential, not required package-label scan after removal; device close and recipient attestation use its own evidence policy. Administrative override requires reason and audit; it never fabricates a physical scan.

## Journal

Use transactional local SQLite journal distinct from legacy task.db. Tables: local_commands(command_id PK, session_id, physical_key, owner_generation, state, payload_hash, dispatched_at); local_events(event_id PK, command_id, boot_id, sequence, evidence_json, acknowledged_at); ownership_cache(generation, signed_manifest, applied_at). Durable write before dispatch; monotonically increasing sequence per boot; event UUID unique across boots. Do not overwrite unacknowledged events on restart. Never log access secrets or full recipient details.

Startup: load last acknowledged ownership; pause new delivery sessions until online policy refresh; query outstanding doors; send queued evidence; server decides recovery. Before-dispatch expired permission is denied. After-dispatch evidence may be delivered after token expiry if correlated to previously accepted command; expiry must not erase real physical events. Offline terminal may finish an already-authorized physical session and queue facts, but may not authorize a new parcel transaction. If journal cannot write, block new opens.

## Shared-site command gate

Wrap every BoxHelper.OpenBox, direct controller.OpenLocker, maintenance open and other serial actuation path. Legacy intent can address only LEGACY ownership; new intent only DELIVERY; FROZEN rejects all normal opens. Emergency maintenance is explicit supervised gate operation with reason, not a bypass. Generation mismatch freezes. Active command prevents ownership change. Existing global status caches must not be accepted as current evidence without address/time/session checks.

## Hardware/build acceptance

Resolve missing ZipporaService source/reference, actual DLLs (including SQLite/printing/audio dependencies), x86/x64 behavior, terminal OS image, protocol variants and scanners. Verify split/combined serial frames, corrupt CRC, wrong-address response, disconnect, two quick scans, printer jam, non-ASCII labels, power loss between journal and send, and restart with door open. Test real apartment deposit/pickup alongside new transactions on partitioned doors before rollout.
