<?php
declare(strict_types=1);
use Zpx\HubDispatch\Service as HubDispatch;
use Zpx\HubReceiving\Service as HubReceiving;
use Zpx\Identity\{Failure,Secrets,Service as Identity};

$dispOrg = insertId($runtime, "INSERT INTO organizations(name) VALUES ('Synthetic hub dispatch test')");
putenv('ZPX_ORGANIZATION_ID=' . $dispOrg);

$crypto = new Secrets();
$identity = new Identity($runtime, $crypto);
$dispatch = new HubDispatch($runtime, $crypto);

// Create hub staff
$staffEmail = 'hub.dispatch@example.invalid';
$staffPhone = '+12025550501';
$staffInput = ['name' => 'Hub Dispatcher', 'email' => $staffEmail, 'phone' => $staffPhone, 'password' => Secrets::token(), 'address' => ['line1' => 'Hub', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '73301', 'country_code' => 'US']];
$staffUser = $identity->register($staffInput)['user_id'];
foreach (['EMAIL' => $staffEmail, 'PHONE' => $staffPhone] as $kind => $value) {
    $c = $identity->challenge(['kind' => $kind, 'contact_value' => $value, 'purpose' => 'REGISTER']);
    $q = $runtime->prepare("SELECT payload FROM outbox_events WHERE aggregate_id=(SELECT id FROM verification_challenges WHERE public_id=?) AND event_type='identity.contact_verification'");
    $q->execute([$c['challenge_id']]);
    $message = json_decode($crypto->decrypt(json_decode($q->fetchColumn(), true)['encrypted_message']), true);
    $identity->verify(['challenge_id' => $c['challenge_id'], 'code' => $message['code']]);
}

// Create hub
$hubLocation = insertId($runtime, "INSERT INTO locations(organization_id,code,name,kind,address_text,site_mode,status,access_policy) VALUES ($dispOrg,'HUB-DISP','Dispatch Hub','HUB','Hub addr','DELIVERY_ONLY','ACTIVE','{}')");
$hub = insertId($runtime, "INSERT INTO hubs(location_id,status) VALUES ($hubLocation,'ACTIVE')");
$runtime->exec("INSERT INTO hub_staff(hub_id,user_id) VALUES ($hub,$staffUser)");

// Grant HUB_STAFF scoped to the hub location, exactly as Seed.php does. An org-wide grant
// would hide the location scoping, so this fixture must match the seeded shape.
$hubStaffRole = $runtime->query("SELECT id FROM roles WHERE code='HUB_STAFF'")->fetchColumn();
$runtime->exec("INSERT INTO scoped_role_grants(user_id,role_id,organization_id,location_id,granted_by) VALUES ($staffUser,$hubStaffRole,$dispOrg,$hubLocation,$staffUser)");

// Create destination location and slot
$destLocation = insertId($runtime, "INSERT INTO locations(organization_id,code,name,kind,address_text,site_mode,status,access_policy) VALUES ($dispOrg,'DEST-DISP','Dest Locker','LOCKER','Dest addr','DELIVERY_ONLY','ACTIVE','{}')");
$slot = insertId($runtime, "INSERT INTO hub_slots(hub_id,code,destination_location_id,kind) VALUES ($hub,'LOT-A',$destLocation,'STAGING')");

