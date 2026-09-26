# API and terminal event contract

`contracts/openapi.json` is the machine-readable implementation contract for core Phase 1 commands and reads. Base `/api/delivery/v1`, JSON, UUID/string IDs, UTC RFC3339 timestamps. New API is not legacy `ret/msg/data` or GET mutation routes. Administrative policy screens beyond core contract are implemented in PR 7 with explicit schema additions, not undocumented arbitrary CRUD.

## Authentication and writes

Browser: server-managed secure HttpOnly SameSite session cookie and CSRF header for writes. Native: short-lived Bearer token and rotated refresh token in secure OS storage; revocation on logout/suspension. Terminal: independent per-device credential authentication over TLS; bind authenticated identity to own locker. Operator mobile credentials cannot impersonate devices. OpenAPI expresses transport schemes, while service-level roles are in operation descriptions/doc 06.

Every business POST except initial registration/login uses Idempotency-Key; initial register/login is rate-limited and challenge-protected. Device webhook/event submission also uses unique event UUID plus signed/enrolled channel. Use If-Match for updates of existing package/run/session, and body run_revision where two aggregates are involved. New create commands do not require a preexisting aggregate ETag. GET responses expose version and ETag when applicable. Store canonical payload hash per scope/key; same key/different payload → 409.

Error envelope: code, message, correlation_id, retryable, optional current_version/recovery_action. 400 malformed; 401 auth; 403 role/scope; 404 unavailable or cross-scope resource; 409 stale version/wrong state/wrong destination/duplicate conflict; 422 capacity/label/field validation; 429 rate limit; 503 dependency unavailable. Do not leak another customer's address/identity in error messages.

## Primary scan command

Concurrency clarification: `If-Match` is the quoted version of the resource in the path. For `/runs/{run_id}/...` it is the run revision, also matched by `run_revision` when supplied. Manifest/assignment/order changes advance that revision; individual scans update package versions and transactional counters without revising the manifest. `expected_package_version` independently protects the resolved package. After authentication and scope checks, a matching stored idempotency response is replayed before new-operation version checks. See [execution details](13_IMPLEMENTATION_EXECUTION.md).

POST /runs/{run_id}/scans body: label_payload, action INBOUND_PICKUP/OUTBOUND_LOAD/FINAL_DEPOSIT, client_event_id, run_revision, optional stop_id/session_id, expected_package_version. Auth actor is server-derived. Resolve action and label; OUTBOUND_LOAD may immediately transfer custody when correctly staged; locker actions return AWAITING_DEVICE_EVIDENCE/session_id until terminal facts confirm. Barcode lookup endpoint is read-only in effect, never transfers custody.

Response includes result ACCEPTED/ALREADY_PROCESSED/AWAITING_DEVICE_EVIDENCE, package_id/SI, final_location_id, stop_sequence, package_version, run_revision, counters expected/accepted/pending and can_depart. Counts are server-computed. App cannot report final location as authority; backend resolves from shipment.

## Terminal pairing/session

The initial slice uses app approval and app-created sessions. Terminal-only entry and delegated attestation need the device-scoped contract additions explicitly assigned to P3.1/P5.1 in [the execution contract](13_IMPLEMENTATION_EXECUTION.md); the current core OpenAPI does not yet describe those additional commands. Do not silently let device credentials use customer endpoints.

Terminal creates pairing scene with requested workflow and location bound to enrolled device. App approves scene only after showing site/action; backend records actor approval and creates short-lived role-scoped grant. Terminal polls scene/session via own authentication. Label or public SI must accompany actual shipment action where required, but does not authorize it.

POST /locker-sessions: package_id, action ORIGIN_DEPOSIT/INBOUND_PICKUP/FINAL_DEPOSIT/RECIPIENT_PICKUP, pairing_id or pickup_grant, requested_size if deposit. Server selects physical door and returns session_id/status/compartment display/version. No client-selected electrical board/door for customer action. Authenticated terminal GET /devices/me/commands returns ready commands with command_id/session_id, physical address, protocol_profile, ownership_generation, expires_at and policy version.

POST /devices/me/events: event_id, boot_id, sequence, command_id, session_id, event_type DISPATCH_RECORDED/OPEN_OBSERVED/CLOSE_OBSERVED/UNKNOWN, observed_at, frame_hash and normalized physical address. Service checks device/locker/session/address/generation and active operation. Real raw board cannot sign business IDs; terminal signs authenticated telemetry and correlates serial evidence. Do not invent board-level signed acknowledgments. Evidence may be delayed and must be reconciled by observed order and current session, not blindly rejected because HTTP retry is late.

User attestation is submitted separately by session actor after close; endpoint checks actor's active pairing/session. Server chooses assurance level (DOOR_PLUS_ACTOR; SENSOR_PLUS_ACTOR if verified supported hardware). Confirmed session appends custody and occupancy transaction plus notification outbox. Label scan or command ACK alone cannot do this.

## Hub and route rules

Hub receive includes primary label, expected inbound run and receiving session. Stage includes package label and slot code/run revision; server validates destination. Publish run freezes manifest revision and validates hours/load limits. Departure requires exact loaded-set equality and actor custody. Complete run requires every manifest item resolved; exceptions may close planned items only with explicit disposition/return allocation, never erase custody. Driver assignment, partial departure and stop reorder are dispatcher actions with reason and new revision.

## External providers and retries

Payment webhook endpoint accepts provider raw payload for signature verification, not a normalized client-paid flag. Notification provider callback similarly authenticated/deduped. No provider API keys inside mobile/website. Map adapter returns ordered coordinates/travel estimates or unavailable; stored route order remains usable. Client event IDs survive restart; trace ID and source aggregate IDs propagate to server events.

## Legacy adapter contract (internal, separately deployed)

ReadTopology(site) returns cabinet/body/box IDs, electrical addresses, type/dimensions where known, operational status and config version. ReadEligibility(linkProof, site) returns approved/expired membership assertion, not a resident list. ApplyOwnershipGeneration is a privileged configuration deployment with validation and ACK, not a customer HTTP API. Legacy operations get owner-filtered availability and command authorization. Never proxy new shipment create/payment/hub data into o_store.

## Phase 1 canonical amendment — 2026-09-26

Read [phase1_END_TO_END_DELIVERY_FLOW.md](../../phase1_END_TO_END_DELIVERY_FLOW.md).

Add API families/contracts for:

Customer:
- list active size classes, interior dimensions and prices;
- create/update shipment with recipient snapshot, destination locker and estimated size;
- "send to myself";
- request origin size upgrade;
- create/confirm payment adjustment before larger compartment session;
- authenticated tracking using SI;
- create/revoke/share pickup grant where sender-as-recipient policy allows.

Driver:
- set/read availability;
- list/request nearby pickup opportunities;
- receive/read offers;
- accept/decline offer atomically with offer revision/idempotency key;
- read assembled multi-locker INBOUND run.

Operations:
- list/re-offer/override pickup demands;
- inspect offer/assignment history;
- configure size/rate policy.

Worker/events:
- `ORIGIN_DEPOSIT_CONFIRMED` -> pickup demand creation/aggregation;
- pickup offer issued/expired/accepted;
- demand assigned/re-offered;
- `FINAL_DEPOSIT_CONFIRMED` -> READY_FOR_PICKUP notification.

SI remains a public identifier and tracking key; it is never a pickup credential.

