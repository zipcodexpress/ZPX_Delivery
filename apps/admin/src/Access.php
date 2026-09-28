<?php
declare(strict_types=1);
namespace ZpxAdmin;

use PDO;
use Zpx\Identity\Failure;

/** Capability hints for the current admin release. Every action still checks its own role. */
final class Access
{
    public function __construct(private PDO $db) {}

    public function forUser(string $user): array
    {
        $org = getenv('ZPX_ORGANIZATION_ID') ?: '';
        if (!ctype_digit($org) || $org === '0') {
            throw new Failure(503, 'AUTH_NOT_CONFIGURED', 'Identity service is not configured.');
        }
        $query = $this->db->prepare("SELECT r.code, g.location_id FROM scoped_role_grants g
            JOIN roles r ON r.id=g.role_id JOIN users u ON u.id=g.user_id
            WHERE g.user_id=? AND g.organization_id=? AND u.organization_id=? AND u.status='ACTIVE'
            AND (g.expires_at IS NULL OR g.expires_at>now()) ORDER BY g.location_id NULLS FIRST, r.code");
        $query->execute([$user, $org, $org]);
        $grants = $query->fetchAll(PDO::FETCH_ASSOC);
        $policyVersion = 1 + (int)sprintf('%u', crc32(json_encode($grants, JSON_THROW_ON_ERROR)));
        $networkAdmin = false;
        $scopes = [];
        foreach ($grants as $grant) {
            if ($grant['code'] === 'ADMIN' && $grant['location_id'] === null) {
                $networkAdmin = true;
            }
            if ($grant['location_id'] !== null && in_array($grant['code'], ['ADMIN', 'HUB_STAFF'], true)) {
                $id = (string)$grant['location_id'];
                $scopes['LOCATION:'.$id] = ['kind'=>'LOCATION', 'id'=>$id];
            }
        }
        if ($networkAdmin) {
            return ['capabilities'=>['admin.access','drivers.review','pickup_routes.manage','pickup_recovery.manage','shipments.read'],
                'scopes'=>[['kind'=>'NETWORK','id'=>$org]], 'policy_version'=>$policyVersion];
        }
        return ['capabilities'=>[], 'scopes'=>array_values($scopes), 'policy_version'=>$policyVersion];
    }

    public function requireNetworkAdmin(string $user): array
    {
        $access = $this->forUser($user);
        if (!in_array('admin.access', $access['capabilities'], true)) {
            throw new Failure(403, 'ACCESS_DENIED', 'Access denied.');
        }
        return $access;
    }
}
