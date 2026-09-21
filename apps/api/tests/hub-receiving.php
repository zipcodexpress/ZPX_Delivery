<?php
declare(strict_types=1);
use Zpx\Custody\Service as Custody;
use Zpx\HubReceiving\Service as HubReceiving;
use Zpx\Identity\{Failure,Secrets,Service as Identity};

$hubOrg = insertId($runtime, "INSERT INTO organizations(name) VALUES ('Synthetic hub receiving test')");
putenv('ZPX_ORGANIZATION_ID=' . $hubOrg);

$crypto = new Secrets();
$identity = new Identity($runtime, $crypto);
$hubService = new HubReceiving($runtime, $crypto);
$custody = new Custody($runtime, $crypto);

// Create hub staff user
$staffEmail = 'hub.receive@example.invalid';
$staffPhone = '+12025550401';
$staffInput = [
    'name' => 'Synthetic Hub Receiver',
    'email' => $staffEmail,
    'phone' => $staffPhone,
    'password' => Secrets::token(),
    'address' => ['line1' => 'Hub addr', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '73301', 'country_code' => 'US'],
];
$staffUser = $identity->register($staffInput)['user_id'];
foreach (['EMAIL' => $staffEmail, 'PHONE' => $staffPhone] as $kind => $value) {
    $c = $identity->challenge(['kind' => $kind, 'contact_value' => $value, 'purpose' => 'REGISTER']);
    $q = $runtime->prepare("SELECT payload FROM outbox_events WHERE aggregate_id=(SELECT id FROM verification_challenges WHERE public_id=?) AND event_type='identity.contact_verification'");
    $q->execute([$c['challenge_id']]);
    $message = json_decode($crypto->decrypt(json_decode($q->fetchColumn(), true)['encrypted_message']), true);
    $identity->verify(['challenge_id' => $c['challenge_id'], 'code' => $message['code']]);
}

// Create hub and location
$hubLocation = insertId($runtime, "INSERT INTO locations(organization_id,code,name,kind,address_text,site_mode,status,access_policy) VALUES ($hubOrg,'HUB-RCV','Receive Hub','HUB','Hub addr','DELIVERY_ONLY','ACTIVE','{}')");
$hub = insertId($runtime, "INSERT INTO hubs(location_id,status) VALUES ($hubLocation,'ACTIVE')");
$runtime->exec("INSERT INTO hub_staff(hub_id,user_id) VALUES ($hub,$staffUser)");

// Grant HUB_STAFF scoped to the hub location, exactly as Seed.php does. An org-wide grant
// would hide the location scoping, so this fixture must match the seeded shape.
$hubStaffRole = $runtime->query("SELECT id FROM roles WHERE code='HUB_STAFF'")->fetchColumn();
$runtime->exec("INSERT INTO scoped_role_grants(user_id,role_id,organization_id,location_id,granted_by) VALUES ($staffUser,$hubStaffRole,$hubOrg,$hubLocation,$staffUser)");

// Create driver with INBOUND_CUSTODY packages
$driverEmail = 'driver.receive@example.invalid';
$driverPhone = '+12025550402';
$driverInput = [
    'name' => 'Synthetic Driver Rcv',
    'email' => $driverEmail,
    'phone' => $driverPhone,
    'password' => Secrets::token(),
    'address' => ['line1' => 'Driver addr', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '73301', 'country_code' => 'US'],
];
$driverUser = $identity->register($driverInput)['user_id'];
foreach (['EMAIL' => $driverEmail, 'PHONE' => $driverPhone] as $kind => $value) {
    $c = $identity->challenge(['kind' => $kind, 'contact_value' => $value, 'purpose' => 'REGISTER']);
    $q = $runtime->prepare("SELECT payload FROM outbox_events WHERE aggregate_id=(SELECT id FROM verification_challenges WHERE public_id=?) AND event_type='identity.contact_verification'");
    $q->execute([$c['challenge_id']]);
    $message = json_decode($crypto->decrypt(json_decode($q->fetchColumn(), true)['encrypted_message']), true);
    $identity->verify(['challenge_id' => $c['challenge_id'], 'code' => $message['code']]);
}
$driverRole = $runtime->query("SELECT id FROM roles WHERE code='DRIVER'")->fetchColumn();
$runtime->exec("INSERT INTO scoped_role_grants(user_id,role_id,organization_id,granted_by) VALUES ($driverUser,$driverRole,$hubOrg,$driverUser)");
$driverId = insertId($runtime, "INSERT INTO drivers(user_id,engagement_type,status) VALUES ($driverUser,'SYNTHETIC','ACTIVE')");

