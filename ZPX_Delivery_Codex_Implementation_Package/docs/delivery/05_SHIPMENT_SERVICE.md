# Shipment Service

Responsibilities:
- create shipment
- package snapshot
- recipient snapshot
- apply quote
- payment state
- create SI
- initiate origin reservation
- expose tracking
- transition state through legal transitions only

Pseudo-interface:

```php
interface ShipmentServiceInterface {
    public function createShipment(CreateShipmentCommand $cmd): Shipment;
    public function applyPayment(string $shipmentId, PaymentResult $payment): Shipment;
    public function markDeposited(string $shipmentId, DepositContext $ctx): Shipment;
    public function activateRoutePlan(string $shipmentId, int $routePlanId): void;
    public function getCustomerTracking(string $shipmentId): TrackingView;
}
```

Shipment service MUST NOT decide driver assignment.
Shipment service MUST NOT directly open locker compartments.
