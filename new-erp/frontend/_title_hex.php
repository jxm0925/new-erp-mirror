<?php
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=jiantan_erp;charset=utf8mb4', 'root', '123456');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$row = $pdo->query("SELECT title FROM jt_auth_rule WHERE name='sso/index'")->fetch(PDO::FETCH_ASSOC);
echo $row['title'], PHP_EOL;
echo bin2hex($row['title']), PHP_EOL;
