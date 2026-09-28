<?php
declare(strict_types=1);
namespace ZpxAdmin;

use think\facade\View;
use think\Request;
use think\Response;
use Zpx\Http\SessionCookies;
use Zpx\Identity\{Failure, Secrets, Service};
use Zpx\Infrastructure\Database\Connection;
use Zpx\Driver\Service as DriverService;
use Zpx\Custody\{PickupRouting, PickupRecovery};
use Zpx\Shipping\Service as ShippingService;

final class PageController
{
    public static function stylesheet(): Response
    {
        return Response::create((string)file_get_contents(dirname(__DIR__).'/public/admin.css'), 'html', 200)
            ->header(['Content-Type'=>'text/css; charset=utf-8','X-Content-Type-Options'=>'nosniff','Cache-Control'=>'public, max-age=3600']);
    }

    public static function handle(Request $request, string $page): Response
    {
        $method = $request->method(true);
        if ($page === 'login') {
            if ($method === 'GET') { return self::loginPage($request); }
            if ($method === 'POST') { return self::login($request); }
            return self::plain(405, 'Method not allowed');
        }
        if ($page === 'logout' && $method !== 'POST') { return self::plain(405, 'Method not allowed'); }
        if ($page !== 'logout' && $method !== 'GET') { return self::plain(405, 'Method not allowed'); }
        $token = $request->cookie('zpx_delivery_session', '');
        if (!is_string($token) || $token === '') { return self::redirect('/admin/login'); }
        $profile = [];
        try {
            $db = Connection::fromEnvironment();
            $identity = new Service($db, new Secrets());
            $session = $identity->authenticate($token, 'BROWSER');
            if ($page === 'logout') {
                self::checkOrigin($request);
                $identity->csrf($token, (string)$request->post('_csrf', ''));
                $identity->logout($session, Secrets::uuid());
                $response = self::redirect('/admin/login');
                $response->header(['Set-Cookie'=>SessionCookies::value('', 0, '/')]);
                return SessionCookies::clearLegacy($response);
            }
            $profile = $identity->profile((string)$session['user_id']);
            $access = (new Access($db))->requireNetworkAdmin((string)$session['user_id']);
            $data = self::pageData($page, $db, (string)$session['user_id'], $request);
            $html = View::fetch('dashboard', [
                'title'=>self::title($page), 'page'=>$page, 'name'=>$profile['name'],
                'csrf'=>(new Secrets())->digest('csrf', $token),
                'network'=>$access['scopes'][0]['id'],
                'operationsUrl'=>getenv('ADMIN_OPERATIONS_URL') ?: 'http://localhost:5174/',
                'data'=>$data,
            ]);
            return self::html($html);
        } catch (Failure $error) {
            if ($error->status === 401) { return self::redirect('/admin/login'); }
            if ($error->status === 403) {
                $customer=in_array('CUSTOMER', $profile['roles'] ?? [], true);
                return self::html(View::fetch('no_access', [
                    'appUrl'=>$customer ? (getenv('ADMIN_CUSTOMER_URL') ?: 'http://localhost:5173/')
                        : (getenv('ADMIN_OPERATIONS_URL') ?: 'http://localhost:5174/'),
                    'appName'=>$customer ? 'customer portal' : 'operations portal',
                    'csrf'=>(new Secrets())->digest('csrf', $token),
                ]), 403);
            }
            return self::plain($error->status, 'Admin service is unavailable.');
        }
    }

    public static function action(Request $request, string $operation): Response
    {
        if ($request->method(true) !== 'POST') { return self::plain(405, 'Method not allowed'); }
        try {
            self::checkOrigin($request);
            $token = $request->cookie('zpx_delivery_session', '');
            if (!is_string($token) || $token === '') { return self::redirect('/admin/login'); }
            $db = Connection::fromEnvironment();
            $crypto = new Secrets();
            $identity = new Service($db, $crypto);
            $session = $identity->authenticate($token, 'BROWSER');
            $user = (string)$session['user_id'];
            (new Access($db))->requireNetworkAdmin($user);
            $identity->csrf($token, (string)$request->post('_csrf', ''));
            $identity->limit('admin-form:'.$user, 50);
            $key = (string)$request->post('idempotency_key', '');
            $id = (string)$request->post('id', '');
            match ($operation) {
                'driver-approve' => (new DriverService($db,$crypto))->approve($user,$id,$key),
                'driver-reject' => (new DriverService($db,$crypto))->reject($user,$id,['reason'=>(string)$request->post('reason','')],$key),
                'route-assign' => (new PickupRouting($db,$crypto))->assign($user, self::routeInput($request,$id),$key),
                'pickup-release' => (new PickupRecovery($db,$crypto))->release($user,$id,
                    ['reason'=>(string)$request->post('reason',''),'expected_revision'=>$request->post('revision',-1)],$key),
                'customer-restrict' => (new CustomerManagement($db,$crypto))->restrict($user,$id,$request->post('reason',''),$key),
                'customer-revoke' => (new CustomerManagement($db,$crypto))->revoke($user,$id,(string)$request->post('restriction_id',''),$request->post('reason',''),$key),
                'driver-suspend', 'driver-reactivate' => (new DriverAdministration($db,$crypto))->transition($user,$id,[
                    'action'=>$operation==='driver-suspend'?'SUSPEND':'REACTIVATE',
                    'reason'=>$request->post('reason',''), 'expected_version'=>$request->post('version',-1),
                ],$key),
            };
            $page = match ($operation) {
                'driver-approve','driver-reject','driver-suspend','driver-reactivate'=>'drivers',
                'route-assign'=>'pickup-routes',
                'customer-restrict','customer-revoke'=>'customers',
                default=>'pickup-recovery',
            };
            return self::redirect('/admin/'.$page);
        } catch (Failure $error) {
            if ($error->status === 401) { return self::redirect('/admin/login'); }
            if (in_array($error->status, [409, 412, 422], true)) {
                $back = match ($operation) {
                    'driver-approve','driver-reject','driver-suspend','driver-reactivate'=>'/admin/drivers',
                    'route-assign'=>'/admin/pickup-routes',
                    'customer-restrict','customer-revoke'=>'/admin/customers',
                    default=>'/admin/pickup-recovery',
                };
                return self::html(View::fetch('action_error', [
                    'message'=>$error->getMessage(), 'status'=>$error->status,
                    'back'=>$back, 'reason'=>(string)$request->post('reason',''),
                    'hub'=>(string)$request->post('hub_id',''),
                    'latitude'=>(string)$request->post('latitude',''),
                    'longitude'=>(string)$request->post('longitude',''),
                ]), $error->status);
            }
            return self::plain($error->status, $error->getMessage());
        }
    }

