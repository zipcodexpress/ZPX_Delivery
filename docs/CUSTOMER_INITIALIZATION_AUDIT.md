# Customer shipment initialization audit

Phase 1 contract: [phase1_END_TO_END_DELIVERY_FLOW.md](phase1_END_TO_END_DELIVERY_FLOW.md), sections 2–7 and 21. Inspected 2026-09-26 before implementation.

| Required capability | Baseline | Evidence and gap |
|---|---|---|
| New customer registration, login, verification, profile | IMPLEMENTED | `apps/api/src/Identity/Service.php`, `packages/ui/Account.tsx`, `apps/api/tests/identity.php`; development verification delivery only. |
| Recipient contact/address or send to myself | PARTIAL | `apps/api/src/Shipping/Service.php::create` and `packages/ui/Shipping.tsx` collect contact but omit address and self choice. |
| Origin selection | IMPLEMENTED | `Shipping.tsx` / `LockerMap.tsx` and `Shipping/Service.php::locations`; synthetic sites permit draft planning only. |
| Destination search/selection | IMPLEMENTED | `LockerMap.tsx` search and selected destination in `Shipping.tsx`. |
| SMALL/MEDIUM/LARGE with published limits | MISSING | Form accepts dimensions and weight only; no persisted class or rate-card limits. |
| Size-only authoritative quote | MISSING | `Shipping/Service.php::quote` charges by weight with hard-coded demo version. |
| Server-confirmed payment | IMPLEMENTED | `Shipping/Service.php::confirmPayment`, `Payments/HostedCheckout.php`; local simulation and Authorize.net sandbox only. |
| Finalization after payment | PARTIAL | Paid shipment becomes `READY`, but customer journey status is not explicit. |
| Stable SI from creation | PARTIAL | `Shipping/Service.php::label` issues SI only on first label generation. |
| Primary printable/scannable label | IMPLEMENTED | `Shipping/Service.php::label` and `LabelPdf.php`; development-only. |
| READY_FOR_ORIGIN_DEPOSIT | MISSING | API and customer UI expose generic `READY`; no explicit journey state. |

Implementation target: close these initialization gaps while preserving existing payment, custody, and label behavior. Production site commissioning, origin deposit, size adjustment, and driver offers remain subsequent milestones.
