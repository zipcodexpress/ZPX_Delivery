<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Zpx\Custody\LegacyOccupancyReview;
function expect(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message); }
$now=1700000000;
$box=['cabinet_id'=>'10191','box_id'=>'100','board_address'=>1,'door_address'=>0,
    'row'=>1,'column'=>1,'width'=>80,'height'=>20,'depth'=>400,'status'=>0,'blocked'=>0];
$target=['compartment_id'=>'200','board_address'=>1,'door_address'=>0,'row'=>1,'column'=>1,
    'width'=>80,'height'=>20,'depth'=>400,'status'=>'FROZEN','owner'=>null,'claim_state'=>null,'session_status'=>null];
$review=static fn(array $boxes,array $holds=[],array $doors=[]) => LegacyOccupancyReview::build('10191',$boxes,$holds,$doors,$now,$now);
$r=$review([$box],[],[$target]);
expect($r['items'][0]['state']==='EMPTY_UNVERIFIED' && !$r['activation_allowed'] && !$r['items'][0]['allocation_allowed'],'Source empty flag granted allocation.');
$occupied=$box;$occupied['status']=1;
expect($review([$occupied],[],[$target])['items'][0]['state']==='LEGACY_OCCUPIED','Occupied source flag ignored.');
$r=$review([$box],[['box_id'=>'100']],[$target]);
expect($r['items'][0]['state']==='LEGACY_OCCUPIED' && count($r['items'][0]['reasons'])>0,'Active pickup was overridden by empty flag.');
$claimed=$target;$claimed['claim_state']='OCCUPIED';
expect($review([$occupied],[['box_id'=>'100']],[$claimed])['items'][0]['state']==='CUSTODY_CONFLICT','Conflicting custody was ignored.');
expect($review([$box],[],[$claimed])['items'][0]['state']==='DELIVERY_OCCUPIED','Delivery custody was cleared.');
$pending=$target;$pending['session_status']='UNKNOWN';
expect($review([$box],[],[$pending])['items'][0]['state']==='DELIVERY_REVIEW','Interrupted session was ignored.');
$blocked=$box;$blocked['blocked']=1;
expect($review([$blocked],[],[$target])['items'][0]['state']==='BLOCKED','Blocked source box became empty.');
$unknown=$box;$unknown['status']=null;
expect($review([$unknown],[],[$target])['items'][0]['state']==='SOURCE_REVIEW','Unknown source flag became empty.');
$controller=$box;$controller['box_id']='101';$controller['door_address']=6;$controller['width']=0;$controller['depth']=0;$controller['height']=80;
expect($review([$controller])['items'][0]['state']==='DISPLAY_ONLY','Controller became a physical door.');
expect($review([$controller],[['box_id'=>'101']])['items'][0]['state']==='MAPPING_REVIEW','Hold on controller was discarded.');
$wrong=$target;$wrong['height']=40;
expect($review([$box],[],[$wrong])['items'][0]['state']==='MAPPING_REVIEW','Wrong geometry accepted.');
expect($review([$box])['items'][0]['state']==='MAPPING_REVIEW','Unmapped source box accepted.');
expect($review([],[],[$target])['items'][0]['state']==='MAPPING_REVIEW','Unmapped Delivery door accepted.');
expect(count($review([$box],[['box_id'=>'missing']],[$target])['warnings'])===1,'Orphan source hold hidden.');
foreach([
    static fn()=> $review([$box,$box],[],[$target]),
    static fn()=> $review([$box],[],[$target,$target]),
    static fn()=> $review([array_replace($box,['box_id'=>'101']),$box],[],[$target]),
    static fn()=> $review([array_replace($box,['cabinet_id'=>'10123'])],[],[$target]),
    static fn()=> LegacyOccupancyReview::build('10191',[$box],[],[$target],$now-301,$now),
    static fn()=> LegacyOccupancyReview::build('10191',[$box],[],[$target],$now+1,$now),
] as $invalid){$denied=false;try{$invalid();}catch(InvalidArgumentException){$denied=true;}expect($denied,'Duplicate/wrong-cabinet/stale snapshot accepted.');}
echo "PASS: occupancy flags, active holds, custody conflicts, blocked/unknown cells, controller exclusion, mapping/geometry, duplicates and snapshot freshness; no allocation, database or door writes.\n";
