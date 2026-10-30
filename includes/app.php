<?php
declare(strict_types=1);
require_once __DIR__.'/conexion.php';
$config=require __DIR__.'/config.php';
date_default_timezone_set($config['app']['timezone']??'America/Lima');
if(($config['app']['debug']??false)===true){ini_set('display_errors','1');error_reporting(E_ALL);}else{ini_set('display_errors','0');error_reporting(E_ALL);}
if(PHP_SAPI!=='cli'&&!headers_sent()){
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}

// Error cuyo texto se puede mostrar tal cual a la persona (validaciones, cupos llenos…).
class MensajeError extends RuntimeException{}

const DIAS_ES=['domingo','lunes','martes','miércoles','jueves','viernes','sábado'];
const MESES_ES=['','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

// ---- Utilidades generales -------------------------------------------------
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url(string $p=''):string{global $config;return rtrim($config['app']['url'],'/').($p!==''?'/'.ltrim($p,'/'):'');}
// URL de un archivo estático con la fecha de modificación, para que el navegador recargue tras cada cambio.
function asset(string $p):string{$f=__DIR__.'/../'.$p;return url($p).(is_file($f)?'?v='.filemtime($f):'');}
function go(string $p){header('Location: '.url($p));exit;}
function plural(int $n,string $uno,string $otros):string{return $n.' '.($n===1?$uno:$otros);}

// ---- Sesión, CSRF y avisos --------------------------------------------------
function session_start_safe():void{global $config;if(session_status()===PHP_SESSION_ACTIVE)return;session_name($config['security']['session_name']??'TA_ADMIN');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);session_start();}
function csrf():string{session_start_safe();return $_SESSION['csrf']??=bin2hex(random_bytes(32));}
function csrf_ok():bool{session_start_safe();return isset($_POST['csrf'],$_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],(string)$_POST['csrf']);}
function csrf_field():string{return '<input type="hidden" name="csrf" value="'.e(csrf()).'">';}
function flash(string $type,string $text):void{session_start_safe();$_SESSION['flash']=[$type,$text];}
function flash_get():?array{session_start_safe();$f=$_SESSION['flash']??null;unset($_SESSION['flash']);return $f;}
function admin():?array{session_start_safe();return $_SESSION['admin']??null;}
function require_admin():array{global $config;session_start_safe();$a=admin();if(!$a){flash('error','Inicia sesión para continuar.');go('admin/');}$max=(int)($config['security']['session_timeout']??3600);if(isset($_SESSION['last'])&&time()-$_SESSION['last']>$max){$_SESSION=[];session_destroy();session_start_safe();flash('error','La sesión expiró por inactividad.');go('admin/');}$_SESSION['last']=time();return $a;}

// ---- Estado de las actividades ----------------------------------------------
// [código, texto] según el modo de control y las fechas de apertura/cierre.
function activity_state(array $a):array{$now=new DateTimeImmutable();if($a['modo_control']==='forzar_abierto')return ['abierta','Abierta'];if($a['modo_control']==='forzar_cerrado')return ['cerrada','Cerrada'];$o=!empty($a['fecha_apertura'])?new DateTimeImmutable($a['fecha_apertura']):null;$c=!empty($a['fecha_cierre'])?new DateTimeImmutable($a['fecha_cierre']):null;if($o&&$now<$o)return ['proxima','Próximamente'];if($c&&$now>$c)return ['cerrada','Cerrada'];return ['abierta','Abierta'];}
// Verdadero cuando la inscripción se cerró sola por haber pasado la fecha de cierre.
function cierre_vencido(array $a):bool{return ($a['modo_control']??'')==='automatico'&&!empty($a['fecha_cierre'])&&new DateTimeImmutable()>new DateTimeImmutable($a['fecha_cierre']);}
// Estado de una actividad en lenguaje claro para el administrador: [clase css, texto].
function activity_summary(array $a):array{
    if($a['estado']==='archivada')return ['archived','Archivada'];
    if($a['estado']==='borrador')return ['draft','Borrador · aún no se ve en la página pública'];
    [$code]=activity_state($a);
    if($code==='abierta')return ['published','Publicada · inscripciones abiertas'];
    if($code==='proxima')return ['draft','Publicada · abre el '.fmt_dt($a['fecha_apertura'])];
    return ['archived','Publicada · inscripciones cerradas'];
}

