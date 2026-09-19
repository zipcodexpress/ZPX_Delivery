<?php
declare(strict_types=1);
namespace Zpx\Development;

use PDO;
use RuntimeException;

/** Synthetic baseline only; never invokes a provider or a device. */
final class Seed
{
    public const ORGANIZATION = 'ZPX synthetic development fixture v1';

    public function __construct(private PDO $db, private string $namespace = 'local')
    {
        if (!preg_match('/^[a-z0-9-]{1,20}$/D', $namespace)) { throw new RuntimeException('Invalid synthetic fixture namespace'); }
    }

    public function run(array $fixture): array
    {
        if (!in_array(getenv('APP_ENV'), ['development', 'test'], true)
            || $this->db->query('SELECT current_database()')->fetchColumn() !== 'zpx_delivery_dev') {
            throw new RuntimeException('Seed requires the explicit local development environment and database');
        }
        if (!$this->db->inTransaction()) { throw new RuntimeException('Seed requires a transaction'); }
        if (($fixture['synthetic'] ?? false) !== true || count($fixture['locations'] ?? []) !== 20) {
            throw new RuntimeException('Expected the canonical synthetic 20-site fixture');
        }
        $this->db->query('SELECT pg_advisory_xact_lock(762940118)');
        $existing = $this->db->prepare('SELECT id FROM organizations WHERE name=?');
        $existing->execute([self::ORGANIZATION . ':' . $this->namespace]);
        $ids = $existing->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) > 1) { throw new RuntimeException('Ambiguous seed organization; no data changed'); }
        if ($ids) { return ['created' => false, 'organization_id' => $ids[0], 'credentials' => []]; }

        $org = $this->insert('INSERT INTO organizations(name) VALUES (?)', [self::ORGANIZATION . ':' . $this->namespace]);
        $accounts = ['ADMIN' => 'ADMIN', 'CUSTOMER' => 'CUSTOMER', 'RECIPIENT' => 'CUSTOMER', 'HUB-STAFF' => 'HUB_STAFF', 'DRIVER-IN' => 'DRIVER', 'DRIVER-OUT' => 'DRIVER'];
        $users = []; $credentials = []; $roles = [];
        foreach (array_unique(array_values($accounts)) as $role) {
            $q = $this->db->prepare('INSERT INTO roles(code) VALUES (?) ON CONFLICT(code) DO NOTHING');
            $q->execute([$role]);
            $q = $this->db->prepare('SELECT id FROM roles WHERE code=?'); $q->execute([$role]);
            $roles[$role] = $q->fetchColumn();
        }
        foreach ($accounts as $account => $role) {
            $login = 'synthetic:' . $this->namespace . ':' . $account;
            $user = $this->insert("INSERT INTO users(organization_id,external_auth_id,display_name,status) VALUES (?,?,?,'ACTIVE')", [$org, $login, 'Synthetic ' . $account]);
            $users[$account] = $user;
            $password = bin2hex(random_bytes(24));
            $q = $this->db->prepare('INSERT INTO auth_credentials(user_id,password_hash,password_changed_at) VALUES (?,?,now())');
            $q->execute([$user, password_hash($password, PASSWORD_DEFAULT)]);
            $credentials[] = ['identity' => $login, 'password' => $password];
        }
        $hubLocation = $this->location($org, $fixture['hub']['id'], 'HUB', 'DELIVERY_ONLY');
        $hub = $this->insert("INSERT INTO hubs(location_id,status) VALUES (?,'ACTIVE')", [$hubLocation]);
        $q = $this->db->prepare('INSERT INTO hub_staff(hub_id,user_id) VALUES (?,?)'); $q->execute([$hub, $users['HUB-STAFF']]);
        foreach ($accounts as $account => $role) {
            $q = $this->db->prepare('INSERT INTO scoped_role_grants(user_id,role_id,organization_id,location_id,granted_by) VALUES (?,?,?,?,?)');
            $q->execute([$users[$account], $roles[$role], $org, $role === 'HUB_STAFF' ? $hubLocation : null, $users['ADMIN']]);
        }
        foreach ($fixture['drivers'] as $driver) {
            $this->insert("INSERT INTO drivers(user_id,engagement_type,status) VALUES (?,'SYNTHETIC','ACTIVE')", [$users[$driver]]);
        }
        $locations = [];
        foreach ($fixture['locations'] as $site) {
            $location = $this->location($org, $site['id'], 'LOCKER', $site['site_mode']);
            $locations[$site['id']] = $location;
            $locker = $this->insert('INSERT INTO lockers(location_id,capabilities) VALUES (?,?)', [$location, '{"synthetic":true,"physical_commands_enabled":false}']);
            foreach (['S' => [200, 200, 300, 2000], 'M' => [400, 400, 500, 10000]] as $code => $size) {
                // Frozen until ownership/commissioning is explicitly configured. No invented board addresses.
                $this->insert("INSERT INTO compartments(locker_id,code,width_mm,height_mm,depth_mm,max_weight_g,status) VALUES (?,?,?,?,?,?,'FROZEN')", [$locker, $code, ...$size]);
            }
        }
        $count = 0;
        foreach ($fixture['run']['stops'] as $stop) {
            foreach ($stop['package_ids'] as $reference) {
                $shipment = $this->insert("INSERT INTO shipments(organization_id,sender_user_id,public_reference,origin_location_id,destination_location_id,service_level,order_status,payment_status) VALUES (?,?,?,?,?,'STANDARD','DRAFT','UNPAID')", [$org, $users['CUSTOMER'], 'SYNTHETIC-' . $this->namespace . '-' . $reference, $locations['AUS-001'], $locations[$stop['location_id']]]);
                $this->insert("INSERT INTO packages(shipment_id,package_uuid,sequence_no,width_mm,height_mm,depth_mm,weight_g,state,custodian_type,custodian_ref) VALUES (?,?,1,100,100,100,500,'CREATED','SENDER',?)", [$shipment, self::uuid(), $users['CUSTOMER']]);
                $count++;
            }
        }
        if ($count !== 10) { throw new RuntimeException('Expected ten synthetic parcels'); }
        return ['created' => true, 'organization_id' => $org, 'credentials' => $credentials];
    }

    private function location(string $org, string $code, string $kind, string $mode): string
    {
        return $this->insert("INSERT INTO locations(organization_id,code,name,kind,address_text,site_mode,status,access_policy) VALUES (?,?,?,?,?,?,'ACTIVE',?)", [$org, $this->namespace . ':' . $code, 'Synthetic ' . $code, $kind, 'Synthetic fixture; not a real address', $mode, '{"synthetic":true,"public_shipping_enabled":false}']);
    }

    private function insert(string $sql, array $values): string
    {
        $q = $this->db->prepare($sql . ' RETURNING id'); $q->execute($values);
        return (string)$q->fetchColumn();
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $h = bin2hex($bytes);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}
