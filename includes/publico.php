<?php
// Diseño común de las páginas públicas (lo que ven las familias).

function pub_inicio(string $titulo,string $encabezado,string $sub='',array $o=[]):void{
    $volver=$o['volver']??null; // [url, texto]
    ?><!doctype html>
<html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#415CA1">
<?php if(!empty($o['privada'])):?><meta name="robots" content="noindex,nofollow"><?php endif;?>
<title><?=e($titulo)?> | Tigres Azules</title>
<link rel="icon" type="image/png" href="<?=e(asset('assets/img/favicon.png'))?>">
<link rel="apple-touch-icon" href="<?=e(asset('assets/img/logo.png'))?>">
<link rel="stylesheet" href="<?=e(asset('assets/css/publico.css'))?>">
</head><body>
<header class="hero"><div class="hero-in">
  <div class="hero-top">
    <a class="marca" href="<?=e(url())?>"><img src="<?=e(asset('assets/img/logo.png'))?>" width="48" height="48" alt=""><span><b>Tigres Azules</b><small>Promo 63 · Actividades</small></span></a>
    <nav class="menu-pub" aria-label="Principal"><a href="<?=e(url())?>">Actividades</a><a href="<?=e(url('mi-turno.php'))?>">Consultar mi turno</a></nav>
  </div>
  <?php if($volver):?><a class="volver" href="<?=e($volver[0])?>">← <?=e($volver[1])?></a><?php endif;?>
  <h1><?=e($encabezado)?></h1>
  <?php if($sub!==''):?><p><?=$sub?></p><?php endif;?>
</div></header>
<main class="wrap">
<?php }

function pub_fin():void{ ?>
</main>
<footer class="footer">
  <img src="<?=e(asset('assets/img/favicon.png'))?>" width="28" height="28" alt="">
  <div><b>Tigres Azules · Promo 63</b><br><small>Colegio Peruano Chino Juan XXIII</small></div>
</footer>
<script src="<?=e(asset('assets/js/publico.js'))?>"></script>
</body></html>
<?php }

// Página de aviso o error con el mismo diseño. Termina la ejecución.
function pub_error(string $titulo,string $mensaje,int $codigo=404,?array $enlace=null){
    http_response_code($codigo);
    pub_inicio($titulo,$titulo,'',['volver'=>[url(),'Ver actividades']]);
    ?><section class="card pad centrado"><p class="lead"><?=e($mensaje)?></p>
    <?php if($enlace):?><p><a class="btn" href="<?=e($enlace[0])?>"><?=e($enlace[1])?></a></p><?php endif;?></section><?php
    pub_fin();
    exit;
}

// Puntos que muestran cuántos lugares están ocupados (o una barra si son muchos).
function pub_pips(int $cap,int $usados):string{
    $usados=min($usados,$cap);
    if($cap>10){$pct=$cap>0?round($usados*100/$cap):0;return '<span class="barra" aria-hidden="true"><i style="width:'.$pct.'%"></i></span>';}
    $h='<span class="pips" aria-hidden="true">';
    for($i=0;$i<$cap;$i++)$h.='<i'.($i<$usados?' class="on"':'').'></i>';
    return $h.'</span>';
}

// "Quedan 2 lugares" / "Queda 1 lugar" / "Completo"
function pub_quedan(int $libres):string{return $libres<=0?'Completo':($libres===1?'Queda 1 lugar':"Quedan $libres lugares");}
