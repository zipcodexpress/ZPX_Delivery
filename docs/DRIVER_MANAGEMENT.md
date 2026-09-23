# Driver Management

Purpose: driver lifecycle, approval workflow, compensation and wallet.
Audience: developers, operations staff and project owner.
Status: implemented (P3.2 driver management increment).
Last reviewed: 2026-09-21.

## Driver Lifecycle

```
REGISTER → PENDING → APPROVED (ACTIVE) → WORKING → PAID
                ↘ REJECTED (INACTIVE)
```

| Status | Meaning |
|---|---|
| `PENDING` | Driver registered, awaiting admin approval |
| `ACTIVE` | Approved, can be assigned runs and work |
| `SUSPENDED` | Temporarily disabled by admin |
| `INACTIVE` | Rejected or permanently deactivated |

## Registration & Approval

### Driver Self-Registration

Drivers register through the API:

```
POST /api/delivery/v1/driver/register
{
  "engagement_type": "CONTRACTOR" | "EMPLOYEE" | "SYNTHETIC",
  "phone": "+12025550100",
  "email": "driver@example.com"
}
```

Response: `{ "driver_id": "1", "status": "PENDING", "message": "..." }`

The driver account must already exist (registered through normal auth). The driver registration creates a driver profile linked to their user account.

### Admin Approval

Admin users approve or reject pending drivers:

```
POST /api/delivery/v1/admin/drivers/{driver_id}/approve
Headers: Idempotency-Key, X-CSRF-Token

POST /api/delivery/v1/admin/drivers/{driver_id}/reject
{ "reason": "Incomplete documentation" }
```

Admins can list all pending drivers:

```
GET /api/delivery/v1/admin/drivers/pending
```

## Driver Profile

Drivers can view their own profile:

```
GET /api/delivery/v1/driver/profile
```

Returns:
- Driver ID, name, status, engagement type
- Contact info (email, phone)
- Application/approval dates
- Current vehicle assignment (if on active shift)

## Wallet & Compensation

### Compensation Model (Phase 1)

Per the design documents: *"No incremental-minutes payout formulas in pilot; compensation is fixed shift/route export configured by operations."*

Phase 1 uses **fixed-rate compensation**:
- Each shift has a `compensation_cents` amount set by operations
- Policy types: `FIXED_SHIFT` (flat rate per shift), `FIXED_ROUTE` (flat rate per route)
- Compensation is recorded after run completion by an admin

### Wallet

Drivers can view their earnings:

```
GET /api/delivery/v1/driver/wallet
```

Returns:
- `total_earned_cents` — all-time earnings
- `total_settled_cents` — amounts already paid out
- `pending_cents` — earned but not yet settled
- `current_shift` — active shift compensation details

### Transaction History

Drivers can view their payment history:

```
GET /api/delivery/v1/driver/transactions?cursor=
```

Each transaction includes:
- Amount, type (SHIFT_PAY, BONUS, etc.)
- Associated run (if applicable)
- Created date, settlement status
- Settlement reference (when paid)

### Recording Run Payment

Admins record compensation after a run is completed:

```
POST /api/delivery/v1/admin/driver-pay
{ "driver_id": "1", "run_id": "5" }
```

The system:
1. Verifies the run is in `COMPLETED` state
2. Looks up the compensation rate from the driver's shift
3. Creates a `driver_pay_entries` record
4. Prevents duplicate payments for the same run

## Database Schema

### Migration 011: Driver Management

Added to `drivers` table:
- `phone`, `email` — driver contact info
- `applied_at`, `approved_at`, `approved_by` — approval workflow timestamps
- `rejection_reason` — reason for rejection (if applicable)
- Status constraint: `PENDING`, `ACTIVE`, `SUSPENDED`, `INACTIVE`

Added to `driver_shifts` table:
- `compensation_cents` — fixed rate for the shift
- `compensation_policy` — `FIXED_SHIFT` or `FIXED_ROUTE`
- `settled_at`, `settlement_reference` — payment tracking

New indexes:
- `ix_drivers_status` — filter by driver status
- `ix_driver_pay_driver` — driver payment history

## API Endpoints Summary

| Endpoint | Method | Auth | Description |
|---|---|---|---|
| `/driver/register` | POST | Customer | Register as driver |
| `/driver/profile` | GET | Driver | View own profile |
| `/driver/wallet` | GET | Driver | View earnings |
| `/driver/transactions` | GET | Driver | Payment history |
| `/driver/runs` | GET | Driver | Assigned runs |
| `/admin/drivers/pending` | GET | Admin | List pending drivers |
| `/admin/drivers/{id}/approve` | POST | Admin | Approve driver |
| `/admin/drivers/{id}/reject` | POST | Admin | Reject driver |
| `/admin/driver-pay` | POST | Admin | Record run payment |

## Frontend

The driver workspace (`packages/ui/DriverWorkspace.tsx`) includes four tabs:

1. **Runs** — assigned run list, manifest detail, scan interface
2. **Profile** — driver info, vehicle, approval status
3. **Wallet** — earnings summary, current shift compensation
4. **Transactions** — payment history with settlement status

### Local DRIVER-IN scan testing

Run `python3 scripts/dev.py seed`, sign in to the operations portal as the generated `DRIVER-IN`
account, and acknowledge its published inbound run. The five manifest packages can then be scanned
in order with `TEST-LABEL-001` through `TEST-LABEL-005`. These deterministic values exist only for
the guarded development/test fixture; `package_labels` stores their SHA-256 hashes, not plaintext.
In development, the workspace shows each token in the manifest; selecting it copies the value into
the scan field. Production label generation and API responses do not expose raw label tokens.

## Future Work

- Driver self-service profile editing (name, contact, vehicle)
- Shift scheduling and assignment UI
- Per-delivery or per-mile compensation models (post-pilot)
- Automated settlement runs (batch payment processing)
- Driver rating and performance metrics
- Mobile app integration for driver workflows
