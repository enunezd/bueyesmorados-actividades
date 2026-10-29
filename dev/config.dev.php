<?php
declare(strict_types=1);
// Solo para desarrollo local con Docker (sin datos reales).
return [
    'app' => ['name' => 'Bueyes Morados (dev)', 'url' => 'http://localhost:8088', 'timezone' => 'America/Lima', 'environment' => 'development', 'debug' => true],
    'database' => ['host' => 'db', 'port' => '3306', 'name' => 'bueyes', 'user' => 'bueyes', 'password' => 'bueyes', 'charset' => 'utf8mb4'],
    'security' => ['session_name' => 'BUEYES_ADMIN', 'session_timeout' => 3600],
];
