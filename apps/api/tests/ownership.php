<?php
declare(strict_types=1);

// Synthetic records are explicitly removed; no device command or custody event is issued.
$owner->beginTransaction();
$org = insertId($owner, "INSERT INTO organizations(name) VALUES ('Synthetic ownership race')");
$user = insertId($owner, "INSERT INTO users(organization_id,external_auth_id,display_name,status) VALUES (?,?,'Synthetic racer','ACTIVE')", [$org,uuid()]);
$loc = insertId($owner, "INSERT INTO locations(organization_id,code,name,kind,address_text,status) VALUES (?,?,'Synthetic','LOCKER','Not real','ACTIVE')", [$org,uuid()]);
$otherLoc = insertId($owner, "INSERT INTO locations(organization_id,code,name,kind,address_text,status) VALUES (?,?,'Synthetic','LOCKER','Not real','ACTIVE')", [$org,uuid()]);
$locker = insertId($owner, 'INSERT INTO lockers(location_id) VALUES (?)', [$loc]);
$otherLocker = insertId($owner, 'INSERT INTO lockers(location_id) VALUES (?)', [$otherLoc]);
$door = insertId($owner, "INSERT INTO compartments(locker_id,code,width_mm,height_mm,depth_mm,max_weight_g,status) VALUES (?,'1',100,100,100,1000,'AVAILABLE')", [$locker]);
$manifest = insertId($owner, "INSERT INTO ownership_manifests(locker_id,generation,manifest_hash,signature_reference,state,issued_by) VALUES (?,1,decode(repeat('ee',32),'hex'),'synthetic','DRAFT',?)", [$locker,$user]);
rejected($owner, "INSERT INTO compartment_ownership(compartment_id,locker_id,manifest_id,owner,generation) VALUES ($door,$otherLocker,$manifest,'DELIVERY',1)", '23503');
rejected($owner, "INSERT INTO compartment_ownership(compartment_id,locker_id,manifest_id,owner,generation) VALUES ($door,$locker,$manifest,'DELIVERY',2)", '23503');
$owner->exec("INSERT INTO compartment_ownership(compartment_id,locker_id,manifest_id,owner,generation) VALUES ($door,$locker,$manifest,'DELIVERY',1)");
rejected($owner, "UPDATE ownership_manifests SET generation=2 WHERE id=$manifest", '23503');
check(true, 'ownership stays bound to physical locker and manifest generation');
$shipment = insertId($owner, "INSERT INTO shipments(organization_id,sender_user_id,public_reference,origin_location_id,destination_location_id,service_level,order_status,payment_status) VALUES (?,?,?,?,?,'STANDARD','DRAFT','UNPAID')", [$org,$user,uuid(),$loc,$loc]);
$packages=[]; $sessions=[];
foreach ([1,2] as $sequence) {
    $package=insertId($owner,"INSERT INTO packages(shipment_id,package_uuid,sequence_no,width_mm,height_mm,depth_mm,weight_g,state,custodian_type,custodian_ref) VALUES (?,?,?,10,10,10,10,'CREATED','SENDER',?)",[$shipment,uuid(),$sequence,$user]);
    $packages[]=$package;
    $sessions[]=insertId($owner,"INSERT INTO locker_sessions(package_id,compartment_id,actor_user_id,action,status,expires_at,evidence_policy) VALUES (?,?,?,'DEPOSIT','PENDING',now()+interval '10 minutes','DOOR_AND_ACTOR')",[$package,$door,$user]);
}
$owner->commit();
$child=null; $pipes=[];
try {
    $runtime->beginTransaction();
    $q=$runtime->prepare("INSERT INTO compartment_claims(compartment_id,package_id,session_id,state) VALUES (?,?,?,'HELD')");
    $q->execute([$door,$packages[0],$sessions[0]]);
    $marker='door-race-'.bin2hex(random_bytes(8));
    $child=proc_open([PHP_BINARY,__DIR__.'/integration.php','--claim-race',$door,$packages[1],$sessions[1],$marker],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($child)) { throw new RuntimeException('Cannot start compartment contender'); }
    stream_set_timeout($pipes[1],10);
    check(trim((string)fgets($pipes[1]))==='READY','independent compartment contender connected');
    $q=$runtime->prepare("SELECT count(*) FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock'");
    $waiting=false;
    for ($attempt=0;$attempt<100;$attempt++) {
        $runtime->query('SELECT pg_stat_clear_snapshot()');
        $q->execute([$marker]);
        if ((int)$q->fetchColumn()===1) { $waiting=true; break; }
        usleep(10000);
    }
    check($waiting,'second compartment reservation actually contends on database lock');
    $runtime->commit();
    $output=stream_get_contents($pipes[1]);
    foreach ($pipes as $pipe) { fclose($pipe); } $pipes=[];
    $exit=proc_close($child); $child=null;
    check($exit===0 && trim($output)==='23505','last compartment has exactly one reservation winner');
    check((int)$owner->query("SELECT count(*) FROM compartment_claims WHERE compartment_id=$door AND package_id={$packages[0]}")->fetchColumn()===1,'winning package claim was not overwritten');
} finally {
    if ($runtime->inTransaction()) { $runtime->rollBack(); }
    if (is_resource($child)) { proc_terminate($child); foreach ($pipes as $pipe) { fclose($pipe); } proc_close($child); }
    $owner->beginTransaction();
    $owner->exec("DELETE FROM compartment_claims WHERE compartment_id=$door; DELETE FROM locker_sessions WHERE compartment_id=$door; DELETE FROM packages WHERE shipment_id=$shipment; DELETE FROM shipments WHERE id=$shipment; DELETE FROM compartment_ownership WHERE compartment_id=$door; DELETE FROM ownership_manifests WHERE id=$manifest; DELETE FROM compartments WHERE id=$door; DELETE FROM lockers WHERE id IN ($locker,$otherLocker); DELETE FROM locations WHERE organization_id=$org; DELETE FROM users WHERE id=$user; DELETE FROM organizations WHERE id=$org");
    $owner->commit();
}
