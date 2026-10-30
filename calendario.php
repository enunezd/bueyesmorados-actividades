<?php
// Descarga un evento .ics (calendario) con el turno de una inscripción.
require __DIR__.'/includes/app.php';
$q=db()->prepare("SELECT i.codigo_confirmacion,i.familia,a.nombre actividad,a.fecha_actividad,co.nombre comision,t.hora_inicio,t.hora_fin
  FROM inscripciones i JOIN actividades a ON a.id=i.actividad_id JOIN comisiones co ON co.id=i.comision_id JOIN turnos t ON t.id=i.turno_id
  WHERE i.codigo_confirmacion=? AND i.estado='confirmada'");
$q->execute([strtoupper(trim((string)($_GET['codigo']??'')))]);$i=$q->fetch();
if(!$i||!$i['fecha_actividad']){http_response_code(404);exit('Evento no disponible');}

$utc=new DateTimeZone('UTC');
$desde=(new DateTimeImmutable($i['fecha_actividad'].' '.$i['hora_inicio']))->setTimezone($utc)->format('Ymd\THis\Z');
$hasta=(new DateTimeImmutable($i['fecha_actividad'].' '.$i['hora_fin']))->setTimezone($utc)->format('Ymd\THis\Z');
$esc=fn(string $s)=>str_replace(["\\",";",",","\r\n","\n"],["\\\\","\\;","\\,","\\n","\\n"],$s);
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="tigres-azules-'.$i['codigo_confirmacion'].'.ics"');
echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Tigres Azules//Actividades//ES\r\nCALSCALE:GREGORIAN\r\nBEGIN:VEVENT\r\n";
echo 'UID:'.$i['codigo_confirmacion']."@tigresazules63.com\r\n";
echo 'DTSTAMP:'.gmdate('Ymd\THis\Z')."\r\nDTSTART:$desde\r\nDTEND:$hasta\r\n";
echo 'SUMMARY:'.$esc($i['actividad'].' · '.$i['comision'])."\r\n";
echo 'DESCRIPTION:'.$esc('Familia '.$i['familia'].'. Comisión '.$i['comision'].'. Código '.$i['codigo_confirmacion'])."\r\n";
echo "END:VEVENT\r\nEND:VCALENDAR\r\n";
