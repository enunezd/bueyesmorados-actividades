<?php
// Búsqueda de familias para el selector (JSON). Solo devuelve nombre y secciones; nunca teléfonos.
require __DIR__.'/includes/app.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_start_safe();

// Freno sencillo contra descargas masivas de la lista: 90 búsquedas por minuto por sesión.
$ahora=time();
$_SESSION['busquedas']=array_values(array_filter($_SESSION['busquedas']??[],fn($t)=>$t>$ahora-60));
if(count($_SESSION['busquedas'])>=90){http_response_code(429);echo '[]';exit;}
$_SESSION['busquedas'][]=$ahora;

$q=trim((string)($_GET['q']??''));
if(mb_strlen($q)<2){echo '[]';exit;}
$res=fam_buscar(db(),mb_substr($q,0,60),8,(int)($_GET['actividad']??0));
echo json_encode(array_map(fn($f)=>['id'=>(int)$f['id'],'nombre'=>$f['nombre'],'secciones'=>$f['secciones'],'ya'=>(bool)$f['ya']],$res),JSON_UNESCAPED_UNICODE);