// Create driver
$driverEmail = 'driver.dispatch@example.invalid';
$driverPhone = '+12025550502';
$driverInput = ['name' => 'Dispatch Driver', 'email' => $driverEmail, 'phone' => $driverPhone, 'password' => Secrets::token(), 'address' => ['line1' => 'Driver', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '73301', 'country_code' => 'US']];
$driverUser = $identity->register($driverInput)['user_id'];
foreach (['EMAIL' => $driverEmail, 'PHONE' => $driverPhone] as $kind => $value) {
    $c = $identity->challenge(['kind' => $kind, 'contact_value' => $value, 'purpose' => 'REGISTER']);
    $q = $runtime->prepare("SELECT payload FROM outbox_events WHERE aggregate_id=(SELECT id FROM verification_challenges WHERE public_id=?) AND event_type='identity.contact_verification'");
    $q->execute([$c['challenge_id']]);
    $message = json_decode($crypto->decrypt(json_decode($q->fetchColumn(), true)['encrypted_message']), true);
    $identity->verify(['challenge_id' => $c['challenge_id'], 'code' => $message['code']]);
}
$driverRole = $runtime->query("SELECT id FROM roles WHERE code='DRIVER'")->fetchColumn();
$runtime->exec("INSERT INTO scoped_role_grants(user_id,role_id,organization_id,granted_by) VALUES ($driverUser,$driverRole,$dispOrg,$driverUser)");
$driverId = insertId($runtime, "INSERT INTO drivers(user_id,engagement_type,status) VALUES ($driverUser,'SYNTHETIC','ACTIVE')");
$vehicle = insertId($runtime, "INSERT INTO vehicles(organization_id,code,max_weight_g,max_volume_mm3,max_packages) VALUES ($dispOrg,'VEH-DISP',50000,1000000000,20)");
$shift = insertId($runtime, "INSERT INTO driver_shifts(driver_id,vehicle_id,starts_at,ends_at) VALUES ($driverId,$vehicle,now()-interval '1 hour',now()+interval '8 hours')");

// Create sender + packages AT_HUB
$senderEmail = 'sender.dispatch@example.invalid';
$senderPhone = '+12025550503';
$senderInput = ['name' => 'Dispatch Sender', 'email' => $senderEmail, 'phone' => $senderPhone, 'password' => Secrets::token(), 'address' => ['line1' => 'Sender', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '73301', 'country_code' => 'US']];
$senderUser = $identity->register($senderInput)['user_id'];
foreach (['EMAIL' => $senderEmail, 'PHONE' => $senderPhone] as $kind => $value) {
    $c = $identity->challenge(['kind' => $kind, 'contact_value' => $value, 'purpose' => 'REGISTER']);
    $q = $runtime->prepare("SELECT payload FROM outbox_events WHERE aggregate_id=(SELECT id FROM verification_challenges WHERE public_id=?) AND event_type='identity.contact_verification'");
    $q->execute([$c['challenge_id']]);
    $message = json_decode($crypto->decrypt(json_decode($q->fetchColumn(), true)['encrypted_message']), true);
    $identity->verify(['challenge_id' => $c['challenge_id'], 'code' => $message['code']]);
}

$packageIds = [];
$labelTokens = [];
for ($i = 0; $i < 2; $i++) {
    $ship = insertId($runtime, "INSERT INTO shipments(organization_id,sender_user_id,public_reference,origin_location_id,destination_location_id,service_level,order_status,payment_status) VALUES ($dispOrg,$senderUser,'DISP-" . ($i + 1) . "',$hubLocation,$destLocation,'STANDARD','READY','PAID')");
    $pkg = insertId($runtime, "INSERT INTO packages(shipment_id,package_uuid,sequence_no,width_mm,height_mm,depth_mm,weight_g,state,custodian_type,custodian_ref,current_location_id,version) VALUES ($ship,'" . uuid() . "',1,100,100,100,500,'AT_HUB','HUB',$hub,$hubLocation,2)");
    $packageIds[] = $pkg;
    $tok = 'ZPX1:L:DISP-TOKEN-' . bin2hex(random_bytes(8));
    $th = hash('sha256', $tok);
    $runtime->exec("INSERT INTO package_labels(package_id,label_version,token_hash,status,expires_at) VALUES ($pkg,1,decode('$th','hex'),'ACTIVE',now()+interval '30 days')");
    $labelTokens[] = $tok;
}

// Test 1: Hub staff can stage a package to a slot
$stageKey = Secrets::uuid();
$stageResult = $dispatch->stageScan($staffUser, ['label_payload' => $labelTokens[0], 'slot_code' => 'LOT-A'], $stageKey);
check($stageResult['state'] === 'STAGED', 'package staged');
check($stageResult['slot_code'] === 'LOT-A', 'staged to LOT-A');

