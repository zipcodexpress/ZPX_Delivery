<?php
declare(strict_types=1);
use Zpx\Custody\Service as Custody;
use Zpx\Identity\{Failure,Secrets,Service as Identity};
use Zpx\Shipping\Service as Shipping;
use Zpx\Infrastructure\Database\Transaction;

$custodyOrg = insertId($runtime, "INSERT INTO organizations(name) VALUES ('Synthetic driver inbound test')");
putenv('ZPX_ORGANIZATION_ID=' . $custodyOrg);
putenv('APP_ENV=test');

$crypto = new Secrets();
$identity = new Identity($runtime, $crypto);
$custody = new Custody($runtime, $crypto);
$shipping = new Shipping($runtime, $crypto);

// Create driver user with DRIVER role
$driverEmail = 'driver.inbound@example.invalid';
$driverPhone = '+12025550201';
$driverInput = [
    'name' => 'Synthetic Driver',
    'email' => $driverEmail,
    'phone' => $driverPhone,
    'password' => Secrets::token(),
    'address' => ['line1' => 'Synthetic depot', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '73301', 'country_code' => 'US'],
];
$driverUser = $identity->register($driverInput)['user_id'];

// Verify driver contacts
foreach (['EMAIL' => $driverEmail, 'PHONE' => $driverPhone] as $kind => $value) {
    $c = $identity->challenge(['kind' => $kind, 'contact_value' => $value, 'purpose' => 'REGISTER']);
    $q = $runtime->prepare("SELECT payload FROM outbox_events WHERE aggregate_id=(SELECT id FROM verification_challenges WHERE public_id=?) AND event_type='identity.contact_verification'");
    $q->execute([$c['challenge_id']]);
    $message = json_decode($crypto->decrypt(json_decode($q->fetchColumn(), true)['encrypted_message']), true);
    $identity->verify(['challenge_id' => $c['challenge_id'], 'code' => $message['code']]);
}

// Grant DRIVER role
$runtime->exec("INSERT INTO roles(code) VALUES ('DRIVER') ON CONFLICT DO NOTHING");
$driverRole = $runtime->query("SELECT id FROM roles WHERE code='DRIVER'")->fetchColumn();
$runtime->exec("INSERT INTO scoped_role_grants(user_id,role_id,organization_id,granted_by) VALUES ($driverUser,$driverRole,$custodyOrg,$driverUser)");

// Create driver profile
$driverId = insertId($runtime, "INSERT INTO drivers(user_id,engagement_type,status) VALUES ($driverUser,'SYNTHETIC','ACTIVE')");

// Create hub and locations
$hubLocation = insertId($runtime, "INSERT INTO locations(organization_id,code,name,kind,address_text,site_mode,status,access_policy) VALUES ($custodyOrg,'TEST-HUB','Test Hub','HUB','Synthetic hub','DELIVERY_ONLY','ACTIVE','{}')");
$hub = insertId($runtime, "INSERT INTO hubs(location_id,status) VALUES ($hubLocation,'ACTIVE')");
$originLocation = insertId($runtime, "INSERT INTO locations(organization_id,code,name,kind,address_text,site_mode,status,access_policy) VALUES ($custodyOrg,'TEST-ORIGIN','Test Origin','LOCKER','Synthetic origin','DELIVERY_ONLY','ACTIVE','{\"synthetic\":true}')");
$destLocation1 = insertId($runtime, "INSERT INTO locations(organization_id,code,name,kind,address_text,site_mode,status,access_policy) VALUES ($custodyOrg,'TEST-DEST1','Test Dest 1','LOCKER','Synthetic dest 1','DELIVERY_ONLY','ACTIVE','{\"synthetic\":true}')");
$destLocation2 = insertId($runtime, "INSERT INTO locations(organization_id,code,name,kind,address_text,site_mode,status,access_policy) VALUES ($custodyOrg,'TEST-DEST2','Test Dest 2','LOCKER','Synthetic dest 2','DELIVERY_ONLY','ACTIVE','{\"synthetic\":true}')");

// Create vehicle
$vehicle = insertId($runtime, "INSERT INTO vehicles(organization_id,code,max_weight_g,max_volume_mm3,max_packages) VALUES ($custodyOrg,'TEST-V1',50000,1000000000,20)");

// Create driver shift
$shift = insertId($runtime, "INSERT INTO driver_shifts(driver_id,vehicle_id,starts_at,ends_at) VALUES ($driverId,$vehicle,now()-interval '1 hour',now()+interval '8 hours')");

