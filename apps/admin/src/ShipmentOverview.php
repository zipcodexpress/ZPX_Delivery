<?php
declare(strict_types=1);
namespace ZpxAdmin;

use PDO;
use Zpx\Identity\{Failure,Secrets};
use Zpx\Shipping\Service as ShippingService;

/** Read-only joins around the authoritative shipping and custody services. */
final class ShipmentOverview
{
    public function __construct(private PDO $db, private Secrets $crypto) {}
    private function q(string $sql,array $args=[]): \PDOStatement
    { $q=$this->db->prepare($sql); $q->execute($args); return $q; }

    public function detail(string $actor,string $id): array
    {
        (new Access($this->db))->requireNetworkAdmin($actor);
        $shipping=new ShippingService($this->db,$this->crypto);
        $shipment=$shipping->get($actor,$id,'operations');
        $tracking=$shipping->tracking($actor,$id,'operations');
        $payments=$shipping->paymentHistory($actor,$id)['items'];
        $org=(string)(getenv('ZPX_ORGANIZATION_ID') ?: '0');
        $parties=$this->q("SELECT s.sender_user_id,u.display_name AS sender_name,
            sp.user_id AS recipient_user_id,ru.display_name AS recipient_name
            FROM shipments s JOIN users u ON u.id=s.sender_user_id
            LEFT JOIN shipment_parties sp ON sp.shipment_id=s.id AND sp.party_role='RECIPIENT'
            LEFT JOIN users ru ON ru.id=sp.user_id WHERE s.id=? AND s.organization_id=?",[$id,$org])->fetch(PDO::FETCH_ASSOC);
        if (!$parties) { throw new Failure(404,'SHIPMENT_NOT_FOUND','Shipment not found.'); }
        $quotes=$this->q('SELECT id,amount_cents,currency,purpose,created_at,expires_at FROM pricing_quotes WHERE shipment_id=? ORDER BY id DESC LIMIT 20',[$id])->fetchAll(PDO::FETCH_ASSOC);
        $refunds=$this->q('SELECT r.id,r.payment_id,r.amount_cents,r.status,r.created_at FROM refunds r JOIN payments p ON p.id=r.payment_id WHERE p.shipment_id=? ORDER BY r.id DESC LIMIT 20',[$id])->fetchAll(PDO::FETCH_ASSOC);
        $sessions=$this->q('SELECT ls.id,ls.action,ls.status,ls.created_at,c.code AS box_code,k.id AS locker_id,l.name AS location_name
            FROM locker_sessions ls JOIN compartments c ON c.id=ls.compartment_id JOIN lockers k ON k.id=c.locker_id
            JOIN locations l ON l.id=k.location_id WHERE ls.package_id=? AND l.organization_id=? ORDER BY ls.id DESC LIMIT 30',
            [$shipment['package_id'],$org])->fetchAll(PDO::FETCH_ASSOC);
        $runs=$this->q('SELECT DISTINCT rr.id,rr.kind,rr.state,u.display_name AS driver_name,d.id AS driver_id
            FROM manifest_items mi JOIN route_runs rr ON rr.id=mi.run_id JOIN drivers d ON d.id=rr.driver_id
            JOIN users u ON u.id=d.user_id WHERE mi.package_id=? AND rr.organization_id=? ORDER BY rr.id DESC LIMIT 20',
            [$shipment['package_id'],$org])->fetchAll(PDO::FETCH_ASSOC);
        $pickup=$this->q('SELECT pg.action,pg.created_at,pg.expires_at,pg.consumed_at FROM pickup_grants pg
            WHERE pg.package_id=? ORDER BY pg.id DESC LIMIT 10',[$shipment['package_id']])->fetchAll(PDO::FETCH_ASSOC);
        return compact('shipment','tracking','payments','parties','quotes','refunds','sessions','runs','pickup');
    }
}
