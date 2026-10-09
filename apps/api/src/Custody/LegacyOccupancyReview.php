<?php
declare(strict_types=1);
namespace Zpx\Custody;

/** Read-only comparison. No result grants allocation, custody or opening authority. */
final class LegacyOccupancyReview
{
    public static function build(string $sourceCabinet,array $source,array $holds,array $delivery,int $capturedAt,int $now): array
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D',$sourceCabinet) || $capturedAt>$now || $capturedAt<$now-300) {
            throw new \InvalidArgumentException('Cabinet identity or snapshot time is invalid/stale.');
        }
        $byAddress=[];$byId=[];$counts=[];$items=[];$mapped=[];$warnings=[];
        foreach ($delivery as $box) {
            $key=self::address($box);
            if (isset($byAddress[$key])) { throw new \InvalidArgumentException('Duplicate Delivery physical address.'); }
            $byAddress[$key]=$box;
        }
        foreach ($source as $box) {
            if ((string)$box['cabinet_id']!==$sourceCabinet || isset($byId[(string)$box['box_id']])) {
                throw new \InvalidArgumentException('Duplicate or wrong-cabinet source box.');
            }
            $byId[(string)$box['box_id']]=$box;
        }
        $active=[];
        foreach ($holds as $hold) {
            $id=(string)$hold['box_id'];
            if (!isset($byId[$id])) { $warnings[]='Unmapped legacy hold: box '.$id; continue; }
            $active[$id]=($active[$id]??0)+1;
        }
        $addresses=[];
        foreach ($source as $box) {
            $id=(string)$box['box_id'];$key=self::address($box);
            if (isset($addresses[$key])) { throw new \InvalidArgumentException('Duplicate source physical address.'); }
            $addresses[$key]=true;$target=$byAddress[$key]??null;$reason=[];
            $controller=(float)$box['height']>0 && (float)$box['width']===0.0 && (float)$box['depth']===0.0;
            $occupied=($active[$id]??0)>0 || in_array((string)($box['status']??''),['1','4'],true);
            $matches=$target!==null && (int)$box['row']===(int)$target['row'] && (int)$box['column']===(int)$target['column'];
            foreach (['width','height','depth'] as $dimension) {
                $matches=$matches && (float)$box[$dimension]>0 && (float)$box[$dimension]===(float)($target[$dimension]??0);
            }
            if ($target!==null) { $mapped[$key]=true; }
            $claim=$target['claim_state']??null;$session=$target['session_status']??null;
            $deliveryHold=$claim!==null || in_array($session,['READY','OPEN','CLOSED','UNKNOWN'],true);
            $state=match (true) {
                $controller && (!$occupied && ($active[$id]??0)===0) && $target===null => 'DISPLAY_ONLY',
                !$matches => 'MAPPING_REVIEW',
                $occupied && $deliveryHold => 'CUSTODY_CONFLICT',
                $deliveryHold => $claim==='OCCUPIED' ? 'DELIVERY_OCCUPIED' : 'DELIVERY_REVIEW',
                $occupied => 'LEGACY_OCCUPIED',
                (string)($box['blocked']??'')==='1' => 'BLOCKED',
                (string)($box['status']??'')==='0' && (string)($box['blocked']??'')==='0' => 'EMPTY_UNVERIFIED',
                default => 'SOURCE_REVIEW',
            };
            if (!$matches && !$controller) { $reason[]='Address, placement or confirmed geometry does not match.'; }
            if (($active[$id]??0)>0 && !in_array((string)($box['status']??''),['1','4'],true)) { $reason[]='Active source record conflicts with box flag.'; }
            if (($active[$id]??0)>1) { $reason[]='Multiple active source records.'; }
            if ((string)($box['blocked']??'')==='1') { $reason[]='Legacy blocked flag is set.'; }
            if ($state==='EMPTY_UNVERIFIED') { $reason[]='Verify physical emptiness and exclusive ownership before activation.'; }
            $items[]=['legacy_box_id'=>$id,'compartment_id'=>$target['compartment_id']??null,
                'board_address'=>(int)$box['board_address'],'door_address'=>(int)$box['door_address'],
                'displayed_door'=>(int)$box['door_address']+1,'state'=>$state,'source_hold_count'=>$active[$id]??0,
                'delivery_status'=>$target['status']??null,'delivery_owner'=>$target['owner']??null,
                'allocation_allowed'=>false,'reasons'=>$reason];
            $counts[$state]=($counts[$state]??0)+1;
        }
        foreach ($byAddress as $key=>$box) {
            if (!isset($mapped[$key])) {
                $warnings[]='Delivery door has no source mapping: '.$key;
                $items[]=['legacy_box_id'=>null,'compartment_id'=>$box['compartment_id'],
                    'board_address'=>(int)$box['board_address'],'door_address'=>(int)$box['door_address'],
                    'displayed_door'=>(int)$box['door_address']+1,'state'=>'MAPPING_REVIEW','allocation_allowed'=>false,
                    'reasons'=>['Delivery door missing from source snapshot.']];
                $counts['MAPPING_REVIEW']=($counts['MAPPING_REVIEW']??0)+1;
            }
        }
        usort($items,static fn($a,$b)=>[$a['board_address'],$a['door_address']]<=>[$b['board_address'],$b['door_address']]);
        return ['source_cabinet_id'=>$sourceCabinet,'captured_at'=>gmdate('c',$capturedAt),
            'read_only'=>true,'activation_allowed'=>false,'counts'=>$counts,'warnings'=>array_values(array_unique($warnings)),'items'=>$items];
    }

    private static function address(array $box): string
    {
        foreach (['board_address','door_address'] as $field) {
            if (!isset($box[$field]) || !preg_match('/^[0-9]{1,3}$/D',(string)$box[$field]) || (int)$box[$field]>255) {
                throw new \InvalidArgumentException('Missing/invalid physical address.');
            }
        }
        return (int)$box['board_address'].':'.(int)$box['door_address'];
    }
}