// Create vehicle, shift, run
$vehicle = insertId($runtime, "INSERT INTO vehicles(organization_id,code,max_weight_g,max_volume_mm3,max_packages) VALUES ($hubOrg,'VEH-RCV',50000,1000000000,20)");
$shift = insertId($runtime, "INSERT INTO driver_shifts(driver_id,vehicle_id,starts_at,ends_at) VALUES ($driverId,$vehicle,now()-interval '1 hour',now()+interval '8 hours')");
$run = insertId($runtime, "INSERT INTO route_runs(organization_id,hub_id,driver_id,vehicle_id,kind,state,revision,planned_start,planned_end) VALUES ($hubOrg,$hub,$driverId,$vehicle,'INBOUND','ACKNOWLEDGED',1,now(),now()+interval '6 hours')");
$stop = insertId($runtime, "INSERT INTO route_run_stops(run_id,location_id,sequence_no,state) VALUES ($run,$hubLocation,1,'EXPECTED')");

// Create sender for shipments
$senderEmail = 'sender.receive@example.invalid';
$senderPhone = '+12025550403';
$senderInput = [
    'name' => 'Synthetic Sender Rcv',
    'email' => $senderEmail,
    'phone' => $senderPhone,
    'password' => Secrets::token(),
    'address' => ['line1' => 'Sender addr', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '73301', 'country_code' => 'US'],
];
$senderUser = $identity->register($senderInput)['user_id'];
foreach (['EMAIL' => $senderEmail, 'PHONE' => $senderPhone] as $kind => $value) {
    $c = $identity->challenge(['kind' => $kind, 'contact_value' => $value, 'purpose' => 'REGISTER']);
    $q = $runtime->prepare("SELECT payload FROM outbox_events WHERE aggregate_id=(SELECT id FROM verification_challenges WHERE public_id=?) AND event_type='identity.contact_verification'");
    $q->execute([$c['challenge_id']]);
    $message = json_decode($crypto->decrypt(json_decode($q->fetchColumn(), true)['encrypted_message']), true);
    $identity->verify(['challenge_id' => $c['challenge_id'], 'code' => $message['code']]);
}

// Create 3 packages in INBOUND_CUSTODY with labels
$packageIds = [];
$labelTokens = [];
$manifest = insertId($runtime, "INSERT INTO manifests(run_id,revision,state) VALUES ($run,1,'ACTIVE')");
for ($i = 0; $i < 3; $i++) {
    $destLocation = insertId($runtime, "INSERT INTO locations(organization_id,code,name,kind,address_text,site_mode,status,access_policy) VALUES ($hubOrg,'DEST-RCV-$i','Dest $i','LOCKER','Dest addr','DELIVERY_ONLY','ACTIVE','{}')");
    $shipment = insertId($runtime, "INSERT INTO shipments(organization_id,sender_user_id,public_reference,origin_location_id,destination_location_id,service_level,order_status,payment_status) VALUES ($hubOrg,$senderUser,'HUB-RCV-" . ($i + 1) . "',$hubLocation,$destLocation,'STANDARD','READY','PAID')");
    $package = insertId($runtime, "INSERT INTO packages(shipment_id,package_uuid,sequence_no,width_mm,height_mm,depth_mm,weight_g,state,custodian_type,custodian_ref,current_location_id,version) VALUES ($shipment,'" . uuid() . "',1,100,100,100,500,'INBOUND_CUSTODY','DRIVER',$driverId,NULL,1)");
    $packageIds[] = $package;
    $payload = 'ZPX1:L:HUB-RCV-TOKEN-' . bin2hex(random_bytes(8));
    $tokenHash = hash('sha256', $payload);
    $runtime->exec("INSERT INTO package_labels(package_id,label_version,token_hash,status,expires_at) VALUES ($package,1,decode('$tokenHash','hex'),'ACTIVE',now()+interval '30 days')");
    $labelTokens[] = $payload;
    insertId($runtime, "INSERT INTO manifest_items(manifest_id,run_id,package_id,stop_id,state) VALUES ($manifest,$run,$package,$stop,'EXPECTED')");
}

