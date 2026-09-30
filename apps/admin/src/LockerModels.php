<?php
declare(strict_types=1);
namespace ZpxAdmin;

use PDO;
use LogicException;
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
    private static function address(mixed $value): int
    {
        if (!is_string($value) || !ctype_digit($value) || (int)$value>255) {
            throw new Failure(422,'INVALID_ADDRESS','Enter a door address from 0 to 255.');
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

    public function catalog(string $actor,string $page='',array $input=[]): array
    {
        $this->authorize($actor);
        $text=static function(mixed $value): string {
            return is_string($value) ? trim(substr($value,0,120)) : '';
        };
        $search=$text($input['q']??'');
        $status=$text($input['status']??'');
        $size=$text($input['size']??'');
        $source=$text($input['source']??'');
        $body=$text($input['body']??'');
        $bodyWhere=''; $bodyArgs=[$this->org()];
        $boxWhere=''; $boxArgs=[$this->org()];
        $slotWhere=''; $slotArgs=[$this->org()];
        if ($page==='body-models') {
            if ($search!=='') {
                $bodyWhere.=' AND (b.model_name ILIKE ? OR b.code ILIKE ? OR b.model_id::text=? OR b.legacy_model_id::text=?)';
                array_push($bodyArgs,'%'.$search.'%','%'.$search.'%',$search,$search);
            }
            $bodyWhere.=match ($status) {
                'DRAFT'=>" AND b.status='DRAFT' AND b.legacy_model_id IS NULL",
                'READY'=>" AND b.status='READY'",
                'HISTORICAL'=>' AND b.legacy_model_id IS NOT NULL',
                default=>'',
            };
        }
        if ($page==='box-models') {
            if ($search!=='') {
                $boxWhere.=' AND (model_name ILIKE ? OR code ILIKE ? OR model_id::text=? OR legacy_model_id::text=?)';
                array_push($boxArgs,'%'.$search.'%','%'.$search.'%',$search,$search);
            }
            if (in_array($size,['SMALL','MEDIUM','LARGE','XLARGE'],true)) {
                $boxWhere.=' AND size_class=?'; $boxArgs[]=$size;
            }
            $boxWhere.=match ($source) {
                'LOCAL'=>' AND legacy_model_id IS NULL',
                'HISTORICAL'=>' AND legacy_model_id IS NOT NULL',
                default=>'',
            };
        }
        if ($page==='body-box-layouts') {
            if ($search!=='') {
                $slotWhere.=' AND (b.model_name ILIKE ? OR x.model_name ILIKE ? OR s.body_box_id::text=? OR s.addr::text=?)';
                array_push($slotArgs,'%'.$search.'%','%'.$search.'%',$search,$search);
            }
            if (preg_match('/^[1-9][0-9]{0,17}$/D',$body)) {
                $slotWhere.=' AND s.body_model_id=?'; $slotArgs[]=$body;
            }
            $slotWhere.=match ($status) {
                'DRAFT'=>" AND b.status='DRAFT' AND b.legacy_model_id IS NULL",
                'READY'=>" AND b.status='READY'",
                'HISTORICAL'=>' AND b.legacy_model_id IS NOT NULL',
                default=>'',
            };
        }
        $bodies=$this->q('SELECT b.model_id AS id,b.code,b.version,b.revision,b.model_name AS name,b.status,b.legacy_model_id,
                (SELECT count(*) FROM cabinet_body_box s WHERE s.body_model_id=b.model_id) AS slot_count
                FROM cabinet_body_model b WHERE b.organization_id=?'.$bodyWhere.' ORDER BY b.model_id DESC LIMIT 100',$bodyArgs)->fetchAll(PDO::FETCH_ASSOC);
        return [
            'filter'=>['q'=>$search,'status'=>$status,'size'=>$size,'source'=>$source,'body'=>$body],
            'bodies'=>$bodies,
            'draft_bodies'=>array_values(array_filter($bodies,static fn(array $row): bool => $row['status']==='DRAFT' && $row['legacy_model_id']===null)),
            'boxes'=>$this->q('SELECT model_id AS id,code,version,revision,model_name AS name,size_class,width_mm,height_mm,depth_mm,max_weight_g,
                CASE WHEN is_allocable THEN 1 ELSE 0 END AS is_allocable,
                CASE WHEN is_allocable THEN \'Yes\' ELSE \'No\' END AS allocable_label,
                legacy_size_category,legacy_price_raw,dimensions_source_unit,legacy_model_id,length,width,height,
                CASE WHEN legacy_model_id IS NULL AND NOT EXISTS
                    (SELECT 1 FROM cabinet_body_box s JOIN cabinet_body_model b ON b.model_id=s.body_model_id
                     WHERE s.box_model_id=cabinet_box_model.model_id AND (b.status=\'READY\' OR b.legacy_model_id IS NOT NULL))
                    AND NOT EXISTS (SELECT 1 FROM cabinet_box cb WHERE cb.box_model_id=cabinet_box_model.model_id)
                    AND NOT EXISTS (SELECT 1 FROM compartments c WHERE c.box_model_id=cabinet_box_model.model_id)
                    THEN 1 ELSE 0 END AS editable
                FROM cabinet_box_model WHERE organization_id=?'.$boxWhere.' ORDER BY model_id DESC LIMIT 100',$boxArgs)->fetchAll(PDO::FETCH_ASSOC),
            'slots'=>$this->q('SELECT s.body_box_id AS id,s.body_model_id,s.box_model_id,b.status AS body_status,b.legacy_model_id AS legacy_body_model_id,b.model_name AS body_name,
                s."row" AS display_row,s."column" AS display_column,s.addr AS door_address,
                x.code AS box_code,x.version AS box_version,x.model_name AS box_name,x.is_allocable
                FROM cabinet_body_box s JOIN cabinet_box_model x ON x.model_id=s.box_model_id
                JOIN cabinet_body_model b ON b.model_id=s.body_model_id
                WHERE s.organization_id=?'.$slotWhere.' ORDER BY s.body_model_id,s."row",s."column" LIMIT 500',$slotArgs)->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function readyBodies(string $actor): array
    {
        $this->authorize($actor);
        return $this->q("SELECT model_id AS id,code,version,model_name AS name,
            (SELECT count(*) FROM cabinet_body_box s WHERE s.body_model_id=cabinet_body_model.model_id) AS slot_count
            FROM cabinet_body_model WHERE organization_id=? AND status='READY'
            ORDER BY code,version DESC LIMIT 100",[$this->org()])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addBoxModel(string $actor,array $input): void
    {
        $this->authorize($actor);
        Input::fields($input,['name','size_class','width_mm','height_mm','depth_mm','max_weight_g','reason'],
            ['code','version','is_allocable','legacy_size_category','legacy_price_raw']);
        $code=isset($input['code']) && $input['code']!=='' ? self::code($input['code']) : null;
        $version=isset($input['version']) && $input['version']!=='' ? self::number($input['version'],10000) : 1;
        $name=trim(Input::text($input['name'],1,120)); $reason=self::reason($input['reason']);
        $size=Input::text($input['size_class'],1,16);
        if (!in_array($size,['SMALL','MEDIUM','LARGE','XLARGE'],true)) {
            throw new Failure(422,'INVALID_SIZE_CLASS','Choose Small, Mid, Large or X-large.');
        }
        $measure=[];
        foreach (['width_mm','height_mm','depth_mm','max_weight_g'] as $field) { $measure[]=self::number($input[$field],100000); }
        $allocable=($input['is_allocable'] ?? '0')==='1';
        if (isset($input['is_allocable']) && !in_array($input['is_allocable'],['0','1'],true)) {
            throw new Failure(422,'INVALID_ALLOCABILITY','Choose whether this model is allocable.');
        }
        $legacySize=trim(Input::text($input['legacy_size_category'] ?? '',0,80));
        $legacyPrice=trim(Input::text($input['legacy_price_raw'] ?? '',0,80));
        (new Transaction($this->db))->run(function() use($actor,$code,$version,$name,$size,$measure,$reason,$allocable,$legacySize,$legacyPrice) {
            $id=(string)$this->q("SELECT nextval('locker_box_models_id_seq')")->fetchColumn();
            $code ??= 'BOX-'.$id;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['box-model:'.$this->org().':'.$code.':'.$version]);
            if ($this->q('SELECT 1 FROM cabinet_box_model WHERE organization_id=? AND code=? AND version=?',[$this->org(),$code,$version])->fetchColumn()) {
                throw new Failure(409,'MODEL_EXISTS','Box model version already exists.');
            }
            $sizeCat=['SMALL'=>'small','MEDIUM'=>'middle','LARGE'=>'large','XLARGE'=>'x-large'][$size];
            $price=preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D',$legacyPrice) ? $legacyPrice : null;
            $id=(string)$this->q('INSERT INTO cabinet_box_model(model_id,organization_id,code,version,model_name,size_class,
                width_mm,height_mm,depth_mm,max_weight_g,is_allocable,legacy_size_category,legacy_price_raw,
                dimensions_source_unit,size_cat,length,width,height,model_price)
                OVERRIDING SYSTEM VALUE VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING model_id',
                [$id,$this->org(),$code,$version,$name,$size,...$measure,$allocable?'true':'false',
                    $legacySize ?: null,$legacyPrice ?: null,'MM',$sizeCat,$measure[2],$measure[0],$measure[1],$price])->fetchColumn();
            $this->audit($actor,'LOCKER_BOX_MODEL_CREATED','locker_box_model',$id,$reason);
        });
    }

    public function addBodyModel(string $actor,array $input): void
    {
        $this->authorize($actor);
        Input::fields($input,['name','reason'],['code','version']);
        $code=isset($input['code']) && $input['code']!=='' ? self::code($input['code']) : null;
        $version=isset($input['version']) && $input['version']!=='' ? self::number($input['version'],10000) : 1;
        $name=trim(Input::text($input['name'],1,120)); $reason=self::reason($input['reason']);
        (new Transaction($this->db))->run(function() use($actor,$code,$version,$name,$reason) {
            $id=(string)$this->q("SELECT nextval('locker_body_models_id_seq')")->fetchColumn();
            $code ??= 'BODY-MODEL-'.$id;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['body-model:'.$this->org().':'.$code.':'.$version]);
            if ($this->q('SELECT 1 FROM cabinet_body_model WHERE organization_id=? AND code=? AND version=?',[$this->org(),$code,$version])->fetchColumn()) {
                throw new Failure(409,'MODEL_EXISTS','Body model version already exists.');
            }
            $id=(string)$this->q('INSERT INTO cabinet_body_model(model_id,organization_id,code,version,model_name) OVERRIDING SYSTEM VALUE VALUES (?,?,?,?,?) RETURNING model_id',
                [$id,$this->org(),$code,$version,$name])->fetchColumn();
            $this->audit($actor,'LOCKER_BODY_MODEL_CREATED','locker_body_model',$id,$reason);
        });
    }

    public function updateBoxModel(string $actor,string $modelId,array $input): void
    {
        $this->authorize($actor); $modelId=self::id($modelId);
        Input::fields($input,['name','size_class','width_mm','height_mm','depth_mm','max_weight_g','revision','reason'],
            ['is_allocable','legacy_size_category','legacy_price_raw']);
        $name=trim(Input::text($input['name'],1,120));
        $size=Input::text($input['size_class'],1,16);
        if (!in_array($size,['SMALL','MEDIUM','LARGE','XLARGE'],true)) {
            throw new Failure(422,'INVALID_SIZE_CLASS','Choose Small, Mid, Large or X-large.');
        }
        $measure=[];
        foreach (['width_mm','height_mm','depth_mm','max_weight_g'] as $field) { $measure[]=self::number($input[$field],100000); }
        $revision=self::number($input['revision'],1000000);
        $allocable=$input['is_allocable'] ?? '0';
        if (!in_array($allocable,['0','1'],true)) { throw new Failure(422,'INVALID_ALLOCABILITY','Choose whether this model is allocable.'); }
        $legacySize=trim(Input::text($input['legacy_size_category'] ?? '',0,80));
        $legacyPrice=trim(Input::text($input['legacy_price_raw'] ?? '',0,80));
        $reason=self::reason($input['reason']);
        (new Transaction($this->db))->run(function() use($actor,$modelId,$name,$size,$measure,$revision,$allocable,$legacySize,$legacyPrice,$reason) {
            $model=$this->q('SELECT revision,legacy_model_id FROM cabinet_box_model WHERE model_id=? AND organization_id=? FOR UPDATE',
                [$modelId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$model) { throw new Failure(404,'MODEL_NOT_FOUND','Box model not found.'); }
            if ($model['legacy_model_id']!==null || $this->q("SELECT 1 FROM cabinet_body_box s JOIN cabinet_body_model b ON b.model_id=s.body_model_id
                WHERE s.box_model_id=? AND (b.status='READY' OR b.legacy_model_id IS NOT NULL) LIMIT 1",[$modelId])->fetchColumn()
                || $this->q('SELECT 1 FROM cabinet_box WHERE box_model_id=? LIMIT 1',[$modelId])->fetchColumn()
                || $this->q('SELECT 1 FROM compartments WHERE box_model_id=? LIMIT 1',[$modelId])->fetchColumn()) {
                throw new Failure(409,'MODEL_IMMUTABLE','Historical or published box models must be copied before editing.');
            }
            if ((int)$model['revision']!==$revision) { throw new Failure(412,'STALE_MODEL','Refresh this box model before saving.'); }
            $sizeCat=['SMALL'=>'small','MEDIUM'=>'middle','LARGE'=>'large','XLARGE'=>'x-large'][$size];
            $price=preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/D',$legacyPrice) ? $legacyPrice : null;
            $this->q('UPDATE cabinet_box_model SET model_name=?,size_class=?,width_mm=?,height_mm=?,depth_mm=?,max_weight_g=?,
                is_allocable=?,legacy_size_category=?,legacy_price_raw=?,dimensions_source_unit=\'MM\',
                size_cat=?,length=?,width=?,height=?,model_price=?,revision=revision+1 WHERE model_id=?',
                [$name,$size,...$measure,$allocable==='1'?'true':'false',$legacySize ?: null,$legacyPrice ?: null,
                    $sizeCat,$measure[2],$measure[0],$measure[1],$price,$modelId]);
            $this->audit($actor,'LOCKER_BOX_MODEL_UPDATED','locker_box_model',$modelId,$reason);
        });
    }

    public function updateBodyModel(string $actor,string $modelId,array $input): void
    {
        $this->authorize($actor); $modelId=self::id($modelId);
        Input::fields($input,['name','revision','reason']);
        $name=trim(Input::text($input['name'],1,120));
        $revision=self::number($input['revision'],1000000); $reason=self::reason($input['reason']);
        (new Transaction($this->db))->run(function() use($actor,$modelId,$name,$revision,$reason) {
            $model=$this->q('SELECT status,legacy_model_id,revision FROM cabinet_body_model WHERE model_id=? AND organization_id=? FOR UPDATE',
                [$modelId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$model) { throw new Failure(404,'MODEL_NOT_FOUND','Body model not found.'); }
            if ($model['legacy_model_id']!==null || $model['status']!=='DRAFT') {
                throw new Failure(409,'MODEL_IMMUTABLE','Historical or ready body models must be copied before editing.');
            }
            if ((int)$model['revision']!==$revision) { throw new Failure(412,'STALE_MODEL','Refresh this body model before saving.'); }
            $this->q('UPDATE cabinet_body_model SET model_name=?,revision=revision+1 WHERE model_id=?',[$name,$modelId]);
            $this->audit($actor,'LOCKER_BODY_MODEL_UPDATED','locker_body_model',$modelId,$reason);
        });
    }

    public function copyBoxModel(string $actor,string $sourceId,string $reason): void
    {
        $this->authorize($actor); $sourceId=self::id($sourceId); $reason=self::reason($reason);
        (new Transaction($this->db))->run(function() use($actor,$sourceId,$reason) {
            $source=$this->q('SELECT model_id FROM cabinet_box_model WHERE model_id=? AND organization_id=? FOR SHARE',
                [$sourceId,$this->org()])->fetchColumn();
            if (!$source) { throw new Failure(404,'MODEL_NOT_FOUND','Box model not found.'); }
            $id=(string)$this->q("SELECT nextval('locker_box_models_id_seq')")->fetchColumn();
            $this->q('INSERT INTO cabinet_box_model(model_id,organization_id,code,version,model_name,size_class,
                width_mm,height_mm,depth_mm,max_weight_g,is_allocable,legacy_size_category,legacy_price_raw,
                dimensions_source_unit,size_cat,length,width,height,model_price)
                OVERRIDING SYSTEM VALUE SELECT ?,organization_id,?,1,left(model_name || \' copy\',120),size_class,
                width_mm,height_mm,depth_mm,max_weight_g,false,legacy_size_category,legacy_price_raw,
                dimensions_source_unit,size_cat,length,width,height,model_price FROM cabinet_box_model WHERE model_id=?',
                [$id,'BOX-'.$id,$sourceId]);
            $this->audit($actor,'LOCKER_BOX_MODEL_COPIED','locker_box_model',$id,$reason.' [source '.$sourceId.']');
        });
    }

    public function copyBodyModel(string $actor,string $sourceId,string $reason): void
    {
        $this->authorize($actor); $sourceId=self::id($sourceId); $reason=self::reason($reason);
        (new Transaction($this->db))->run(function() use($actor,$sourceId,$reason) {
            $source=$this->q('SELECT model_id FROM cabinet_body_model WHERE model_id=? AND organization_id=? FOR SHARE',
                [$sourceId,$this->org()])->fetchColumn();
            if (!$source) { throw new Failure(404,'MODEL_NOT_FOUND','Body model not found.'); }
            $id=(string)$this->q("SELECT nextval('locker_body_models_id_seq')")->fetchColumn();
            $this->q('INSERT INTO cabinet_body_model(model_id,organization_id,code,version,model_name)
                OVERRIDING SYSTEM VALUE SELECT ?,organization_id,?,1,left(model_name || \' copy\',120)
                FROM cabinet_body_model WHERE model_id=?',[$id,'BODY-MODEL-'.$id,$sourceId]);
            $this->q('INSERT INTO cabinet_body_box(organization_id,body_model_id,box_model_id,"row","column",addr,create_time)
                SELECT organization_id,?,box_model_id,"row","column",addr,extract(epoch from now())::bigint
                FROM cabinet_body_box WHERE body_model_id=? ORDER BY body_box_id',[$id,$sourceId]);
            $this->audit($actor,'LOCKER_BODY_MODEL_COPIED','locker_body_model',$id,$reason.' [source '.$sourceId.']');
        });
    }

    public function addSlot(string $actor,array $input): void
    {
        $this->authorize($actor);
        Input::fields($input,['body_model_id','box_model_id','row','column','door_address','reason']);
        $body=self::id($input['body_model_id']); $box=self::id($input['box_model_id']);
        $row=self::number($input['row'],99); $column=self::number($input['column'],99);
        $door=self::address($input['door_address']);
        $reason=self::reason($input['reason']);
        (new Transaction($this->db))->run(function() use($actor,$body,$box,$row,$column,$door,$reason) {
            $model=$this->q('SELECT status,legacy_model_id FROM cabinet_body_model WHERE model_id=? AND organization_id=? FOR UPDATE',[$body,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$model) { throw new Failure(404,'MODEL_NOT_FOUND','Body model not found.'); }
            if ($model['legacy_model_id']!==null) { throw new Failure(409,'MODEL_UNVERIFIED','Historical layouts are read-only.'); }
            if ($model['status']!=='DRAFT') { throw new Failure(409,'MODEL_READY','Create a new model version to change a ready layout.'); }
            if (!$this->q('SELECT 1 FROM cabinet_box_model WHERE model_id=? AND organization_id=?',[$box,$this->org()])->fetchColumn()) {
                throw new Failure(404,'MODEL_NOT_FOUND','Box model not found.');
            }
            if ((int)$this->q('SELECT count(*) FROM cabinet_body_box WHERE body_model_id=?',[$body])->fetchColumn()>=100) {
                throw new Failure(409,'MODEL_FULL','A body model may have at most 100 boxes.');
            }
            if ($this->q('SELECT 1 FROM cabinet_body_box WHERE body_model_id=? AND "row"=? AND "column"=?',[$body,$row,$column])->fetchColumn()) {
                throw new Failure(409,'SLOT_EXISTS','That body position already has a box model.');
            }
            if ($this->q('SELECT 1 FROM cabinet_body_box WHERE body_model_id=? AND addr=?',[$body,$door])->fetchColumn()) {
                throw new Failure(409,'DOOR_EXISTS','That door address is already used by this body.');
            }
            $this->q('INSERT INTO cabinet_body_box(organization_id,body_model_id,box_model_id,"row","column",addr,create_time)
                VALUES (?,?,?,?,?,?,extract(epoch from now())::bigint)',[$this->org(),$body,$box,$row,$column,$door]);
            $this->audit($actor,'LOCKER_MODEL_SLOT_ADDED','locker_body_model',$body,$reason.' [row '.$row.', column '.$column.', door '.$door.']');
        });
    }

    public function updateSlot(string $actor,string $slotId,array $input): void
    {
        $this->authorize($actor); $slotId=self::id($slotId);
        Input::fields($input,['box_model_id','row','column','door_address','reason']);
        $box=self::id($input['box_model_id']); $row=self::number($input['row'],99);
        $column=self::number($input['column'],99); $door=self::address($input['door_address']);
        $reason=self::reason($input['reason']);
        (new Transaction($this->db))->run(function() use($actor,$slotId,$box,$row,$column,$door,$reason) {
            $slot=$this->q('SELECT s.body_model_id,b.status,b.legacy_model_id FROM cabinet_body_box s
                JOIN cabinet_body_model b ON b.model_id=s.body_model_id WHERE s.body_box_id=? AND s.organization_id=? FOR UPDATE OF b',
                [$slotId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$slot) { throw new Failure(404,'SLOT_NOT_FOUND','Layout position not found.'); }
            if ($slot['legacy_model_id']!==null) { throw new Failure(409,'MODEL_UNVERIFIED','Historical layouts are read-only.'); }
            if ($slot['status']!=='DRAFT') { throw new Failure(409,'MODEL_READY','A ready layout cannot be changed.'); }
            if (!$this->q('SELECT 1 FROM cabinet_box_model WHERE model_id=? AND organization_id=?',[$box,$this->org()])->fetchColumn()) {
                throw new Failure(404,'MODEL_NOT_FOUND','Box model not found.');
            }
            $body=$slot['body_model_id'];
            if ($this->q('SELECT 1 FROM cabinet_body_box WHERE body_model_id=? AND body_box_id<>? AND "row"=? AND "column"=?',
                [$body,$slotId,$row,$column])->fetchColumn()) { throw new Failure(409,'SLOT_EXISTS','That body position is already used.'); }
            if ($this->q('SELECT 1 FROM cabinet_body_box WHERE body_model_id=? AND body_box_id<>? AND addr=?',
                [$body,$slotId,$door])->fetchColumn()) { throw new Failure(409,'DOOR_EXISTS','That door address is already used.'); }
            $this->q('UPDATE cabinet_body_box SET box_model_id=?,"row"=?,"column"=?,addr=? WHERE body_box_id=?',
                [$box,$row,$column,$door,$slotId]);
            $this->audit($actor,'LOCKER_MODEL_SLOT_UPDATED','locker_body_model',(string)$body,$reason.' [slot '.$slotId.']');
        });
    }

    public function ready(string $actor,string $bodyId,string $reason): void
    {
        $this->authorize($actor); $bodyId=self::id($bodyId); $reason=self::reason($reason);
        (new Transaction($this->db))->run(function() use($actor,$bodyId,$reason) {
            $model=$this->q('SELECT status,legacy_model_id FROM cabinet_body_model WHERE model_id=? AND organization_id=? FOR UPDATE',[$bodyId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$model) { throw new Failure(404,'MODEL_NOT_FOUND','Body model not found.'); }
            if ($model['legacy_model_id']!==null) { throw new Failure(409,'MODEL_UNVERIFIED','Historical layouts cannot be activated.'); }
            if ($model['status']!=='DRAFT') { throw new Failure(409,'MODEL_READY','This layout is already ready.'); }
            if (!$this->q('SELECT 1 FROM cabinet_body_box WHERE body_model_id=? LIMIT 1',[$bodyId])->fetchColumn()) {
                throw new Failure(409,'MODEL_EMPTY','Add at least one box position.');
            }
            if ($this->q('SELECT 1 FROM cabinet_body_box WHERE body_model_id=? AND addr IS NULL LIMIT 1',[$bodyId])->fetchColumn()) {
                throw new Failure(409,'ADDRESS_REQUIRED','Every position needs an explicit door address.');
            }
            if ($this->q("SELECT 1 FROM cabinet_body_box s JOIN cabinet_box_model x ON x.model_id=s.box_model_id
                WHERE s.body_model_id=? AND (x.dimensions_source_unit<>'MM' OR x.width_mm IS NULL OR x.height_mm IS NULL
                OR x.depth_mm IS NULL OR x.max_weight_g IS NULL) LIMIT 1",[$bodyId])->fetchColumn()) {
                throw new Failure(409,'MODEL_UNVERIFIED','Verify and select local box models with millimeter dimensions before publishing.');
            }
            $this->q("UPDATE cabinet_body_model SET status='READY' WHERE model_id=?",[$bodyId]);
            $this->audit($actor,'LOCKER_BODY_MODEL_READY','locker_body_model',$bodyId,$reason);
        });
    }

    public function removeSlot(string $actor,string $slotId,string $reason): void
    {
        $this->authorize($actor); $slotId=self::id($slotId); $reason=self::reason($reason);
        (new Transaction($this->db))->run(function() use($actor,$slotId,$reason) {
            $slot=$this->q('SELECT s.body_model_id,b.status,b.legacy_model_id FROM cabinet_body_box s
                JOIN cabinet_body_model b ON b.model_id=s.body_model_id
                WHERE s.body_box_id=? AND s.organization_id=? FOR UPDATE OF b',[$slotId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$slot) { throw new Failure(404,'SLOT_NOT_FOUND','Layout position not found.'); }
            if ($slot['legacy_model_id']!==null) { throw new Failure(409,'MODEL_UNVERIFIED','Historical layouts are read-only.'); }
            if ($slot['status']!=='DRAFT') { throw new Failure(409,'MODEL_READY','A ready layout cannot be changed.'); }
            $this->q('DELETE FROM cabinet_body_box WHERE body_box_id=?',[$slotId]);
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

    /** Called inside the setup binding transaction; the caller owns the board and destination locks. */
    public function materializeBody(string $actor,string $lockerId,string $modelId,string $bodyCode,
        string $bodyName,int $position,string $boardId,string $reason): void
    {
        if (!$this->db->inTransaction()) { throw new LogicException('Setup binding requires one transaction.'); }
        $this->authorize($actor); self::id($lockerId); self::id($modelId); self::id($boardId);
        $this->requireInactiveLocker($lockerId,true);
        if (!$this->q('SELECT 1 FROM controller_boards WHERE id=? AND locker_id=?',[$boardId,$lockerId])->fetchColumn()) {
            throw new Failure(409,'BOARD_MISMATCH','Controller does not belong to this locker.');
        }
        $this->insertBody($actor,$lockerId,$modelId,$bodyCode,$position,$reason,$boardId,$bodyName);
    }

    private function insertBody(string $actor,string $lockerId,string $modelId,string $bodyCode,int $position,string $reason,
        ?string $boardId=null,?string $bodyName=null): void
    {
        $model=$this->q('SELECT status FROM cabinet_body_model WHERE model_id=? AND organization_id=? FOR SHARE',[$modelId,$this->org()])->fetch(PDO::FETCH_ASSOC);
        if (!$model) { throw new Failure(404,'MODEL_NOT_FOUND','Body model not found in this network.'); }
        if ($model['status']!=='READY') { throw new Failure(409,'MODEL_NOT_READY','Finish the body layout before using it.'); }
        $slots=$this->q('SELECT s."row" AS display_row,s."column" AS display_column,s.addr AS door_address,
            s.box_model_id,x.width_mm,x.height_mm,x.depth_mm,x.max_weight_g,x.dimensions_source_unit
            FROM cabinet_body_box s JOIN cabinet_box_model x ON x.model_id=s.box_model_id
            WHERE s.body_model_id=? AND s.organization_id=? ORDER BY s."row",s."column"',[$modelId,$this->org()])->fetchAll(PDO::FETCH_ASSOC);
        if (!$slots) { throw new Failure(409,'MODEL_EMPTY','Body model has no box positions.'); }
        foreach ($slots as $slot) {
            if ($slot['dimensions_source_unit']!=='MM' || min((int)$slot['width_mm'],(int)$slot['height_mm'],
                (int)$slot['depth_mm'],(int)$slot['max_weight_g'])<1) {
                throw new Failure(409,'MODEL_UNVERIFIED','Operational boxes need verified millimeter dimensions and weight limits.');
            }
        }
        if ($boardId!==null && in_array(null,array_column($slots,'door_address'),true)) {
            throw new Failure(409,'ADDRESS_REQUIRED','Every physical box needs an explicit door address.');
        }
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
        $body=(string)$this->q('INSERT INTO locker_body_modules(locker_id,code,display_sequence,body_model_id,display_name,controller_board_id)
            VALUES (?,?,?,?,?,?) RETURNING id',[$lockerId,$bodyCode,$position,$modelId,$bodyName,$boardId])->fetchColumn();
        $module=(string)$this->q('INSERT INTO locker_box_modules(locker_id,body_module_id,code,display_sequence)
            VALUES (?,?,?,1) RETURNING id',[$lockerId,$body,$moduleCode])->fetchColumn();
        foreach ($slots as $slot) {
            $this->q("INSERT INTO compartments(locker_id,code,width_mm,height_mm,depth_mm,max_weight_g,status,box_module_id,
                box_model_id,display_row,display_column,controller_board_id,door_address) VALUES (?,?,?,?,?,?,'FROZEN',?,?,?,?,?,?)",
                [$lockerId,$bodyCode.'-'.$slot['display_row'].'-'.$slot['display_column'],$slot['width_mm'],$slot['height_mm'],
                    $slot['depth_mm'],$slot['max_weight_g'],$module,$slot['box_model_id'],$slot['display_row'],$slot['display_column'],
                    $boardId,$boardId===null?null:$slot['door_address']]);
        }
        $this->audit($actor,'LOCKER_BODY_MODEL_INSTANTIATED','locker',$lockerId,$reason.' [body '.$body.', model '.$modelId.']');
    }
}
