<?php
declare(strict_types=1);
namespace Zpx\Driver;

use PDO;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;

/** Driver lifecycle: register → pending → approved → working → paid. */
final class Service
{
    private Identity $identity;

    public function __construct(private PDO $db, private Secrets $crypto)
    {
        $this->identity = new Identity($db, $crypto);
    }

    private function q(string $sql, array $values = []): \PDOStatement
    {
        $q = $this->db->prepare($sql);
        $q->execute($values);
        return $q;
    }

    private function org(): string
    {
        return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0');
    }

    // ── Registration & Approval ──────────────────────────────────────

    public function register(string $user, array $input, string $key): array
    {
        Input::fields($input, ['engagement_type'], ['phone', 'email']);
        $engagement = Input::text($input['engagement_type'], 1, 24);
        if (!in_array($engagement, ['CONTRACTOR', 'EMPLOYEE', 'SYNTHETIC'], true)) {
            throw new Failure(422, 'INVALID_INPUT', 'Invalid engagement type.');
        }

        return (new Transaction($this->db))->run(function () use ($user, $engagement, $input, $key) {
            $scope = 'driver:' . $this->org() . ':register';
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$scope . ':' . $key]);

            if (!$this->q("SELECT id FROM users WHERE id=? AND organization_id=? AND status='ACTIVE'", [$user, $this->org()])->fetchColumn()) {
                throw new Failure(403, 'ACCESS_DENIED', 'Access denied.');
            }

            // Check if user already has a driver profile
            $existing = $this->q('SELECT id, status FROM drivers WHERE user_id=?', [$user])->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                throw new Failure(409, 'DRIVER_EXISTS', 'A driver profile already exists. Status: ' . $existing['status']);
            }

            $phone = isset($input['phone']) ? Input::text($input['phone'], 1, 20) : null;
            $email = isset($input['email']) ? Input::text($input['email'], 1, 254) : null;

