<?php
// Sortea las actividades cuya inscripción ya se cerró y tienen la asignación automática activada.
// Pensado para una tarea programada (cron) cada pocos minutos; además las páginas del sitio lo
// intentan solas, así que funciona aunque no se configure el cron.
//   php cli/asignar-pendientes.php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../includes/app.php';
$n=asig_ejecutar_pendientes();
echo date('Y-m-d H:i:s').' · '.($n?"se sortearon $n actividad(es)":'nada pendiente')."\n";