// Test 1: Hub staff can open a receiving session
$openKey = Secrets::uuid();
$session = $hubService->openSession($staffUser, ['hub_id' => $hub, 'inbound_run_id' => $run], $openKey);
check($session['state'] === 'OPEN', 'session opened');
check($session['expected_count'] === 3, 'expected count is 3');
check($session['received_count'] === 0, 'received count is 0');

// Test 2: Cannot open duplicate session for same run
failsIdentity(fn() => $hubService->openSession($staffUser, ['hub_id' => $hub, 'inbound_run_id' => $run], Secrets::uuid()), 409, 'duplicate session rejected');

// Test 3: Hub staff can scan a package
$scanKey = Secrets::uuid();
$scanResult = $hubService->receiveScan($staffUser, [
    'label_payload' => $labelTokens[0],
    'inbound_run_id' => $run,
    'receiving_session_id' => $session['receiving_session_id'],
    'expected_package_version' => 1,
], $scanKey);
check($scanResult['state'] === 'AT_HUB', 'package transitions to AT_HUB');
check($scanResult['result_code'] === 'ACCEPTED', 'scan accepted');
check($scanResult['received_count'] === 1, 'one received');
check($scanResult['expected_count'] === 3, 'three expected');

// Test 4: Package custody updated
$custodyRow = $runtime->query("SELECT state, custodian_type, custodian_ref, version FROM packages WHERE id={$packageIds[0]}")->fetch(PDO::FETCH_ASSOC);
check($custodyRow['state'] === 'AT_HUB', 'package state is AT_HUB in DB');
check($custodyRow['custodian_type'] === 'HUB', 'custodian is HUB');
check($custodyRow['custodian_ref'] === $hub, 'custodian ref is hub ID');

// Test 5: Custody event recorded
$custodyEvent = $runtime->query("SELECT event_type, previous_custodian_type, new_custodian_type FROM custody_events WHERE package_id={$packageIds[0]} AND event_type='CUSTODY_TRANSFER'")->fetch(PDO::FETCH_ASSOC);
check($custodyEvent['previous_custodian_type'] === 'DRIVER', 'previous custodian was DRIVER');
check($custodyEvent['new_custodian_type'] === 'HUB', 'new custodian is HUB');

// Test 6: Duplicate scan rejected
failsIdentity(fn() => $hubService->receiveScan($staffUser, [
    'label_payload' => $labelTokens[0],
    'inbound_run_id' => $run,
    'receiving_session_id' => $session['receiving_session_id'],
    'expected_package_version' => 1,
], Secrets::uuid()), 409, 'duplicate scan rejected');

// Test 7: Scan second and third packages
$scanResult2 = $hubService->receiveScan($staffUser, [
    'label_payload' => $labelTokens[1],
    'inbound_run_id' => $run,
    'receiving_session_id' => $session['receiving_session_id'],
    'expected_package_version' => 1,
], Secrets::uuid());
check($scanResult2['received_count'] === 2, 'two received');

$scanResult3 = $hubService->receiveScan($staffUser, [
    'label_payload' => $labelTokens[2],
    'inbound_run_id' => $run,
    'receiving_session_id' => $session['receiving_session_id'],
    'expected_package_version' => 1,
], Secrets::uuid());
check($scanResult3['received_count'] === 3, 'all three received');

// Test 8: Close session
$closeKey = Secrets::uuid();
$closeResult = $hubService->closeSession($staffUser, $session['receiving_session_id'], $closeKey);
check($closeResult['state'] === 'CLOSED', 'session closed');
check($closeResult['received_count'] === 3, 'three received at close');
check($closeResult['short_count'] === 0, 'no shortages');

// Test 9: Session status after close
$status = $hubService->getSession($staffUser, $session['receiving_session_id']);
check($status['state'] === 'CLOSED', 'session status is CLOSED');

// Test 10: Non-hub-staff cannot access
$customerEmail = 'customer.receive@example.invalid';
$customerPhone = '+12025550404';
$customerInput = [
    'name' => 'Synthetic Customer Rcv',
    'email' => $customerEmail,
    'phone' => $customerPhone,
    'password' => Secrets::token(),
    'address' => ['line1' => 'Cust addr', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '73301', 'country_code' => 'US'],
];
$customerUser = $identity->register($customerInput)['user_id'];
failsIdentity(fn() => $hubService->openSession($customerUser, ['hub_id' => $hub, 'inbound_run_id' => $run], Secrets::uuid()), 403, 'customer cannot open session');

