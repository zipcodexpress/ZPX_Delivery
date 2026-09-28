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

    public static function handle(Request $request, string $page, string $resource=''): Response
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
            $data = self::pageData($page, $db, (string)$session['user_id'], $request, $resource);
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
            $result = match ($operation) {
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
                'partner-create' => (new PartnerRegistry($db,$crypto))->create($user,[
                    'code'=>$request->post('code',''),'display_name'=>$request->post('display_name',''),
                    'legal_name'=>$request->post('legal_name',''),'roles'=>$request->post('roles',[]),
                    'reason'=>$request->post('reason',''),
                ],$key),
                'site-create' => (new SiteInventory($db,$crypto))->create($user,[
                    'code'=>$request->post('code',''),'name'=>$request->post('name',''),
                    'site_type'=>$request->post('site_type',''), 'address'=>self::addressInput($request),
                    'timezone'=>$request->post('timezone',''), 'reason'=>$request->post('reason',''),
                ],$key),
                'site-update' => (new SiteInventory($db,$crypto))->updateDraft($user,$id,[
                    'name'=>$request->post('name',''),'address'=>self::addressInput($request),
                    'timezone'=>$request->post('timezone',''),'version'=>$request->post('version',''),
                    'reason'=>$request->post('reason',''),
                ]),
                'site-relationship' => (new SiteInventory($db,$crypto))->setRelationship($user,$id,[
                    'owner_partner_id'=>$request->post('owner_partner_id',''),
                    'host_partner_id'=>$request->post('host_partner_id',''),
                    'contract_reference'=>$request->post('contract_reference',''),
                    'starts_on'=>$request->post('starts_on',''),'ends_on'=>$request->post('ends_on',''),
                    'version'=>$request->post('version',''),'reason'=>$request->post('reason',''),
                ]),
                'site-contact-add' => (new SiteInventory($db,$crypto))->addContact($user,$id,[
                    'name'=>$request->post('name',''),'role_title'=>$request->post('role_title',''),
                    'email'=>$request->post('email',''),'phone'=>$request->post('phone',''),
                    'role_code'=>$request->post('role_code',''),'is_primary'=>$request->post('is_primary','0'),
                    'reason'=>$request->post('reason',''),
                ]),
                'site-contact-archive' => (new SiteInventory($db,$crypto))->archiveContact($user,$id,
                    (string)$request->post('assignment_id',''),(string)$request->post('reason','')),
                'site-overdue' => (new SiteInventory($db,$crypto))->setOverdueDraft($user,$id,[
                    'grace_days'=>$request->post('grace_days',''),'daily_cents'=>$request->post('daily_cents',''),
                    'cap_cents'=>$request->post('cap_cents',''),'version'=>$request->post('version',''),
                    'reason'=>$request->post('reason',''),
                ]),
                'site-location-create' => (new SiteInventory($db,$crypto))->addLocation($user,$id,[
                    'code'=>$request->post('code',''),'name'=>$request->post('name',''),
                    'address_text'=>$request->post('address_text',''),'reason'=>$request->post('reason',''),
                ],$key),
                'location-overdue' => (new SiteInventory($db,$crypto))->setLocationOverdueDraft($user,$id,
                    (string)$request->post('location_id',''),[
                        'grace_days'=>$request->post('grace_days',''),'daily_cents'=>$request->post('daily_cents',''),
                        'cap_cents'=>$request->post('cap_cents',''),'reason'=>$request->post('reason',''),
                    ]),
                'location-deactivate' => (new SiteInventory($db,$crypto))->deactivateLocation($user,$id,
                    (string)$request->post('location_id',''),(string)$request->post('reason','')),
                'location-reactivate' => (new SiteInventory($db,$crypto))->reactivateLocation($user,$id,
                    (string)$request->post('location_id',''),(string)$request->post('reason','')),
                'locker-body-add' => (new SiteInventory($db,$crypto))->addBody($user,$id,[
                    'code'=>$request->post('code',''),'position'=>$request->post('position',''),
                    'reason'=>$request->post('reason',''),
                ]),
                'locker-module-add' => (new SiteInventory($db,$crypto))->addBoxModule($user,$id,[
                    'body_id'=>$request->post('body_id',''),'code'=>$request->post('code',''),
                    'position'=>$request->post('position',''),'reason'=>$request->post('reason',''),
                ]),
                'locker-box-add' => (new SiteInventory($db,$crypto))->addDraftBox($user,$id,[
                    'code'=>$request->post('code',''),'module_id'=>$request->post('module_id',''),
                    'row'=>$request->post('row',''),'column'=>$request->post('column',''),
                    'width_mm'=>$request->post('width_mm',''),'height_mm'=>$request->post('height_mm',''),
                    'depth_mm'=>$request->post('depth_mm',''),'max_weight_g'=>$request->post('max_weight_g',''),
                    'reason'=>$request->post('reason',''),
                ]),
                'locker-box-assign' => (new SiteInventory($db,$crypto))->assignBox($user,$id,[
                    'box_id'=>$request->post('box_id',''),'module_id'=>$request->post('module_id',''),
                    'reason'=>$request->post('reason',''),
                ]),
                'customer-rename' => (new PeopleEditor($db,$crypto))->rename($user,$id,(string)$request->post('name',''),(string)$request->post('reason','')),
                'address-save' => (new PeopleEditor($db,$crypto))->save($user,$id,[
                    'address_id'=>$request->post('address_id',''),'kind'=>$request->post('kind',''),
                    'label'=>$request->post('label',''),'address'=>self::addressInput($request),
                    'reason'=>$request->post('reason',''),
                ]),
                'address-archive' => (new PeopleEditor($db,$crypto))->archive($user,$id,
                    (string)$request->post('address_id',''),(string)$request->post('reason','')),
            };
            if ($operation==='site-create') { return self::redirect('/admin/sites/'.$result); }
            if (in_array($operation,['site-update','site-relationship','site-contact-add','site-contact-archive','site-overdue','site-location-create','location-overdue','location-deactivate','location-reactivate'],true)) { return self::redirect('/admin/sites/'.$id); }
            if (str_starts_with($operation,'locker-')) { return self::redirect('/admin/lockers/'.$id); }
            if (in_array($operation,['customer-rename','address-save','address-archive'],true)) { return self::redirect('/admin/customers/'.$id); }
            $page = match ($operation) {
                'driver-approve','driver-reject','driver-suspend','driver-reactivate'=>'drivers',
                'route-assign'=>'pickup-routes',
                'customer-restrict','customer-revoke'=>'customers',
                'partner-create'=>'partners',
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
                    'partner-create'=>'/admin/partners',
                    'site-create','site-update','site-relationship','site-contact-add','site-contact-archive','site-location-create','site-overdue','location-overdue','location-deactivate','location-reactivate'=>'/admin/sites',
                    'locker-body-add','locker-module-add','locker-box-add','locker-box-assign'=>'/admin/lockers/'.$id,
                    'customer-rename','address-save','address-archive'=>'/admin/customers',
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

    private static function addressInput(Request $request): array
    {
        return ['line1'=>$request->post('line1',''),'line2'=>$request->post('line2',''),
            'city'=>$request->post('city',''),'region'=>$request->post('region',''),
            'postal_code'=>$request->post('postal_code',''),'country_code'=>$request->post('country_code','US')];
    }

    private static function pageData(string $page, \PDO $db, string $user, Request $request, string $resource): array
    {
        $crypto = new Secrets();
        $data = match ($page) {
            'customers'=>(new CustomerManagement($db,$crypto))->list($user,(string)$request->get('cursor','')),
            'customer-detail'=>(new CustomerManagement($db,$crypto))->detail($user,$resource)
                + ['addresses'=>(new PeopleEditor($db,$crypto))->addresses($user,$resource)],
            'customer-history'=>(new History($db,$crypto))->page($user,'customer',$resource,
                (string)$request->get('kind','shipments'),(string)$request->get('cursor','')),
            'drivers'=>(new DriverService($db,$crypto))->listPending($user)
                + ['all'=>(new DriverAdministration($db,$crypto))->list($user,(string)$request->get('cursor',''))],
            'driver-detail'=>(new DriverAdministration($db,$crypto))->detail($user,$resource),
            'driver-history'=>(new History($db,$crypto))->page($user,'driver',$resource,
                (string)$request->get('kind','runs'),(string)$request->get('cursor','')),
            'partners'=>(new PartnerRegistry($db,$crypto))->list($user,(string)$request->get('cursor','')),
            'partner-detail'=>(new PartnerRegistry($db,$crypto))->detail($user,$resource),
            'sites'=>(new SiteInventory($db,$crypto))->sites($user,(string)$request->get('cursor','')),
            'site-detail'=>(new SiteInventory($db,$crypto))->site($user,$resource),
            'lockers'=>(new SiteInventory($db,$crypto))->lockers($user,(string)$request->get('cursor','')),
            'locker-detail'=>(new SiteInventory($db,$crypto))->locker($user,$resource,(string)$request->get('cursor','')),
            'pickup-routes'=>(new PickupRouting($db,$crypto))->list($user),
            'pickup-recovery'=>(new PickupRecovery($db,$crypto))->list($user),
            'shipments'=>(new ShippingService($db,$crypto))->list($user,'operations',
                (string)$request->get('cursor','')),
            'shipment-detail'=>(new ShipmentOverview($db,$crypto))->detail($user,$resource),
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
        if ($page==='partners') { $data['create_key']=Secrets::uuid(); }
        if ($page==='site-detail') { $data['create_key']=Secrets::uuid(); }
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
            'customers'=>'Customers', 'customer-detail'=>'Customer detail',
            'customer-history'=>'Customer history',
            'drivers'=>'Drivers', 'driver-detail'=>'Driver detail', 'driver-history'=>'Driver history', 'pickup-routes'=>'Pickup routes',
            'partners'=>'Partners', 'partner-detail'=>'Partner detail',
            'sites'=>'Sites', 'site-detail'=>'Site detail', 'locker-detail'=>'Locker inventory',
            'pickup-recovery'=>'Pickup recovery', 'shipments'=>'Shipments', 'shipment-detail'=>'Shipment lifecycle',
            'lockers'=>'Lockers',
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
