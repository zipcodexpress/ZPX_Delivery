-- ZPX Delivery Network initial logical schema.
-- Adapt naming/types to the existing production DB conventions before migration.

CREATE TABLE delivery_locker_nodes (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  locker_id BIGINT NOT NULL,
  node_code VARCHAR(64) NOT NULL UNIQUE,
  endpoint_enabled TINYINT(1) NOT NULL DEFAULT 1,
  relay_enabled TINYINT(1) NOT NULL DEFAULT 0,
  hub_enabled TINYINT(1) NOT NULL DEFAULT 0,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Chicago',
  status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_delivery_locker_nodes_locker (locker_id),
  KEY idx_delivery_locker_nodes_status (status)
);

CREATE TABLE delivery_shipments (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  shipment_no VARCHAR(64) NOT NULL UNIQUE,
  sender_user_id BIGINT NULL,
  recipient_user_id BIGINT NULL,
  recipient_name VARCHAR(255) NULL,
  recipient_phone VARCHAR(64) NULL,
  origin_locker_node_id BIGINT NOT NULL,
  final_destination_locker_node_id BIGINT NOT NULL,
  service_level VARCHAR(32) NOT NULL,
  status VARCHAR(40) NOT NULL,
  active_route_plan_id BIGINT NULL,
  promised_delivery_at DATETIME NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  quoted_price DECIMAL(12,2) NULL,
  paid_price DECIMAL(12,2) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_delivery_shipments_status (status),
  KEY idx_delivery_shipments_origin (origin_locker_node_id),
  KEY idx_delivery_shipments_destination (final_destination_locker_node_id)
);

CREATE TABLE delivery_packages (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  shipment_id BIGINT NOT NULL,
  package_code VARCHAR(64) NOT NULL UNIQUE,
  size_class VARCHAR(24) NULL,
  weight_lb DECIMAL(10,2) NULL,
  length_in DECIMAL(10,2) NULL,
  width_in DECIMAL(10,2) NULL,
  height_in DECIMAL(10,2) NULL,
  declared_value DECIMAL(12,2) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_delivery_packages_shipment (shipment_id)
);

CREATE TABLE delivery_shipping_identifiers (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  shipment_id BIGINT NOT NULL,
  si_public_code VARCHAR(64) NOT NULL UNIQUE,
  final_destination_locker_node_id BIGINT NULL,
  destination_zone VARCHAR(64) NULL,
  relay_policy VARCHAR(64) NOT NULL DEFAULT 'AUTHORIZED_ROUTE_ONLY',
  status VARCHAR(24) NOT NULL DEFAULT 'ACTIVE',
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  KEY idx_delivery_si_shipment (shipment_id)
);

CREATE TABLE delivery_route_plans (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  shipment_id BIGINT NOT NULL,
  version_no INT NOT NULL,
  status VARCHAR(24) NOT NULL,
  reason_code VARCHAR(64) NULL,
  estimated_cost DECIMAL(12,2) NULL,
  estimated_minutes INT NULL,
  estimated_distance_miles DECIMAL(10,2) NULL,
  score DECIMAL(12,4) NULL,
  score_version VARCHAR(32) NULL,
  created_at DATETIME NOT NULL,
  activated_at DATETIME NULL,
  superseded_at DATETIME NULL,
  UNIQUE KEY uk_route_plan_version (shipment_id, version_no),
  KEY idx_route_plan_shipment_status (shipment_id, status)
);

CREATE TABLE delivery_route_plan_nodes (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  route_plan_id BIGINT NOT NULL,
  sequence_no INT NOT NULL,
  locker_node_id BIGINT NOT NULL,
  node_role VARCHAR(24) NOT NULL,
  planned_arrival_at DATETIME NULL,
  planned_departure_at DATETIME NULL,
  UNIQUE KEY uk_route_plan_node_seq (route_plan_id, sequence_no)
);

CREATE TABLE delivery_shipment_legs (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  shipment_id BIGINT NOT NULL,
  route_plan_id BIGINT NOT NULL,
  sequence_no INT NOT NULL,
  origin_locker_node_id BIGINT NOT NULL,
  destination_locker_node_id BIGINT NOT NULL,
  status VARCHAR(32) NOT NULL,
  earliest_pickup_at DATETIME NULL,
  latest_pickup_at DATETIME NULL,
  latest_dropoff_at DATETIME NULL,
  assigned_driver_id BIGINT NULL,
  assigned_driver_task_id BIGINT NULL,
  estimated_distance_miles DECIMAL(10,2) NULL,
  estimated_duration_minutes INT NULL,
  estimated_cost DECIMAL(12,2) NULL,
  actual_cost DECIMAL(12,2) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uk_route_leg_seq (route_plan_id, sequence_no),
  KEY idx_leg_status (status),
  KEY idx_leg_origin_status (origin_locker_node_id, status),
  KEY idx_leg_destination_status (destination_locker_node_id, status)
);

