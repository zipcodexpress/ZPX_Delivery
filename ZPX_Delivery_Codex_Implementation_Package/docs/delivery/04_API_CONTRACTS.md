# REST API Contracts

All mutating APIs should require:
- authenticated principal
- request_id / idempotency_key
- timestamp
- API version

## Consumer

### POST /api/delivery/shipments/quote

Request:
```json
{
  "origin_locker_id": 101,
  "destination_locker_id": 901,
  "package": {
    "weight_lb": 4.2,
    "length_in": 12,
    "width_in": 8,
    "height_in": 6
  },
  "service_level": "SAME_DAY"
}
```

Response:
```json
{
  "quote_id": "Q-...",
  "currency": "USD",
  "price": 13.95,
  "expires_at": "...",
  "estimated_delivery_at": "...",
  "estimated_legs": 2
}
```

### POST /api/delivery/shipments

Create shipment from accepted quote.

### POST /api/delivery/shipments/{id}/pay

### POST /api/delivery/shipments/{id}/cancel

### GET /api/delivery/shipments/{id}/tracking

Customer tracking should return simplified states, not internal routing details by default.

## Driver

### POST /api/delivery/driver/trips

```json
{
  "origin": {"lat": 30.2672, "lng": -97.7431},
  "destination": {"lat": 32.7767, "lng": -96.7970},
  "planned_departure_at": "...",
  "latest_departure_at": "...",
  "maximum_detour_minutes": 10,
  "maximum_detour_miles": 8,
  "vehicle_id": 44,
  "capacity": {
    "max_package_count": 35,
    "max_weight_lb": 300,
    "small": 30,
    "medium": 15,
    "large": 4
  }
}
```

### GET /api/delivery/driver/trips/{id}/matches

Returns ranked route/package opportunities.

### POST /api/delivery/driver/offers/{id}/accept

### POST /api/delivery/driver/tasks/{id}/start

### POST /api/delivery/driver/tasks/{id}/complete

### GET /api/delivery/driver/tasks/{id}

### GET /api/delivery/driver/earnings

## Locker Validation

### POST /api/delivery/locker/validate-origin-deposit

### POST /api/delivery/locker/validate-relay-drop

### POST /api/delivery/locker/validate-relay-pickup

### POST /api/delivery/locker/validate-final-drop

### POST /api/delivery/locker/validate-recipient-pickup

Example relay-drop request:

```json
{
  "request_id": "uuid",
  "locker_id": 2002,
  "driver_id": 883,
  "shipment_id": "SHP-...",
  "shipment_leg_id": 1233,
  "si_code": "58372",
  "manifest_id": "MAN-...",
  "device_id": "terminal-..."
}
```

Response:

```json
{
  "authorized": true,
  "authorization_id": "AUTH-...",
  "compartment_id": 18,
  "expires_at": "...",
  "next_action": "OPEN_COMPARTMENT"
}
```

Wrong final locker:

```json
{
  "authorized": false,
  "error_code": "WRONG_FINAL_DESTINATION",
  "correct_locker_id": 2091
}
```

## Routing

### POST /api/delivery/routing/plan

### POST /api/delivery/routing/recalculate

### GET /api/delivery/routing/shipments/{id}/candidates

## Matchmaking

### POST /api/delivery/matching/run

Internal/admin endpoint.

### GET /api/delivery/matching/legs/{leg_id}/candidates
