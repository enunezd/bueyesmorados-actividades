<?php
$me=require_admin();
$current=basename($_SERVER['SCRIPT_NAME']);
$nav=[
    'panel.php'=>'Inicio',
    'actividades.php'=>'Actividades',
    'familias.php'=>'Familias',
    'inscripciones.php'=>'Inscripciones',
    'exportar.php'=>'Exportar datos',
];
// Las pantallas de una actividad resaltan «Actividades» en el menú.
$grupo=['actividad.php'=>'actividades.php','estructura.php'=>'actividades.php','sorteo.php'=>'actividades.php'];
$activo=$grupo[$current]??$current;
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#101c44">
<title><?=e($title??'Administración')?> | Tigres Azules</title>
<link rel="icon" type="image/png" href="<?=e(asset('assets/img/favicon.png'))?>">
<link rel="stylesheet" href="<?=e(asset('assets/css/admin.css'))?>"></head>
<body>
<aside class="sidebar">
  <a class="brand" href="<?=e(url('admin/panel.php'))?>"><img src="<?=e(asset('assets/img/logo.png'))?>" width="44" height="44" alt=""><span><b>Tigres Azules</b><small>Promo 63 · Admin</small></span></a>
  <nav>
    <?php foreach($nav as $file=>$label):?>
      <a href="<?=e(url('admin/'.$file))?>" class="<?=$activo===$file?'active':''?>"><?=e($label)?></a>
    <?php endforeach;?>
    <a href="<?=e(url())?>" target="_blank" rel="noopener">Ver página pública ↗</a>
  </nav>
  <div class="sidefoot"><small>Sesión de <?=e($me['nombre'])?></small><a href="<?=e(url('admin/cerrar-sesion.php'))?>">Cerrar sesión</a></div>
</aside>
<main class="content">
<header class="top">
  <button class="menu" type="button" aria-label="Abrir menú" onclick="document.body.classList.toggle('nav-open')">☰</button>
  <div><h1><?=e($title??'Administración')?></h1><?php if(($subtitle??'')!==''):?><p><?=e($subtitle)?></p><?php endif;?></div>
</header>
<?php if($flash=flash_get()):?><div class="alert <?=e($flash[0])?>" role="status"><?=e($flash[1])?></div><?php endif;?>