// Test 11: Shortage scenario — create new run with 3 packages, receive only 2
$run2 = insertId($runtime, "INSERT INTO route_runs(organization_id,hub_id,driver_id,vehicle_id,kind,state,revision,planned_start,planned_end) VALUES ($hubOrg,$hub,$driverId,$vehicle,'INBOUND','ACKNOWLEDGED',1,now(),now()+interval '6 hours')");
$stop2 = insertId($runtime, "INSERT INTO route_run_stops(run_id,location_id,sequence_no,state) VALUES ($run2,$hubLocation,1,'EXPECTED')");
$manifest2 = insertId($runtime, "INSERT INTO manifests(run_id,revision,state) VALUES ($run2,1,'ACTIVE')");
$shortPkgIds = [];
$shortTokens = [];
for ($i = 0; $i < 3; $i++) {
    $destLoc = insertId($runtime, "INSERT INTO locations(organization_id,code,name,kind,address_text,site_mode,status,access_policy) VALUES ($hubOrg,'SHORT-DEST-$i','Short Dest $i','LOCKER','Addr','DELIVERY_ONLY','ACTIVE','{}')");
    $ship = insertId($runtime, "INSERT INTO shipments(organization_id,sender_user_id,public_reference,origin_location_id,destination_location_id,service_level,order_status,payment_status) VALUES ($hubOrg,$senderUser,'SHORT-" . ($i + 1) . "',$hubLocation,$destLoc,'STANDARD','READY','PAID')");
    $pkg = insertId($runtime, "INSERT INTO packages(shipment_id,package_uuid,sequence_no,width_mm,height_mm,depth_mm,weight_g,state,custodian_type,custodian_ref,current_location_id,version) VALUES ($ship,'" . uuid() . "',1,100,100,100,500,'INBOUND_CUSTODY','DRIVER',$driverId,NULL,1)");
    $shortPkgIds[] = $pkg;
    $tok = 'ZPX1:L:SHORT-TOKEN-' . bin2hex(random_bytes(8));
    $th = hash('sha256', $tok);
    $runtime->exec("INSERT INTO package_labels(package_id,label_version,token_hash,status,expires_at) VALUES ($pkg,1,decode('$th','hex'),'ACTIVE',now()+interval '30 days')");
    $shortTokens[] = $tok;
    insertId($runtime, "INSERT INTO manifest_items(manifest_id,run_id,package_id,stop_id,state) VALUES ($manifest2,$run2,$pkg,$stop2,'EXPECTED')");
}

$session2 = $hubService->openSession($staffUser, ['hub_id' => $hub, 'inbound_run_id' => $run2], Secrets::uuid());
// Receive only 2 of 3
$hubService->receiveScan($staffUser, ['label_payload' => $shortTokens[0], 'inbound_run_id' => $run2, 'receiving_session_id' => $session2['receiving_session_id'], 'expected_package_version' => 1], Secrets::uuid());
$hubService->receiveScan($staffUser, ['label_payload' => $shortTokens[1], 'inbound_run_id' => $run2, 'receiving_session_id' => $session2['receiving_session_id'], 'expected_package_version' => 1], Secrets::uuid());

$closeResult2 = $hubService->closeSession($staffUser, $session2['receiving_session_id'], Secrets::uuid());
check($closeResult2['received_count'] === 2, 'shortage: two received');
check($closeResult2['short_count'] === 1, 'shortage: one short');

// Test 12: Short manifest item marked SHORT
$shortItem = $runtime->query("SELECT state FROM manifest_items WHERE package_id={$shortPkgIds[2]}")->fetchColumn();
check($shortItem === 'SHORT', 'unreceived manifest item marked SHORT');

