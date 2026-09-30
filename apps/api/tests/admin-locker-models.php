<?php
declare(strict_types=1);

use ZpxAdmin\{LockerModels,LockerSetups,Navigation,SiteInventory};
use Zpx\Identity\Secrets;

$previousOrg=getenv('ZPX_ORGANIZATION_ID'); putenv('ZPX_ORGANIZATION_ID='.$shippingOrg);
try {
    $models=new LockerModels($runtime);
    failsIdentity(fn()=>$models->catalog($sender),403,'customer cannot read locker model catalog');
    $box=['code'=>'TEST-BOX-S','version'=>'1','name'=>'Synthetic small box','size_class'=>'SMALL','width_mm'=>'260',
        'height_mm'=>'280','depth_mm'=>'340','max_weight_g'=>'4000','is_allocable'=>'1',
        'legacy_size_category'=>'small','legacy_price_raw'=>'12.50','reason'=>'Synthetic box model setup'];
    failsIdentity(fn()=>$models->addBoxModel($customerAdmin,array_replace($box,['size_class'=>'HUGE'])),422,
        'box model requires a supported size class');
    $models->addBoxModel($customerAdmin,$box);
    $legacyBox=$runtime->query("SELECT size_cat,length,width,height,model_price FROM cabinet_box_model WHERE code='TEST-BOX-S'")
        ->fetch(PDO::FETCH_ASSOC);
    check($legacyBox['size_cat']==='small' && (int)$legacyBox['length']===340
        && (int)$legacyBox['width']===260 && (int)$legacyBox['height']===280
        && $legacyBox['model_price']==='12.50',
        'box model writes legacy fields into the canonical PostgreSQL table');
    failsIdentity(fn()=>$models->addBoxModel($customerAdmin,$box),409,'box model version cannot be duplicated');
    $catalog=$models->catalog($customerAdmin);
    $boxId=(string)$catalog['boxes'][0]['id'];
    check($catalog['boxes'][0]['size_class']==='SMALL' && $catalog['boxes'][0]['legacy_price_raw']==='12.50'
        && $catalog['boxes'][0]['dimensions_source_unit']==='MM','box model retains size, units and raw legacy metadata');
    $boxEdit=['name'=>'Corrected small box','size_class'=>'SMALL','width_mm'=>'270','height_mm'=>'280',
        'depth_mm'=>'340','max_weight_g'=>'4200','is_allocable'=>'0','legacy_size_category'=>'small',
        'legacy_price_raw'=>'13.50','revision'=>'1','reason'=>'Correct draft box dimensions'];
    failsIdentity(fn()=>$models->updateBoxModel($sender,$boxId,$boxEdit),403,'customer cannot edit box model');
    $models->updateBoxModel($customerAdmin,$boxId,$boxEdit);
    $editedBox=$models->catalog($customerAdmin)['boxes'][0];
    check($editedBox['name']==='Corrected small box' && (int)$editedBox['width_mm']===270
        && $editedBox['dimensions_source_unit']==='MM' && (int)$editedBox['revision']===2,
        'admin edits an unpublished box model with verified dimensions');
    failsIdentity(fn()=>$models->updateBoxModel($customerAdmin,$boxId,$boxEdit),412,
        'stale box model revision cannot overwrite another edit');
    $models->addBoxModel($customerAdmin,array_replace($box,['code'=>'TEST-BOX-M','name'=>'Synthetic mid box',
        'size_class'=>'MEDIUM','height_mm'=>'400','legacy_size_category'=>'middle']));
    $midBoxId=(string)$models->catalog($customerAdmin)['boxes'][0]['id'];
    $models->addBodyModel($customerAdmin,['code'=>'TEST-BODY-A','version'=>'1','name'=>'Synthetic body layout',
        'reason'=>'Synthetic body model setup']);
    $bodyId=(string)$models->catalog($customerAdmin)['bodies'][0]['id'];
    $bodyEdit=['name'=>'Corrected body layout','revision'=>'1','reason'=>'Correct draft body name'];
    $models->updateBodyModel($customerAdmin,$bodyId,$bodyEdit);
    check($models->catalog($customerAdmin)['bodies'][0]['name']==='Corrected body layout',
        'admin edits an unpublished body model');
    failsIdentity(fn()=>$models->updateBodyModel($customerAdmin,$bodyId,$bodyEdit),412,
        'stale body model revision cannot overwrite another edit');
    failsIdentity(fn()=>$models->ready($customerAdmin,$bodyId,'Premature model publication'),409,
        'empty body model cannot be published');
    $slot=['body_model_id'=>$bodyId,'box_model_id'=>$boxId,'row'=>'1','column'=>'1','door_address'=>'4',
        'reason'=>'Synthetic layout slot setup'];
    $models->addSlot($customerAdmin,$slot);
    failsIdentity(fn()=>$models->addSlot($customerAdmin,$slot),409,'duplicate body layout position is rejected');
    failsIdentity(fn()=>$models->addSlot($customerAdmin,array_replace($slot,['column'=>'2'])),409,
        'duplicate door address is rejected independently of display position');
    $models->addSlot($customerAdmin,array_replace($slot,['column'=>'2','door_address'=>'9']));
    $removeId=(string)$models->catalog($customerAdmin)['slots'][1]['id'];
    $models->updateSlot($customerAdmin,$removeId,['box_model_id'=>$midBoxId,'row'=>'2','column'=>'3',
        'door_address'=>'17','reason'=>'Correct the draft slot position']);
    $changed=array_values(array_filter($models->catalog($customerAdmin)['slots'],
        static fn(array $row): bool => (string)$row['id']===$removeId))[0];
    check((int)$changed['display_row']===2 && (int)$changed['display_column']===3
        && (int)$changed['door_address']===17 && (string)$changed['box_model_id']===$midBoxId,
        'draft editing preserves independent display and door values');
    failsIdentity(fn()=>$models->updateSlot($customerAdmin,$removeId,['box_model_id'=>$midBoxId,
        'row'=>'3','column'=>'3','door_address'=>'4','reason'=>'Duplicate draft door check']),409,
        'draft edit rejects an occupied door address');
    $models->removeSlot($customerAdmin,$removeId,'Synthetic draft layout correction');
    check(count($models->catalog($customerAdmin)['slots'])===1,'draft layout position can be removed before publication');
    $models->ready($customerAdmin,$bodyId,'Synthetic layout review complete');
    failsIdentity(fn()=>$models->updateBodyModel($customerAdmin,$bodyId,array_replace($bodyEdit,['revision'=>'2'])),409,
        'published body model cannot be edited in place');
    failsIdentity(fn()=>$models->updateBoxModel($customerAdmin,$boxId,array_replace($boxEdit,['revision'=>'2'])),409,
        'box model used by published layout cannot be edited in place');
    $models->copyBoxModel($customerAdmin,$boxId,'Create editable published box copy');
    $boxCopy=(string)$models->catalog($customerAdmin)['boxes'][0]['id'];
    check($boxCopy!==$boxId && $models->catalog($customerAdmin)['boxes'][0]['editable']==1,
        'published box model can be copied into editable local model');
    $models->copyBodyModel($customerAdmin,$bodyId,'Create editable published body copy');
    $bodyCopy=$models->catalog($customerAdmin)['bodies'][0];
    check($bodyCopy['status']==='DRAFT' && (int)$bodyCopy['slot_count']===1
        && $bodyCopy['legacy_model_id']===null,'published body copy keeps layout and returns to draft');
    $copiedSlot=array_values(array_filter($models->catalog($customerAdmin)['slots'],
        static fn(array $row): bool => (string)$row['body_model_id']===(string)$bodyCopy['id']))[0];
    $models->updateSlot($customerAdmin,(string)$copiedSlot['id'],['box_model_id'=>$boxCopy,'row'=>'1',
        'column'=>'1','door_address'=>'5','reason'=>'Correct copied body-box layout']);
    check((int)$runtime->query('SELECT addr FROM cabinet_body_box WHERE body_box_id='.(int)$copiedSlot['id'])->fetchColumn()===5,
        'copied body-box position is editable without changing the published source');
    $historicalBox=(string)$runtime->query("INSERT INTO cabinet_box_model(organization_id,code,version,model_name,size_class,
        dimensions_source_unit,legacy_model_id,size_cat,length,width,height)
        VALUES ($shippingOrg,'HISTORICAL-TEST-BOX',1,'Historical test box','SMALL','UNVERIFIED',980001,'small',34,26,28)
        RETURNING model_id")->fetchColumn();
    $historicalBody=(string)$runtime->query("INSERT INTO cabinet_body_model(organization_id,code,version,model_name,status,legacy_model_id)
        VALUES ($shippingOrg,'HISTORICAL-TEST-BODY',1,'Historical test body','DRAFT',980001)
        RETURNING model_id")->fetchColumn();
    $runtime->prepare('INSERT INTO cabinet_body_box(organization_id,body_model_id,box_model_id,"row","column",addr,legacy_body_box_id)
        VALUES (?,?,?,?,?,?,?)')->execute([$shippingOrg,$historicalBody,$historicalBox,1,1,0,980001]);
    failsIdentity(fn()=>$models->updateBoxModel($customerAdmin,$historicalBox,$boxEdit),409,
        'historical box snapshot remains read-only');
    failsIdentity(fn()=>$models->updateBodyModel($customerAdmin,$historicalBody,$bodyEdit),409,
        'historical body snapshot remains read-only');
    $models->copyBoxModel($customerAdmin,$historicalBox,'Copy historical box for verified local editing');
    $historicalBoxCopy=(string)$models->catalog($customerAdmin)['boxes'][0]['id'];
    check($models->catalog($customerAdmin)['boxes'][0]['dimensions_source_unit']==='UNVERIFIED',
        'historical box copy does not pretend source dimensions are millimeters');
    $models->updateBoxModel($customerAdmin,$historicalBoxCopy,array_replace($boxEdit,['revision'=>'1']));
    $models->copyBodyModel($customerAdmin,$historicalBody,'Copy historical body for local draft correction');
    $historicalBodyCopy=(string)$models->catalog($customerAdmin)['bodies'][0]['id'];
    failsIdentity(fn()=>$models->ready($customerAdmin,$historicalBodyCopy,'Premature historical copy publication'),409,
        'copied historical layout cannot publish with unverified source box model');
    $historicalSlot=array_values(array_filter($models->catalog($customerAdmin)['slots'],
        static fn(array $row): bool => (string)$row['body_model_id']===$historicalBodyCopy))[0];
    $models->updateSlot($customerAdmin,(string)$historicalSlot['id'],['box_model_id'=>$historicalBoxCopy,
        'row'=>'1','column'=>'1','door_address'=>'0','reason'=>'Select verified local box for copied layout']);
    $models->ready($customerAdmin,$historicalBodyCopy,'Verified copied historical body layout');
    check($models->catalog($customerAdmin)['bodies'][0]['status']==='READY',
        'verified local copy can be published without mutating historical source');
    $models->addBodyModel($customerAdmin,['code'=>'TEST-BODY-B','version'=>'1','name'=>'Synthetic mixed body',
        'reason'=>'Synthetic second body setup']);
    $secondBodyId=(string)$models->catalog($customerAdmin)['bodies'][0]['id'];
    $models->addSlot($customerAdmin,['body_model_id'=>$secondBodyId,'box_model_id'=>$midBoxId,
        'row'=>'1','column'=>'1','door_address'=>'17','reason'=>'Synthetic mid layout position']);
    $models->ready($customerAdmin,$secondBodyId,'Synthetic mixed body ready');
    failsIdentity(fn()=>$models->addSlot($customerAdmin,array_replace($slot,['column'=>'2'])),409,
        'ready body layout refuses new slots');
    failsIdentity(fn()=>$models->removeSlot($customerAdmin,(string)$models->catalog($customerAdmin)['slots'][0]['id'],
        'Ready layout removal attempt'),409,'ready body layout refuses slot removal');
    $runtime->beginTransaction();
    try {
        rejected($runtime,"UPDATE locker_body_models SET name='Tampered' WHERE id=$bodyId",'42501');
        rejected($runtime,"DELETE FROM locker_body_model_slots WHERE body_model_id=$bodyId",'42501');
    } finally { $runtime->rollBack(); }

    $sites=new SiteInventory($runtime,new Secrets());
    $code='TEST-MODEL-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));
    $site=$sites->create($customerAdmin,['code'=>$code,'name'=>'Synthetic model test site','site_type'=>'APARTMENT',
        'address'=>['line1'=>'1 Example Lane','line2'=>'','city'=>'Austin','region'=>'TX','postal_code'=>'00000','country_code'=>'US'],
        'timezone'=>'America/Chicago','reason'=>'Synthetic model site setup'],Secrets::uuid());
    $location=$sites->addLocation($customerAdmin,$site,['code'=>$code.'-ENTRY','name'=>'Model test entrance',
        'address_text'=>'1 Example Lane','reason'=>'Synthetic model location setup'],Secrets::uuid());
    $lockerId=(string)$sites->site($customerAdmin,$site)['locations'][0]['locker_id'];
    $models->instantiate($customerAdmin,$lockerId,['body_model_id'=>$bodyId,'body_code'=>'BODY-A','position'=>'1',
        'reason'=>'Synthetic frozen model instantiation']);
    $detail=$sites->locker($customerAdmin,$lockerId);
    check(count($detail['bodies'])===1 && (string)$detail['bodies'][0]['body_model_id']===$bodyId
        && count($detail['boxes'])===1 && $detail['boxes'][0]['code']==='BODY-A-1-1'
        && (string)$detail['boxes'][0]['box_model_id']===$boxId
        && $detail['boxes'][0]['status']==='FROZEN' && $detail['boxes'][0]['board_address']===null
        && $detail['occupancy']['unavailable']===1,
        'ready model creates correctly sized frozen draft structure without hardware mapping');
    failsIdentity(fn()=>$models->instantiate($customerAdmin,$lockerId,['body_model_id'=>$bodyId,
        'body_code'=>'BODY-A','position'=>'2','reason'=>'Duplicate model body test']),409,
        'model cannot duplicate a body code');
    $models->assemble($customerAdmin,$lockerId,['body_model_ids'=>[$secondBodyId,$bodyId],'expected_position'=>'2',
        'reason'=>'Synthetic ordered set assembly']);
    $assembled=$sites->locker($customerAdmin,$lockerId);
    check(array_column($assembled['bodies'],'code')===['BODY-A','BODY-2','BODY-3']
        && (string)$assembled['bodies'][1]['body_model_id']===$secondBodyId
        && (string)$assembled['bodies'][2]['body_model_id']===$bodyId
        && count($assembled['boxes'])===3
        && in_array('MEDIUM',array_column($assembled['boxes'],'size_class'),true),
        'selected body models append in order and generate all boxes');
    failsIdentity(fn()=>$models->assemble($customerAdmin,$lockerId,['body_model_ids'=>[$bodyId],'expected_position'=>'2',
        'reason'=>'Synthetic duplicate submission guard']),409,'duplicate assembly submission needs a refreshed structure');
    failsIdentity(fn()=>$models->assemble($customerAdmin,$lockerId,['body_model_ids'=>[$bodyId,'999999999'],'expected_position'=>'4',
        'reason'=>'Synthetic atomic rollback check']),404,'invalid later body rolls back full set');
    check(count($sites->locker($customerAdmin,$lockerId)['bodies'])===3,'failed set assembly leaves no partial body');
    $runtime->prepare("UPDATE locations SET status='ACTIVE' WHERE id=?")->execute([$location]);
    failsIdentity(fn()=>$models->instantiate($customerAdmin,$lockerId,['body_model_id'=>$bodyId,
        'body_code'=>'BODY-B','position'=>'2','reason'=>'Active locker model guard']),409,
        'active locker refuses model instantiation');
    failsIdentity(fn()=>$models->assemble($customerAdmin,$lockerId,['body_model_ids'=>[$bodyId],'expected_position'=>'4',
        'reason'=>'Active set assembly guard']),409,'active locker refuses set assembly');
    $foreignModel=insertId($owner,"INSERT INTO locker_body_models(organization_id,code,version,name,status)
        VALUES (?,'FOREIGN-BODY',1,'Foreign layout','READY')",[$foreignCustomerOrg]);
    failsIdentity(fn()=>$models->instantiate($customerAdmin,$lockerId,['body_model_id'=>$foreignModel,
        'body_code'=>'BODY-C','position'=>'3','reason'=>'Cross network model guard']),409,
        'active guard applies before cross-network model lookup');
    $runtime->prepare("UPDATE locations SET status='INACTIVE' WHERE id=?")->execute([$location]);
    failsIdentity(fn()=>$models->instantiate($customerAdmin,$lockerId,['body_model_id'=>$foreignModel,
        'body_code'=>'BODY-C','position'=>'3','reason'=>'Cross network model guard']),404,
        'foreign body model cannot be instantiated in this network');
    $runtime->prepare('UPDATE locations SET site_id=NULL WHERE id=?')->execute([$location]);
    failsIdentity(fn()=>$models->assemble($customerAdmin,$lockerId,['body_model_ids'=>[$bodyId],'expected_position'=>'4',
        'reason'=>'Unbound site assembly guard']),409,'ordered set requires an installation site');
    $runtime->prepare('UPDATE locations SET site_id=? WHERE id=?')->execute([$site,$location]);

    $models->addBodyModel($customerAdmin,['code'=>'TEST-THREE-DOOR','version'=>'1','name'=>'Synthetic three door body',
        'reason'=>'Synthetic six door setup model']);
    $threeDoorId=(string)$models->catalog($customerAdmin)['bodies'][0]['id'];
    foreach ([['1','4',$boxId],['2','9',$boxId],['3','17',$midBoxId]] as [$row,$door,$boxModel]) {
        $models->addSlot($customerAdmin,['body_model_id'=>$threeDoorId,'box_model_id'=>$boxModel,
            'row'=>$row,'column'=>'1','door_address'=>$door,'reason'=>'Synthetic three door slot']);
    }
    $models->ready($customerAdmin,$threeDoorId,'Synthetic three door layout ready');
    $setups=new LockerSetups($runtime);
    failsIdentity(fn()=>$setups->listing($sender),403,'customer cannot read cabinet setup drafts');
    $setupId=$setups->create($customerAdmin,['code'=>$code.'-SET','name'=>'Synthetic six door cabinet',
        'reason'=>'Synthetic unbound cabinet setup']);
    check(count($setups->detail($customerAdmin,$setupId)['bodies'])===0,
        'new cabinet setup has no operational locker or location');
    $correctionId=$setups->create($customerAdmin,['code'=>$code.'-EDIT','name'=>'Draft correction cabinet',
        'reason'=>'Synthetic cabinet draft correction']);
    $setups->addBody($customerAdmin,$correctionId,['body_model_id'=>$threeDoorId,'code'=>'TEMP',
        'name'=>'Temporary body','display_sequence'=>'5','controller_address'=>'11',
        'protocol_profile'=>'UNVERIFIED','version'=>'1','reason'=>'Synthetic correction body setup']);
    $correctionBody=(string)$setups->detail($customerAdmin,$correctionId)['bodies'][0]['id'];
    $setups->removeBody($customerAdmin,$correctionId,['body_id'=>$correctionBody,'version'=>'2',
        'reason'=>'Synthetic correction body removal']);
    check(count($setups->detail($customerAdmin,$correctionId)['bodies'])===0,
        'draft cabinet body can be removed before binding');
    $first=['body_model_id'=>$threeDoorId,'code'=>'LEFT','name'=>'Left body','display_sequence'=>'1',
        'controller_address'=>'7','protocol_profile'=>'SIMULATED_24','version'=>'1','reason'=>'Synthetic left body setup'];
    $setups->addBody($customerAdmin,$setupId,$first);
    check((int)$runtime->query('SELECT count(*) FROM cabinet_box WHERE cabinet_id='.(int)$setupId)->fetchColumn()===3,
        'selecting a body generates three linked cabinet_box rows immediately');
    failsIdentity(fn()=>$setups->addBody($customerAdmin,$setupId,array_replace($first,
        ['code'=>'RIGHT','display_sequence'=>'2','controller_address'=>'3'])),412,'stale cabinet draft is rejected');
    failsIdentity(fn()=>$setups->addBody($customerAdmin,$setupId,array_replace($first,
        ['code'=>'RIGHT','display_sequence'=>'2','version'=>'2'])),409,'controller address collision is independent of display order');
    $setups->addBody($customerAdmin,$setupId,array_replace($first,
        ['code'=>'RIGHT','name'=>'Right body','display_sequence'=>'2','controller_address'=>'3','version'=>'2']));
    $preview=$setups->detail($customerAdmin,$setupId);
    check(count($preview['bodies'])===2 && count($preview['boxes'])===6
        && array_column($preview['boxes'],'controller_address')===['7','7','7','3','3','3']
        && array_column($preview['boxes'],'door_address')===['4','9','17','4','9','17'],
        'six-door draft keeps body sequence, controller address and door address independent');
    $emptyLocation=$sites->addLocation($customerAdmin,$site,['code'=>$code.'-FINAL','name'=>'Final setup location',
        'address_text'=>'1 Example Lane','reason'=>'Synthetic final location binding'],Secrets::uuid());
    $bind=['location_id'=>$emptyLocation,'version'=>'3','idempotency_key'=>Secrets::uuid(),
        'reason'=>'Synthetic six door final binding'];
    failsIdentity(fn()=>$setups->bind($customerAdmin,$setupId,array_replace($bind,['location_id'=>$location])),409,
        'binding refuses a destination with existing inventory');
    failsIdentity(fn()=>$setups->bind($customerAdmin,$setupId,array_replace($bind,['location_id'=>'999999999'])),404,
        'binding refuses a missing or foreign location');
    $savedEnv=getenv('APP_ENV'); putenv('APP_ENV=production');
    try {
        failsIdentity(fn()=>$setups->bind($customerAdmin,$setupId,$bind),409,
            'simulator profile cannot be bound in production mode');
    } finally { putenv($savedEnv===false?'APP_ENV':'APP_ENV='.$savedEnv); }
    $boundLocker=$setups->bind($customerAdmin,$setupId,$bind);
    check((int)$runtime->query('SELECT count(*) FROM cabinet_box WHERE cabinet_id='.(int)$setupId.' AND compartment_id IS NOT NULL')->fetchColumn()===6,
        'final binding links each cabinet_box to its existing operational compartment');
    check($setups->bind($customerAdmin,$setupId,$bind)===$boundLocker,
        'identical final binding retry returns the original locker');
    failsIdentity(fn()=>$setups->bind($customerAdmin,$setupId,array_replace($bind,['location_id'=>$location])),409,
        'changed payload cannot reuse a bound cabinet setup');
    $installed=$sites->locker($customerAdmin,$boundLocker);
    check(count($installed['bodies'])===2 && count($installed['boards'])===2 && count($installed['boxes'])===6
        && count(array_unique(array_column($installed['boxes'],'id')))===6
        && count(array_filter($installed['boxes'],static fn(array $box): bool => $box['status']==='FROZEN'))===6
        && $installed['locker']['location_status']==='INACTIVE',
        'binding reuses the destination locker and creates six distinct frozen boxes');
    $pairs=$runtime->query('SELECT b.board_address,c.door_address FROM compartments c
        JOIN controller_boards b ON b.id=c.controller_board_id WHERE c.locker_id='.(int)$boundLocker.'
        ORDER BY b.display_sequence,c.display_row')->fetchAll(PDO::FETCH_ASSOC);
    check(array_map(static fn(array $row): string => $row['board_address'].'/'.$row['door_address'],$pairs)
        ===['7/4','7/9','7/17','3/4','3/9','3/17'],
        'installed controller and door pairs match the synthetic mapping fixture');
    failsIdentity(fn()=>$setups->addBody($customerAdmin,$setupId,array_replace($first,['version'=>'4'])),409,
        'bound cabinet setup is immutable');
    failsIdentity(fn()=>$setups->removeBody($customerAdmin,$setupId,['body_id'=>(string)$preview['bodies'][0]['id'],
        'version'=>'4','reason'=>'Bound setup removal attempt']),409,'bound cabinet body cannot be removed');
    $referenceBody=(string)$runtime->query("INSERT INTO cabinet_body(cabinet_id,body_model_id,body_name,sequence,
        display_sequence,addr,protocol_profile,legacy_body_id) VALUES ($correctionId,$historicalBody,
        'Historical body','0',0,0,'UNVERIFIED',980002) RETURNING body_id")->fetchColumn();
    $runtime->query("INSERT INTO cabinet_box(cabinet_id,body_id,box_model_id,\"row\",\"column\",addr,legacy_box_id)
        VALUES ($correctionId,$referenceBody,$historicalBox,1,1,0,980002),
               ($correctionId,$referenceBody,$historicalBox,2,1,1,980003)");
    $runtime->prepare("UPDATE cabinet SET status='REFERENCE',legacy_cabinet_id=? WHERE cabinet_id=?")
        ->execute([90000000+(int)$correctionId,$correctionId]);
    $referenceDetail=$setups->detail($customerAdmin,$correctionId);
    $review=$referenceDetail['reference_review'];
    check((int)$review['body']['total']===1 && (int)$review['box']['total']===2
        && (int)$review['box']['template_differences']===1
        && (int)$review['box']['unverified_dimensions']===2
        && (int)$referenceDetail['boxes'][0]['template_difference']===0
        && (int)$referenceDetail['boxes'][1]['template_difference']===1
        && $review['location_links']===[],
        'historical review reports raw identities, template drift, and unverified dimensions without binding');
    $runtime->prepare('INSERT INTO legacy_location_links(location_id,source_system,legacy_cabinet_id,source_revision)
        VALUES (?,\'zipcodexpress\',?,\'synthetic-link-revision\')')
        ->execute([$location,90000000+(int)$correctionId]);
    $linkedReview=$setups->detail($customerAdmin,$correctionId)['reference_review'];
    check(count($linkedReview['location_links'])===1
        && $linkedReview['location_links'][0]['source_revision']==='synthetic-link-revision'
        && $setups->detail($customerAdmin,$correctionId)['draft']['status']==='REFERENCE',
        'a matching location link remains a review candidate and never promotes a historical cabinet');
    failsIdentity(fn()=>$setups->addBody($customerAdmin,$correctionId,array_replace($first,['version'=>'3'])),409,
        'historical reference cannot be edited into an operational draft');
    failsIdentity(fn()=>$setups->bind($customerAdmin,$correctionId,array_replace($bind,['version'=>'3'])),409,
        'historical reference cannot bind to an operational locker');
    $runtime->beginTransaction();
    try {
        rejected($runtime,"UPDATE cabinet SET status='DRAFT' WHERE cabinet_id=$correctionId",'42501');
    } finally { $runtime->rollBack(); }

    $referencePage=new think\Request();
    $referenceUri='/admin/locker-setups/'.$correctionId;
    $referencePage->withServer(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>$referenceUri,
        'PATH_INFO'=>$referenceUri,'HTTP_HOST'=>'localhost:8000']);
    $referencePage->withCookie(['zpx_delivery_session'=>$adminToken]);
    $referenceApp=new think\App(dirname(__DIR__)); $referenceApp->debug(false);
    $referenceResponse=$referenceApp->http->run($referencePage);
    check($referenceResponse->getCode()===200
        && str_contains($referenceResponse->getContent(),'Historical reconciliation review')
        && str_contains($referenceResponse->getContent(),'Template check')
        && str_contains($referenceResponse->getContent(),'No matching slot')
        && str_contains($referenceResponse->getContent(),'synthetic-link-revision')
        && !str_contains($referenceResponse->getContent(),'name="location_id"'),
        'historical cabinet page shows review evidence without a bind control');
    $referenceApp->http->end($referenceResponse);

    $request=new think\Request();
    $request->withServer(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/admin/body-box-layouts',
        'PATH_INFO'=>'/admin/body-box-layouts','HTTP_HOST'=>'localhost:8000']);
    $request->withCookie(['zpx_delivery_session'=>$adminToken]);
    $app=new think\App(dirname(__DIR__)); $app->debug(false);
    $response=$app->http->run($request);
    check($response->getCode()===200 && str_contains($response->getContent(),'Body layout positions')
        && str_contains($response->getContent(),'Add position to draft body')
        && str_contains($response->getContent(),'People')
        && str_contains($response->getContent(),'Lockers &amp; hardware'),
        'admin HTML renders the separate body-box layout page');
    $app->http->end($response);
    foreach (['box-models'=>'Add box model','body-models'=>'Add body model','body-box-layouts'=>'Add position to draft body'] as $page=>$marker) {
        $pageRequest=new think\Request();
        $pageRequest->withServer(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/admin/'.$page,
            'PATH_INFO'=>'/admin/'.$page,'HTTP_HOST'=>'localhost:8000']);
        $pageRequest->withCookie(['zpx_delivery_session'=>$adminToken]);
        $pageResponse=$app->http->run($pageRequest);
        check($pageResponse->getCode()===200 && str_contains($pageResponse->getContent(),$marker)
            && !str_contains($pageResponse->getContent(),'name="code"'),$page.' renders separately without an operator code field');
        $editMarker=['box-models'=>'Save box model','body-models'=>'Save body model',
            'body-box-layouts'=>'Save draft position'][$page];
        check(str_contains($pageResponse->getContent(),$editMarker),$page.' exposes its edit form');
        $app->http->end($pageResponse);
    }
    $editBoxForm=new think\Request();
    $editBoxForm->withServer(['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/admin/action/locker-model-box-update',
        'PATH_INFO'=>'/admin/action/locker-model-box-update','HTTP_HOST'=>'localhost:8000']);
    $editBoxForm->withHeader(['origin'=>'http://localhost:8000','host'=>'localhost:8000']);
    $editBoxForm->withCookie(['zpx_delivery_session'=>$adminToken]);
    $editBoxForm->withPost(['_csrf'=>(new Secrets())->digest('csrf',$adminToken),'id'=>$boxCopy,
        'name'=>'Updated via form','size_class'=>'SMALL','width_mm'=>'270','height_mm'=>'280',
        'depth_mm'=>'340','max_weight_g'=>'4200','is_allocable'=>'0','revision'=>'1',
        'reason'=>'Verify box model edit form']);
    $editBoxResponse=$app->http->run($editBoxForm);
    check($editBoxResponse->getCode()===303 &&
        $runtime->query('SELECT model_name FROM cabinet_box_model WHERE model_id='.(int)$boxCopy)->fetchColumn()==='Updated via form',
        'box model edit form saves through guarded admin route');
    $app->http->end($editBoxResponse);
    $editBodyForm=new think\Request();
    $editBodyForm->withServer(['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/admin/action/locker-model-body-update',
        'PATH_INFO'=>'/admin/action/locker-model-body-update','HTTP_HOST'=>'localhost:8000']);
    $editBodyForm->withHeader(['origin'=>'http://localhost:8000','host'=>'localhost:8000']);
    $editBodyForm->withCookie(['zpx_delivery_session'=>$adminToken]);
    $editBodyForm->withPost(['_csrf'=>(new Secrets())->digest('csrf',$adminToken),'id'=>$bodyCopy['id'],
        'name'=>'Updated body via form','revision'=>'1','reason'=>'Verify body model edit form']);
    $editBodyResponse=$app->http->run($editBodyForm);
    check($editBodyResponse->getCode()===303 &&
        $runtime->query('SELECT model_name FROM cabinet_body_model WHERE model_id='.(int)$bodyCopy['id'])->fetchColumn()==='Updated body via form',
        'body model edit form saves through guarded admin route');
    $app->http->end($editBodyResponse);
    $createSetupForm=new think\Request();
    $createSetupForm->withServer(['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/admin/action/locker-setup-create',
        'PATH_INFO'=>'/admin/action/locker-setup-create','HTTP_HOST'=>'localhost:8000']);
    $createSetupForm->withHeader(['origin'=>'http://localhost:8000','host'=>'localhost:8000']);
    $createSetupForm->withCookie(['zpx_delivery_session'=>$adminToken]);
    $createSetupForm->withPost(['_csrf'=>(new Secrets())->digest('csrf',$adminToken),
        'code'=>$code.'-FORM','name'=>'Form cabinet setup','reason'=>'Synthetic form cabinet creation']);
    $createdSetup=$app->http->run($createSetupForm);
    $formSetupId=(string)$setups->listing($customerAdmin)['items'][0]['id'];
    check($createdSetup->getCode()===303 && $setups->detail($customerAdmin,$formSetupId)['draft']['name']==='Form cabinet setup',
        'admin form creates an unbound cabinet setup through the guarded route');
    $app->http->end($createdSetup);
    $addBodyForm=new think\Request();
    $addBodyForm->withServer(['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/admin/action/locker-setup-body-add',
        'PATH_INFO'=>'/admin/action/locker-setup-body-add','HTTP_HOST'=>'localhost:8000']);
    $addBodyForm->withHeader(['origin'=>'http://localhost:8000','host'=>'localhost:8000']);
    $addBodyForm->withCookie(['zpx_delivery_session'=>$adminToken]);
    $addBodyForm->withPost(['_csrf'=>(new Secrets())->digest('csrf',$adminToken),'id'=>$formSetupId,
        'body_model_id'=>$threeDoorId,'code'=>'FORM-BODY','name'=>'Form body','display_sequence'=>'1',
        'controller_address'=>'8','protocol_profile'=>'UNVERIFIED','version'=>'1',
        'reason'=>'Synthetic form body selection']);
    $addedBody=$app->http->run($addBodyForm);
    check($addedBody->getCode()===303 && count($setups->detail($customerAdmin,$formSetupId)['boxes'])===3,
        'admin form selects a body and expands three preview boxes');
    $app->http->end($addedBody);
    $setupRequest=new think\Request();
    $setupRequest->withServer(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/admin/locker-setups/'.$setupId,
        'PATH_INFO'=>'/admin/locker-setups/'.$setupId,'HTTP_HOST'=>'localhost:8000']);
    $setupRequest->withCookie(['zpx_delivery_session'=>$adminToken]);
    $setupResponse=$app->http->run($setupRequest);
    check($setupResponse->getCode()===200 && str_contains($setupResponse->getContent(),'Synthetic six door cabinet')
        && str_contains($setupResponse->getContent(),'Controller / door')
        && str_contains($setupResponse->getContent(),'Review locker'),
        'admin HTML renders bound cabinet setup and independent address preview');
    $app->http->end($setupResponse);
    $detailRequest=new think\Request();
    $detailRequest->withServer(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/admin/lockers/'.$lockerId,
        'PATH_INFO'=>'/admin/lockers/'.$lockerId,'HTTP_HOST'=>'localhost:8000']);
    $detailRequest->withCookie(['zpx_delivery_session'=>$adminToken]);
    $detailResponse=$app->http->run($detailRequest);
    check($detailResponse->getCode()===200
        && str_contains($detailResponse->getContent(),'Assemble locker set')
        && str_contains($detailResponse->getContent(),'name="body_model_ids[]"'),
        'locker detail renders ordered body model selection');
    $app->http->end($detailResponse);
    $form=new think\Request();
    $form->withServer(['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/admin/action/locker-model-assemble',
        'PATH_INFO'=>'/admin/action/locker-model-assemble','HTTP_HOST'=>'localhost:8000']);
    $form->withHeader(['origin'=>'http://localhost:8000','host'=>'localhost:8000']);
    $form->withCookie(['zpx_delivery_session'=>$adminToken]);
    $form->withPost(['_csrf'=>(new Secrets())->digest('csrf',$adminToken),'id'=>$lockerId,
        'body_model_ids'=>[$bodyId],'expected_position'=>'4','reason'=>'Synthetic browser assembly action']);
    $formResponse=$app->http->run($form);
    check($formResponse->getCode()===303 && count($sites->locker($customerAdmin,$lockerId)['bodies'])===4,
        'admin form assembles a body through the guarded route');
    $app->http->end($formResponse);
    $groups=Navigation::groups('locker-detail',['lockers.read','lockers.configure']);
    check(count($groups)===1 && $groups[0]['id']==='lockers' && $groups[0]['open']
        && count($groups[0]['items'])===5,'menu registry hides ungranted groups and expands current locker area');
    $models->addBoxModel($customerAdmin,array_diff_key(array_replace($box,['name'=>'No-code box model']),array_flip(['code','version'])));
    $generatedBox=$models->catalog($customerAdmin)['boxes'][0];
    $models->addBodyModel($customerAdmin,['name'=>'No-code body model','reason'=>'Verify generated internal identity']);
    $generatedBody=$models->catalog($customerAdmin)['bodies'][0];
    check(str_starts_with($generatedBox['code'],'BOX-') && str_starts_with($generatedBody['code'],'BODY-MODEL-'),
        'model forms can create records without operator-entered codes or versions');
    $models->addSlot($customerAdmin,['body_model_id'=>(string)$generatedBody['id'],
        'box_model_id'=>(string)$generatedBox['id'],'row'=>'1','column'=>'1','door_address'=>'0',
        'reason'=>'Verify legacy zero-based door address']);
    $models->ready($customerAdmin,(string)$generatedBody['id'],'Verify zero-based layout publication');
    $zeroSet=$setups->create($customerAdmin,['name'=>'Zero-based legacy cabinet','reason'=>'Verify legacy zero address']);
    $setups->addBody($customerAdmin,$zeroSet,['body_model_id'=>(string)$generatedBody['id'],
        'name'=>'Zero-based body','display_sequence'=>'0','controller_address'=>'0',
        'protocol_profile'=>'UNVERIFIED','version'=>'1','reason'=>'Verify zero-based body sequence']);
    $zero=$setups->detail($customerAdmin,$zeroSet);
    check((int)$zero['bodies'][0]['display_sequence']===0 && (int)$zero['bodies'][0]['controller_address']===0
        && (int)$zero['boxes'][0]['door_address']===0,
        'cabinet model, body, and generated box preserve independent zero-based legacy values');
} finally { putenv($previousOrg===false?'ZPX_ORGANIZATION_ID':'ZPX_ORGANIZATION_ID='.$previousOrg); }
