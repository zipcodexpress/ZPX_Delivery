<?php
declare(strict_types=1);
use Zpx\Custody\PickupOffers;
use Zpx\Identity\Secrets;

$offers=new PickupOffers($runtime,$crypto);
$runtime->exec("INSERT INTO driver_shifts(driver_id,vehicle_id,starts_at,ends_at) VALUES ($otherDriverId,$vehicle,now()-interval '1 hour',now()+interval '8 hours')");
$originB=insertId($runtime,"INSERT INTO locations(organization_id,code,name,kind,address_text,site_mode,status,access_policy) VALUES ($custodyOrg,'TEST-OFFER-B','Offer origin B','LOCKER','Synthetic origin B','DELIVERY_ONLY','ACTIVE','{}')");
$offerPackages=[];
foreach ([$originLocation,$originB] as $i=>$origin) {
    $shipment=insertId($runtime,"INSERT INTO shipments(organization_id,sender_user_id,public_reference,origin_location_id,destination_location_id,service_level,order_status,payment_status) VALUES ($custodyOrg,$senderUser,'OFFER-$i',$origin,$destLocation1,'STANDARD','READY','PAID')");
    $package=insertId($runtime,"INSERT INTO packages(shipment_id,package_uuid,sequence_no,width_mm,height_mm,depth_mm,weight_g,state,custodian_type,custodian_ref,current_location_id,version) VALUES ($shipment,'".Secrets::uuid()."',1,100,100,100,500,'AT_ORIGIN','LOCKER','origin',$origin,1)");
    $label='OFFER-TEST-LABEL-'.$i;
    $runtime->prepare("INSERT INTO package_labels(package_id,label_version,token_hash,status,expires_at) VALUES (?,1,decode(?,'hex'),'ACTIVE',now()+interval '1 day')")->execute([$package,hash('sha256',$label)]);
    $runtime->exec("INSERT INTO pickup_demands(package_id,origin_location_id,status) VALUES ($package,$origin,'OPEN')");
    $offerPackages[]=[$package,$label,$origin];
}
failsIdentity(fn()=>$offers->refresh($driverUser),409,'offline driver cannot request pickups');
$offers->availability($driverUser,['status'=>'AVAILABLE']);
$offers->availability($otherDriverUser,['status'=>'AVAILABLE']);
$first=$offers->refresh($driverUser)['items'];
$other=$offers->refresh($otherDriverUser)['items'];
check(count($first)===2 && count($other)===2,'both available drivers see origin offers');
$lateShipment=insertId($runtime,"INSERT INTO shipments(organization_id,sender_user_id,public_reference,origin_location_id,destination_location_id,service_level,order_status,payment_status) VALUES ($custodyOrg,$senderUser,'OFFER-LATE',$originLocation,$destLocation1,'STANDARD','READY','PAID')");
$latePackage=insertId($runtime,"INSERT INTO packages(shipment_id,package_uuid,sequence_no,width_mm,height_mm,depth_mm,weight_g,state,custodian_type,custodian_ref,current_location_id,version) VALUES ($lateShipment,'".Secrets::uuid()."',1,100,100,100,500,'AT_ORIGIN','LOCKER','origin',$originLocation,1)");
$runtime->exec("INSERT INTO pickup_demands(package_id,origin_location_id,status) VALUES ($latePackage,$originLocation,'OPEN')");
$offerA=array_values(array_filter($first,fn($o)=>$o['origin']==='Test Origin'))[0]['offer_id'];
$otherA=array_values(array_filter($other,fn($o)=>$o['origin']==='Test Origin'))[0]['offer_id'];
$key=Secrets::uuid();
$accepted=$offers->accept($driverUser,$offerA,$key);
check($accepted['package_count']===1 && $runtime->query("SELECT status FROM pickup_demands WHERE package_id=$latePackage")->fetchColumn()==='OPEN','offer accepts only demand items visible when offered');
$replayed=$offers->accept($driverUser,$offerA,$key);
check($replayed['run_id']===$accepted['run_id'] && $replayed['stop_id']===$accepted['stop_id'] && $replayed['package_count']===$accepted['package_count'],'same acceptance key replays exact run');
failsIdentity(fn()=>$offers->accept($otherDriverUser,$otherA,Secrets::uuid()),409,'competing driver cannot claim accepted demand');
check($runtime->query("SELECT COUNT(*) FROM active_allocations WHERE package_id={$offerPackages[0][0]}")->fetchColumn()==1,'accepted package has one active allocation');
$offerB=array_values(array_filter($first,fn($o)=>$o['origin']==='Offer origin B'))[0]['offer_id'];
$second=$offers->accept($driverUser,$offerB,Secrets::uuid());
check($second['run_id']===$accepted['run_id'] && $second['revision']===$accepted['revision']+1,'nearby locker acceptance appends to one published inbound run');
$detail=$custody->getRun($driverUser,$accepted['run_id']);
check(count($detail['stops'])===2 && count($detail['manifest'])===2,'run freezes both exact packages and stops');
$custody->acknowledgeRun($driverUser,$accepted['run_id'],Secrets::uuid());
$originLocker=insertId($runtime,'INSERT INTO lockers(location_id) VALUES (?)',[$originLocation]);
$originDoor=insertId($runtime,"INSERT INTO compartments(locker_id,code,width_mm,height_mm,depth_mm,max_weight_g,status) VALUES (?,'O1',200,200,200,1000,'AVAILABLE')",[$originLocker]);
$originSession=insertId($runtime,"INSERT INTO locker_sessions(package_id,compartment_id,actor_user_id,action,status,expires_at,evidence_policy) VALUES (?,?,?,'ORIGIN_DEPOSIT','CONFIRMED',now()+interval '1 day','SYNTHETIC')",[$offerPackages[0][0],$originDoor,$senderUser]);
$runtime->prepare("INSERT INTO compartment_claims(compartment_id,package_id,session_id,state) VALUES (?,?,?,'OCCUPIED')")
    ->execute([$originDoor,$offerPackages[0][0],$originSession]);
