# Driver Application

## Main tabs

- Home
- Drive & Earn
- My Routes
- Active Route
- Earnings
- Profile

## Drive & Earn

Inputs:
- origin/current location
- destination
- departure window
- max detour minutes
- max detour miles
- vehicle
- available package capacity

Output:
- ranked opportunities
- package count
- pickup/drop locations
- estimated extra miles
- estimated extra minutes
- route payout
- capacity used
- expected completion time

## Active Route

Each stop shows:
- navigation
- drop count
- pickup count
- package exceptions
- manifest scan
- locker validation result

## Dynamic Add-On

During active route, the backend may create optional add-on offers.

Example:
- +4 packages
- +2.8 miles
- +6 minutes
- +$11.50

Driver must accept before the task is modified unless the driver previously opted into auto-add within explicit limits.

## GPS

Before job:
- planned route is enough.

During active task:
- GPS updates may be used for ETA, fraud prevention, routing, and add-on matching.

After route completion:
- stop active high-frequency tracking.