// Create route run with stops
$run = insertId($runtime, "INSERT INTO route_runs(organization_id,hub_id,driver_id,vehicle_id,kind,state,revision,planned_start,planned_end) VALUES ($custodyOrg,$hub,$driverId,$vehicle,'INBOUND','PUBLISHED',1,now(),now()+interval '6 hours')");
$stop1 = insertId($runtime, "INSERT INTO route_run_stops(run_id,location_id,sequence_no,state) VALUES ($run,$originLocation,1,'EXPECTED')");
$stop2 = insertId($runtime, "INSERT INTO route_run_stops(run_id,location_id,sequence_no,state) VALUES ($run,$destLocation1,2,'EXPECTED')");

// Create sender user for shipments
$senderEmail = 'sender.inbound@example.invalid';
$senderPhone = '+12025550202';
$senderInput = [
    'name' => 'Synthetic Sender',
    'email' => $senderEmail,
    'phone' => $senderPhone,
    'password' => Secrets::token(),
    'address' => ['line1' => 'Synthetic address', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '73301', 'country_code' => 'US'],
];
$senderUser = $identity->register($senderInput)['user_id'];
foreach (['EMAIL' => $senderEmail, 'PHONE' => $senderPhone] as $kind => $value) {
    $c = $identity->challenge(['kind' => $kind, 'contact_value' => $value, 'purpose' => 'REGISTER']);
    $q = $runtime->prepare("SELECT payload FROM outbox_events WHERE aggregate_id=(SELECT id FROM verification_challenges WHERE public_id=?) AND event_type='identity.contact_verification'");
    $q->execute([$c['challenge_id']]);
    $message = json_decode($crypto->decrypt(json_decode($q->fetchColumn(), true)['encrypted_message']), true);
    $identity->verify(['challenge_id' => $c['challenge_id'], 'code' => $message['code']]);
}

// Create 3 shipments with packages at AT_ORIGIN and active labels
$packageIds = [];
$labelTokens = [];
$manifest = insertId($runtime, "INSERT INTO manifests(run_id,revision,state) VALUES ($run,1,'ACTIVE')");
for ($i = 0; $i < 3; $i++) {
    $destId = $i < 2 ? $destLocation1 : $destLocation2;
    $shipment = insertId($runtime, "INSERT INTO shipments(organization_id,sender_user_id,public_reference,origin_location_id,destination_location_id,service_level,order_status,payment_status) VALUES ($custodyOrg,$senderUser,'DRIVER-TEST-" . ($i + 1) . "',$originLocation,$destId,'STANDARD','READY','PAID')");
    $package = insertId($runtime, "INSERT INTO packages(shipment_id,package_uuid,sequence_no,width_mm,height_mm,depth_mm,weight_g,state,custodian_type,custodian_ref,current_location_id) VALUES ($shipment,'" . uuid() . "',1,100,100,100,500,'AT_ORIGIN','SENDER',$senderUser,$originLocation)");
    $packageIds[] = $package;

    // Create active label
    $payload = 'ZPX1:L:TEST-TOKEN-' . bin2hex(random_bytes(8));
    $tokenHash = hash('sha256', $payload);
    $runtime->exec("INSERT INTO package_labels(package_id,label_version,token_hash,status,expires_at) VALUES ($package,1,decode('$tokenHash','hex'),'ACTIVE',now()+interval '30 days')");
    $labelTokens[] = $payload;

    // Create shipping identifier
    $runtime->exec("INSERT INTO shipping_identifiers(package_id,si,destination_location_id) VALUES ($package,'ZPX-TEST-" . ($i + 1) . "',$destId)");

    // Create manifest item
    insertId($runtime, "INSERT INTO manifest_items(manifest_id,run_id,package_id,stop_id,state) VALUES ($manifest,$run,$package," . ($i < 2 ? $stop1 : $stop2) . ",'EXPECTED')");
}

// Test 1: Driver can list their runs
$runs = $custody->listRuns($driverUser);
check(count($runs['items']) === 1, 'driver sees exactly one assigned run');
check($runs['items'][0]['id'] === $run, 'run ID matches');
check($runs['items'][0]['state'] === 'PUBLISHED', 'run state is PUBLISHED');
check($runs['items'][0]['expected_count'] === 3, 'run shows 3 expected packages');
check($runs['items'][0]['loaded_count'] === 0, 'no packages loaded yet');

