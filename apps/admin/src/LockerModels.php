<?php
declare(strict_types=1);
namespace ZpxAdmin;

use PDO;
use Zpx\Identity\{Failure,Input};
use Zpx\Infrastructure\Database\Transaction;

/** Reusable inventory templates. Instantiation creates frozen boxes, never working doors. */
final class LockerModels
{
    public function __construct(private PDO $db) {}
    private function q(string $sql,array $args=[]): \PDOStatement
    { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }
    private function authorize(string $actor): void { (new Access($this->db))->requireNetworkAdmin($actor); }
    private static function id(mixed $value): string
    {
        $id=(string)$value;
        if (!preg_match('/^[1-9][0-9]{0,17}$/D',$id)) { throw new Failure(422,'INVALID_ID','Invalid ID.'); }
        return $id;
    }
    private static function code(mixed $value,int $max=40): string
    {
        $code=trim(Input::text($value,2,$max));
        if (!preg_match('/^[A-Z][A-Z0-9-]{1,'.($max-1).'}$/D',$code)) {
            throw new Failure(422,'INVALID_CODE','Use an uppercase model or body code.');
        }
        return $code;
    }
    private static function number(mixed $value,int $max): int
    {
        if (!is_string($value) || !ctype_digit($value) || (int)$value<1 || (int)$value>$max) {
            throw new Failure(422,'INVALID_NUMBER','Enter a positive number in range.');
        }
        return (int)$value;
    }
    private static function reason(mixed $value): string
    { return Input::text(trim(Input::text($value,10,500)),10,500); }
    private function audit(string $actor,string $action,string $entity,string $id,string $reason): void
    {
        $this->q('INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id,reason) VALUES (?,?,?,?,?)',
            [$actor,$action,$entity,$id,$reason]);
    }

