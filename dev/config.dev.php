<?php
declare(strict_types=1);
// Solo para desarrollo local con Docker (sin datos reales).
return [
    'app' => ['name' => 'Tigres Azules (dev)', 'url' => 'http://localhost:8088', 'timezone' => 'America/Lima', 'environment' => 'development', 'debug' => true],
    'database' => ['host' => 'db', 'port' => '3306', 'name' => 'tigres', 'user' => 'tigres', 'password' => 'tigres', 'charset' => 'utf8mb4'],
    'security' => ['session_name' => 'TA_ADMIN', 'session_timeout' => 3600],
];