// Test 2: Driver can read run manifest
$manifest = $custody->getRun($driverUser, $run);
check(count($manifest['manifest']) === 3, 'manifest has 3 items');
check(count($manifest['stops']) === 2, 'run has 2 stops');
check($manifest['state'] === 'PUBLISHED', 'manifest run state is PUBLISHED');

// Test 3: Driver can acknowledge run
$ackKey = Secrets::uuid();
$ackResult = $custody->acknowledgeRun($driverUser, $run, $ackKey);
check($ackResult['state'] === 'ACKNOWLEDGED', 'run acknowledged');
check($ackResult['revision'] === 1, 'revision preserved');

// Test 4: Acknowledge is idempotent with same key
$ackRetry = $custody->acknowledgeRun($driverUser, $run, $ackKey);
check($ackRetry['state'] === 'ACKNOWLEDGED', 'acknowledge retry returns same state');

// Test 5: Cannot acknowledge a non-PUBLISHED run with a new key
failsIdentity(fn() => $custody->acknowledgeRun($driverUser, $run, Secrets::uuid()), 409, 'already-acknowledged run cannot be acknowledged again');

// Test 6: Driver can resolve a valid label
$resolved = $custody->resolveScan($driverUser, $labelTokens[0]);
check($resolved['package_id'] === $packageIds[0], 'label resolves to correct package');
check($resolved['package_state'] === 'AT_ORIGIN', 'package is at origin');

// Test 7: Driver cannot resolve unknown label
failsIdentity(fn() => $custody->resolveScan($driverUser, 'UNKNOWN-TOKEN'), 404, 'unknown label rejected');

// Test 8: Driver can perform inbound pickup scan
$scanKey = Secrets::uuid();
$scanResult = $custody->inboundPickupScan($driverUser, $run, ['label_token' => $labelTokens[0]], $scanKey);
check($scanResult['state'] === 'INBOUND_CUSTODY', 'package transitions to INBOUND_CUSTODY');
check($scanResult['result_code'] === 'ACCEPTED', 'scan accepted');
check($scanResult['loaded_count'] === 1, 'one package loaded');
check($scanResult['expected_count'] === 3, 'three packages expected');
check($scanResult['package_version'] === 1, 'package version incremented');

// Test 9: Scan is idempotent with same key
$scanRetry = $custody->inboundPickupScan($driverUser, $run, ['label_token' => $labelTokens[0]], $scanKey);
check($scanRetry['package_id'] === $packageIds[0], 'idempotent retry returns same package');

// Test 10: Duplicate scan with different key rejected
failsIdentity(
    fn() => $custody->inboundPickupScan($driverUser, $run, ['label_token' => $labelTokens[0]], Secrets::uuid()),
    409,
    'duplicate scan with different key rejected'
);

// Test 11: Scan second package
$scanKey2 = Secrets::uuid();
$scanResult2 = $custody->inboundPickupScan($driverUser, $run, ['label_token' => $labelTokens[1]], $scanKey2);
check($scanResult2['loaded_count'] === 2, 'two packages loaded after second scan');

// Test 12: Scan third package
$scanKey3 = Secrets::uuid();
$scanResult3 = $custody->inboundPickupScan($driverUser, $run, ['label_token' => $labelTokens[2]], $scanKey3);
check($scanResult3['loaded_count'] === 3, 'all three packages loaded');
check($scanResult3['loaded_count'] === $scanResult3['expected_count'], 'loaded equals expected');

// Test 13: Package custody updated correctly
$custodyRow = $runtime->query("SELECT state, custodian_type, custodian_ref, version FROM packages WHERE id={$packageIds[0]}")->fetch(PDO::FETCH_ASSOC);
check($custodyRow['state'] === 'INBOUND_CUSTODY', 'package state is INBOUND_CUSTODY in DB');
check($custodyRow['custodian_type'] === 'DRIVER', 'custodian is DRIVER');
check($custodyRow['custodian_ref'] === $driverId, 'custodian ref is driver ID');
check((int)$custodyRow['version'] === 1, 'package version is 1');

// Test 14: Custody event recorded
$custodyEvent = $runtime->query("SELECT event_type, previous_custodian_type, new_custodian_type FROM custody_events WHERE package_id={$packageIds[0]}")->fetch(PDO::FETCH_ASSOC);
check($custodyEvent['event_type'] === 'CUSTODY_TRANSFER', 'custody event recorded');
check($custodyEvent['previous_custodian_type'] === 'SENDER', 'previous custodian was SENDER');
check($custodyEvent['new_custodian_type'] === 'DRIVER', 'new custodian is DRIVER');

