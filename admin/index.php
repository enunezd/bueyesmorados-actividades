<?php
require __DIR__.'/../includes/app.php';
session_start_safe();
if(admin())go('admin/panel.php');
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_ok())$error='El formulario venció. Inténtalo de nuevo.';
    else{
        $pdo=db();
        $q=$pdo->prepare('SELECT * FROM administradores WHERE usuario=? AND activo=1 LIMIT 1');
        $q->execute([strtolower(trim($_POST['usuario']??''))]);$a=$q->fetch();
        if($a&&$a['bloqueado_hasta']&&strtotime($a['bloqueado_hasta'])>time()){
            $error='Demasiados intentos fallidos. Espera '.tiempo_restante(new DateTimeImmutable($a['bloqueado_hasta'])).' e inténtalo de nuevo.';
        }elseif($a&&password_verify($_POST['clave']??'',$a['clave_hash'])){
            session_regenerate_id(true);
            $_SESSION['admin']=['id'=>(int)$a['id'],'nombre'=>$a['nombre'],'usuario'=>$a['usuario']];
            $_SESSION['last']=time();
            $pdo->prepare('UPDATE administradores SET intentos_fallidos=0,bloqueado_hasta=NULL,ultimo_acceso=? WHERE id=?')->execute([date('Y-m-d H:i:s'),$a['id']]);
            audit((int)$a['id'],'inicio_sesion','administradores',(int)$a['id']);
            go('admin/panel.php');
        }else{
            // 5 errores seguidos bloquean la cuenta 10 minutos (frena adivinar contraseñas).
            if($a){
                $n=(int)$a['intentos_fallidos']+1;
                $pdo->prepare('UPDATE administradores SET intentos_fallidos=?,bloqueado_hasta=? WHERE id=?')->execute([$n>=5?0:$n,$n>=5?date('Y-m-d H:i:s',time()+600):null,$a['id']]);
            }
            $error='Usuario o contraseña incorrectos.';
        }
    }
}
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#101c44"><title>Administración | Tigres Azules</title>
<link rel="icon" type="image/png" href="<?=e(asset('assets/img/favicon.png'))?>">
<style>
:root{--p:#415CA1;--po:#2B4693;--pd:#101c44;--acc:#ECE15D}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;font-family:"Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;background:linear-gradient(135deg,var(--pd),var(--po) 60%,var(--p));color:#1c2438}
.login{width:100%;max-width:420px;background:#fff;padding:32px 30px;border-radius:20px;box-shadow:0 24px 60px #0007;border-top:5px solid var(--acc)}
.logo{display:block;margin:-62px auto 12px;border-radius:50%;background:#fff;box-shadow:0 8px 24px #101c4455}
h1{color:var(--pd);margin:0 0 4px;font-size:24px;text-align:center}
.sub{margin:0 0 20px;text-align:center;color:#5d6985}
.field{margin:14px 0}
.field label{display:block;font-weight:700;margin-bottom:6px}
.field input{width:100%;padding:13px;border:1.5px solid #c3cde3;border-radius:10px;font-size:16px;font-family:inherit}
.field input:focus{outline:3px solid var(--acc);border-color:var(--p)}
.btn{width:100%;padding:13px;border:0;border-radius:10px;background:var(--p);color:#fff;font-weight:700;font-size:16px;cursor:pointer;font-family:inherit}
.btn:hover{background:var(--po)}
.error{background:#fdeaea;color:#a43333;padding:12px;border-radius:10px;margin-bottom:6px}
.volver{display:block;text-align:center;margin-top:18px;color:var(--p)}
</style></head><body>
<main class="login">
  <img class="logo" src="<?=e(asset('assets/img/logo.png'))?>" width="96" height="96" alt="Tigres Azules · Promo 63">
  <h1>Administración</h1>
  <p class="sub">Tigres Azules · Promo 63</p>
  <?php if($f=flash_get()):?><div class="error"><?=e($f[1])?></div><?php endif;?>
  <?php if($error):?><div class="error" role="alert"><?=e($error)?></div><?php endif;?>
  <form method="post"><?=csrf_field()?>
    <div class="field"><label for="u">Usuario</label><input id="u" name="usuario" required autofocus autocomplete="username"></div>
    <div class="field"><label for="c">Contraseña</label><input id="c" type="password" name="clave" required autocomplete="current-password"></div>
    <button class="btn">Ingresar</button>
  </form>
  <a class="volver" href="<?=e(url())?>">← Volver a la página pública</a>
</main></body></html>