$runtime->prepare("UPDATE packages SET custodian_ref=? WHERE id=?")->execute([$originLocker,$offerPackages[0][0]]);
$runtime->exec("UPDATE compartment_claims SET state='HELD' WHERE package_id={$offerPackages[0][0]}");
failsIdentity(fn()=> $custody->inboundPickupScan($driverUser,$accepted['run_id'],['label_payload'=>$offerPackages[0][1],'action'=>'INBOUND_PICKUP','client_event_id'=>Secrets::uuid(),'run_revision'=>$second['revision'],'expected_package_version'=>1],Secrets::uuid(),'"'.$second['revision'].'"'),409,'unresolved origin door claim blocks pickup custody transfer');
$runtime->exec("UPDATE compartment_claims SET state='OCCUPIED' WHERE package_id={$offerPackages[0][0]}");
$pickup=$custody->inboundPickupScan($driverUser,$accepted['run_id'],['label_payload'=>$offerPackages[0][1],'action'=>'INBOUND_PICKUP','client_event_id'=>Secrets::uuid(),'run_revision'=>$second['revision'],'expected_package_version'=>1],Secrets::uuid(),'"'.$second['revision'].'"');
check($pickup['result']==='ACCEPTED' && $runtime->query("SELECT status FROM pickup_demands WHERE package_id={$offerPackages[0][0]}")->fetchColumn()==='RESOLVED','physical pickup scan resolves only its own demand');
check((int)$runtime->query("SELECT count(*) FROM compartment_claims WHERE package_id={$offerPackages[0][0]}")->fetchColumn()===0,'accepted origin pickup releases exact occupied locker claim');
check($runtime->query("SELECT status FROM pickup_demands WHERE package_id={$offerPackages[1][0]}")->fetchColumn()==='ASSIGNED','uncollected second parcel remains assigned');
$fresh=$offers->refresh($otherDriverUser)['items'];
check(count($fresh)===1 && $fresh[0]['package_count']===1 && $fresh[0]['origin']==='Test Origin','refresh replaces stale offers with newly ready demand only');
failsIdentity(fn()=>$offers->accept($otherDriverUser,$otherA,Secrets::uuid()),409,'replaced stale offer cannot be accepted');
echo "Pickup offer test suite complete.\n";
