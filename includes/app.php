<?php
declare(strict_types=1);
require_once __DIR__.'/conexion.php';
$config=require __DIR__.'/config.php'; date_default_timezone_set($config['app']['timezone']??'America/Lima');
if(($config['app']['debug']??false)===true){ini_set('display_errors','1');error_reporting(E_ALL);}else{ini_set('display_errors','0');error_reporting(E_ALL);}
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url(string $p=''):string{global $config;return rtrim($config['app']['url'],'/').($p!==''?'/'.ltrim($p,'/'):'');}
function go(string $p):never{header('Location: '.url($p));exit;}
function session_start_safe():void{global $config;if(session_status()===PHP_SESSION_ACTIVE)return;session_name($config['security']['session_name']??'BUEYES_ADMIN');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);session_start();}
function csrf():string{session_start_safe();return $_SESSION['csrf']??=bin2hex(random_bytes(32));}
function csrf_ok():bool{session_start_safe();return isset($_POST['csrf'],$_SESSION['csrf'])&&hash_equals($_SESSION['csrf'],(string)$_POST['csrf']);}
function flash(string $type,string $text):void{session_start_safe();$_SESSION['flash']=[$type,$text];}
function flash_get():?array{session_start_safe();$f=$_SESSION['flash']??null;unset($_SESSION['flash']);return $f;}
function admin():?array{session_start_safe();return $_SESSION['admin']??null;}
function require_admin():array{global $config;session_start_safe();$a=admin();if(!$a){flash('error','Inicia sesión para continuar.');go('admin/');}$max=(int)($config['security']['session_timeout']??3600);if(isset($_SESSION['last'])&&time()-$_SESSION['last']>$max){$_SESSION=[];session_destroy();session_start_safe();flash('error','La sesión expiró por inactividad.');go('admin/');}$_SESSION['last']=time();return $a;}
function activity_state(array $a):array{$now=new DateTimeImmutable();if($a['modo_control']==='forzar_abierto')return ['abierta','Abierta'];if($a['modo_control']==='forzar_cerrado')return ['cerrada','Cerrada'];$o=!empty($a['fecha_apertura'])?new DateTimeImmutable($a['fecha_apertura']):null;$c=!empty($a['fecha_cierre'])?new DateTimeImmutable($a['fecha_cierre']):null;if($o&&$now<$o)return ['proxima','Próximamente'];if($c&&$now>$c)return ['cerrada','Cerrada'];return ['abierta','Abierta'];}
function audit(?int $aid,string $action,string $entity,?int $id=null,string $detail=''):void{try{$s=db()->prepare('INSERT INTO auditoria(administrador_id,accion,entidad,entidad_id,detalle,ip) VALUES(?,?,?,?,?,?)');$s->execute([$aid,$action,$entity,$id,$detail,substr($_SERVER['REMOTE_ADDR']??'',0,45)]);}catch(Throwable $e){error_log($e->getMessage());}}
function fmt_date(?string $d):string{return $d?date('d/m/Y',strtotime($d)):'Por definir';}
function fmt_dt(?string $d):string{return $d?date('d/m/Y H:i',strtotime($d)):'No definida';}

function hhmm(string $t):string{return substr($t,0,5);}

// Estado de una actividad en lenguaje claro para el administrador: [clase css, texto].
function activity_summary(array $a):array{
    if($a['estado']==='archivada')return ['archived','Archivada'];
    if($a['estado']==='borrador')return ['draft','Borrador · aún no se ve en la página pública'];
    [$code]=activity_state($a);
    if($code==='abierta')return ['published','Publicada · inscripciones abiertas'];
    if($code==='proxima')return ['draft','Publicada · abre el '.fmt_dt($a['fecha_apertura'])];
    return ['archived','Publicada · inscripciones cerradas'];
}

// Un valor de <input type="datetime-local"> a DATETIME de MySQL (o null si está vacío).
function dt_from_input(?string $v):?string{$v=trim((string)$v);return $v===''?null:str_replace('T',' ',$v).':00';}
function dt_to_input(?string $v):string{return $v?str_replace(' ','T',substr($v,0,16)):'';}

// Texto de ayuda contextual (se muestra bajo el campo).
function hint(string $text):string{return '<small class="hint">'.e($text).'</small>';}
