<?php
$source=json_decode(file_get_contents(__DIR__.'/source.json'),true,512,JSON_THROW_ON_ERROR);
$prepared=json_decode(file_get_contents(__DIR__.'/prepared.json'),true,512,JSON_THROW_ON_ERROR);
$ids=fn($rows,$key)=>implode(',',array_unique(array_map(fn($r)=>(int)$r[$key],$rows)));
$workIds=$ids($source['orders'],'id');
$productIds=$ids($source['products'],'id');
$logIds=$ids($prepared['details'],'logId');
$cfg=parse_ini_file('D:/erp/erp.sdjiantan.com/.env',true)['database'];
$pdo=new PDO("mysql:host={$cfg['hostname']};port={$cfg['hostport']};dbname={$cfg['database']};charset=utf8mb4",$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');$pdo->beginTransaction();
$q=fn($sql)=>$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$orders=$q("SELECT w.id,w.order_no,w.order_note,w.line_note,o.remark,o.remark_hanjie,o.remark_liucheng,o.remark_wuliu FROM jt_works_order w LEFT JOIN jt_order o ON o.id=w.order_id WHERE w.id IN ($workIds)");
$products=$q("SELECT id,name,remark FROM jt_works_order_product WHERE id IN ($productIds)");
$logs=$q("SELECT id,order_id,createtime,admin_id,message FROM jt_works_order_logs WHERE id IN ($logIds)");
$pdo->rollBack();
$om=array_column($orders,null,'id');$pm=array_column($products,null,'id');$lm=array_column($logs,null,'id');
foreach($source['orders'] as $o)if(($om[$o['id']]['order_no']??null)!==$o['order_no'])throw new RuntimeException('工单关联已变化');
foreach($source['products'] as $p)if(($pm[$p['id']]['name']??null)!==$p['name'])throw new RuntimeException('工单产品已变化');
foreach($source['logs'] as $l){if(!isset($lm[$l['id']]))continue;$r=$lm[$l['id']];if($r['order_id']!=$l['order_id']||$r['createtime']!=$l['createtime']||$r['admin_id']!==$l['admin_id'])throw new RuntimeException('报工记录已变化');}
if(count($logs)!==count(array_unique(array_column($prepared['details'],'logId'))))throw new RuntimeException('报工备注关联不完整');
file_put_contents(__DIR__.'/remarks.json',json_encode(compact('orders','products','logs'),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
$nonempty=fn($v)=>$v!==null && trim((string)$v)!=='';
echo json_encode(['orders'=>count($orders),'product_remarks'=>count(array_filter($products,fn($r)=>$nonempty($r['remark']))),'report_remarks'=>count(array_filter($logs,fn($r)=>$nonempty($r['message'])))]),PHP_EOL;
