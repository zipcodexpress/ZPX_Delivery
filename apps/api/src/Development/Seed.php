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
        if ($ids) {
            $this->syncDriverInLabelHashes((string)$ids[0]);
            $this->ensureDemoShifts((string)$ids[0]);
            $this->ensureDemoSlots((string)$ids[0]);
            $this->ensureAssumedLockerOutcomes((string)$ids[0]);
            $this->syncSizePolicy((string)$ids[0]);
            $this->syncLargeCompartments((string)$ids[0]);
            $this->syncVirtualLockerInventory((string)$ids[0]);
            $this->syncProfileAddresses((string)$ids[0]);
            return ['created' => false, 'organization_id' => $ids[0], 'credentials' => []];
        }

        $org = $this->insert('INSERT INTO organizations(name) VALUES (?)', [self::ORGANIZATION . ':' . $this->namespace]);
        $this->syncSizePolicy($org);
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
            // Add verified email and phone for all accounts so browser login works
            $email = strtolower(str_replace([':', '-'], ['.', ''], $login)) . '@synthetic.local';
            $phone = '+1202555' . str_pad((string)(array_search($account, array_keys($accounts)) + 200), 4, '0', STR_PAD_LEFT);
            $crypto = new \Zpx\Identity\Secrets();
            $emailLookup = $crypto->digest('contact:EMAIL', $email);
            $phoneLookup = $crypto->digest('contact:PHONE', $phone);
            $this->db->prepare("INSERT INTO user_contacts(user_id,kind,value_ciphertext,lookup_hmac,key_version,verified_at) VALUES (?,'EMAIL',?,decode(?,'hex'),1,now())")->execute([$user, $crypto->encrypt($email), $emailLookup]);
            $this->db->prepare("INSERT INTO user_contacts(user_id,kind,value_ciphertext,lookup_hmac,key_version,verified_at) VALUES (?,'PHONE',?,decode(?,'hex'),1,now())")->execute([$user, $crypto->encrypt($phone), $phoneLookup]);
            $credentials[] = ['identity' => $login, 'password' => $password, 'email' => $email, 'phone' => $phone];
        }
        $this->syncProfileAddresses($org);
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
            foreach (['S' => [200, 200, 300, 2000], 'M' => [400, 400, 500, 10000], 'L' => [600, 600, 700, 30000]] as $code => $size) {
                // Frozen until ownership/commissioning is explicitly configured. No invented board addresses.
                $this->insert("INSERT INTO compartments(locker_id,code,width_mm,height_mm,depth_mm,max_weight_g,status) VALUES (?,?,?,?,?,?,'FROZEN')", [$locker, $code, ...$size]);
            }
        }
        $this->syncVirtualLockerInventory($org);
        $count = 0;
        foreach ($fixture['run']['stops'] as $stop) {
            foreach ($stop['package_ids'] as $reference) {
                $shipment = $this->insert("INSERT INTO shipments(organization_id,sender_user_id,public_reference,origin_location_id,destination_location_id,service_level,order_status,payment_status) VALUES (?,?,?,?,?,'STANDARD','DRAFT','UNPAID')", [$org, $users['CUSTOMER'], 'SYNTHETIC-' . $this->namespace . '-' . $reference, $locations['AUS-001'], $locations[$stop['location_id']]]);
                $this->insert("INSERT INTO packages(shipment_id,package_uuid,sequence_no,width_mm,height_mm,depth_mm,weight_g,state,custodian_type,custodian_ref) VALUES (?,?,1,100,100,100,500,'CREATED','SENDER',?)", [$shipment, self::uuid(), $users['CUSTOMER']]);
                $count++;
            }
        }
        if ($count !== 10) { throw new RuntimeException('Expected ten synthetic parcels'); }

        // --- P3.2 driver inbound fixture ---
        // Create a vehicle and driver shift for DRIVER-IN
        $vehicle = $this->insert("INSERT INTO vehicles(organization_id,code,max_weight_g,max_volume_mm3,max_packages) VALUES (?,'VEH-IN-01',50000,1000000000,20)", [$org]);
        $driverInId = $this->db->prepare('SELECT id FROM drivers WHERE user_id=?');
        $driverInId->execute([$users['DRIVER-IN']]);
        $driverInProfile = (string)$driverInId->fetchColumn();
        $shift = $this->insert("INSERT INTO driver_shifts(driver_id,vehicle_id,starts_at,ends_at) VALUES (?,?,now()-interval '1 hour',now()+interval '8 hours')", [$driverInProfile, $vehicle]);

        // Create an inbound run for DRIVER-IN with two stops
        $run = $this->insert("INSERT INTO route_runs(organization_id,hub_id,driver_id,vehicle_id,kind,state,revision,planned_start,planned_end) VALUES (?,?,?,?,'INBOUND','PUBLISHED',1,now(),now()+interval '6 hours')", [$org, $hub, $driverInProfile, $vehicle]);
        $stop1 = $this->insert("INSERT INTO route_run_stops(run_id,location_id,sequence_no,state) VALUES (?,?,1,'EXPECTED')", [$run, $locations['AUS-001']]);
        $stop2 = $this->insert("INSERT INTO route_run_stops(run_id,location_id,sequence_no,state) VALUES (?,?,2,'EXPECTED')", [$run, $locations['AUS-002']]);

        // Transition first 5 packages to AT_ORIGIN with paid/ready status and active labels
        $manifest = $this->insert("INSERT INTO manifests(run_id,revision,state) VALUES (?,1,'ACTIVE')", [$run]);
        $packages = $this->db->query("SELECT p.id, p.shipment_id, s.destination_location_id FROM packages p JOIN shipments s ON s.id=p.shipment_id WHERE p.state='CREATED' ORDER BY p.id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($packages as $i => $pkg) {
            $stopId = $i < 3 ? $stop1 : $stop2;
            // Mark shipment as paid/ready
            $this->db->prepare("UPDATE shipments SET order_status='READY', payment_status='PAID', development_only=true WHERE id=?")->execute([$pkg['shipment_id']]);
            // Transition package to AT_ORIGIN
            $this->db->prepare("UPDATE packages SET state='AT_ORIGIN', custodian_type='LOCKER', custodian_ref='origin', current_location_id=?, version=1 WHERE id=?")->execute([$locations['AUS-001'], $pkg['id']]);
            // Deterministic plaintext exists only as fixture knowledge for local manual scans.
            // As with production labels, the database stores only its one-way hash.
            $payload = sprintf('TEST-LABEL-%03d', $i + 1);
            $tokenHash = hash('sha256', $payload);
            $this->db->prepare("INSERT INTO package_labels(package_id,label_version,token_hash,status,expires_at) VALUES (?,1,decode(?,'hex'),'ACTIVE',now()+interval '30 days')")->execute([$pkg['id'], $tokenHash]);
            // Create shipping identifier
            $suffix = strtoupper(substr(md5((string)$pkg['id']), 0, 10));
            $this->db->prepare("INSERT INTO shipping_identifiers(package_id,si,destination_location_id) VALUES (?,?,?)")->execute([$pkg['id'], 'ZPX-DEV-' . $suffix, $pkg['destination_location_id']]);
            // Create manifest item
            $this->insert("INSERT INTO manifest_items(manifest_id,run_id,package_id,stop_id,state) VALUES (?,?,?,?, 'EXPECTED')", [$manifest, $run, $pkg['id'], $stopId]);
        }

        $this->ensureDemoShifts((string)$org);
        $this->ensureDemoSlots((string)$org);
        $this->ensureAssumedLockerOutcomes((string)$org);
        return ['created' => true, 'organization_id' => $org, 'credentials' => $credentials];
    }

    /** Historical UI fixtures only. These do not commission doors or assert device evidence. */
    private function ensureAssumedLockerOutcomes(string $org): void
    {
        $user = $this->db->prepare('SELECT id FROM users WHERE organization_id=? AND external_auth_id=?');
        $location = $this->db->prepare('SELECT id FROM locations WHERE organization_id=? AND code=?');
        $user->execute([$org, 'synthetic:' . $this->namespace . ':CUSTOMER']);
        $sender = $user->fetchColumn();
        $user->execute([$org, 'synthetic:' . $this->namespace . ':RECIPIENT']);
        $recipient = $user->fetchColumn();
        $location->execute([$org, $this->namespace . ':AUS-001']);
        $origin = $location->fetchColumn();
        $location->execute([$org, $this->namespace . ':AUS-004']);
        $destination = $location->fetchColumn();
        $locker = $this->db->prepare('SELECT id FROM lockers WHERE location_id=?');
        $locker->execute([$destination]);
        $lockerId = $locker->fetchColumn();
        if (!$sender || !$recipient || !$origin || !$destination || !$lockerId) {
            throw new RuntimeException('Synthetic locker outcome prerequisites are missing');
        }
        $existing = $this->db->prepare('SELECT id FROM shipments WHERE organization_id=? AND public_reference=?');
        $crypto = new \Zpx\Identity\Secrets();
        $evidence = json_encode(['synthetic_fixture'=>true,'assumed_mature_product'=>true,'device_evidence'=>false], JSON_THROW_ON_ERROR);
        foreach (['DEPOSIT-DEMO' => false, 'PICKUP-DEMO' => true] as $name => $collected) {
            $reference = 'SYNTHETIC-' . $this->namespace . '-' . $name;
            $existing->execute([$org, $reference]);
            if ($existing->fetchColumn()) { continue; } // Never overwrite local demo edits.
            $shipment = $this->insert("INSERT INTO shipments(organization_id,sender_user_id,public_reference,origin_location_id,destination_location_id,service_level,order_status,payment_status,development_only) VALUES (?,?,?,?,?,'STANDARD','READY','PAID',true)", [$org,$sender,$reference,$origin,$destination]);
            $this->db->prepare("INSERT INTO shipment_parties(shipment_id,party_role,user_id,contact_encrypted) VALUES (?,'RECIPIENT',?,?)")
                ->execute([$shipment,$recipient,$crypto->encrypt('{"synthetic_fixture":true}')]);
            $package = $this->insert('INSERT INTO packages(shipment_id,package_uuid,sequence_no,width_mm,height_mm,depth_mm,weight_g,state,custodian_type,custodian_ref,current_location_id,version) VALUES (?,?,1,100,100,100,500,?,?,?,?,?)',
                [$shipment,self::uuid(),$collected?'COLLECTED':'AT_DESTINATION',$collected?'RECIPIENT':'LOCKER',$collected?$recipient:$lockerId,$collected?null:$destination,$collected?2:1]);
            $this->db->prepare("INSERT INTO custody_events(package_id,operation_uuid,package_version,actor_user_id,event_type,previous_custodian_type,previous_custodian_ref,new_custodian_type,new_custodian_ref,location_id,evidence,occurred_at) VALUES (?,?,1,NULL,'SYNTHETIC_ASSUMED_TRANSFER','DRIVER','synthetic-history','LOCKER',?,?,?::jsonb,now()-interval '1 hour')")
                ->execute([$package,self::uuid(),$lockerId,$destination,$evidence]);
            $this->db->prepare("INSERT INTO package_events(package_id,event_uuid,event_type,actor_user_id,details,occurred_at) VALUES (?,?,'DEMO_FINAL_DEPOSIT_ASSUMED',NULL,?::jsonb,now()-interval '1 hour')")
                ->execute([$package,self::uuid(),$evidence]);
            if ($collected) {
                $this->db->prepare("INSERT INTO custody_events(package_id,operation_uuid,package_version,actor_user_id,event_type,previous_custodian_type,previous_custodian_ref,new_custodian_type,new_custodian_ref,location_id,evidence,occurred_at) VALUES (?,?,2,?,'SYNTHETIC_ASSUMED_TRANSFER','LOCKER',?,'RECIPIENT',?,NULL,?::jsonb,now())")
                    ->execute([$package,self::uuid(),$recipient,$lockerId,$recipient,$evidence]);
                $this->db->prepare("INSERT INTO package_events(package_id,event_uuid,event_type,actor_user_id,details,occurred_at) VALUES (?,?,'DEMO_RECIPIENT_PICKUP_ASSUMED',?,?::jsonb,now())")
                    ->execute([$package,self::uuid(),$recipient,$evidence]);
            }
        }
    }

    private function ensureDemoSlots(string $org): void
    {
        $hub = $this->db->prepare('SELECT h.id FROM hubs h JOIN locations l ON l.id=h.location_id WHERE l.organization_id=? AND l.code=?');
        $hub->execute([$org, $this->namespace . ':HUB-AUS-01']);
        $hubId = $hub->fetchColumn();
        if (!$hubId) { return; }
        $location = $this->db->prepare('SELECT id FROM locations WHERE organization_id=? AND code=?');
        $insert = $this->db->prepare("INSERT INTO hub_slots(hub_id,code,destination_location_id,kind) VALUES (?,?,?,'STAGING') ON CONFLICT(hub_id,code) DO NOTHING");
        foreach (['AUS-004', 'AUS-005'] as $code) {
            $location->execute([$org, $this->namespace . ':' . $code]);
            $destination = $location->fetchColumn();
            if ($destination) { $insert->execute([$hubId, 'LOT-' . $code, $destination]); }
        }
    }

    private function ensureDemoShifts(string $org): void
    {
        $vehicle = $this->db->prepare('SELECT id FROM vehicles WHERE organization_id=? AND code=?');
        $driver = $this->db->prepare('SELECT d.id FROM drivers d JOIN users u ON u.id=d.user_id WHERE u.organization_id=? AND u.external_auth_id=?');
        $active = $this->db->prepare('SELECT id FROM driver_shifts WHERE driver_id=? AND starts_at<=now() AND ends_at>now()');
        foreach (['DRIVER-IN' => 'VEH-IN-01', 'DRIVER-OUT' => 'VEH-OUT-01'] as $account => $code) {
            $driver->execute([$org, 'synthetic:' . $this->namespace . ':' . $account]);
            $driverId = $driver->fetchColumn();
            if (!$driverId) { continue; }
            $active->execute([$driverId]);
            if ($active->fetchColumn()) { continue; }
            $vehicle->execute([$org, $code]);
            $vehicleId = $vehicle->fetchColumn();
            if (!$vehicleId) {
                $vehicleId = $this->insert('INSERT INTO vehicles(organization_id,code,max_weight_g,max_volume_mm3,max_packages) VALUES (?,?,50000,1000000000,20)', [$org, $code]);
            }
            $this->db->prepare("INSERT INTO driver_shifts(driver_id,vehicle_id,starts_at,ends_at) VALUES (?,?,now()-interval '1 hour',now()+interval '8 hours')")->execute([$driverId, $vehicleId]);
        }
    }

    private function syncSizePolicy(string $org): void
    {
        $rules = ['classes' => [
            ['code' => 'SMALL', 'amount_cents' => 100, 'max_width_mm' => 180, 'max_height_mm' => 180, 'max_depth_mm' => 280, 'max_weight_g' => 2000],
            ['code' => 'MEDIUM', 'amount_cents' => 200, 'max_width_mm' => 360, 'max_height_mm' => 360, 'max_depth_mm' => 480, 'max_weight_g' => 10000],
            ['code' => 'LARGE', 'amount_cents' => 300, 'max_width_mm' => 550, 'max_height_mm' => 550, 'max_depth_mm' => 650, 'max_weight_g' => 30000],
        ]];
        $this->db->prepare("INSERT INTO pricing_policies(organization_id,code,version,rules,effective_at) VALUES (?,'PHASE1_SIZE',1,?::jsonb,now()) ON CONFLICT(organization_id,code,version) DO NOTHING")
            ->execute([$org, json_encode($rules, JSON_THROW_ON_ERROR)]);
    }

    private function syncLargeCompartments(string $org): void
    {
        $this->db->prepare("INSERT INTO compartments(locker_id,code,width_mm,height_mm,depth_mm,max_weight_g,status)
            SELECT k.id,'L',600,600,700,30000,'FROZEN' FROM lockers k JOIN locations l ON l.id=k.location_id
            WHERE l.organization_id=? AND l.kind='LOCKER' AND l.access_policy->>'synthetic'='true'
              AND NOT EXISTS (SELECT 1 FROM compartments c WHERE c.locker_id=k.id AND c.code='L')")
            ->execute([$org]);
    }
    /** Separate simulation inventory never changes the frozen physical demo doors. */
    private function syncVirtualLockerInventory(string $org): void
    {
        $lockers=$this->db->prepare("SELECT k.id FROM lockers k JOIN locations l ON l.id=k.location_id WHERE l.organization_id=? AND l.access_policy->>'synthetic'='true'");
        $lockers->execute([$org]);
        foreach ($lockers->fetchAll(PDO::FETCH_COLUMN) as $locker) {
            $this->db->prepare("INSERT INTO locker_devices(locker_id,external_device_id,status) VALUES (?,?,'SIMULATED') ON CONFLICT(external_device_id) DO NOTHING")
                ->execute([$locker,'simulated:'.$locker]);
            foreach (['S'=>[200,200,300,2000],'M'=>[400,400,500,10000],'L'=>[600,600,700,30000]] as $code=>$size) {
                $this->db->prepare("INSERT INTO compartments(locker_id,code,width_mm,height_mm,depth_mm,max_weight_g,status) VALUES (?,?,?,?,?,?,'SIMULATED_AVAILABLE') ON CONFLICT(locker_id,code) DO NOTHING")
                    ->execute([$locker,'SIM-'.$code,...$size]);
            }
        }
    }
    private function syncProfileAddresses(string $org): void
    {
        $q=$this->db->prepare("SELECT u.id FROM users u WHERE u.organization_id=? AND u.external_auth_id LIKE ? AND NOT EXISTS (SELECT 1 FROM user_addresses a WHERE a.user_id=u.id AND a.kind='PROFILE')");
        $q->execute([$org,'synthetic:'.$this->namespace.':%']);
        $address=['line1'=>'Synthetic test address — not a real destination','city'=>'Austin','region'=>'TX','postal_code'=>'00000','country_code'=>'US'];
        $cipher=(new \Zpx\Identity\Secrets())->encrypt(json_encode($address,JSON_THROW_ON_ERROR));
        $insert=$this->db->prepare("INSERT INTO user_addresses(user_id,kind,address_ciphertext,country_code,key_version) VALUES (?,'PROFILE',?,'US',1)");
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $user) { $insert->execute([$user,$cipher]); }
    }

    /** Upgrade pre-existing local fixtures whose original random label plaintext was discarded. */
    private function syncDriverInLabelHashes(string $org): void
    {
        $q = $this->db->prepare(
            "SELECT pl.id FROM package_labels pl
             JOIN manifest_items mi ON mi.package_id=pl.package_id
             JOIN route_runs r ON r.id=mi.run_id
             JOIN drivers d ON d.id=r.driver_id
             JOIN users u ON u.id=d.user_id
             JOIN packages p ON p.id=pl.package_id
             JOIN shipments s ON s.id=p.shipment_id
             WHERE r.organization_id=? AND r.kind='INBOUND' AND u.external_auth_id=?
               AND s.development_only=true AND pl.status='ACTIVE'
             ORDER BY mi.id"
        );
        $q->execute([$org, 'synthetic:' . $this->namespace . ':DRIVER-IN']);
        $labels = $q->fetchAll(PDO::FETCH_COLUMN);
        if (count($labels) !== 5) { throw new RuntimeException('Expected five synthetic DRIVER-IN labels'); }
        $update = $this->db->prepare("UPDATE package_labels SET token_hash=decode(?,'hex'), token_ciphertext=NULL WHERE id=?");
        foreach ($labels as $i => $label) {
            $update->execute([hash('sha256', sprintf('TEST-LABEL-%03d', $i + 1)), $label]);
        }
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
