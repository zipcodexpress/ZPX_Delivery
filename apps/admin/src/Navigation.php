<?php
declare(strict_types=1);
namespace ZpxAdmin;

/** Static menu registry for implemented admin pages; permissions still guard every route/action. */
final class Navigation
{
    private const GROUPS = [
        'people'=>'People',
        'network'=>'Network & locations',
        'lockers'=>'Lockers & hardware',
        'shipments'=>'Shipments & tracking',
        'dispatch'=>'Dispatch & drivers',
        'partners'=>'Partners & carriers',
    ];
    private const ITEMS = [
        ['id'=>'customers','parent'=>'people','label'=>'Customers','route'=>'/admin/customers','read'=>'customers.read','sort'=>10,'ready'=>true],
        ['id'=>'drivers','parent'=>'people','label'=>'Drivers','route'=>'/admin/drivers','read'=>'drivers.read','sort'=>20,'ready'=>true],
        ['id'=>'sites','parent'=>'network','label'=>'Installation sites','route'=>'/admin/sites','read'=>'sites.read','sort'=>10,'ready'=>true],
        ['id'=>'lockers','parent'=>'lockers','label'=>'All lockers','route'=>'/admin/lockers','read'=>'lockers.read','sort'=>10,'ready'=>true],
        ['id'=>'locker-models','parent'=>'lockers','label'=>'Models & layouts','route'=>'/admin/locker-models','read'=>'lockers.configure','sort'=>20,'ready'=>true],
        ['id'=>'shipments','parent'=>'shipments','label'=>'All shipments','route'=>'/admin/shipments','read'=>'shipments.read','sort'=>10,'ready'=>true],
        ['id'=>'pickup-routes','parent'=>'dispatch','label'=>'Route setup','route'=>'/admin/pickup-routes','read'=>'pickup_routes.manage','sort'=>10,'ready'=>true],
        ['id'=>'pickup-recovery','parent'=>'dispatch','label'=>'Pickup recovery','route'=>'/admin/pickup-recovery','read'=>'pickup_recovery.manage','sort'=>20,'ready'=>true],
        ['id'=>'partners','parent'=>'partners','label'=>'All partners','route'=>'/admin/partners','read'=>'partners.read','sort'=>10,'ready'=>true],
    ];

    public static function groups(string $page,array $capabilities): array
    {
        $active = match (true) {
            str_starts_with($page,'customer')=>'customers',
            str_starts_with($page,'driver')=>'drivers',
            str_starts_with($page,'partner')=>'partners',
            str_starts_with($page,'site')=>'sites',
            str_starts_with($page,'locker-detail')=>'lockers',
            str_starts_with($page,'shipment')=>'shipments',
            default=>$page,
        };
        $groups=[];
        foreach (self::GROUPS as $id=>$label) {
            $items=[];
            foreach (self::ITEMS as $item) {
                if ($item['parent']!==$id || !$item['ready'] || !in_array($item['read'],$capabilities,true)) { continue; }
                $item['active']=$item['id']===$active;
                $items[]=$item;
            }
            if ($items) {
                $groups[]=['id'=>$id,'label'=>$label,'open'=>in_array(true,array_column($items,'active'),true),'items'=>$items];
            }
        }
        return $groups;
    }
}