CREATE TABLE delivery_driver_profiles (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT NOT NULL UNIQUE,
  status VARCHAR(24) NOT NULL,
  reliability_score DECIMAL(6,3) NULL,
  rating DECIMAL(4,2) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE delivery_vehicles (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  driver_id BIGINT NOT NULL,
  vehicle_type VARCHAR(32) NOT NULL,
  make VARCHAR(64) NULL,
  model VARCHAR(64) NULL,
  plate VARCHAR(32) NULL,
  max_package_count INT NULL,
  max_weight_lb DECIMAL(10,2) NULL,
  small_capacity INT NULL,
  medium_capacity INT NULL,
  large_capacity INT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME NOT NULL
);

CREATE TABLE delivery_driver_trips (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  driver_id BIGINT NOT NULL,
  vehicle_id BIGINT NOT NULL,
  origin_lat DECIMAL(10,7) NOT NULL,
  origin_lng DECIMAL(10,7) NOT NULL,
  destination_lat DECIMAL(10,7) NOT NULL,
  destination_lng DECIMAL(10,7) NOT NULL,
  planned_departure_at DATETIME NOT NULL,
  latest_departure_at DATETIME NULL,
  expected_arrival_at DATETIME NULL,
  maximum_detour_minutes INT NOT NULL DEFAULT 10,
  maximum_detour_miles DECIMAL(10,2) NULL,
  available_package_count INT NULL,
  available_weight_lb DECIMAL(10,2) NULL,
  small_capacity INT NULL,
  medium_capacity INT NULL,
  large_capacity INT NULL,
  status VARCHAR(24) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_driver_trip_driver_status (driver_id, status),
  KEY idx_driver_trip_departure (planned_departure_at)
);

CREATE TABLE delivery_driver_trip_routes (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  driver_trip_id BIGINT NOT NULL UNIQUE,
  route_provider VARCHAR(32) NULL,
  encoded_polyline MEDIUMTEXT NULL,
  base_distance_miles DECIMAL(10,2) NULL,
  base_duration_minutes INT NULL,
  route_payload JSON NULL,
  created_at DATETIME NOT NULL
);

CREATE TABLE delivery_driver_tasks (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  task_no VARCHAR(64) NOT NULL UNIQUE,
  driver_id BIGINT NOT NULL,
  driver_trip_id BIGINT NULL,
  status VARCHAR(24) NOT NULL,
  estimated_payout DECIMAL(12,2) NULL,
  final_payout DECIMAL(12,2) NULL,
  estimated_distance_miles DECIMAL(10,2) NULL,
  estimated_duration_minutes INT NULL,
  incremental_distance_miles DECIMAL(10,2) NULL,
  incremental_duration_minutes INT NULL,
  accepted_at DATETIME NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_driver_task_driver_status (driver_id, status)
);

CREATE TABLE delivery_driver_task_stops (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  driver_task_id BIGINT NOT NULL,
  sequence_no INT NOT NULL,
  locker_node_id BIGINT NOT NULL,
  stop_type VARCHAR(24) NOT NULL,
  planned_arrival_at DATETIME NULL,
  actual_arrival_at DATETIME NULL,
  status VARCHAR(24) NOT NULL,
  UNIQUE KEY uk_task_stop_seq (driver_task_id, sequence_no)
);

CREATE TABLE delivery_driver_task_legs (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  driver_task_id BIGINT NOT NULL,
  shipment_leg_id BIGINT NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uk_task_leg (driver_task_id, shipment_leg_id)
);

CREATE TABLE delivery_route_manifests (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  manifest_no VARCHAR(64) NOT NULL UNIQUE,
  driver_task_id BIGINT NOT NULL,
  status VARCHAR(24) NOT NULL,
  authorization_token_hash VARCHAR(255) NULL,
  valid_from DATETIME NULL,
  valid_until DATETIME NULL,
  created_at DATETIME NOT NULL
);

CREATE TABLE delivery_route_manifest_items (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  manifest_id BIGINT NOT NULL,
  shipment_id BIGINT NOT NULL,
  shipment_leg_id BIGINT NOT NULL,
  package_id BIGINT NOT NULL,
  status VARCHAR(24) NOT NULL,
  UNIQUE KEY uk_manifest_package (manifest_id, package_id)
);

CREATE TABLE delivery_match_offers (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  shipment_leg_id BIGINT NULL,
  driver_task_id BIGINT NULL,
  driver_trip_id BIGINT NOT NULL,
  driver_id BIGINT NOT NULL,
  offer_type VARCHAR(24) NOT NULL,
  status VARCHAR(24) NOT NULL,
  match_score DECIMAL(12,4) NOT NULL,
  estimated_detour_minutes INT NULL,
  estimated_detour_miles DECIMAL(10,2) NULL,
  offered_payout DECIMAL(12,2) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  responded_at DATETIME NULL,
  KEY idx_match_offer_driver_status (driver_id, status)
);

CREATE TABLE delivery_match_score_details (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  match_offer_id BIGINT NOT NULL,
  score_version VARCHAR(32) NOT NULL,
  feature_name VARCHAR(64) NOT NULL,
  feature_value DECIMAL(14,6) NULL,
  weight_value DECIMAL(14,6) NULL,
  contribution DECIMAL(14,6) NULL,
  created_at DATETIME NOT NULL,
  KEY idx_match_score_offer (match_offer_id)
);

CREATE TABLE delivery_custody_events (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  event_uuid CHAR(36) NOT NULL UNIQUE,
  shipment_id BIGINT NOT NULL,
  package_id BIGINT NULL,
  shipment_leg_id BIGINT NULL,
  event_type VARCHAR(40) NOT NULL,
  event_at DATETIME NOT NULL,
  locker_node_id BIGINT NULL,
  driver_id BIGINT NULL,
  previous_custodian_type VARCHAR(24) NULL,
  previous_custodian_id VARCHAR(64) NULL,
  new_custodian_type VARCHAR(24) NULL,
  new_custodian_id VARCHAR(64) NULL,
  authorization_reference VARCHAR(128) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL,
  KEY idx_custody_shipment_time (shipment_id, event_at)
);

CREATE TABLE delivery_shipment_events (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  event_uuid CHAR(36) NOT NULL UNIQUE,
  shipment_id BIGINT NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  event_at DATETIME NOT NULL,
  actor_type VARCHAR(24) NULL,
  actor_id VARCHAR(64) NULL,
  metadata JSON NULL,
  created_at DATETIME NOT NULL,
  KEY idx_shipment_event_time (shipment_id, event_at)
);

CREATE TABLE delivery_pricing_quotes (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  quote_no VARCHAR(64) NOT NULL UNIQUE,
  shipment_id BIGINT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  service_level VARCHAR(32) NOT NULL,
  pricing_version VARCHAR(32) NOT NULL,
  route_assumption_json JSON NULL,
  price_components_json JSON NOT NULL,
  total_price DECIMAL(12,2) NOT NULL,
  estimated_cost DECIMAL(12,2) NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL
);

CREATE TABLE delivery_driver_earning_lines (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  driver_id BIGINT NOT NULL,
  driver_task_id BIGINT NOT NULL,
  earning_type VARCHAR(32) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  calculation_version VARCHAR(32) NOT NULL,
  reference_json JSON NULL,
  created_at DATETIME NOT NULL,
  reversed_by_id BIGINT NULL,
  KEY idx_driver_earning_driver (driver_id, created_at)
);

CREATE TABLE delivery_driver_settlements (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  settlement_no VARCHAR(64) NOT NULL UNIQUE,
  driver_id BIGINT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  status VARCHAR(24) NOT NULL,
  created_at DATETIME NOT NULL,
  paid_at DATETIME NULL
);

CREATE TABLE delivery_scheduled_routes (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  route_code VARCHAR(64) NOT NULL UNIQUE,
  origin_locker_node_id BIGINT NOT NULL,
  destination_locker_node_id BIGINT NOT NULL,
  schedule_rule VARCHAR(255) NOT NULL,
  vehicle_type VARCHAR(32) NULL,
  package_capacity INT NULL,
  weight_capacity_lb DECIMAL(10,2) NULL,
  default_driver_payment DECIMAL(12,2) NULL,
  status VARCHAR(24) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
);

CREATE TABLE delivery_scheduled_route_runs (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  scheduled_route_id BIGINT NOT NULL,
  planned_departure_at DATETIME NOT NULL,
  actual_departure_at DATETIME NULL,
  actual_arrival_at DATETIME NULL,
  driver_id BIGINT NULL,
  vehicle_id BIGINT NULL,
  status VARCHAR(24) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_scheduled_run_departure (planned_departure_at, status)
);

CREATE TABLE delivery_routing_decisions (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  shipment_id BIGINT NOT NULL,
  route_plan_id BIGINT NULL,
  decision_type VARCHAR(32) NOT NULL,
  decision_version VARCHAR(32) NOT NULL,
  reason_code VARCHAR(64) NULL,
  candidates_json JSON NULL,
  selected_json JSON NULL,
  created_at DATETIME NOT NULL,
  KEY idx_routing_decision_shipment (shipment_id, created_at)
);
