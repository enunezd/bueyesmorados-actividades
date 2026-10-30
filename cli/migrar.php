<?php
// Aplica las migraciones pendientes de la base de datos.  Uso: php cli/migrar.php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../includes/app.php';
require __DIR__.'/../includes/migraciones.php';
$aplicadas=migrar(db());
echo $aplicadas?"Migraciones aplicadas:\n - ".implode("\n - ",$aplicadas)."\n":"La base de datos ya está al día.\n";
