<?php
declare(strict_types=1);
namespace ZpxAdmin;

use PDO;
use Zpx\Identity\{Failure,Secrets};

/** Paged, network-scoped transaction and activity history. */
final class History
{
    public function __construct(private PDO $db,private Secrets $crypto) {}
    private function q(string $sql,array $args=[]): \PDOStatement
    { $q=$this->db->prepare($sql); $q->execute($args); return $q; }

    public function page(string $actor,string $type,string $id,string $kind,string $cursor='',string $search=''): array
    {
        (new Access($this->db))->requireNetworkAdmin($actor);
        if (!preg_match('/^[1-9][0-9]{0,17}$/D',$id) || $cursor!=='' && !preg_match('/^[1-9][0-9]{0,17}$/D',$cursor)) {
            throw new Failure(422,'INVALID_ID','Invalid history cursor or account ID.');
        }
        $org=(string)(getenv('ZPX_ORGANIZATION_ID') ?: '0');
        if ($type==='customer') {
            $subject=(new CustomerManagement($this->db,$this->crypto))->detail($actor,$id)['customer'];
            $name=$subject['name']; $back='/admin/customers/'.$id;
            $sql=match($kind) {
                'shipments'=>"SELECT s.id AS cursor_id,s.public_reference AS reference,
                    CASE WHEN s.sender_user_id=? THEN 'Sent' ELSE 'Received' END AS detail,
                    s.order_status||' / '||s.payment_status AS status,s.created_at AS occurred_at,s.id AS shipment_id
                    FROM shipments s WHERE s.organization_id=? AND (s.sender_user_id=? OR EXISTS
                    (SELECT 1 FROM shipment_parties sp WHERE sp.shipment_id=s.id AND sp.party_role='RECIPIENT' AND sp.user_id=?))",
                'payments'=>"SELECT p.id AS cursor_id,s.public_reference AS reference,
                    p.provider||' · '||p.amount_cents||' cents' AS detail,p.status,p.created_at AS occurred_at,s.id AS shipment_id
                    FROM payments p JOIN shipments s ON s.id=p.shipment_id WHERE s.sender_user_id=? AND s.organization_id=?",
                default=>throw new Failure(422,'INVALID_HISTORY','Invalid customer history type.'),
            };
            $args=$kind==='shipments'?[$id,$org,$id,$id]:[$id,$org];
        } elseif ($type==='driver') {
            $subject=(new DriverAdministration($this->db,$this->crypto))->detail($actor,$id)['driver'];
            $name=$subject['name']; $back='/admin/drivers/'.$id;
            $sql=match($kind) {
                'runs'=>"SELECT r.id AS cursor_id,'Run '||r.id AS reference,r.kind AS detail,r.state AS status,
                    r.created_at AS occurred_at,NULL::bigint AS shipment_id FROM route_runs r
                    WHERE r.driver_id=? AND r.organization_id=?",
                'offers'=>"SELECT o.id AS cursor_id,'Offer '||o.id AS reference,l.name AS detail,o.status,
                    o.offered_at AS occurred_at,NULL::bigint AS shipment_id FROM driver_offers o
                    JOIN locations l ON l.id=o.origin_location_id WHERE o.driver_id=? AND l.organization_id=?",
                'earnings'=>"SELECT e.id AS cursor_id,'Entry '||e.id AS reference,
                    e.kind||' · '||e.amount_cents||' cents' AS detail,
                    CASE WHEN e.settled_at IS NULL THEN 'UNSETTLED' ELSE 'SETTLED' END AS status,
                    e.created_at AS occurred_at,NULL::bigint AS shipment_id FROM driver_pay_entries e
                    JOIN drivers d ON d.id=e.driver_id JOIN users u ON u.id=d.user_id
                    WHERE e.driver_id=? AND u.organization_id=?",
                'scans'=>"SELECT se.id AS cursor_id,se.action AS reference,s.public_reference AS detail,
                    se.result_code AS status,se.received_at AS occurred_at,s.id AS shipment_id FROM scan_events se
                    JOIN packages p ON p.id=se.package_id JOIN shipments s ON s.id=p.shipment_id
                    WHERE se.actor_user_id=? AND s.organization_id=?",
                default=>throw new Failure(422,'INVALID_HISTORY','Invalid driver history type.'),
            };
            $args=[$kind==='scans'?$subject['user_id']:$id,$org];
        } else { throw new Failure(422,'INVALID_HISTORY','Invalid history subject.'); }
        $sql='SELECT * FROM ('.$sql.') h WHERE TRUE';
        $search=trim(substr($search,0,120));
        if ($search!=='') {
            $sql.=' AND (h.reference ILIKE ? OR h.detail ILIKE ? OR h.status ILIKE ?)';
            array_push($args,...array_fill(0,3,'%'.$search.'%'));
        }
        if ($cursor!=='') { $sql.=' AND h.cursor_id<?'; $args[]=$cursor; }
        $rows=$this->q($sql.' ORDER BY h.cursor_id DESC LIMIT 26',$args)->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>25; $rows=array_slice($rows,0,25);
        return ['items'=>$rows,'next_cursor'=>$more?(string)end($rows)['cursor_id']:null,
            'name'=>$name,'kind'=>$kind,'type'=>$type,'back'=>$back,'id'=>$id,'search'=>$search,
            'next_url'=>$more?$back.'/history?'.http_build_query(array_filter([
                'kind'=>$kind,'q'=>$search,'cursor'=>(string)end($rows)['cursor_id'],
            ],static fn($value): bool => $value!=='')):null];
    }
}