// Test 13: Receive a package that reached the hub through a real driver pickup.
// Origin parcels start at version 1 and pickup increments, so the version a receiver
// sends is only knowable by resolving the label first — it is not a constant.
$run3 = insertId($runtime, "INSERT INTO route_runs(organization_id,hub_id,driver_id,vehicle_id,kind,state,revision,planned_start,planned_end) VALUES ($hubOrg,$hub,$driverId,$vehicle,'INBOUND','ACKNOWLEDGED',1,now(),now()+interval '6 hours')");
$stop3 = insertId($runtime, "INSERT INTO route_run_stops(run_id,location_id,sequence_no,state) VALUES ($run3,$hubLocation,1,'EXPECTED')");
$manifest3 = insertId($runtime, "INSERT INTO manifests(run_id,revision,state) VALUES ($run3,1,'ACTIVE')");
$originLoc = insertId($runtime, "INSERT INTO locations(organization_id,code,name,kind,address_text,site_mode,status,access_policy) VALUES ($hubOrg,'ORIGIN-RCV','Origin locker','LOCKER','Origin addr','DELIVERY_ONLY','ACTIVE','{}')");
$destLoc3 = insertId($runtime, "INSERT INTO locations(organization_id,code,name,kind,address_text,site_mode,status,access_policy) VALUES ($hubOrg,'DEST-RCV-FLOW','Flow dest','LOCKER','Dest addr','DELIVERY_ONLY','ACTIVE','{}')");
$ship3 = insertId($runtime, "INSERT INTO shipments(organization_id,sender_user_id,public_reference,origin_location_id,destination_location_id,service_level,order_status,payment_status) VALUES ($hubOrg,$senderUser,'HUB-RCV-FLOW',$originLoc,$destLoc3,'STANDARD','READY','PAID')");
$pkg3 = insertId($runtime, "INSERT INTO packages(shipment_id,package_uuid,sequence_no,width_mm,height_mm,depth_mm,weight_g,state,custodian_type,custodian_ref,current_location_id,version) VALUES ($ship3,'" . uuid() . "',1,100,100,100,500,'AT_ORIGIN','LOCKER','origin',$originLoc,1)");
$token3 = 'ZPX1:L:FLOW-TOKEN-' . bin2hex(random_bytes(8));
$runtime->exec("INSERT INTO package_labels(package_id,label_version,token_hash,status,expires_at) VALUES ($pkg3,1,decode('" . hash('sha256', $token3) . "','hex'),'ACTIVE',now()+interval '30 days')");
insertId($runtime, "INSERT INTO manifest_items(manifest_id,run_id,package_id,stop_id,state) VALUES ($manifest3,$run3,$pkg3,$stop3,'EXPECTED')");

$pickup = $custody->inboundPickupScan($driverUser, $run3, ['label_token' => $token3], Secrets::uuid());
check($pickup['package_version'] === 2, 'driver pickup increments the package past its origin version');

// Test 14: Hub staff may resolve a label to learn the current version
$resolvedByHub = $custody->resolveScan($staffUser, $token3);
check($resolvedByHub['package_id'] === $pkg3, 'hub staff resolve returns the scanned package');
check($resolvedByHub['package_state'] === 'INBOUND_CUSTODY', 'hub staff resolve reports inbound custody');
check($resolvedByHub['package_version'] === 2, 'hub staff resolve reports the current package version');

// Test 15: A stale version is rejected; the resolved version is accepted
$session3 = $hubService->openSession($staffUser, ['hub_id' => $hub, 'inbound_run_id' => $run3], Secrets::uuid());
failsIdentity(fn() => $hubService->receiveScan($staffUser, [
    'label_payload' => $token3,
    'inbound_run_id' => $run3,
    'receiving_session_id' => $session3['receiving_session_id'],
    'expected_package_version' => 1,
], Secrets::uuid()), 409, 'stale expected package version rejected');

$flowScan = $hubService->receiveScan($staffUser, [
    'label_payload' => $token3,
    'inbound_run_id' => $run3,
    'receiving_session_id' => $session3['receiving_session_id'],
    'expected_package_version' => $resolvedByHub['package_version'],
], Secrets::uuid());
check($flowScan['state'] === 'AT_HUB', 'package received using the resolved version');
check($flowScan['package_version'] === 3, 'hub receive increments the package version');

// Test 16: Resolution stays open to drivers and closed to customers
check($custody->resolveScan($driverUser, $token3)['package_version'] === 3, 'driver resolve still works after hub receive');
failsIdentity(fn() => $custody->resolveScan($customerUser, $token3), 403, 'customer cannot resolve label');

// Test 17: A parcel that is not on this run's manifest cannot be received into the session.
// $shortTokens[2] was never received, so it is still in inbound custody on run 2.
failsIdentity(fn() => $hubService->receiveScan($staffUser, [
    'label_payload' => $shortTokens[2],
    'inbound_run_id' => $run3,
    'receiving_session_id' => $session3['receiving_session_id'],
    'expected_package_version' => 1,
], Secrets::uuid()), 409, 'parcel from another run cannot be received into this session');
check($runtime->query("SELECT state FROM packages WHERE id={$shortPkgIds[2]}")->fetchColumn() === 'INBOUND_CUSTODY', 'rejected parcel keeps driver custody');

