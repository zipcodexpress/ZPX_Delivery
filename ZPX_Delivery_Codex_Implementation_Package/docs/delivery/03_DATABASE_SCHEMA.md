# Database Schema

See `../../sql/001_delivery_core.sql`.

## Main table groups

### Shipment
- delivery_shipments
- delivery_packages
- delivery_shipping_identifiers
- delivery_shipment_events

### Routing
- delivery_route_plans
- delivery_route_plan_nodes
- delivery_shipment_legs
- delivery_routing_decisions

### Locker
- delivery_locker_nodes
- delivery_locker_capacity_snapshots
- delivery_locker_reservations

### Driver
- delivery_driver_profiles
- delivery_vehicles
- delivery_driver_trips
- delivery_driver_trip_routes
- delivery_driver_location_events

### Dispatch / Batch
- delivery_driver_tasks
- delivery_driver_task_stops
- delivery_driver_task_legs
- delivery_route_manifests
- delivery_route_manifest_items
- delivery_match_offers
- delivery_match_score_details

### Custody
- delivery_custody_events

### Money
- delivery_pricing_quotes
- delivery_payments
- delivery_driver_earning_lines
- delivery_driver_settlements

### Operations
- delivery_claims
- delivery_notifications
- delivery_scheduled_routes
- delivery_scheduled_route_runs
