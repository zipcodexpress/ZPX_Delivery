<?php
declare(strict_types=1);

// One-time, local-only import of a sanitized DBeaver CSV (kind,payload JSON).
// The source MySQL connection is read-only; no source password is needed here.
if ($argc !== 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php scripts/import_legacy_cabinets.php /private/tmp/sanitized.csv\n");
    exit(2);
}
$config=[];
foreach (file(__DIR__.'/../.env.dev',FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if ($line==='' || $line[0]==='#' || !str_contains($line,'=')) { continue; }
    [$key,$value]=explode('=',$line,2);
    $config[$key]=trim($value,"\"'");
}
if (($config['DB_NAME']??'')!=='zpx_delivery_dev') {
    throw new RuntimeException('The local development database configuration is required.');
}
$expected=['box_model'=>16,'body_model'=>17,'body_box'=>202,'cabinet'=>101,'body'=>335,'box'=>6028];
$ids=['box_model'=>'model_id','body_model'=>'model_id','body_box'=>'body_box_id',
    'cabinet'=>'cabinet_id','body'=>'body_id','box'=>'box_id'];
$rows=array_fill_keys(array_keys($expected),[]);
$handle=fopen($argv[1],'rb');
if ($handle===false || fgetcsv($handle,0,',','"','')!==['kind','payload']) { throw new RuntimeException('Expected sanitized kind,payload CSV.'); }
while (($entry=fgetcsv($handle,0,',','"',''))!==false) {
    if (count($entry)!==2 || !isset($rows[$entry[0]])) { throw new RuntimeException('Unexpected export row.'); }
    $row=json_decode($entry[1],true,512,JSON_THROW_ON_ERROR);
    $id=$row[$ids[$entry[0]]]??null;
    if (!is_int($id) || $id<1 || isset($rows[$entry[0]][$id])
        || array_intersect(['api_key','api_secret','password','token'],array_keys($row))) {
        throw new RuntimeException('Duplicate/invalid ID or credential in export.');
    }
    $rows[$entry[0]][$id]=$row;
}
fclose($handle);
foreach ($expected as $kind=>$count) {
    if (count($rows[$kind])!==$count) { throw new RuntimeException("Unexpected $kind count; source changed or export incomplete."); }
}
foreach ($rows['body_box'] as $r) {
    if (!isset($rows['body_model'][$r['body_model_id']],$rows['box_model'][$r['box_model_id']])) {
        throw new RuntimeException('Layout references an absent model.');
    }
}
foreach ($rows['body'] as $r) {
    if (!isset($rows['cabinet'][$r['cabinet_id']],$rows['body_model'][$r['body_model_id']])) {
        throw new RuntimeException('Body references an absent cabinet or model.');
    }
}
foreach ($rows['box'] as $r) {
    if (!isset($rows['cabinet'][$r['cabinet_id']],$rows['body'][$r['body_id']],$rows['box_model'][$r['box_model_id']])
        || $rows['body'][$r['body_id']]['cabinet_id']!==$r['cabinet_id']) {
        throw new RuntimeException('Box references an absent or mismatched parent.');
    }
}

$db=new PDO('pgsql:host=127.0.0.1;port=5432;dbname=zpx_delivery_dev',
    $config['DB_USER'],$config['DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->beginTransaction();
try {
    $db->exec('SET LOCAL lock_timeout = \'5s\'');
    $org=(int)$db->query('SELECT min(id) FROM organizations')->fetchColumn();
    if ($org<1 || (int)$db->query('SELECT count(*) FROM organizations')->fetchColumn()!==1) {
        throw new RuntimeException('Import requires exactly one local development organization.');
    }
    if ((int)$db->query('SELECT count(*) FROM cabinet WHERE legacy_cabinet_id IS NOT NULL')->fetchColumn()!==0) {
        throw new RuntimeException('Historical cabinets are already imported.');
    }
    $insert=static function(string $sql,array $values) use($db): int {
        $q=$db->prepare($sql); $q->execute($values); return (int)$q->fetchColumn();
    };
    $map=['box_model'=>[],'body_model'=>[],'cabinet'=>[],'body'=>[]];
    $size=['small'=>'SMALL','middle'=>'MEDIUM','large'=>'LARGE','x-large'=>'XLARGE'];
    foreach ($rows['box_model'] as $id=>$r) {
        if (!isset($size[$r['size_cat']])) { throw new RuntimeException('Unknown legacy box size.'); }
        $map['box_model'][$id]=$insert('INSERT INTO cabinet_box_model
            (organization_id,code,version,model_name,size_class,is_allocable,legacy_size_category,
             legacy_price_raw,dimensions_source_unit,legacy_model_id,size_cat,length,width,height,model_price)
            VALUES (?,?,1,?,?,?,?,?,\'UNVERIFIED\',?,?,?,?,?,?) RETURNING model_id',
            [$org,'LEGACY-BOX-'.$id,$r['model_name'],$size[$r['size_cat']],(int)$r['is_allocable'],
             $r['size_cat'],(string)$r['model_price'],$id,$r['size_cat'],$r['length'],$r['width'],$r['height'],$r['model_price']]);
    }
    foreach ($rows['body_model'] as $id=>$r) {
        $map['body_model'][$id]=$insert('INSERT INTO cabinet_body_model
            (organization_id,code,version,model_name,status,legacy_model_id)
            VALUES (?,?,1,?,\'DRAFT\',?) RETURNING model_id',
            [$org,'LEGACY-BODY-'.$id,$r['model_name'],$id]);
    }
    foreach ($rows['body_box'] as $id=>$r) {
        $insert('INSERT INTO cabinet_body_box
            (organization_id,body_model_id,box_model_id,"row","column",addr,legacy_body_box_id,create_time)
            VALUES (?,?,?,?,?,?,?,?) RETURNING body_box_id',
            [$org,$map['body_model'][$r['body_model_id']],$map['box_model'][$r['box_model_id']],
             $r['row'],$r['column'],$r['addr'],$id,$r['create_time']]);
    }
    foreach ($rows['cabinet'] as $id=>$r) {
        $map['cabinet'][$id]=$insert('INSERT INTO cabinet
            (organization_id,cabinet_name,status,legacy_cabinet_id,state,city,address,zipcode,
             latitude,longitude,service_type,create_time,address_url)
            VALUES (?,?,\'REFERENCE\',?,?,?,?,?,?,?,?,?,?) RETURNING cabinet_id',
            [$org,'Legacy cabinet '.$id,$id,$r['state'],$r['city'],$r['address'],$r['zipcode'],
             $r['latitude'],$r['longitude'],$r['service_type'],$r['create_time'],$r['address_url']]);
    }
    foreach ($rows['body'] as $id=>$r) {
        if (!ctype_digit((string)$r['sequence'])) { throw new RuntimeException('Non-numeric legacy body sequence.'); }
        $map['body'][$id]=$insert('INSERT INTO cabinet_body
            (cabinet_id,body_model_id,body_name,direction,sequence,display_sequence,addr,protocol_profile,legacy_body_id)
            VALUES (?,?,?,?,?,?,?,\'UNVERIFIED\',?) RETURNING body_id',
            [$map['cabinet'][$r['cabinet_id']],$map['body_model'][$r['body_model_id']],
             $r['body_name'],$r['direction'],$r['sequence'],(int)$r['sequence'],$r['addr'],$id]);
    }
    foreach ($rows['box'] as $id=>$r) {
        $insert('INSERT INTO cabinet_box
            (cabinet_id,body_id,box_model_id,"row","column",addr,status,blocked,create_time,update_time,legacy_box_id)
            VALUES (?,?,?,?,?,?,?,?,to_timestamp(?),to_timestamp(?),?) RETURNING box_id',
            [$map['cabinet'][$r['cabinet_id']],$map['body'][$r['body_id']],
             $map['box_model'][$r['box_model_id']],$r['row'],$r['column'],$r['addr'],
             $r['status'],$r['blocked'],$r['create_time'],$r['update_time'],$id]);
    }
    $count=(int)$db->query("SELECT count(*) FROM cabinet_box x JOIN cabinet c ON c.cabinet_id=x.cabinet_id
        WHERE c.status='REFERENCE' AND x.compartment_id IS NULL AND x.legacy_box_id IS NOT NULL")->fetchColumn();
    if ($count!==$expected['box']) { throw new RuntimeException('Imported box reconciliation failed.'); }
    $db->commit();
    echo 'Imported inactive legacy reference: '.json_encode($expected,JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}