// Test 18: Staff assigned to a different hub are denied every operation on this hub's session
$otherLocation = insertId($runtime, "INSERT INTO locations(organization_id,code,name,kind,address_text,site_mode,status,access_policy) VALUES ($hubOrg,'HUB-OTHER','Other Hub','HUB','Other addr','DELIVERY_ONLY','ACTIVE','{}')");
$otherHub = insertId($runtime, "INSERT INTO hubs(location_id,status) VALUES ($otherLocation,'ACTIVE')");
$otherEmail = 'hub.other@example.invalid';
$otherPhone = '+12025550405';
$otherInput = [
    'name' => 'Synthetic Other Receiver',
    'email' => $otherEmail,
    'phone' => $otherPhone,
    'password' => Secrets::token(),
    'address' => ['line1' => 'Other hub addr', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '73301', 'country_code' => 'US'],
];
$otherUser = $identity->register($otherInput)['user_id'];
foreach (['EMAIL' => $otherEmail, 'PHONE' => $otherPhone] as $kind => $value) {
    $c = $identity->challenge(['kind' => $kind, 'contact_value' => $value, 'purpose' => 'REGISTER']);
    $q = $runtime->prepare("SELECT payload FROM outbox_events WHERE aggregate_id=(SELECT id FROM verification_challenges WHERE public_id=?) AND event_type='identity.contact_verification'");
    $q->execute([$c['challenge_id']]);
    $message = json_decode($crypto->decrypt(json_decode($q->fetchColumn(), true)['encrypted_message']), true);
    $identity->verify(['challenge_id' => $c['challenge_id'], 'code' => $message['code']]);
}
$runtime->exec("INSERT INTO scoped_role_grants(user_id,role_id,organization_id,location_id,granted_by) VALUES ($otherUser,$hubStaffRole,$hubOrg,$otherLocation,$otherUser)");
$runtime->exec("INSERT INTO hub_staff(hub_id,user_id) VALUES ($otherHub,$otherUser)");

failsIdentity(fn() => $hubService->openSession($otherUser, ['hub_id' => $hub, 'inbound_run_id' => $run3], Secrets::uuid()), 403, 'staff from another hub cannot open a session for this hub');
failsIdentity(fn() => $hubService->receiveScan($otherUser, [
    'label_payload' => $token3,
    'inbound_run_id' => $run3,
    'receiving_session_id' => $session3['receiving_session_id'],
    'expected_package_version' => 3,
], Secrets::uuid()), 404, 'staff from another hub cannot scan into this session');
failsIdentity(fn() => $hubService->getSession($otherUser, $session3['receiving_session_id']), 404, 'staff from another hub cannot read this session');
failsIdentity(fn() => $hubService->closeSession($otherUser, $session3['receiving_session_id'], Secrets::uuid()), 404, 'staff from another hub cannot close this session');

// Test 19: The rightful hub can still close the session after the denied attempts
$finalClose = $hubService->closeSession($staffUser, $session3['receiving_session_id'], Secrets::uuid());
check($finalClose['state'] === 'CLOSED', 'assigned staff can still close their own session');
check($finalClose['received_count'] === 1, 'assigned staff closed with the one received parcel');

// Test 20: Refused scans are journaled with the staff member who attempted them. The row cannot
// be written inside the refusing transaction — that rolls back — so this proves the flush works.
$refused = $runtime->query("SELECT actor_user_id, result_code FROM scan_events WHERE action='HUB_RECEIVE' AND result_code<>'ACCEPTED' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$codes = array_column($refused, 'result_code');
check(in_array('ALREADY_RECEIVED', $codes, true), 'refused duplicate receive survives the rollback');
check(in_array('VERSION_MISMATCH', $codes, true), 'refused stale-version scan survives the rollback');
check(in_array('NOT_ON_MANIFEST', $codes, true), 'refused off-manifest scan survives the rollback');
check(in_array('SESSION_NOT_FOUND', $codes, true), 'refused cross-hub scan survives the rollback');
$crossHub = array_values(array_filter($refused, fn($r) => $r['result_code'] === 'SESSION_NOT_FOUND'));
check((string)$crossHub[0]['actor_user_id'] === $otherUser, 'cross-hub attempt names the staff member who tried');

echo "\nHub receiving test suite complete.\n";
