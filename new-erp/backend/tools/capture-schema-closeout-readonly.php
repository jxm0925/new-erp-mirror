<?php
declare(strict_types=1);

// Read-only evidence for the explicitly authorized baseline adoption.
// Does not boot the application, run migrations/seeders, or expose credentials.
$base=dirname(__DIR__);
require $base.'/vendor/autoload.php';
$pdo=null;
try {
    $args=[];
    foreach(array_slice($argv,1) as $arg){
        if(!preg_match('/^--(output|compare)=(.+)$/D',$arg,$match)) throw new RuntimeException('Unknown evidence argument.');
        $args[$match[1]]=$match[2];
    }
    if(empty($args['output'])||file_exists($args['output'])) throw new RuntimeException('A new evidence output path is required; refusing overwrite.');
    if(is_file($base.'/bootstrap/cache/config.php')||getenv('APP_CONFIG_CACHE')) throw new RuntimeException('Configuration cache requires review.');
    $values=Dotenv\Dotenv::createArrayBacked($base)->load();
    foreach(['DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','DB_URL','DB_SOCKET'] as $key){
        $override=getenv($key);
        if($override!==false&&(string)$override!==(string)($values[$key]??'')) throw new RuntimeException('Database environment override requires review.');
    }
    $appEnv=getenv('APP_ENV')?:($values['APP_ENV']??'');
    if(!preg_match('/^[A-Za-z0-9_-]*$/D',$appEnv)||($appEnv!==''&&is_file($base.'/.env.'.$appEnv))) throw new RuntimeException('Environment-specific configuration requires review.');
    $database=$values['DB_DATABASE']??'';
    $host=$values['DB_HOST']??'127.0.0.1';
    $port=(string)($values['DB_PORT']??'3306');
    if(($values['DB_CONNECTION']??'mysql')!=='mysql'||!empty($values['DB_URL'])||!empty($values['DB_SOCKET'])||!preg_match('/^[A-Za-z0-9_]+$/D',$database)||str_ends_with(strtolower($database),'_test')||!in_array($host,['127.0.0.1','localhost','::1'],true)||!ctype_digit($port)||(int)$port<1||(int)$port>65535) throw new RuntimeException('Expected local development database required.');
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_PERSISTENT=>false,PDO::ATTR_TIMEOUT=>5,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false];
    if(!empty($values['MYSQL_ATTR_SSL_CA']))$options[PDO::MYSQL_ATTR_SSL_CA]=$values['MYSQL_ATTR_SSL_CA'];
    $pdo=new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",$values['DB_USERNAME']??'root',$values['DB_PASSWORD']??'',$options);
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Database identity mismatch.');
    $report=['captured_at'=>gmdate(DATE_ATOM),'mode'=>'baseline_closeout_readonly','database'=>$database,'server_version'=>$pdo->query('SELECT VERSION()')->fetchColumn(),'environment_sha256'=>hash_file('sha256',$base.'/.env'),'tables'=>[],'migration_records'=>[]];
    $tables=$pdo->query('SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach($tables as $table=>$type){
        if($type!=='BASE TABLE')throw new RuntimeException('Unexpected non-base table requires review.');
        $quoted=chr(96).str_replace(chr(96),chr(96).chr(96),$table).chr(96);
        $sql=array_values($pdo->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_ASSOC))[1];
        $hashes=[];$targetBackup=[];
        $query=$pdo->query('SELECT * FROM '.$quoted);
        while($row=$query->fetch(PDO::FETCH_ASSOC)){
            $hashes[]=hash('sha256',serialize($row));
            if($table==='erp_production_material_consumptions')$targetBackup[]=$row;
        }
        $query->closeCursor();
        sort($hashes,SORT_STRING);
        $report['tables'][$table]=['create_sql'=>$sql,'row_count'=>count($hashes),'row_set_sha256'=>hash('sha256',implode("\n",$hashes))];
        if($table==='erp_production_material_consumptions')$report['affected_table_rows']=$targetBackup;
    }
    $report['migration_records']=$pdo->query('SELECT id,migration,batch FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $report['unique_duplicate_groups']=(int)$pdo->query('SELECT COUNT(*) FROM (SELECT output_record_id,source_holding_id FROM erp_production_material_consumptions WHERE output_record_id IS NOT NULL AND source_holding_id IS NOT NULL GROUP BY output_record_id,source_holding_id HAVING COUNT(*)>1) AS duplicate_groups')->fetchColumn();
    $pdo->rollBack();
    if(isset($args['compare'])){
        $before=json_decode(file_get_contents($args['compare']),true,512,JSON_THROW_ON_ERROR);
        if($before['database']!==$database)throw new RuntimeException('Comparison database identity differs.');
        $changed=[];$schemaChanged=[];
        foreach($before['tables'] as $table=>$old){
            if(!isset($report['tables'][$table])){$changed[]=$table;$schemaChanged[]=$table;continue;}
            $new=$report['tables'][$table];
            if($table!=='migrations'&&($old['row_count']!==$new['row_count']||$old['row_set_sha256']!==$new['row_set_sha256']))$changed[]=$table;
            $normalize=static fn(string $sql):string=>preg_replace('/\bAUTO_INCREMENT=\d+\b/','AUTO_INCREMENT=<counter>',$sql);
            if($normalize($old['create_sql'])!==$normalize($new['create_sql']))$schemaChanged[]=$table;
        }
        $afterLedger=array_column($report['migration_records'],null,'id');
        $legacyUnchanged=true;
        foreach($before['migration_records'] as $record)if(($afterLedger[$record['id']]??null)!==$record)$legacyUnchanged=false;
        $report['comparison']=['original_tables'=>count($before['tables']),'new_tables'=>array_values(array_diff(array_keys($report['tables']),array_keys($before['tables']))),'changed_business_row_sets'=>$changed,'changed_table_structures'=>$schemaChanged,'original_migration_records_unchanged'=>$legacyUnchanged,'environment_file_unchanged'=>$before['environment_sha256']===$report['environment_sha256']];
    }
    $out=fopen($args['output'],'x');
    if(!$out)throw new RuntimeException('Cannot exclusively create evidence file.');
    try{if(fwrite($out,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Evidence write failed.');}finally{fclose($out);}
    echo json_encode(['result'=>'CAPTURED','path'=>$args['output'],'tables'=>count($report['tables']),'migration_records'=>count($report['migration_records']),'affected_table_rows'=>count($report['affected_table_rows']),'duplicate_groups'=>$report['unique_duplicate_groups'],'comparison'=>$report['comparison']??null],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
}catch(Throwable $error){
    if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    echo json_encode(['result'=>'FAILED','class'=>get_class($error),'code'=>(string)$error->getCode(),'reason'=>get_class($error)===RuntimeException::class?$error->getMessage():'Read-only capture failed; connection details omitted.']).PHP_EOL;
    exit(1);
}
