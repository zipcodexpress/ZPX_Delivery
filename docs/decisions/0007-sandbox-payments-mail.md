# Sandbox payments and verification delivery

Purpose: record P2.1/P2.2 provider boundaries. Audience: developers and reviewers.
Status: Accepted for development. Owner: unassigned. Reviewed: 2026-09-19.

Richard selected Authorize.net and supplied sandbox and SMTP settings in private
`.env.dev`. Customers enter card details on Accept Hosted. The API fixes both
provider URLs to sandbox and refuses production mode. No live payment support is enabled.

A shipment's stored quote creates one pending checkout and a stable invoice reference.
The hosted token is encrypted at rest and returned only to its verified sender.
Token creation occurs outside SQL transactions under a session advisory lock. A
retry reuses the token; an expired or ambiguous checkout is retained for review,
not silently replaced with another potentially chargeable checkout.

A browser receipt never confirms payment. Authenticated reconciliation or a
signature-verified notification fetches transaction details from Authorize.net.
Invoice, exact cents, capture type/status, response code and submission time must
match the saved checkout. A locked transaction updates payment/order, audit,
package event and outbox together. Provider transaction IDs are unique and replay
records one event. Payment does not change physical custody.

Localhost is not an externally reachable webhook endpoint. The portal therefore
supports bounded unsettled-transaction lookup and an optional receipt transaction
ID for direct lookup. External webhook delivery remains unverified; production
requires HTTPS enrollment, captured provider signature evidence and durable
notification ingestion. Refunds, voids, chargebacks and automatic recovery of
ambiguous/expired payments are not implemented.

Verification outbox payloads remain encrypted. An opt-in worker claims jobs with
leases, performs SMTP outside a transaction, records SMTP acceptance separately
from queued status, retries up to five attempts and skips expired/consumed codes.
Stable Message-ID mitigates duplication; SMTP does not provide exactly-once
semantics after a crash. Lease fencing prevents an old worker overwriting a newer
attempt. Only explicitly allowed development email recipients can use SMTP;
synthetic addresses and phone verification remain local. Previously local messages
are not promoted or drained. The normal development stack leaves this worker stopped.

Runtime containers never receive root or migration DB credentials. Mail credentials
are separated from the web API. SMTP requires verified TLS. Legacy SMTP_* names
are supported where MAIL_HOST is blank, preserving existing private configuration.

References: [Accept Hosted](https://developer.authorize.net/api/reference/features/accept-hosted.html),
[webhooks](https://developer.authorize.net/api/reference/features/webhooks.html),
[sandbox testing guide](https://developer.authorize.net/hello_world/testing_guide.html).
