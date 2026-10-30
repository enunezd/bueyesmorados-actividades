<?php
declare(strict_types=1);

// Conexión única a la base de datos. La sesión de MySQL usa la hora local de la
// app (Lima), así CURRENT_TIMESTAMP guarda la misma hora que ven las personas.
function db(): PDO {
    static $pdo;
    if($pdo instanceof PDO)return $pdo;
    $c=require __DIR__.'/config.php';
    $d=$c['database'];
    $offset=(new DateTimeImmutable('now',new DateTimeZone($c['app']['timezone']??'America/Lima')))->format('P');
    $dsn="mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset={$d['charset']}";
    return $pdo=new PDO($dsn,$d['user'],$d['password'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::MYSQL_ATTR_INIT_COMMAND=>"SET time_zone='$offset'",
    ]);
}