    private static function routeInput(Request $request, string $id): array
    {
        $input = ['origin_location_id'=>$id, 'hub_id'=>(string)$request->post('hub_id',''),
            'expected_version'=>$request->post('version',-1)];
        $latitude = trim((string)$request->post('latitude',''));
        $longitude = trim((string)$request->post('longitude',''));
        if ($latitude !== '' || $longitude !== '') {
            $input['latitude']=$latitude; $input['longitude']=$longitude;
        }
        return $input;
    }

    private static function pageData(string $page, \PDO $db, string $user, Request $request): array
    {
        $crypto = new Secrets();
        $data = match ($page) {
            'customers'=>(new CustomerManagement($db,$crypto))->list($user,(string)$request->get('cursor','')),
            'drivers'=>(new DriverService($db,$crypto))->listPending($user)
                + ['all'=>(new DriverAdministration($db,$crypto))->list($user,(string)$request->get('cursor',''))],
            'pickup-routes'=>(new PickupRouting($db,$crypto))->list($user),
            'pickup-recovery'=>(new PickupRecovery($db,$crypto))->list($user),
            'shipments'=>(new ShippingService($db,$crypto))->list($user,'operations',
                (string)$request->get('cursor','')),
            default=>[],
        };
        foreach (['items','origins'] as $collection) {
            if (!isset($data[$collection])) { continue; }
            foreach ($data[$collection] as &$row) { $row['form_key']=Secrets::uuid(); }
            unset($row);
        }
        foreach ($data['all']['items'] ?? [] as $index=>$row) {
            $data['all']['items'][$index]['form_key']=Secrets::uuid();
        }
        return $data;
    }

    private static function loginPage(Request $request, string $error = ''): Response
    {
        $token = bin2hex(random_bytes(32));
        $html = View::fetch('login', ['csrf'=>$token, 'error'=>$error]);
        $response = self::html($html);
        $secure = getenv('APP_ENV') !== 'development' && getenv('APP_ENV') !== 'test' ? '; Secure' : '';
        $response->header(['Set-Cookie'=>'zpx_admin_login_csrf='.$token.'; Path=/admin; Max-Age=600; HttpOnly; SameSite=Strict'.$secure]);
        return $response;
    }

    private static function login(Request $request): Response
    {
        try {
            self::checkOrigin($request);
            $cookie = $request->cookie('zpx_admin_login_csrf', '');
            $submitted = $request->post('_csrf', '');
            if (!is_string($cookie) || !is_string($submitted) || !preg_match('/^[a-f0-9]{64}$/D', $cookie) || !hash_equals($cookie, $submitted)) {
                throw new Failure(403, 'CSRF_REJECTED', 'Reload the sign-in page.');
            }
            $db = Connection::fromEnvironment();
            $identity = new Service($db, new Secrets());
            $identity->limit('ip:'.$request->server('REMOTE_ADDR', 'unknown'), 100);
            $login = $identity->login(['email'=>(string)$request->post('email',''),
                'password'=>(string)$request->post('password',''), 'client_kind'=>'BROWSER']);
            $response = self::redirect('/admin');
            $response->header(['Set-Cookie'=>SessionCookies::value($login['_cookie'], 28800, '/')]);
            return SessionCookies::clearLegacy($response);
        } catch (Failure $error) {
            return self::loginPage($request, $error->status === 401 ? 'Email or password is incorrect.' : $error->getMessage());
        }
    }

    private static function checkOrigin(Request $request): void
    {
        $host = $request->header('host', '');
        $origin = $request->header('origin', '');
        if ($origin === '' || $host === '' || !in_array($origin, ['http://'.$host, 'https://'.$host], true)) {
            throw new Failure(403, 'ORIGIN_REJECTED', 'Request origin is not allowed.');
        }
    }

    private static function title(string $page): string
    {
        return match ($page) {
            'customers'=>'Customers', 'drivers'=>'Drivers', 'pickup-routes'=>'Pickup routes',
            'pickup-recovery'=>'Pickup recovery', 'shipments'=>'Shipments',
            default=>'Operations overview',
        };
    }

    private static function html(string $html, int $status = 200): Response
    {
        return Response::create($html, 'html', $status)->header([
            'Cache-Control'=>'no-store', 'X-Content-Type-Options'=>'nosniff',
            'Content-Security-Policy'=>"default-src 'self'; style-src 'self'; script-src 'none'; form-action 'self'; frame-ancestors 'none'",
        ]);
    }

    private static function plain(int $status, string $message): Response
    {
        return self::html(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $status);
    }

    private static function redirect(string $path): Response
    {
        return Response::create('', 'html', 303)->header(['Location'=>$path, 'Cache-Control'=>'no-store']);
    }
}
