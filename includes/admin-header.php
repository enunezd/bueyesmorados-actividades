<?php
$me=require_admin();
$current=basename($_SERVER['SCRIPT_NAME']);
$nav=[
    'panel.php'=>'Inicio',
    'actividades.php'=>'Actividades',
    'estructura.php'=>'Comisiones y turnos',
    'inscripciones.php'=>'Inscripciones',
    'exportar.php'=>'Exportar CSV',
];
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?=e($title??'Administración')?> | Bueyes Morados</title>
<link rel="stylesheet" href="<?=e(url('assets/css/admin.css'))?>"></head>
<body>
<aside class="sidebar">
  <div class="brand">BM <span>Bueyes Morados</span></div>
  <nav>
    <?php foreach($nav as $file=>$label):?>
      <a href="<?=e(url('admin/'.$file))?>" class="<?=$current===$file?'active':''?>"><?=e($label)?></a>
    <?php endforeach;?>
    <a href="<?=e(url())?>" target="_blank">Ver página pública ↗</a>
  </nav>
  <div class="sidefoot"><small><?=e($me['nombre'])?></small><a href="<?=e(url('admin/cerrar-sesion.php'))?>">Cerrar sesión</a></div>
</aside>
<main class="content">
<header class="top">
  <button class="menu" onclick="document.body.classList.toggle('nav-open')">☰</button>
  <div><h1><?=e($title??'Administración')?></h1><p><?=e($subtitle??'')?></p></div>
</header>
<?php if($f=flash_get()):?><div class="alert <?=e($f[0])?>"><?=e($f[1])?></div><?php endif;?>
