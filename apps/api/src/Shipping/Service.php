<?php
declare(strict_types=1);
namespace Zpx\Shipping;

use PDO;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;
use Zpx\Infrastructure\Messaging\Outbox;

/** Shipment planning never changes physical custody or authorizes a door. */
final class Service
{
    private Identity $identity;
    public function __construct(private PDO $db, private Secrets $crypto) { $this->identity=new Identity($db,$crypto); }
    private function q(string $sql,array $values=[]): \PDOStatement { $q=$this->db->prepare($sql); $q->execute($values); return $q; }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }
    public static function id(mixed $id): string {
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,17}$/D',$id)) { throw new Failure(422,'INVALID_INPUT','Invalid identifier.'); }
        return $id;
    }
    private function customer(string $user): void { $this->identity->requireRole($user,'CUSTOMER'); $this->identity->requireVerified($user); }
    private function local(array $location): bool {
        $policy=json_decode($location['access_policy'] ?? '{}',true);
        return in_array(getenv('APP_ENV'),['development','test'],true) && ($policy['synthetic'] ?? false)===true;
    }
    private function draftEligible(array $row): bool {
        $policy=json_decode($row['access_policy'] ?? '{}',true);
        return $row['kind']==='LOCKER' && $row['status']==='ACTIVE' && $row['site_mode']!=='LEGACY_ONLY'
            && ($this->local($row) || ($policy['public_shipping_enabled'] ?? false)===true);
    }
    public function locations(): array {
        $rows=$this->q("SELECT * FROM locations WHERE organization_id=? AND kind='LOCKER' AND status='ACTIVE' ORDER BY code",[$this->org()])->fetchAll(PDO::FETCH_ASSOC);
        return ['items'=>array_values(array_map(function ($r) {
            $policy=json_decode($r['access_policy'] ?? '{}',true);
            $position=null;
            if ($r['latitude']!==null && $r['longitude']!==null) { $position=['latitude'=>(float)$r['latitude'],'longitude'=>(float)$r['longitude'],'illustrative'=>$this->local($r)]; }
            elseif ($this->local($r)) { $n=(int)substr($r['code'],-3)-1;$position=['latitude'=>30.2672+0.035*sin($n*2.4)*(1+$n/20),'longitude'=>-97.7431+0.045*cos($n*2.4)*(1+$n/20),'illustrative'=>true]; }
            return ['map_position'=>$position,'id'=>(string)$r['id'],'code'=>$r['code'],'name'=>$r['name'],'site_mode'=>$r['site_mode'],
                'address'=>['line1'=>$r['address_text'],'city'=>$this->local($r)?'Synthetic fixture':'','region'=>'','postal_code'=>'','country_code'=>'US'],
                'eligible'=>false,
                'draft_eligible'=>true,'development_only'=>$this->local($r),'printer_available'=>false,
                'access_instructions'=>$this->local($r)?'Test shipment planning only. No physical drop-off.':'Public shipping planning. Confirm availability before drop-off.'];
        },array_filter($rows,fn($r)=>$this->draftEligible($r)))),'next_cursor'=>null];
    }
    private function canonical(mixed $v): mixed {
        if (!is_array($v)) { return $v; }
        if (!array_is_list($v)) { ksort($v); }
        return array_map(fn($item)=>$this->canonical($item),$v);
    }
    private function once(string $user,string $action,string $key,array $payload,callable $authorized,callable $operation): array {
        Input::text($key,16,100);
        $result=(new Transaction($this->db))->run(function () use ($user,$action,$key,$payload,$authorized,$operation) {
            $scope='shipping:'.$this->org().':'.$user.':'.$action;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            // Recheck active actor and scope on every retry, before returning its saved response.
            if (!$this->q("SELECT id FROM users WHERE id=? AND organization_id=? AND status='ACTIVE'",[$user,$this->org()])->fetchColumn()) { throw new Failure(403,'ACCESS_DENIED','Access denied.'); }
            $authorized();
            $hash=$this->crypto->digest('shipping-request',json_encode($this->canonical($payload),JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','This request key was already used for different details.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
            }
            $result=$operation();
            if (!$result instanceof Failure) {
                $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",[$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            }
            return $result;
        });
        if ($result instanceof Failure) { throw $result; } // attempt failures must commit
        return $result;
    }
    private function event(string $user,array $row,string $type): void {
        $this->q("INSERT INTO package_events(package_id,event_uuid,event_type,actor_user_id,details,occurred_at) VALUES (?,?,?,?, '{}',now())",[$row['package_id'],Secrets::uuid(),$type,$user]);
        $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,?,'shipment',?)",[$user,$type,$row['id']]);
        (new Outbox($this->db))->append(Secrets::uuid(),'shipment',(string)$row['id'],'shipping.'.strtolower($type),['shipment_id'=>(string)$row['id']]);
    }
    private function select(): string {
        return 'SELECT s.*,p.id AS package_id,p.package_uuid,p.version AS package_version,p.state AS package_state,p.width_mm,p.height_mm,p.depth_mm,p.weight_g,p.current_location_id,p.custodian_type,p.custodian_ref,si.si,o.name AS origin_name,d.name AS destination_name,cl.name AS current_location_name FROM shipments s JOIN packages p ON p.shipment_id=s.id AND p.sequence_no=1 JOIN locations o ON o.id=s.origin_location_id JOIN locations d ON d.id=s.destination_location_id LEFT JOIN locations cl ON cl.id=p.current_location_id LEFT JOIN shipping_identifiers si ON si.package_id=p.id';
    }
    private function operationsWhere(string $user,array &$values): string {
        $values[]=$user;
        return "EXISTS (SELECT 1 FROM scoped_role_grants g JOIN roles r ON r.id=g.role_id WHERE g.user_id=? AND g.organization_id=s.organization_id AND (g.expires_at IS NULL OR g.expires_at>now()) AND ((r.code IN ('ADMIN','DISPATCHER') AND (g.location_id IS NULL OR g.location_id IN (s.origin_location_id,s.destination_location_id,p.current_location_id))) OR (r.code IN ('HUB_STAFF','HUB_SUPERVISOR') AND p.custodian_type='HUB' AND g.location_id=p.current_location_id AND EXISTS (SELECT 1 FROM hubs h JOIN hub_staff hs ON hs.hub_id=h.id WHERE h.location_id=g.location_id AND p.custodian_ref=h.id::text AND hs.user_id=g.user_id AND h.status='ACTIVE'))))";
    }
    private function row(string $user,string $id,string $view='customer',bool $lock=false): array {
        self::id($id); $values=[$id,$this->org()];
        if ($view==='operations') { $where=$this->operationsWhere($user,$values); }
        else { $values[]=$user; $values[]=$user; $where="(s.sender_user_id=? OR EXISTS (SELECT 1 FROM shipment_parties sp WHERE sp.shipment_id=s.id AND sp.party_role='RECIPIENT' AND sp.user_id=?))"; }
        $row=$this->q($this->select().' WHERE s.id=? AND s.organization_id=? AND '.$where.($lock?' FOR UPDATE OF s,p':''),$values)->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new Failure(404,'SHIPMENT_NOT_FOUND','Shipment not found.'); }
        return $row;
    }
    private function sender(string $user,string $id,bool $lock=false): array {
        $this->customer($user); $row=$this->row($user,$id,'customer',$lock);
        if ($row['sender_user_id']!==$user) { throw new Failure(403,'SENDER_REQUIRED','Only the sender can change this shipment.'); }
        return $row;
    }
    private function present(array $r,string $user,string $view='customer'): array {
        return ['shipment_id'=>(string)$r['id'],'public_reference'=>$r['public_reference'],'package_id'=>(string)$r['package_id'],
            'origin_location_id'=>(string)$r['origin_location_id'],'destination_location_id'=>(string)$r['destination_location_id'],
            'origin_name'=>$r['origin_name'],'destination_name'=>$r['destination_name'],'order_status'=>$r['order_status'],'payment_status'=>$r['payment_status'],
            'package_state'=>$r['package_state'],'version'=>(int)$r['version'],'service_level'=>$r['service_level'],
            'relationship'=>$view==='operations'?'OPERATIONS':($r['sender_user_id']===$user?'SENDER':'RECIPIENT'),
            'package'=>array_map('intval',array_intersect_key($r,array_flip(['width_mm','height_mm','depth_mm','weight_g']))),
            'development_only'=>(bool)$r['development_only'],'created_at'=>gmdate('c',strtotime($r['created_at']))];
    }
    public function list(string $user,string $view,string $cursor=''): array {
        if (!in_array($view,['sending','receiving','history','operations'],true)) { throw new Failure(422,'INVALID_INPUT','Invalid shipment view.'); }
        $values=[$this->org()];
        if ($view==='operations') { $where=$this->operationsWhere($user,$values); }
        elseif ($view==='history') { $values[]=$user;$values[]=$user;$where="(s.sender_user_id=? OR EXISTS (SELECT 1 FROM shipment_parties sp WHERE sp.shipment_id=s.id AND sp.party_role='RECIPIENT' AND sp.user_id=?))"; }
        elseif ($view==='sending') { $values[]=$user; $where='s.sender_user_id=?'; }
        else { $values[]=$user; $where="EXISTS (SELECT 1 FROM shipment_parties sp WHERE sp.shipment_id=s.id AND sp.party_role='RECIPIENT' AND sp.user_id=?)"; }
        if ($cursor!=='') { self::id($cursor); $where.=' AND s.id<?'; $values[]=$cursor; }
        $rows=$this->q($this->select().' WHERE s.organization_id=? AND '.$where.' ORDER BY s.id DESC LIMIT 26',$values)->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>25; $rows=array_slice($rows,0,25);
        return ['items'=>array_map(fn($r)=>$this->present($r,$user,$view),$rows),'next_cursor'=>$more?(string)end($rows)['id']:null];
    }
    public function get(string $user,string $id,string $view='customer'): array { return $this->present($this->row($user,$id,$view),$user,$view); }
    public function searchPackages(string $user,string $query): array {
        $query=trim(Input::text($query,1,100));
        $values=[$this->org()];
        $where=$this->operationsWhere($user,$values);
        $values[]=$query; $values[]=$query; $values[]=$query;
        $identifier="(s.public_reference=? OR p.package_uuid::text=? OR si.si=?";
        if (preg_match('/^[1-9][0-9]{0,17}$/D',$query)) { $identifier.=' OR p.id=?'; $values[]=$query; }
        $identifier.=')';
        $rows=$this->q($this->select().' WHERE s.organization_id=? AND '.$where.' AND '.$identifier.' ORDER BY s.id DESC LIMIT 25',$values)->fetchAll(PDO::FETCH_ASSOC);
        return ['items'=>array_map(fn($r)=>$this->present($r,$user,'operations'),$rows),'next_cursor'=>null];
    }
    public function lookup(string $user,string $reference): array {
        $id=$this->q('SELECT id FROM shipments WHERE organization_id=? AND public_reference=?',[$this->org(),Input::text(trim($reference),1,64)])->fetchColumn();
        if (!$id) { throw new Failure(404,'SHIPMENT_NOT_FOUND','No shipment available for this account and reference.'); }
        return $this->get($user,(string)$id);
    }
    public function create(string $user,array $input,string $key): array {
        Input::fields($input,['origin_location_id','destination_location_id','recipient','package','service_level']);
        $origin=self::id($input['origin_location_id']); $destination=self::id($input['destination_location_id']);
        if ($origin===$destination || $input['service_level']!=='STANDARD') { throw new Failure(422,'INVALID_ROUTE','Choose different origin and destination lockers and standard service.'); }
        if (!is_array($input['recipient']) || !is_array($input['package'])) { throw new Failure(422,'INVALID_INPUT','Recipient and parcel details are required.'); }
        Input::fields($input['recipient'],['name','email','phone']);
        $recipient=['name'=>trim(Input::text($input['recipient']['name'],1,160)),'email'=>Input::contact('EMAIL',$input['recipient']['email']),'phone'=>Input::contact('PHONE',$input['recipient']['phone'])];
        if ($recipient['name']==='') { throw new Failure(422,'INVALID_INPUT','Recipient name is required.'); }
        Input::fields($input['package'],['width_mm','height_mm','depth_mm','weight_g']);
        foreach ($input['package'] as $v) { if (!is_int($v) || $v<1 || $v>100000) { throw new Failure(422,'INVALID_PACKAGE','Use positive whole-number parcel measurements.'); } }
        return $this->once($user,'create',$key,$input,fn()=>$this->customer($user),function () use ($user,$input,$origin,$destination,$recipient) {
            $allLocal=true;
            foreach ([$origin,$destination] as $location) {
                $r=$this->q('SELECT * FROM locations WHERE id=? AND organization_id=? FOR SHARE',[$location,$this->org()])->fetch(PDO::FETCH_ASSOC);
                if (!$r || !$this->draftEligible($r)) { throw new Failure(422,'LOCATION_UNAVAILABLE','One of the selected locations is unavailable for shipping.'); }
                $allLocal=$allLocal && $this->local($r);
                // Planning fit only: this neither reserves nor unfreezes a compartment.
                $p=$input['package'];
                if (!$this->q('SELECT c.id FROM compartments c JOIN lockers l ON l.id=c.locker_id WHERE l.location_id=? AND c.width_mm>=? AND c.height_mm>=? AND c.depth_mm>=? AND c.max_weight_g>=? LIMIT 1',[$location,$p['width_mm'],$p['height_mm'],$p['depth_mm'],$p['weight_g']])->fetchColumn()) { throw new Failure(422,'PACKAGE_TOO_LARGE','The parcel does not fit the declared compartment sizes at both sites.'); }
            }
            $id=(string)$this->q("INSERT INTO shipments(organization_id,sender_user_id,public_reference,origin_location_id,destination_location_id,service_level,order_status,payment_status) VALUES (?,?,?,?,?,'STANDARD','DRAFT','UNPAID') RETURNING id",[$this->org(),$user,'ZPX-ORDER-'.strtoupper(bin2hex(random_bytes(12))),$origin,$destination])->fetchColumn();
            $this->q('UPDATE shipments SET development_only=? WHERE id=?',[$allLocal?'true':'false',$id]);
            $p=$input['package'];
            $this->q("INSERT INTO packages(shipment_id,package_uuid,sequence_no,width_mm,height_mm,depth_mm,weight_g,state,custodian_type,custodian_ref) VALUES (?,?,1,?,?,?,?,'CREATED','SENDER',?)",[$id,Secrets::uuid(),$p['width_mm'],$p['height_mm'],$p['depth_mm'],$p['weight_g'],$user]);
            $this->q("INSERT INTO shipment_parties(shipment_id,party_role,contact_encrypted,email_lookup,phone_lookup) VALUES (?,'RECIPIENT',?,decode(?,'hex'),decode(?,'hex'))",[$id,$this->crypto->encrypt(json_encode($recipient,JSON_THROW_ON_ERROR)),$this->crypto->digest('contact:EMAIL',$recipient['email']),$this->crypto->digest('contact:PHONE',$recipient['phone'])]);
            $row=$this->row($user,$id); $this->event($user,$row,'SHIPMENT_CREATED');
            return $this->present($row,$user);
        });
    }
    private function version(array $row,string $match): void {
        if ($match!=='"'.$row['version'].'"') { throw new Failure(409,'VERSION_CONFLICT','This shipment changed. Refresh and try again.'); }
    }
    public function quote(string $user,string $id,array $input,string $key,string $match): array {
        Input::fields($input,['service_level']);
        return $this->once($user,'quote:'.$id,$key,[$input,$match],fn()=>$this->sender($user,$id),function () use ($user,$id,$input,$match) {
            $row=$this->sender($user,$id,true); $this->version($row,$match);
            if ($row['order_status']!=='DRAFT' || $input['service_level']!==$row['service_level']) { throw new Failure(409,'QUOTE_UNAVAILABLE','Only an unchanged draft can be quoted.'); }
            foreach ([$row['origin_location_id'],$row['destination_location_id']] as $location) {
                $r=$this->q('SELECT * FROM locations WHERE id=? AND organization_id=? FOR SHARE',[$location,$this->org()])->fetch(PDO::FETCH_ASSOC);
                if (!$r || !$this->draftEligible($r) || !$this->local($r)) { throw new Failure(503,'RATE_POLICY_UNAVAILABLE','A live rate and service policy has not been configured.'); }
            }
            // Existing synthetic seed drafts acquire the explicit test marker when first quoted.
            $this->q('UPDATE shipments SET development_only=true WHERE id=?',[$id]);
            $amount=500+100*(int)ceil((int)$row['weight_g']/1000);
            $q=$this->q("INSERT INTO pricing_quotes(shipment_id,policy_version,amount_cents,expires_at,shipment_version) VALUES (?,'DEMO-2026-01',?,now()+interval '15 minutes',?) RETURNING id,expires_at",[$id,$amount,$row['version']])->fetch(PDO::FETCH_ASSOC);
            $this->event($user,$row,'QUOTE_CREATED');
            return ['quote_id'=>(string)$q['id'],'amount_cents'=>$amount,'currency'=>'USD','expires_at'=>gmdate('c',strtotime($q['expires_at'])),'policy_version'=>'DEMO-2026-01','development_only'=>true];
        });
    }
    public function cancel(string $user,string $id,array $input,string $key,string $match): array {
        Input::fields($input,['reason']); Input::text($input['reason'],1,500);
        return $this->once($user,'cancel:'.$id,$key,[$input,$match],fn()=>$this->sender($user,$id),function () use ($user,$id,$match) {
            $row=$this->sender($user,$id,true); $this->version($row,$match);
            if ($row['order_status']!=='DRAFT' || !in_array($row['payment_status'],['UNPAID','FAILED'],true) || $row['package_state']!=='CREATED' || $row['custodian_type']!=='SENDER' || $row['custodian_ref']!==$user) { throw new Failure(409,'CANCEL_UNAVAILABLE','Only unpaid drafts still held by the sender can be cancelled here.'); }
            $this->q("UPDATE shipments SET order_status='CANCELLED',version=version+1 WHERE id=?",[$id]);
            $this->event($user,$row,'SHIPMENT_CANCELLED');
            return ['operation_id'=>Secrets::uuid(),'status'=>'COMPLETED'];
        });
    }
    public function tracking(string $user,string $id,string $view): array {
        $row=$this->row($user,$id,$view);
        $events=$this->q('SELECT event_type,occurred_at FROM package_events WHERE package_id=? ORDER BY occurred_at,id',[$row['package_id']])->fetchAll(PDO::FETCH_ASSOC);
        $result=['shipment_id'=>$id,'milestones'=>array_map(fn($e)=>['code'=>$e['event_type'],'occurred_at'=>gmdate('c',strtotime($e['occurred_at']))],$events)];
        if ($view!=='operations') { return $result; }

        $result['package']=[
            'package_id'=>(string)$row['package_id'],'package_uuid'=>$row['package_uuid'],'public_reference'=>$row['public_reference'],
            'si'=>$row['si'],'state'=>$row['package_state'],'version'=>(int)$row['package_version'],
            'origin_name'=>$row['origin_name'],'destination_name'=>$row['destination_name'],
        ];
        $result['current_custody']=[
            'type'=>$row['custodian_type'],'reference'=>$row['custodian_ref'],
            'location_id'=>$row['current_location_id']===null?null:(string)$row['current_location_id'],
            'location_name'=>$row['current_location_name'],
        ];
        $result['events']=$this->operationsTimeline((string)$row['package_id']);
        return $result;
    }
    private function operationsTimeline(string $package): array {
        $timeline=[];
        $append=static function (array &$target,array $row,string $source,string $code,string $occurred,array $details=[]): void {
            $target[]=['source'=>$source,'source_id'=>(string)$row['id'],'code'=>$code,'result'=>$row['result'] ?? null,
                'occurred_at'=>gmdate('c',strtotime($row[$occurred])),'actor'=>$row['actor'] ?? null,
                'location_name'=>$row['location_name'] ?? null,'details'=>$details];
        };
        foreach ($this->q('SELECT pe.id,pe.event_type,pe.occurred_at,u.display_name AS actor FROM package_events pe LEFT JOIN users u ON u.id=pe.actor_user_id WHERE pe.package_id=?',[$package])->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $append($timeline,$e,'PACKAGE_EVENT',$e['event_type'],'occurred_at');
        }
        foreach ($this->q('SELECT ce.id,ce.event_type,ce.previous_custodian_type,ce.previous_custodian_ref,ce.new_custodian_type,ce.new_custodian_ref,ce.package_version,ce.occurred_at,u.display_name AS actor,l.name AS location_name FROM custody_events ce LEFT JOIN users u ON u.id=ce.actor_user_id LEFT JOIN locations l ON l.id=ce.location_id WHERE ce.package_id=?',[$package])->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $append($timeline,$e,'CUSTODY_EVENT',$e['event_type'],'occurred_at',['from'=>$e['previous_custodian_type'].':'.$e['previous_custodian_ref'],'to'=>$e['new_custodian_type'].':'.$e['new_custodian_ref'],'package_version'=>(int)$e['package_version']]);
        }
        foreach ($this->q('SELECT se.id,se.action,se.result_code AS result,se.run_id,se.received_at,u.display_name AS actor FROM scan_events se JOIN users u ON u.id=se.actor_user_id WHERE se.package_id=?',[$package])->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $append($timeline,$e,'SCAN_EVENT',$e['action'],'received_at',['run_id'=>$e['run_id']===null?null:(string)$e['run_id']]);
        }
        foreach ($this->q('SELECT ri.id,ri.disposition,ri.created_at,rs.id AS session_id,l.name AS location_name,u.display_name AS actor FROM receiving_items ri JOIN receiving_sessions rs ON rs.id=ri.session_id JOIN hubs h ON h.id=rs.hub_id JOIN locations l ON l.id=h.location_id JOIN users u ON u.id=rs.receiver_user_id WHERE ri.package_id=?',[$package])->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $append($timeline,$e,'RECEIVING_ITEM','RECEIVING_'.$e['disposition'],'created_at',['session_id'=>(string)$e['session_id']]);
        }
        foreach ($this->q('SELECT sa.id,sa.outbound_run_id,sa.routing_revision,sa.created_at,hs.code AS slot_code,l.name AS location_name,u.display_name AS actor FROM staging_assignments sa JOIN hub_slots hs ON hs.id=sa.slot_id JOIN hubs h ON h.id=hs.hub_id JOIN locations l ON l.id=h.location_id JOIN users u ON u.id=sa.assigned_by WHERE sa.package_id=?',[$package])->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $append($timeline,$e,'STAGING_ASSIGNMENT','STAGED','created_at',['slot_code'=>$e['slot_code'],'outbound_run_id'=>(string)$e['outbound_run_id'],'routing_revision'=>(int)$e['routing_revision']]);
        }
        foreach ($this->q('SELECT dc.id,dc.status,dc.called_at,COALESCE(dc.actual_pickup_at,dc.confirmed_at,dc.called_at) AS occurred_at,l.name AS location_name,u.display_name AS actor,rr.id AS run_id FROM staging_assignments sa JOIN route_runs rr ON rr.id=sa.outbound_run_id JOIN dispatch_calls dc ON dc.id=rr.dispatch_call_id JOIN hubs h ON h.id=dc.hub_id JOIN locations l ON l.id=h.location_id LEFT JOIN drivers d ON d.id=dc.driver_id LEFT JOIN users u ON u.id=d.user_id WHERE sa.package_id=?',[$package])->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $append($timeline,$e,'DISPATCH_CALL','DISPATCH_'.$e['status'],'occurred_at',['run_id'=>(string)$e['run_id'],'called_at'=>gmdate('c',strtotime($e['called_at']))]);
        }
        foreach ($this->q('SELECT e.id,e.code,e.status,e.notes,e.resolution_code,e.created_at,e.resolved_at,reporter.display_name AS actor,resolver.display_name AS resolver,l.name AS location_name FROM exceptions e JOIN users reporter ON reporter.id=e.recorded_by LEFT JOIN users resolver ON resolver.id=e.resolved_by LEFT JOIN hubs h ON h.id=e.hub_id LEFT JOIN locations l ON l.id=h.location_id WHERE e.package_id=?',[$package])->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $append($timeline,$e,'EXCEPTION','EXCEPTION_'.$e['code'],'created_at',['status'=>$e['status'],'notes'=>$e['notes']]);
            if ($e['resolved_at']!==null) {
                $resolved=$e; $resolved['actor']=$e['resolver'];
                $append($timeline,$resolved,'EXCEPTION','EXCEPTION_RESOLVED','resolved_at',['exception_code'=>$e['code'],'resolution_code'=>$e['resolution_code']]);
            }
        }
        usort($timeline,static fn($a,$b)=>[$a['occurred_at'],$a['source'],$a['source_id']]<=>[$b['occurred_at'],$b['source'],$b['source_id']]);
        return $timeline;
    }
    private function localShipment(array $row): void {
        if (!in_array(getenv('APP_ENV'),['development','test'],true) || !$row['development_only']) {
            throw new Failure(503,'PROVIDER_NOT_CONFIGURED','Live payment and label delivery are not configured.');
        }
    }
    public function payment(string $user,string $id,array $input,string $key,string $match): array {
        Input::fields($input,['quote_id']); self::id($input['quote_id']);
        $result=$this->once($user,'payment:'.$id,$key,[$input,$match],fn()=>$this->sender($user,$id),function () use ($user,$id,$input,$match) {
            $row=$this->sender($user,$id,true); $this->localShipment($row); $this->version($row,$match);
            if ($row['order_status']!=='DRAFT' || !in_array($row['payment_status'],['UNPAID','FAILED'],true)) { throw new Failure(409,'PAYMENT_UNAVAILABLE','This shipment cannot start a checkout.'); }
            $quote=$this->q("SELECT * FROM pricing_quotes WHERE id=? AND shipment_id=? AND shipment_version=? AND expires_at>now() AND policy_version='DEMO-2026-01'",[$input['quote_id'],$id,$row['version']])->fetch(PDO::FETCH_ASSOC);
            if (!$quote) { throw new Failure(409,'QUOTE_EXPIRED','Request a new quote before checkout.'); }
            $provider=getenv('PAYMENT_PROVIDER') ?: 'LOCAL_TEST';
            if (!in_array($provider,['LOCAL_TEST','AUTHORIZE_NET_SANDBOX'],true)) { throw new Failure(503,'PAYMENT_NOT_CONFIGURED','Unsupported development payment provider.'); }
            if ($provider==='AUTHORIZE_NET_SANDBOX' && !\Zpx\Payments\AuthorizeNet::configured()) { throw new Failure(503,'PAYMENT_NOT_CONFIGURED','The sandbox credentials are incomplete.'); }
            $reference=$provider==='LOCAL_TEST'?'LOCAL-'.Secrets::uuid():'ZP'.strtoupper(bin2hex(random_bytes(9)));
            $payment=(string)$this->q("INSERT INTO payments(shipment_id,provider,provider_reference,amount_cents,status,quote_id) VALUES (?,?,?,?,'PENDING',?) RETURNING id",[$id,$provider,$reference,$quote['amount_cents'],$quote['id']])->fetchColumn();
            $this->q("UPDATE shipments SET payment_status='PENDING',version=version+1 WHERE id=?",[$id]);
            $this->event($user,$row,$provider==='LOCAL_TEST'?'TEST_CHECKOUT_STARTED':'SANDBOX_CHECKOUT_STARTED');
            return ['provider'=>$provider,'payment_id'=>$payment,'provider_session_reference'=>$reference,'status'=>'PENDING','development_only'=>true];
        });
        return (new \Zpx\Payments\HostedCheckout($this->db,$this->crypto))->prepare($user,$result['payment_id']);
    }
    public function confirmPayment(string $user,string $payment,array $input,string $key): array {
        self::id($payment); Input::fields($input,['outcome']);
        if (!in_array($input['outcome'],['SUCCEEDED','FAILED'],true)) { throw new Failure(422,'INVALID_INPUT','Invalid test outcome.'); }
        $id=$this->q("SELECT p.shipment_id FROM payments p JOIN shipments s ON s.id=p.shipment_id WHERE p.id=? AND s.sender_user_id=? AND s.organization_id=?",[$payment,$user,$this->org()])->fetchColumn();
        if (!$id) { throw new Failure(404,'PAYMENT_NOT_FOUND','Payment not found.'); }
        return $this->once($user,'test-payment:'.$payment,$key,$input,fn()=>$this->sender($user,$id),function () use ($user,$id,$payment,$input) {
            $row=$this->sender($user,$id,true); $this->localShipment($row);
            $pay=$this->q('SELECT p.*,q.expires_at FROM payments p JOIN pricing_quotes q ON q.id=p.quote_id WHERE p.id=? FOR UPDATE OF p',[$payment])->fetch(PDO::FETCH_ASSOC);
            if ($pay['provider']!=='LOCAL_TEST' || $pay['status']!=='PENDING' || $row['order_status']!=='DRAFT' || $row['payment_status']!=='PENDING') { throw new Failure(409,'PAYMENT_UNAVAILABLE','This test checkout is no longer pending.'); }
            $status=$input['outcome']==='SUCCEEDED' && strtotime($pay['expires_at'])>time()?'PAID':'FAILED';
            $this->q('UPDATE payments SET status=? WHERE id=?',[$status,$payment]);
            $this->q("UPDATE shipments SET payment_status=?,order_status=?,version=version+1 WHERE id=?",[$status,$status==='PAID'?'READY':'DRAFT',$id]);
            $this->q("INSERT INTO payment_events(provider,provider_event_id,payment_id,payload_reference) VALUES ('LOCAL_TEST',?,?,?)",['local-confirm-'.$payment,$payment,'Local test adapter: '.$status]);
            $this->event($user,$row,$status==='PAID'?'TEST_PAYMENT_CONFIRMED':'TEST_PAYMENT_FAILED');
            return ['payment_id'=>$payment,'provider_session_reference'=>$pay['provider_reference'],'status'=>$status,'development_only'=>true];
        });
    }
    public function paymentHistory(string $user,string $id): array {
        $this->identity->profile($user);
        $this->row($user,$id,'operations');
        $rows=$this->q("SELECT p.id,p.provider,p.provider_reference,p.provider_transaction_id,p.amount_cents,q.currency,p.status,p.created_at,q.expires_at FROM payments p JOIN pricing_quotes q ON q.id=p.quote_id WHERE p.shipment_id=? ORDER BY p.id DESC LIMIT 50",[$id])->fetchAll(PDO::FETCH_ASSOC);
        return ['items'=>array_map(static fn($r)=>['payment_id'=>(string)$r['id'],'provider'=>$r['provider'],'reference'=>$r['provider_reference'],'transaction_id'=>$r['provider_transaction_id'],'amount_cents'=>(int)$r['amount_cents'],'currency'=>$r['currency'],'status'=>$r['status'],'created_at'=>gmdate('c',strtotime($r['created_at'])),'quote_expires_at'=>gmdate('c',strtotime($r['expires_at']))],$rows)];
    }
    public function pendingPayment(string $user,string $id): array {
        $row=$this->sender($user,$id); $this->localShipment($row);
        $p=$this->q("SELECT * FROM payments WHERE shipment_id=? AND status='PENDING' ORDER BY id DESC LIMIT 1",[$id])->fetch(PDO::FETCH_ASSOC);
        if (!$p) { throw new Failure(404,'PAYMENT_NOT_FOUND','No pending test checkout.'); }
        return (new \Zpx\Payments\HostedCheckout($this->db,$this->crypto))->status($user,(string)$p['id']);
    }
    private function labelRow(string $user,string $package,bool $lock=false): array {
        self::id($package);
        $id=$this->q('SELECT shipment_id FROM packages WHERE id=?',[$package])->fetchColumn();
        if (!$id) { throw new Failure(404,'SHIPMENT_NOT_FOUND','Shipment not found.'); }
        $row=$this->sender($user,$id,$lock); $this->localShipment($row);
        if ($row['payment_status']!=='PAID' || $row['order_status']!=='READY') { throw new Failure(409,'LABEL_UNAVAILABLE','A confirmed test payment is required before a test label.'); }
        return $row;
    }
    public function label(string $user,string $package,string $key): array {
        return $this->once($user,'label:'.$package,$key,[],fn()=>$this->labelRow($user,$package),function () use ($user,$package) {
            $row=$this->labelRow($user,$package,true);
            $label=$this->q("SELECT * FROM package_labels WHERE package_id=? AND status='ACTIVE'",[$package])->fetch(PDO::FETCH_ASSOC);
            if (!$label) {
                if ($this->q('SELECT id FROM package_labels WHERE package_id=?',[$package])->fetchColumn()) { throw new Failure(409,'LABEL_REPLACEMENT_REQUIRED','A revoked label requires a supervised replacement workflow.'); }
                $payload='ZPX1:L:'.rtrim(strtr(base64_encode(random_bytes(18)),'+/','-_'),'=');
                $label=$this->q("INSERT INTO package_labels(package_id,label_version,token_hash,status,token_ciphertext,expires_at) VALUES (?,1,decode(?,'hex'),'ACTIVE',?,now()+interval '30 days') RETURNING *",[$package,hash('sha256',$payload),$this->crypto->encrypt($payload)])->fetch(PDO::FETCH_ASSOC);
                $suffix=''; $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; foreach (str_split(random_bytes(20)) as $b) { $suffix.=$alphabet[ord($b)&31]; }
                $code=$this->q('SELECT code FROM locations WHERE id=?',[$row['destination_location_id']])->fetchColumn();
                $code=preg_replace('/[^A-Z0-9-]/','',strtoupper(substr(strrchr(':'.$code,':'),1)));
                $this->q('INSERT INTO shipping_identifiers(package_id,si,destination_location_id) VALUES (?,?,?)',[$package,'ZPX-'.$code.'-'.$suffix,$row['destination_location_id']]);
            }
            if (strtotime($label['expires_at'])<=time()) { throw new Failure(409,'LABEL_EXPIRED','This test label has expired.'); }
            $this->q("INSERT INTO label_print_jobs(label_id,requested_by,status) VALUES (?,?,'REQUESTED')",[$label['id'],$user]);
            $this->event($user,$row,'TEST_LABEL_REQUESTED');
            $si=$this->q('SELECT si FROM shipping_identifiers WHERE package_id=?',[$package])->fetchColumn();
            return ['package_id'=>$package,'si'=>$si,'label_id'=>(string)$label['id'],'label_version'=>(int)$label['label_version'],'label_payload'=>$this->crypto->decrypt($label['token_ciphertext']),'pdf_url'=>'/api/delivery/v1/packages/'.$package.'/label/pdf','expires_at'=>gmdate('c',strtotime($label['expires_at'])),'development_only'=>true];
        });
    }
    public function pdf(string $user,string $package): string {
        $row=$this->labelRow($user,$package);
        $label=$this->q("SELECT pl.*,si.si FROM package_labels pl JOIN shipping_identifiers si ON si.package_id=pl.package_id WHERE pl.package_id=? AND pl.status='ACTIVE' AND pl.expires_at>now()",[$package])->fetch(PDO::FETCH_ASSOC);
        if (!$label) { throw new Failure(404,'LABEL_NOT_FOUND','No active test label.'); }
        return LabelPdf::render($row,$label['si'],$this->crypto->decrypt($label['token_ciphertext']));
    }
    public function claimChallenge(string $user,array $input,string $key): array {
        Input::fields($input,['public_reference']); Input::text($input['public_reference'],1,64);
        $this->identity->limit('claim:'.$user,5);
        return $this->once($user,'claim-challenge',$key,$input,fn()=>$this->customer($user),function () use ($user,$input) {
            if (!in_array(getenv('APP_ENV'),['development','test'],true)) { throw new Failure(503,'PROVIDER_NOT_CONFIGURED','Verification delivery is not configured.'); }
            // Both current verified contacts must match; still demand fresh shipment-bound proof.
            $row=$this->q("SELECT s.id,sp.contact_encrypted,encode(sp.email_lookup,'hex') AS target FROM shipments s JOIN shipment_parties sp ON sp.shipment_id=s.id AND sp.party_role='RECIPIENT' JOIN user_contacts e ON e.user_id=? AND e.kind='EMAIL' AND e.verified_at IS NOT NULL AND e.lookup_hmac=sp.email_lookup JOIN user_contacts p ON p.user_id=e.user_id AND p.kind='PHONE' AND p.verified_at IS NOT NULL AND p.lookup_hmac=sp.phone_lookup WHERE s.organization_id=? AND s.public_reference=? AND s.order_status<>'CANCELLED' AND (sp.user_id IS NULL OR sp.user_id=e.user_id)",[$user,$this->org(),$input['public_reference']])->fetch(PDO::FETCH_ASSOC);
            $public=Secrets::uuid(); $code=(string)random_int(100000,999999);
            $challenge=$this->q("INSERT INTO verification_challenges(user_id,purpose,target_hmac,secret_hash,expires_at,public_id,contact_kind,shipment_id) VALUES (?,'RECIPIENT_CLAIM',decode(?,'hex'),decode(?,'hex'),now()+interval '10 minutes',?,'EMAIL',?) RETURNING id,expires_at",[$user,$row['target'] ?? hash('sha256',Secrets::token()),$this->crypto->digest('claim:'.$public,$code),$public,$row['id'] ?? null])->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $recipient=json_decode($this->crypto->decrypt($row['contact_encrypted']),true,512,JSON_THROW_ON_ERROR);
                $message=$this->crypto->encrypt(json_encode(['challenge_id'=>$public,'kind'=>'EMAIL','to'=>$recipient['email'],'code'=>$code],JSON_THROW_ON_ERROR));
                (new Outbox($this->db))->append(Secrets::uuid(),'verification_challenge',(string)$challenge['id'],'identity.contact_verification',['encrypted_message'=>$message,'delivery'=>\Zpx\Mail\Delivery::channel('EMAIL',$recipient['email'])]);
            }
            return ['challenge_id'=>$public,'expires_at'=>gmdate('c',strtotime($challenge['expires_at'])),'delivery_status'=>'QUEUED'];
        });
    }
    public function claim(string $user,array $input,string $key): array {
        Input::fields($input,['challenge_id','code']);
        if (!is_string($input['challenge_id']) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$input['challenge_id']) || !is_string($input['code']) || !preg_match('/^[0-9]{6}$/D',$input['code'])) { throw new Failure(422,'INVALID_INPUT','Invalid verification details.'); }
        return $this->once($user,'claim',$key,$input,fn()=>$this->customer($user),function () use ($user,$input) {
            $r=$this->q("SELECT *,encode(secret_hash,'hex') AS secret FROM verification_challenges WHERE public_id=? AND user_id=? AND purpose='RECIPIENT_CLAIM' FOR UPDATE",[$input['challenge_id'],$user])->fetch(PDO::FETCH_ASSOC);
            $failure=new Failure(400,'INVALID_VERIFICATION','Verification is invalid or expired.');
            if (!$r || $r['consumed_at']!==null || strtotime($r['expires_at'])<=time() || (int)$r['attempts']>=5) { return $failure; }
            $this->q('UPDATE verification_challenges SET attempts=attempts+1 WHERE id=?',[$r['id']]);
            if (!$r['shipment_id'] || !hash_equals($r['secret'],$this->crypto->digest('claim:'.$input['challenge_id'],$input['code']))) { return $failure; }
            $s=$this->q('SELECT s.id,p.id AS package_id,s.order_status FROM shipments s JOIN packages p ON p.shipment_id=s.id AND p.sequence_no=1 WHERE s.id=? AND s.organization_id=? FOR UPDATE OF s',[$r['shipment_id'],$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$s || $s['order_status']==='CANCELLED') { return $failure; }
            $party=$this->q("UPDATE shipment_parties sp SET user_id=? WHERE shipment_id=? AND party_role='RECIPIENT' AND (user_id IS NULL OR user_id=?) AND EXISTS (SELECT 1 FROM user_contacts e JOIN user_contacts p ON p.user_id=e.user_id WHERE e.user_id=? AND e.kind='EMAIL' AND p.kind='PHONE' AND e.verified_at IS NOT NULL AND p.verified_at IS NOT NULL AND e.lookup_hmac=sp.email_lookup AND p.lookup_hmac=sp.phone_lookup)",[$user,$s['id'],$user,$user]);
            if ($party->rowCount()!==1) { return $failure; }
            $this->q('UPDATE verification_challenges SET consumed_at=now() WHERE id=?',[$r['id']]);
            $this->event($user,$s,'RECIPIENT_CLAIMED');
            return $this->get($user,(string)$s['id']);
        });
    }
}
