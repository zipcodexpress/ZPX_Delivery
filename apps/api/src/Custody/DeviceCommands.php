<?php
declare(strict_types=1);
namespace Zpx\Custody;

use PDO;
use think\Request;
use Zpx\Identity\Failure;
use Zpx\Infrastructure\Database\Transaction;

/** Door authorization for an enrolled terminal; the terminal must journal before actuation. */
final class DeviceCommands
{
    private const PATH='/api/delivery/v1/devices/me/commands';
    public function __construct(private PDO $db) {}
    private function q(string $sql,array $args=[]): \PDOStatement { $q=$this->db->prepare($sql); $q->execute($args); return $q; }

    public function poll(Request $request): array
    {
        return (new Transaction($this->db))->run(function () use ($request) {
            $credential=(new DeviceAuth($this->db))->authenticate($request,'GET',self::PATH);
            $rows=$this->q("SELECT dc.id,dc.command_uuid,dc.expires_at,encode(dc.payload_hash,'hex') AS payload_hash,ls.id AS session_id,
                    c.locker_id,cb.board_address,cb.protocol_profile,c.door_address,co.generation
                FROM device_commands dc JOIN locker_sessions ls ON ls.id=dc.session_id
                JOIN compartments c ON c.id=ls.compartment_id JOIN controller_boards cb ON cb.id=c.controller_board_id AND cb.locker_id=c.locker_id
                JOIN compartment_claims cc ON cc.session_id=ls.id AND cc.compartment_id=c.id AND cc.package_id=ls.package_id
                JOIN compartment_ownership co ON co.compartment_id=c.id AND co.owner='DELIVERY'
                JOIN ownership_manifests om ON om.id=co.manifest_id AND om.locker_id=c.locker_id AND om.generation=co.generation AND om.state='ACTIVE'
                JOIN terminal_pairing_sessions tp ON tp.id=ls.pairing_id
                JOIN packages p ON p.id=ls.package_id
                WHERE dc.device_id=? AND dc.status='PENDING' AND dc.expires_at>now()
                  AND ls.action='FINAL_DEPOSIT' AND ls.status='READY' AND ls.expires_at>now()
                  AND ls.expected_package_version=p.version AND ls.ownership_generation=co.generation AND dc.ownership_generation=co.generation
                  AND cc.state='HELD' AND c.status='AVAILABLE' AND c.locker_id=? AND c.door_address IS NOT NULL
                  AND tp.device_id=dc.device_id AND tp.actor_user_id=ls.actor_user_id AND tp.status='CONSUMED'
                  AND om.generation=(SELECT max(generation) FROM ownership_manifests WHERE locker_id=c.locker_id AND state='ACTIVE')
                  AND p.state='OUTBOUND_CUSTODY' AND p.custodian_type='DRIVER'
                  AND EXISTS (SELECT 1 FROM manifest_items mi JOIN route_runs r ON r.id=mi.run_id
                    JOIN route_run_stops rs ON rs.id=mi.stop_id AND rs.run_id=r.id
                    JOIN drivers dr ON dr.id=r.driver_id
                    JOIN shipments s ON s.id=p.shipment_id JOIN lockers k ON k.location_id=rs.location_id
                    WHERE mi.package_id=p.id AND mi.state='LOADED' AND r.kind='OUTBOUND' AND r.state='IN_PROGRESS'
                      AND r.organization_id=? AND rs.state='ARRIVED' AND k.id=c.locker_id
                      AND s.destination_location_id=rs.location_id AND dr.user_id=ls.actor_user_id
                      AND p.custodian_ref=dr.id::text)
                ORDER BY dc.id LIMIT 20 FOR UPDATE OF dc",[$credential['id'],$credential['locker_id'],(string)(getenv('ZPX_ORGANIZATION_ID') ?: '0')])->fetchAll(PDO::FETCH_ASSOC);
            $commands=[];
            foreach ($rows as $row) {
                $command=['command_id'=>$row['command_uuid'],'session_id'=>(string)$row['session_id'],'action'=>'OPEN',
                    'address'=>['locker_id'=>(string)$row['locker_id'],'board_address'=>(int)$row['board_address'],'door_address'=>(int)$row['door_address']],
                    'protocol_profile'=>$row['protocol_profile'],'ownership_generation'=>(int)$row['generation'],'expires_at'=>$row['expires_at']];
                $hash=hash('sha256',json_encode($command,JSON_THROW_ON_ERROR));
                if ($row['payload_hash']!==null && !hash_equals($row['payload_hash'],$hash)) {
                    throw new Failure(409,'COMMAND_PAYLOAD_CHANGED','Physical address or command policy changed; reconcile before actuation.');
                }
                $this->q("UPDATE device_commands SET dispatched_at=COALESCE(dispatched_at,now()),payload_hash=decode(?,'hex') WHERE id=?",[$hash,$row['id']]);
                $commands[]=$command;
            }
            return ['items'=>$commands];
        });
    }
}