    public function catalog(string $actor): array
    {
        $this->authorize($actor);
        $bodies=$this->q('SELECT b.id,b.code,b.version,b.name,b.status,
                (SELECT count(*) FROM locker_body_model_slots s WHERE s.body_model_id=b.id) AS slot_count
                FROM locker_body_models b WHERE b.organization_id=? ORDER BY b.id DESC LIMIT 100',[$this->org()])->fetchAll(PDO::FETCH_ASSOC);
        return [
            'bodies'=>$bodies,
            'draft_bodies'=>array_values(array_filter($bodies,static fn(array $row): bool => $row['status']==='DRAFT')),
            'boxes'=>$this->q('SELECT id,code,version,name,size_class,width_mm,height_mm,depth_mm,max_weight_g
                FROM locker_box_models WHERE organization_id=? ORDER BY id DESC LIMIT 100',[$this->org()])->fetchAll(PDO::FETCH_ASSOC),
            'slots'=>$this->q('SELECT s.id,s.body_model_id,b.status AS body_status,s.display_row,s.display_column,
                x.code AS box_code,x.version AS box_version,x.name AS box_name
                FROM locker_body_model_slots s JOIN locker_box_models x ON x.id=s.box_model_id
                JOIN locker_body_models b ON b.id=s.body_model_id
                WHERE s.organization_id=? ORDER BY s.body_model_id,s.display_row,s.display_column LIMIT 500',[$this->org()])->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function readyBodies(string $actor): array
    {
        $this->authorize($actor);
        return $this->q("SELECT id,code,version,name,
            (SELECT count(*) FROM locker_body_model_slots s WHERE s.body_model_id=locker_body_models.id) AS slot_count
            FROM locker_body_models WHERE organization_id=? AND status='READY'
            ORDER BY code,version DESC LIMIT 100",[$this->org()])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addBoxModel(string $actor,array $input): void
    {
        $this->authorize($actor);
        Input::fields($input,['code','version','name','size_class','width_mm','height_mm','depth_mm','max_weight_g','reason']);
        $code=self::code($input['code']); $version=self::number($input['version'],10000);
        $name=trim(Input::text($input['name'],1,120)); $reason=self::reason($input['reason']);
        $size=Input::text($input['size_class'],1,16);
        if (!in_array($size,['SMALL','MEDIUM','LARGE','XLARGE'],true)) {
            throw new Failure(422,'INVALID_SIZE_CLASS','Choose Small, Mid, Large or X-large.');
        }
        $measure=[];
        foreach (['width_mm','height_mm','depth_mm','max_weight_g'] as $field) { $measure[]=self::number($input[$field],100000); }
        (new Transaction($this->db))->run(function() use($actor,$code,$version,$name,$size,$measure,$reason) {
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['box-model:'.$this->org().':'.$code.':'.$version]);
            if ($this->q('SELECT 1 FROM locker_box_models WHERE organization_id=? AND code=? AND version=?',[$this->org(),$code,$version])->fetchColumn()) {
                throw new Failure(409,'MODEL_EXISTS','Box model version already exists.');
            }
            $id=(string)$this->q('INSERT INTO locker_box_models(organization_id,code,version,name,size_class,width_mm,height_mm,depth_mm,max_weight_g)
                VALUES (?,?,?,?,?,?,?,?,?) RETURNING id',[$this->org(),$code,$version,$name,$size,...$measure])->fetchColumn();
            $this->audit($actor,'LOCKER_BOX_MODEL_CREATED','locker_box_model',$id,$reason);
        });
    }

    public function addBodyModel(string $actor,array $input): void
    {
        $this->authorize($actor);
        Input::fields($input,['code','version','name','reason']);
        $code=self::code($input['code']); $version=self::number($input['version'],10000);
        $name=trim(Input::text($input['name'],1,120)); $reason=self::reason($input['reason']);
        (new Transaction($this->db))->run(function() use($actor,$code,$version,$name,$reason) {
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['body-model:'.$this->org().':'.$code.':'.$version]);
            if ($this->q('SELECT 1 FROM locker_body_models WHERE organization_id=? AND code=? AND version=?',[$this->org(),$code,$version])->fetchColumn()) {
                throw new Failure(409,'MODEL_EXISTS','Body model version already exists.');
            }
            $id=(string)$this->q('INSERT INTO locker_body_models(organization_id,code,version,name) VALUES (?,?,?,?) RETURNING id',
                [$this->org(),$code,$version,$name])->fetchColumn();
            $this->audit($actor,'LOCKER_BODY_MODEL_CREATED','locker_body_model',$id,$reason);
        });
    }

    public function addSlot(string $actor,array $input): void
    {
        $this->authorize($actor);
        Input::fields($input,['body_model_id','box_model_id','row','column','reason']);
        $body=self::id($input['body_model_id']); $box=self::id($input['box_model_id']);
        $row=self::number($input['row'],99); $column=self::number($input['column'],99);
        $reason=self::reason($input['reason']);
        (new Transaction($this->db))->run(function() use($actor,$body,$box,$row,$column,$reason) {
            $model=$this->q('SELECT status FROM locker_body_models WHERE id=? AND organization_id=? FOR UPDATE',[$body,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$model) { throw new Failure(404,'MODEL_NOT_FOUND','Body model not found.'); }
            if ($model['status']!=='DRAFT') { throw new Failure(409,'MODEL_READY','Create a new model version to change a ready layout.'); }
            if (!$this->q('SELECT 1 FROM locker_box_models WHERE id=? AND organization_id=?',[$box,$this->org()])->fetchColumn()) {
                throw new Failure(404,'MODEL_NOT_FOUND','Box model not found.');
            }
            if ((int)$this->q('SELECT count(*) FROM locker_body_model_slots WHERE body_model_id=?',[$body])->fetchColumn()>=100) {
                throw new Failure(409,'MODEL_FULL','A body model may have at most 100 boxes.');
            }
            if ($this->q('SELECT 1 FROM locker_body_model_slots WHERE body_model_id=? AND display_row=? AND display_column=?',[$body,$row,$column])->fetchColumn()) {
                throw new Failure(409,'SLOT_EXISTS','That body position already has a box model.');
            }
            $this->q('INSERT INTO locker_body_model_slots(organization_id,body_model_id,box_model_id,display_row,display_column)
                VALUES (?,?,?,?,?)',[$this->org(),$body,$box,$row,$column]);
            $this->audit($actor,'LOCKER_MODEL_SLOT_ADDED','locker_body_model',$body,$reason.' [row '.$row.', column '.$column.']');
        });
    }

    public function ready(string $actor,string $bodyId,string $reason): void
    {
        $this->authorize($actor); $bodyId=self::id($bodyId); $reason=self::reason($reason);
        (new Transaction($this->db))->run(function() use($actor,$bodyId,$reason) {
            $model=$this->q('SELECT status FROM locker_body_models WHERE id=? AND organization_id=? FOR UPDATE',[$bodyId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$model) { throw new Failure(404,'MODEL_NOT_FOUND','Body model not found.'); }
            if ($model['status']!=='DRAFT') { throw new Failure(409,'MODEL_READY','This layout is already ready.'); }
            if (!$this->q('SELECT 1 FROM locker_body_model_slots WHERE body_model_id=? LIMIT 1',[$bodyId])->fetchColumn()) {
                throw new Failure(409,'MODEL_EMPTY','Add at least one box position.');
            }
            $this->q("UPDATE locker_body_models SET status='READY' WHERE id=?",[$bodyId]);
            $this->audit($actor,'LOCKER_BODY_MODEL_READY','locker_body_model',$bodyId,$reason);
        });
    }

    public function removeSlot(string $actor,string $slotId,string $reason): void
    {
        $this->authorize($actor); $slotId=self::id($slotId); $reason=self::reason($reason);
        (new Transaction($this->db))->run(function() use($actor,$slotId,$reason) {
            $slot=$this->q('SELECT s.body_model_id,b.status FROM locker_body_model_slots s
                JOIN locker_body_models b ON b.id=s.body_model_id
                WHERE s.id=? AND s.organization_id=? FOR UPDATE OF b',[$slotId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$slot) { throw new Failure(404,'SLOT_NOT_FOUND','Layout position not found.'); }
            if ($slot['status']!=='DRAFT') { throw new Failure(409,'MODEL_READY','A ready layout cannot be changed.'); }
            $this->q('DELETE FROM locker_body_model_slots WHERE id=?',[$slotId]);
            $this->audit($actor,'LOCKER_MODEL_SLOT_REMOVED','locker_body_model',(string)$slot['body_model_id'],
                $reason.' [slot '.$slotId.']');
        });
    }

    public function instantiate(string $actor,string $lockerId,array $input): void
    {
        $this->authorize($actor); $lockerId=self::id($lockerId);
        Input::fields($input,['body_model_id','body_code','position','reason']);
        $modelId=self::id($input['body_model_id']);
        $bodyCode=self::code($input['body_code'],30);
        $position=self::number($input['position'],1000);
        $reason=self::reason($input['reason']);
        (new Transaction($this->db))->run(function() use($actor,$lockerId,$modelId,$bodyCode,$position,$reason) {
            $this->requireInactiveLocker($lockerId);
            $this->insertBody($actor,$lockerId,$modelId,$bodyCode,$position,$reason);
        });
    }

    /** Append selected ready body models in form order; all generated boxes commit together. */
    public function assemble(string $actor,string $lockerId,array $input): void
    {
        $this->authorize($actor); $lockerId=self::id($lockerId);
        Input::fields($input,['body_model_ids','expected_position','reason']);
        $ids=$input['body_model_ids'];
        if (!is_array($ids) || count($ids)>20) { throw new Failure(422,'INVALID_MODELS','Choose up to 20 body models.'); }
        $ids=array_values(array_filter($ids,static fn(mixed $id): bool => $id!=='' && $id!==null));
        if (!$ids) { throw new Failure(422,'INVALID_MODELS','Choose at least one body model.'); }
        $ids=array_map(self::id(...),$ids);
        $expected=self::number($input['expected_position'],1000);
        $reason=self::reason($input['reason']);
        (new Transaction($this->db))->run(function() use($actor,$lockerId,$ids,$expected,$reason) {
            $this->requireInactiveLocker($lockerId,true);
            $position=1+(int)$this->q('SELECT COALESCE(max(display_sequence),0) FROM locker_body_modules WHERE locker_id=?',[$lockerId])->fetchColumn();
            if ($position!==$expected) { throw new Failure(409,'STRUCTURE_CHANGED','Locker structure changed; reload before assembling.'); }
            foreach ($ids as $modelId) {
                if ($position>1000) { throw new Failure(409,'LOCKER_FULL','Body position limit reached.'); }
                $this->insertBody($actor,$lockerId,$modelId,'BODY-'.$position,$position,$reason);
                $position++;
            }
        });
    }

    private function requireInactiveLocker(string $lockerId,bool $requireSite=false): void
    {
        $locker=$this->q('SELECT l.status,l.site_id FROM lockers k JOIN locations l ON l.id=k.location_id
            WHERE k.id=? AND l.organization_id=? FOR UPDATE OF k',[$lockerId,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$locker) { throw new Failure(404,'LOCKER_NOT_FOUND','Locker not found.'); }
        if ($locker['status']!=='INACTIVE') { throw new Failure(409,'LOCKER_LOCATION_ACTIVE','Only an inactive locker location accepts a model draft.'); }
        if ($requireSite && $locker['site_id']===null) { throw new Failure(409,'SITE_REQUIRED','Bind the locker location to an installation site first.'); }
    }

    private function insertBody(string $actor,string $lockerId,string $modelId,string $bodyCode,int $position,string $reason): void
    {
        $model=$this->q('SELECT status FROM locker_body_models WHERE id=? AND organization_id=? FOR SHARE',[$modelId,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$model) { throw new Failure(404,'MODEL_NOT_FOUND','Body model not found in this network.'); }
        if ($model['status']!=='READY') { throw new Failure(409,'MODEL_NOT_READY','Finish the body layout before using it.'); }
        $slots=$this->q('SELECT s.display_row,s.display_column,s.box_model_id,x.width_mm,x.height_mm,x.depth_mm,x.max_weight_g
            FROM locker_body_model_slots s JOIN locker_box_models x ON x.id=s.box_model_id
            WHERE s.body_model_id=? AND s.organization_id=? ORDER BY s.display_row,s.display_column',[$modelId,$this->org()])->fetchAll(PDO::FETCH_ASSOC);
        if (!$slots) { throw new Failure(409,'MODEL_EMPTY','Body model has no box positions.'); }
        if ($this->q('SELECT 1 FROM locker_body_modules WHERE locker_id=? AND (code=? OR display_sequence=?)',[$lockerId,$bodyCode,$position])->fetchColumn()) {
            throw new Failure(409,'BODY_EXISTS','Body code or position already exists at this locker.');
        }
        $moduleCode=$bodyCode.'-BOX';
        if ($this->q('SELECT 1 FROM locker_box_modules WHERE locker_id=? AND code=?',[$lockerId,$moduleCode])->fetchColumn()) {
            throw new Failure(409,'MODULE_EXISTS','Generated box module code already exists.');
        }
        foreach ($slots as $slot) {
            $boxCode=$bodyCode.'-'.$slot['display_row'].'-'.$slot['display_column'];
            if ($this->q('SELECT 1 FROM compartments WHERE locker_id=? AND code=?',[$lockerId,$boxCode])->fetchColumn()) {
                throw new Failure(409,'BOX_CODE_EXISTS','Generated box code already exists.');
            }
        }
        $body=(string)$this->q('INSERT INTO locker_body_modules(locker_id,code,display_sequence,body_model_id)
            VALUES (?,?,?,?) RETURNING id',[$lockerId,$bodyCode,$position,$modelId])->fetchColumn();
        $module=(string)$this->q('INSERT INTO locker_box_modules(locker_id,body_module_id,code,display_sequence)
            VALUES (?,?,?,1) RETURNING id',[$lockerId,$body,$moduleCode])->fetchColumn();
        foreach ($slots as $slot) {
            $this->q("INSERT INTO compartments(locker_id,code,width_mm,height_mm,depth_mm,max_weight_g,status,box_module_id,
                box_model_id,display_row,display_column) VALUES (?,?,?,?,?,?,'FROZEN',?,?,?,?)",
                [$lockerId,$bodyCode.'-'.$slot['display_row'].'-'.$slot['display_column'],$slot['width_mm'],$slot['height_mm'],
                    $slot['depth_mm'],$slot['max_weight_g'],$module,$slot['box_model_id'],$slot['display_row'],$slot['display_column']]);
        }
        $this->audit($actor,'LOCKER_BODY_MODEL_INSTANTIATED','locker',$lockerId,$reason.' [body '.$body.', model '.$modelId.']');
    }
}
