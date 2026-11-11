<?php
$original = json_decode(file_get_contents(__DIR__.'/source.json'), true, 512, JSON_THROW_ON_ERROR);
$ids = implode(',', array_map(fn($p)=>(int)$p['id'], $original['products']));
$cfg = parse_ini_file('D:/erp/erp.sdjiantan.com/.env', true)['database'];
$pdo = new PDO("mysql:host={$cfg['hostname']};port={$cfg['hostport']};dbname={$cfg['database']};charset=utf8mb4", $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');
$pdo->beginTransaction();
$rows = $pdo->query("SELECT id,works_order_id,name,amount,price FROM jt_works_order_product WHERE id IN ($ids) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$workIds=implode(',',array_map(fn($o)=>(int)$o['id'],$original['orders']));
$freight=$pdo->query("SELECT w.id work_order_id,w.order_id,op.id order_product_id,op.name,op.amount,op.price,op.price*op.amount line_amount FROM jt_works_order w JOIN jt_order_product op ON op.order_id=w.order_id WHERE w.id IN ($workIds) AND op.name LIKE '%运费录入%' ORDER BY w.id,op.id")->fetchAll(PDO::FETCH_ASSOC);
$pdo->rollBack();
$map = array_column($rows,null,'id');
foreach ($original['products'] as $p) {
    $r=$map[$p['id']]??null;
    if (!$r || $r['works_order_id']!=$p['works_order_id'] || $r['name']!==$p['name'] || $r['amount']!=$p['amount']) throw new RuntimeException('工单产品已变化，停止补价。');
}
file_put_contents(__DIR__.'/prices.json',json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
file_put_contents(__DIR__.'/freight.json',json_encode($freight,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode(['products'=>count($rows),'missing_prices'=>count(array_filter($rows,fn($r)=>$r['price']===null)),'zero_prices'=>count(array_filter($rows,fn($r)=>$r['price']!==null && (float)$r['price']==0))]),PHP_EOL;
echo json_encode(['freight_lines'=>count($freight),'positive_freight_orders'=>count(array_unique(array_column(array_filter($freight,fn($r)=>(float)$r['line_amount']>0),'work_order_id')))]),PHP_EOL;
