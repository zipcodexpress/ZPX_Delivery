# Existing ZipcodeXpress Integration Strategy

## Principle

Reuse current ZipcodeXpress locker software wherever it already safely provides:
- locker identity
- terminal/device identity
- door control
- compartment inventory
- user authentication where appropriate
- package notifications where appropriate

Add a new Delivery Network domain through explicit APIs.

## Adapter boundary

Create a `LockerPlatformAdapter` so the logistics domain does not depend directly on legacy controller/database details.

Suggested methods:

```php
interface LockerPlatformAdapter {
    public function getLocker(int $lockerId): LockerView;
    public function getAvailableCompartments(int $lockerId, PackageDimensions $pkg): array;
    public function reserveCompartment(int $lockerId, PackageDimensions $pkg, string $reference): Reservation;
    public function authorizeOpen(int $lockerId, int $compartmentId, LockerAuthorization $authorization): OpenResult;
    public function releaseReservation(string $reservationId): void;
}
```

## Do not

- write new logistics code directly against legacy locker tables from many modules,
- let driver app call locker terminals directly,
- embed delivery routing into legacy locker firmware unless absolutely required,
- duplicate door-control logic.

## Preferred integration

Driver/consumer app
-> Delivery API
-> Validation Service
-> LockerPlatformAdapter
-> Existing ZPX Locker Platform
