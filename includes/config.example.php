<?php
declare(strict_types=1);
// Copia este archivo como config.php y completa los datos reales.
return [
    'app' => [
        'name' => 'Actividades Bueyes Morados',
        'url' => 'https://tigresazules63.com/bueyes-morados',
        'timezone' => 'America/Lima',
        'environment' => 'production',
    ],
    'database' => [
        'host' => 'localhost',
        'port' => '3306',
        'name' => 'NOMBRE_BD',
        'user' => 'USUARIO_BD',
        'password' => 'CLAVE_BD',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'session_name' => 'BUEYES_ADMIN',
        'session_timeout' => 3600,
    ],
];