// Test 2: Package state is STAGED
$pkgState = $runtime->query("SELECT state FROM packages WHERE id={$packageIds[0]}")->fetchColumn();
check($pkgState === 'STAGED', 'package state is STAGED in DB');

// Test 3: Hub staff can create dispatch call
$dispatchKey = Secrets::uuid();
$callResult = $dispatch->createDispatchCall($staffUser, ['slot_id' => $slot, 'minutes_to_pickup' => 30], $dispatchKey);
check($callResult['status'] === 'PENDING', 'dispatch call created');
check($callResult['package_count'] === 1, 'one package in call');
check($callResult['destination'] === 'DEST-DISP', 'destination matches');

// Test 4: Slot status is DISPATCHED
$slotStatus = $runtime->query("SELECT status FROM hub_slots WHERE id=$slot")->fetchColumn();
check($slotStatus === 'DISPATCHED', 'slot status is DISPATCHED');

// Test 5: Driver can see available calls
$calls = $dispatch->listAvailableCalls($driverUser);
check(count($calls['items']) === 1, 'driver sees one available call');
check($calls['items'][0]['dispatch_call_id'] === $callResult['dispatch_call_id'], 'call ID matches');

// Test 6: Driver can accept dispatch
$acceptKey = Secrets::uuid();
$acceptResult = $dispatch->acceptDispatch($driverUser, $callResult['dispatch_call_id'], $acceptKey);
check($acceptResult['status'] === 'ACCEPTED', 'dispatch accepted');
check($acceptResult['run_id'] !== '', 'outbound run created');

// Test 7: Stage second package
$stageResult2 = $dispatch->stageScan($staffUser, ['label_payload' => $labelTokens[1], 'slot_code' => 'LOT-A'], Secrets::uuid());
check($stageResult2['state'] === 'STAGED', 'second package staged');

// Test 8: Driver loads packages
$loadKey = Secrets::uuid();
$loadResult = $dispatch->loadPackages($driverUser, $callResult['dispatch_call_id'], $loadKey);
check($loadResult['loaded_count'] >= 1, 'at least one package loaded');
check($loadResult['status'] === 'DISPATCHED', 'dispatch status is DISPATCHED');

// Test 9: Loaded packages are OUTBOUND_CUSTODY
$loadedState = $runtime->query("SELECT state FROM packages WHERE id={$packageIds[0]}")->fetchColumn();
check($loadedState === 'OUTBOUND_CUSTODY', 'loaded package is OUTBOUND_CUSTODY');

// Test 10: Custody event shows hub → driver
$custodyEvent = $runtime->query("SELECT previous_custodian_type, new_custodian_type FROM custody_events WHERE package_id={$packageIds[0]} ORDER BY package_version DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check($custodyEvent['previous_custodian_type'] === 'HUB', 'previous custodian was HUB');
check($custodyEvent['new_custodian_type'] === 'DRIVER', 'new custodian is DRIVER');

// Test 11: Slot is AVAILABLE after pickup
$slotStatus = $runtime->query("SELECT status FROM hub_slots WHERE id=$slot")->fetchColumn();
check($slotStatus === 'AVAILABLE', 'slot available after pickup');

// Test 12: Hub staff can list slots
$slots = $dispatch->listSlots($staffUser);
check(count($slots['items']) >= 1, 'hub has at least one slot');

// Test 13: Non-hub-staff cannot stage
failsIdentity(fn() => $dispatch->stageScan($driverUser, ['label_payload' => $labelTokens[0], 'slot_code' => 'LOT-A'], Secrets::uuid()), 403, 'driver cannot stage');

// Test 14: Non-driver cannot accept dispatch
failsIdentity(fn() => $dispatch->acceptDispatch($staffUser, $callResult['dispatch_call_id'], Secrets::uuid()), 403, 'hub staff cannot accept dispatch');

echo "\nHub dispatch test suite complete.\n";
