<?php
declare(strict_types=1);
function db(): PDO { static $pdo; if($pdo instanceof PDO)return $pdo; $c=require __DIR__.'/config.php'; $d=$c['database'];
 $dsn="mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset={$d['charset']}";
 return $pdo=new PDO($dsn,$d['user'],$d['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); }
