<?php
declare(strict_types=1);

use ZpxAdmin\{LockerModels,Navigation,SiteInventory};
use Zpx\Identity\Secrets;

$previousOrg=getenv('ZPX_ORGANIZATION_ID'); putenv('ZPX_ORGANIZATION_ID='.$shippingOrg);
try {
    $models=new LockerModels($runtime);
    failsIdentity(fn()=>$models->catalog($sender),403,'customer cannot read locker model catalog');
    $box=['code'=>'TEST-BOX-S','version'=>'1','name'=>'Synthetic small box','size_class'=>'SMALL','width_mm'=>'260',
        'height_mm'=>'280','depth_mm'=>'340','max_weight_g'=>'4000','reason'=>'Synthetic box model setup'];
    failsIdentity(fn()=>$models->addBoxModel($customerAdmin,array_replace($box,['size_class'=>'HUGE'])),422,
        'box model requires a supported size class');
    $models->addBoxModel($customerAdmin,$box);
    failsIdentity(fn()=>$models->addBoxModel($customerAdmin,$box),409,'box model version cannot be duplicated');
    $catalog=$models->catalog($customerAdmin);
    $boxId=(string)$catalog['boxes'][0]['id'];
    check($catalog['boxes'][0]['size_class']==='SMALL','box model retains its explicit size class');
    $models->addBoxModel($customerAdmin,array_replace($box,['code'=>'TEST-BOX-M','name'=>'Synthetic mid box',
        'size_class'=>'MEDIUM','height_mm'=>'400']));
    $midBoxId=(string)$models->catalog($customerAdmin)['boxes'][0]['id'];
    $models->addBodyModel($customerAdmin,['code'=>'TEST-BODY-A','version'=>'1','name'=>'Synthetic body layout',
        'reason'=>'Synthetic body model setup']);
    $bodyId=(string)$models->catalog($customerAdmin)['bodies'][0]['id'];
    failsIdentity(fn()=>$models->ready($customerAdmin,$bodyId,'Premature model publication'),409,
        'empty body model cannot be published');
    $slot=['body_model_id'=>$bodyId,'box_model_id'=>$boxId,'row'=>'1','column'=>'1','reason'=>'Synthetic layout slot setup'];
    $models->addSlot($customerAdmin,$slot);
    failsIdentity(fn()=>$models->addSlot($customerAdmin,$slot),409,'duplicate body layout position is rejected');
    $models->addSlot($customerAdmin,array_replace($slot,['column'=>'2']));
    $removeId=(string)$models->catalog($customerAdmin)['slots'][1]['id'];
    $models->removeSlot($customerAdmin,$removeId,'Synthetic draft layout correction');
    check(count($models->catalog($customerAdmin)['slots'])===1,'draft layout position can be removed before publication');
    $models->ready($customerAdmin,$bodyId,'Synthetic layout review complete');
    $models->addBodyModel($customerAdmin,['code'=>'TEST-BODY-B','version'=>'1','name'=>'Synthetic mixed body',
        'reason'=>'Synthetic second body setup']);
    $secondBodyId=(string)$models->catalog($customerAdmin)['bodies'][0]['id'];
    $models->addSlot($customerAdmin,['body_model_id'=>$secondBodyId,'box_model_id'=>$midBoxId,
        'row'=>'1','column'=>'1','reason'=>'Synthetic mid layout position']);
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

    $request=new think\Request();
    $request->withServer(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/admin/locker-models',
        'PATH_INFO'=>'/admin/locker-models','HTTP_HOST'=>'localhost:8000']);
    $request->withCookie(['zpx_delivery_session'=>$adminToken]);
    $app=new think\App(dirname(__DIR__)); $app->debug(false);
    $response=$app->http->run($request);
    check($response->getCode()===200 && str_contains($response->getContent(),'Synthetic body layout')
        && str_contains($response->getContent(),'Add position to draft body')
        && str_contains($response->getContent(),'People')
        && str_contains($response->getContent(),'Lockers &amp; hardware'),
        'admin HTML renders versioned locker model catalog');
    $app->http->end($response);
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
        && count($groups[0]['items'])===2,'menu registry hides ungranted groups and expands current locker area');
} finally { putenv($previousOrg===false?'ZPX_ORGANIZATION_ID':'ZPX_ORGANIZATION_ID='.$previousOrg); }