// Test 15: Scan event recorded
$scanEvent = $runtime->query("SELECT action, result_code FROM scan_events WHERE package_id={$packageIds[0]} AND action='INBOUND_PICKUP'")->fetch(PDO::FETCH_ASSOC);
check($scanEvent['result_code'] === 'ACCEPTED', 'scan event recorded as ACCEPTED');

// Test 16: Manifest items updated
$loadedItems = $runtime->query("SELECT COUNT(*) FROM manifest_items WHERE run_id=$run AND state='LOADED'")->fetchColumn();
check((int)$loadedItems === 3, 'all manifest items marked LOADED');

// Test 17: Non-driver cannot access driver endpoints
$customerEmail = 'customer.inbound@example.invalid';
$customerPhone = '+12025550203';
$customerInput = [
    'name' => 'Synthetic Customer',
    'email' => $customerEmail,
    'phone' => $customerPhone,
    'password' => Secrets::token(),
    'address' => ['line1' => 'Synthetic addr', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '73301', 'country_code' => 'US'],
];
$customerUser = $identity->register($customerInput)['user_id'];
failsIdentity(fn() => $custody->listRuns($customerUser), 403, 'customer cannot list driver runs');
failsIdentity(fn() => $custody->getRun($customerUser, $run), 403, 'customer cannot read run manifest');

// Test 18: Driver cannot see another driver's run
$otherDriverEmail = 'other.driver@example.invalid';
$otherDriverPhone = '+12025550204';
$otherDriverInput = [
    'name' => 'Other Driver',
    'email' => $otherDriverEmail,
    'phone' => $otherDriverPhone,
    'password' => Secrets::token(),
    'address' => ['line1' => 'Other depot', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '73301', 'country_code' => 'US'],
];
$otherDriverUser = $identity->register($otherDriverInput)['user_id'];
foreach (['EMAIL' => $otherDriverEmail, 'PHONE' => $otherDriverPhone] as $kind => $value) {
    $c = $identity->challenge(['kind' => $kind, 'contact_value' => $value, 'purpose' => 'REGISTER']);
    $q = $runtime->prepare("SELECT payload FROM outbox_events WHERE aggregate_id=(SELECT id FROM verification_challenges WHERE public_id=?) AND event_type='identity.contact_verification'");
    $q->execute([$c['challenge_id']]);
    $message = json_decode($crypto->decrypt(json_decode($q->fetchColumn(), true)['encrypted_message']), true);
    $identity->verify(['challenge_id' => $c['challenge_id'], 'code' => $message['code']]);
}
$runtime->exec("INSERT INTO scoped_role_grants(user_id,role_id,organization_id,granted_by) VALUES ($otherDriverUser,$driverRole,$custodyOrg,$otherDriverUser)");
$otherDriverId = insertId($runtime, "INSERT INTO drivers(user_id,engagement_type,status) VALUES ($otherDriverUser,'SYNTHETIC','ACTIVE')");
failsIdentity(fn() => $custody->getRun($otherDriverUser, $run), 404, 'other driver cannot see assigned run');

// Test 19: Revoked label rejected
$runtime->exec("UPDATE package_labels SET status='REVOKED' WHERE package_id={$packageIds[0]}");
failsIdentity(fn() => $custody->resolveScan($driverUser, $labelTokens[0]), 410, 'revoked label rejected on resolve');

// Restore label for scan test
$runtime->exec("UPDATE package_labels SET status='ACTIVE' WHERE package_id={$packageIds[0]}");

// Test 20: Package not AT_ORIGIN rejected
$runtime->exec("UPDATE packages SET state='AT_HUB' WHERE id={$packageIds[1]}");
failsIdentity(
    fn() => $custody->inboundPickupScan($driverUser, $run, ['label_token' => $labelTokens[1]], Secrets::uuid()),
    409,
    'package not at origin rejected'
);

// Test 21: Outbox event created
$outboxCount = $runtime->query("SELECT COUNT(*) FROM outbox_events WHERE event_type='custody.inbound_pickup'")->fetchColumn();
check((int)$outboxCount === 3, 'three outbox events created for inbound pickups');

echo "\nDriver inbound test suite complete.\n";