            $driverId = $this->insert(
                "INSERT INTO drivers(user_id,engagement_type,status,phone,email) VALUES (?,?, 'PENDING',?,?)",
                [$user, $engagement, $phone, $email]
            );

            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'DRIVER_REGISTERED','driver',?)", [$user, $driverId]);

            return [
                'driver_id' => $driverId,
                'user_id' => $user,
                'status' => 'PENDING',
                'message' => 'Driver registration submitted. Awaiting admin approval.',
            ];
        });
    }

    public function approve(string $adminUser, string $driverId, string $key): array
    {
        $this->requireAdmin($adminUser);
        Input::text($driverId, 1, 18);
        Input::text($key, 16, 100);

        return (new Transaction($this->db))->run(function () use ($adminUser, $driverId, $key) {
            $scope = 'driver:' . $this->org() . ':approve';
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$scope . ':' . $key]);

            $driver = $this->q('SELECT * FROM drivers WHERE id=? AND organization_id=(SELECT organization_id FROM users WHERE id=?) FOR UPDATE', [$driverId, $adminUser])->fetch(PDO::FETCH_ASSOC);
            if (!$driver) {
                throw new Failure(404, 'DRIVER_NOT_FOUND', 'Driver not found.');
            }
            if ($driver['status'] === 'ACTIVE') {
                throw new Failure(409, 'ALREADY_APPROVED', 'Driver is already approved.');
            }
            if ($driver['status'] !== 'PENDING') {
                throw new Failure(409, 'INVALID_STATUS', 'Driver cannot be approved from status: ' . $driver['status']);
            }

            $this->q("UPDATE drivers SET status='ACTIVE', approved_at=now(), approved_by=? WHERE id=?", [$adminUser, $driverId]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'DRIVER_APPROVED','driver',?)", [$adminUser, $driverId]);

            return ['driver_id' => $driverId, 'user_id' => $driver['user_id'], 'status' => 'ACTIVE'];
        });
    }

    public function reject(string $adminUser, string $driverId, array $input, string $key): array
    {
        $this->requireAdmin($adminUser);
        Input::fields($input, ['reason']);
        $reason = Input::text($input['reason'], 1, 500);
        Input::text($driverId, 1, 18);
        Input::text($key, 16, 100);

        return (new Transaction($this->db))->run(function () use ($adminUser, $driverId, $reason, $key) {
            $scope = 'driver:' . $this->org() . ':reject';
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$scope . ':' . $key]);

            $driver = $this->q('SELECT * FROM drivers WHERE id=? FOR UPDATE', [$driverId])->fetch(PDO::FETCH_ASSOC);
            if (!$driver) {
                throw new Failure(404, 'DRIVER_NOT_FOUND', 'Driver not found.');
            }
            if ($driver['status'] !== 'PENDING') {
                throw new Failure(409, 'INVALID_STATUS', 'Only pending drivers can be rejected.');
            }

            $this->q("UPDATE drivers SET status='INACTIVE', rejection_reason=?, approved_at=now() WHERE id=?", [$reason, $driverId]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,'DRIVER_REJECTED','driver',?,?)", [$adminUser, $driverId, $reason]);

            return ['driver_id' => $driverId, 'user_id' => $driver['user_id'], 'status' => 'INACTIVE', 'reason' => $reason];
        });
    }

    // ── Driver Profile ───────────────────────────────────────────────

    public function profile(string $user): array
    {
        $this->requireDriver($user);
        $driver = $this->q(
            "SELECT d.*, u.display_name, u.external_auth_id
             FROM drivers d
             JOIN users u ON u.id=d.user_id
             WHERE d.user_id=?",
            [$user]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$driver) {
            throw new Failure(404, 'DRIVER_NOT_FOUND', 'No driver profile found.');
        }

        $vehicle = $this->q(
            "SELECT v.code, v.max_weight_g, v.max_packages
             FROM driver_shifts ds
             JOIN vehicles v ON v.id=ds.vehicle_id
             WHERE ds.driver_id=? AND ds.ends_at > now()
             ORDER BY ds.starts_at DESC LIMIT 1",
            [$driver['id']]
        )->fetch(PDO::FETCH_ASSOC);

        return [
            'driver_id' => (string)$driver['id'],
            'user_id' => $user,
            'name' => $driver['display_name'],
            'status' => $driver['status'],
            'engagement_type' => $driver['engagement_type'],
            'email' => $driver['email'],
            'phone' => $driver['phone'],
            'applied_at' => $driver['applied_at'],
            'approved_at' => $driver['approved_at'],
            'license' => $driver['license_number'] ? [
                'number' => $driver['license_number'],
                'state' => $driver['license_state'],
                'expiry' => $driver['license_expiry'],
            ] : null,
            'date_of_birth' => $driver['date_of_birth'],
            'address' => $driver['address_line1'] ? [
                'line1' => $driver['address_line1'],
                'line2' => $driver['address_line2'],
                'city' => $driver['address_city'],
                'state' => $driver['address_state'],
                'postal_code' => $driver['address_postal_code'],
                'country_code' => $driver['address_country_code'],
            ] : null,
            'emergency_contact' => $driver['emergency_contact_name'] ? [
                'name' => $driver['emergency_contact_name'],
                'phone' => $driver['emergency_contact_phone'],
            ] : null,
            'vehicle_details' => $driver['vehicle_make'] ? [
                'make' => $driver['vehicle_make'],
                'model' => $driver['vehicle_model'],
                'year' => $driver['vehicle_year'] ? (int)$driver['vehicle_year'] : null,
                'color' => $driver['vehicle_color'],
            ] : null,
            'insurance_reference' => $driver['insurance_reference'],
            'notes' => $driver['notes'],
            'assigned_vehicle' => $vehicle ? [
                'code' => $vehicle['code'],
                'max_weight_g' => (int)$vehicle['max_weight_g'],
                'max_packages' => (int)$vehicle['max_packages'],
            ] : null,
        ];
    }

    public function updateProfile(string $user, array $input, string $key): array
    {
        $this->requireDriver($user);
        Input::text($key, 16, 100);

        $allowed = ['name','email','phone','license_number','license_state','license_expiry',
            'date_of_birth','address_line1','address_line2','address_city','address_state',
            'address_postal_code','address_country_code','emergency_contact_name',
            'emergency_contact_phone','vehicle_make','vehicle_model','vehicle_year',
            'vehicle_color','insurance_reference','notes'];
        Input::fields($input, [], $allowed);

        return (new Transaction($this->db))->run(function () use ($user, $input, $key) {
            $scope = 'driver:' . $this->org() . ':' . $user . ':profile-update';
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$scope . ':' . $key]);

            $driverId = $this->driverId($user);

            // Update user display name if provided
            if (isset($input['name'])) {
                $name = Input::text($input['name'], 1, 160);
                $this->q("UPDATE users SET display_name=? WHERE id=?", [$name, $user]);
            }

            // Build driver update
            $sets = ["updated_at=now()"];
            $args = [];
            $columnMap = [
                'email' => 'email', 'phone' => 'phone',
                'license_number' => 'license_number', 'license_state' => 'license_state',
                'license_expiry' => 'license_expiry', 'date_of_birth' => 'date_of_birth',
                'address_line1' => 'address_line1', 'address_line2' => 'address_line2',
                'address_city' => 'address_city', 'address_state' => 'address_state',
                'address_postal_code' => 'address_postal_code', 'address_country_code' => 'address_country_code',
                'emergency_contact_name' => 'emergency_contact_name',
                'emergency_contact_phone' => 'emergency_contact_phone',
                'vehicle_make' => 'vehicle_make', 'vehicle_model' => 'vehicle_model',
                'vehicle_year' => 'vehicle_year', 'vehicle_color' => 'vehicle_color',
                'insurance_reference' => 'insurance_reference', 'notes' => 'notes',
            ];
            foreach ($columnMap as $inputKey => $column) {
                if (isset($input[$inputKey])) {
                    $value = $input[$inputKey];
                    if ($value !== null && $value !== '') {
                        if (in_array($inputKey, ['address_country_code'])) {
                            if (!preg_match('/^[A-Z]{2}$/D', $value)) { throw new Failure(422, 'INVALID_INPUT', 'Country code must be two uppercase letters.'); }
                        } elseif (in_array($inputKey, ['license_state'])) {
                            if (strlen($value) > 0) { $value = strtoupper($value); }
                        } elseif ($inputKey === 'vehicle_year') {
                            $value = (int)$value;
                        } else {
                            $value = Input::text($value, 0, 254);
                        }
                    } else {
                        $value = null;
                    }
                    $sets[] = "$column=?";
                    $args[] = $value;
                }
            }

            if (count($sets) > 1) {
                $args[] = $driverId;
                $this->q("UPDATE drivers SET " . implode(',', $sets) . " WHERE id=?", $args);
            }

            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'DRIVER_PROFILE_UPDATED','driver',?)", [$user, $driverId]);

            return $this->profile($user);
        });
    }

    // ── Wallet & Earnings ────────────────────────────────────────────

    public function wallet(string $user): array
    {
        $this->requireDriver($user);
        $driverId = $this->driverId($user);

        // Total earned (from pay entries)
        $totalEarned = (int)$this->q(
            "SELECT COALESCE(SUM(amount_cents), 0) FROM driver_pay_entries WHERE driver_id=?",
            [$driverId]
        )->fetchColumn();

        // Total settled
        $totalSettled = (int)$this->q(
            "SELECT COALESCE(SUM(amount_cents), 0) FROM driver_pay_entries WHERE driver_id=? AND settled_at IS NOT NULL",
            [$driverId]
        )->fetchColumn();

        // Pending (earned but not settled)
        $pending = $totalEarned - $totalSettled;

        // Current shift compensation
        $currentShift = $this->q(
            "SELECT ds.id, ds.compensation_cents, ds.compensation_policy, ds.starts_at, ds.ends_at
             FROM driver_shifts ds
             WHERE ds.driver_id=? AND ds.ends_at > now()
             ORDER BY ds.starts_at DESC LIMIT 1",
            [$driverId]
        )->fetch(PDO::FETCH_ASSOC);

        return [
            'total_earned_cents' => $totalEarned,
            'total_settled_cents' => $totalSettled,
            'pending_cents' => $pending,
            'currency' => 'USD',
            'current_shift' => $currentShift ? [
                'shift_id' => (string)$currentShift['id'],
                'compensation_cents' => (int)$currentShift['compensation_cents'],
                'policy' => $currentShift['compensation_policy'],
                'starts_at' => $currentShift['starts_at'],
                'ends_at' => $currentShift['ends_at'],
            ] : null,
        ];
    }

    public function transactions(string $user, string $cursor = ''): array
    {
        $this->requireDriver($user);
        $driverId = $this->driverId($user);

        if ($cursor !== '' && !preg_match('/^[1-9][0-9]{0,17}$/D', $cursor)) {
            throw new Failure(422, 'INVALID_INPUT', 'Invalid cursor.');
        }

        $args = [$driverId];
        $where = '';
        if ($cursor !== '') {
            $where = ' AND dpe.id < ?';
            $args[] = $cursor;
        }

        $rows = $this->q(
            "SELECT dpe.id, dpe.amount_cents, dpe.kind, dpe.policy_version, dpe.created_at,
                    dpe.run_id, r.kind AS run_kind, r.planned_start,
                    dpe.settled_at, dpe.settlement_reference
             FROM driver_pay_entries dpe
             LEFT JOIN route_runs r ON r.id=dpe.run_id
             WHERE dpe.driver_id=?" . $where . "
             ORDER BY dpe.id DESC LIMIT 51",
            $args
        )->fetchAll(PDO::FETCH_ASSOC);

        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);

        $items = array_map(fn($r) => [
            'transaction_id' => (string)$r['id'],
            'amount_cents' => (int)$r['amount_cents'],
            'kind' => $r['kind'],
            'policy_version' => $r['policy_version'],
            'run_id' => $r['run_id'] ? (string)$r['run_id'] : null,
            'run_kind' => $r['run_kind'],
            'run_date' => $r['planned_start'],
            'created_at' => gmdate('c', strtotime($r['created_at'])),
            'settled_at' => $r['settled_at'] ? gmdate('c', strtotime($r['settled_at'])) : null,
            'settlement_reference' => $r['settlement_reference'],
        ], $rows);

        return [
            'items' => $items,
            'next_cursor' => $more ? (string)end($rows)['id'] : null,
        ];
    }

    // ── Compensation Calculation ─────────────────────────────────────

    /**
     * Calculate compensation for a completed run.
     * Phase 1: fixed shift/route rate configured by operations.
     */
    public function calculateRunCompensation(string $driverId, string $runId): int
    {
        // Find the shift that covers this run
        $shift = $this->q(
            "SELECT ds.compensation_cents, ds.compensation_policy
             FROM driver_shifts ds
             JOIN route_runs r ON r.driver_id=ds.driver_id
             WHERE ds.driver_id=? AND r.id=?
               AND r.planned_start BETWEEN ds.starts_at AND ds.ends_at
             LIMIT 1",
            [$driverId, $runId]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$shift) {
            return 0;
        }

        return match ($shift['compensation_policy']) {
            'FIXED_SHIFT' => (int)$shift['compensation_cents'],
            'FIXED_ROUTE' => (int)$shift['compensation_cents'],
            default => (int)$shift['compensation_cents'],
        };
    }

    public function recordRunPayment(string $adminUser, string $driverId, string $runId, string $key): array
    {
        $this->requireAdmin($adminUser);
        Input::text($driverId, 1, 18);
        Input::text($runId, 1, 18);
        Input::text($key, 16, 100);

        return (new Transaction($this->db))->run(function () use ($adminUser, $driverId, $runId, $key) {
            $scope = 'driver:' . $this->org() . ':pay-run';
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$scope . ':' . $key]);

            // Check run is completed
            $run = $this->q("SELECT * FROM route_runs WHERE id=? AND driver_id=?", [$runId, $driverId])->fetch(PDO::FETCH_ASSOC);
            if (!$run) {
                throw new Failure(404, 'RUN_NOT_FOUND', 'Run not found for this driver.');
            }
            if ($run['state'] !== 'COMPLETED') {
                throw new Failure(409, 'RUN_NOT_COMPLETED', 'Run must be completed before payment. Current state: ' . $run['state']);
            }

            // Check not already paid
            $existing = $this->q("SELECT id FROM driver_pay_entries WHERE driver_id=? AND run_id=?", [$driverId, $runId])->fetchColumn();
            if ($existing) {
                throw new Failure(409, 'ALREADY_PAID', 'This run has already been compensated.');
            }

            $amount = $this->calculateRunCompensation($driverId, $runId);
            if ($amount <= 0) {
                throw new Failure(409, 'NO_COMPENSATION', 'No compensation configured for this run.');
            }

            $entryId = $this->insert(
                "INSERT INTO driver_pay_entries(driver_id,run_id,policy_version,amount_cents,kind,operation_uuid) VALUES (?,?,1,?,'SHIFT_PAY',?)",
                [$driverId, $runId, $amount, Secrets::uuid()]
            );

            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'DRIVER_PAY_RECORDED','driver_pay_entry',?)", [$adminUser, $entryId]);

            return [
                'pay_entry_id' => $entryId,
                'driver_id' => $driverId,
                'run_id' => $runId,
                'amount_cents' => $amount,
                'kind' => 'SHIFT_PAY',
            ];
        });
    }

    // ── Admin: List pending drivers ──────────────────────────────────

    public function listPending(string $adminUser): array
    {
        $this->requireAdmin($adminUser);

        $rows = $this->q(
            "SELECT d.id, d.user_id, d.engagement_type, d.status, d.phone, d.email, d.applied_at,
                    u.display_name
             FROM drivers d
             JOIN users u ON u.id=d.user_id
             WHERE d.status='PENDING'
             ORDER BY d.applied_at DESC",
            []
        )->fetchAll(PDO::FETCH_ASSOC);

        return ['items' => array_map(fn($r) => [
            'driver_id' => (string)$r['id'],
            'user_id' => $r['user_id'],
            'name' => $r['display_name'],
            'engagement_type' => $r['engagement_type'],
            'status' => $r['status'],
            'email' => $r['email'],
            'phone' => $r['phone'],
            'applied_at' => $r['applied_at'],
        ], $rows)];
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function requireDriver(string $user): void
    {
        $this->identity->requireRole($user, 'DRIVER');
    }

    private function requireAdmin(string $user): void
    {
        $this->identity->requireRole($user, 'ADMIN');
    }

    private function driverId(string $userId): string
    {
        $id = $this->q('SELECT id FROM drivers WHERE user_id=?', [$userId])->fetchColumn();
        if (!$id) {
            throw new Failure(404, 'DRIVER_NOT_FOUND', 'No driver profile found.');
        }
        return (string)$id;
    }

    private function insert(string $sql, array $values): string
    {
        $q = $this->db->prepare($sql . ' RETURNING id');
        $q->execute($values);
        return (string)$q->fetchColumn();
    }
}
