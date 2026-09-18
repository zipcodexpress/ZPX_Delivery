# Initial Engineering Backlog

## Epic 1 — Delivery Core
- migrations
- shipment model
- package model
- shipment events
- state service
- SI model/service

## Epic 2 — Locker Validation
- origin deposit validation
- relay drop validation
- relay pickup validation
- final drop validation
- recipient pickup validation
- idempotency
- locker adapter

## Epic 3 — Driver
- profile
- vehicle
- trip create/edit/cancel
- route geometry provider
- route corridor storage

## Epic 4 — Driver Task
- task
- stops
- shipment-leg assignment
- task lifecycle
- route navigation payload

## Epic 5 — Batch / Manifest
- manifest generation
- QR/token
- batch locker pickup
- batch locker drop
- package-level custody

## Epic 6 — Matchmaking
- candidate finder
- hard filters
- detour engine
- scoring engine
- score audit
- offers
- acceptance/expiry

## Epic 7 — Routing
- graph abstraction
- direct path
- one-relay path
- route scoring
- route-plan versioning
- replan remaining route

## Epic 8 — Pricing / Earnings
- quote engine
- config/versioning
- payout engine
- earning lines
- settlement

## Epic 9 — Consumer UI
- send package
- quote
- payment
- SI/QR
- tracking
- receive/pickup

## Epic 10 — Driver UI
- Drive & Earn
- matches
- active route
- batch pickup/drop
- earnings

## Epic 11 — Operations
- shipment monitor
- driver monitor
- locker monitor
- dispatch board
- route override
- claims/audit