// ---- Auditoría ---------------------------------------------------------------
function audit(?int $aid,string $action,string $entity,?int $id=null,string $detail=''):void{try{$s=db()->prepare('INSERT INTO auditoria(administrador_id,accion,entidad,entidad_id,detalle,ip) VALUES(?,?,?,?,?,?)');$s->execute([$aid,$action,$entity,$id,$detail,substr($_SERVER['REMOTE_ADDR']??'',0,45)]);}catch(Throwable $e){error_log($e->getMessage());}}

// ---- Fechas y textos en español ------------------------------------------------
function fmt_date(?string $d):string{return $d?date('d/m/Y',strtotime($d)):'Por definir';}
function fmt_dt(?string $d):string{return $d?date('d/m/Y H:i',strtotime($d)):'No definida';}
function hhmm(string $t):string{return substr($t,0,5);}
// "viernes 6 de noviembre"
function fecha_larga(?string $d):string{if(!$d)return 'Por definir';$t=strtotime($d);return DIAS_ES[(int)date('w',$t)].' '.date('j',$t).' de '.MESES_ES[(int)date('n',$t)];}
// "viernes 6 de noviembre, 10:00"
function dt_largo(?string $d):string{if(!$d)return 'Sin definir';return fecha_larga($d).', '.date('H:i',strtotime($d));}
// "8 días", "5 horas", "20 minutos" hasta la fecha dada.
function tiempo_restante(DateTimeImmutable $hasta):string{
    $s=$hasta->getTimestamp()-time();
    if($s<=0)return 'ya venció';
    if($s<3600)return plural(max(1,(int)ceil($s/60)),'minuto','minutos');
    if($s<86400)return plural((int)floor($s/3600),'hora','horas');
    $d=(int)floor($s/86400);$h=(int)floor(($s%86400)/3600);
    return $d<3&&$h>0?plural($d,'día','días').' y '.plural($h,'hora','horas'):plural($d,'día','días');
}
// Un valor de <input type="datetime-local"> a DATETIME de MySQL (o null si está vacío).
function dt_from_input(?string $v):?string{$v=trim((string)$v);return $v===''?null:str_replace('T',' ',$v).':00';}
function dt_to_input(?string $v):string{return $v?str_replace(' ','T',substr($v,0,16)):'';}
// Texto de ayuda contextual (se muestra bajo el campo).
function hint(string $text):string{return '<small class="hint">'.e($text).'</small>';}

// Texto en UTF-8 válido (si llegó en otra codificación se convierte en vez de fallar al guardar).
function utf8_limpio(string $s):string{return mb_check_encoding($s,'UTF-8')?$s:mb_convert_encoding($s,'UTF-8','Windows-1252');}

// ---- Teléfonos ------------------------------------------------------------------
function solo_digitos(string $s):string{return preg_replace('/\D+/','',$s)??'';}
function telefono_valido(string $s):bool{$n=strlen(solo_digitos($s));return $n>=7&&$n<=15;}
// Enlace de WhatsApp (Perú por defecto: un celular de 9 dígitos que empieza en 9). Null si el número no sirve.
function wa_url(?string $tel,string $mensaje):?string{
    $d=solo_digitos((string)$tel);
    if(strlen($d)===9&&$d[0]==='9')$d='51'.$d;
    if(strlen($d)<10||strlen($d)>15)return null;
    return 'https://wa.me/'.$d.'?text='.rawurlencode($mensaje);
}

// ---- Inscripciones: utilidades compartidas ------------------------------------------
function codigo_confirmacion(PDO $pdo):string{
    do{$c=strtoupper(substr(bin2hex(random_bytes(6)),0,10));$s=$pdo->prepare('SELECT 1 FROM inscripciones WHERE codigo_confirmacion=?');$s->execute([$c]);}while($s->fetchColumn());
    return $c;
}

require_once __DIR__.'/familias.php';
require_once __DIR__.'/actividades.php';
require_once __DIR__.'/asignacion.php';
