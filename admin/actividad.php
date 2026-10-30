<?php
require __DIR__.'/../includes/app.php';
require __DIR__.'/../includes/admin-actividad.php';
$me=require_admin();
$pdo=db();
asig_ejecutar_pendientes($pdo);
$a=act_actual($pdo,(int)($_GET['id']??0));
$id=(int)$a['id'];

[$cls,$txt]=activity_summary($a);
$familias=act_familias_activas($pdo);
$pasos=act_pasos($a,$familias);
$sig=$pasos['siguiente'];
$lugares=(int)$a['lugares'];$libres=(int)$a['libres'];$ocupados=max(0,$lugares-$libres);
$pct=$lugares>0?(int)round($ocupados*100/$lugares):0;
$publica=url('actividad.php?id='.$id);
[$estado]=activity_state($a);

$title=$a['nombre'];
$subtitle=$txt;
require __DIR__.'/../includes/admin-header.php';
act_tabs($a,'resumen');
$volver=e('admin/actividad.php?id='.$id);
?>

<?php if($sig&&$a['estado']!=='archivada'):?>
  <section class="siguiente"><div><span>Siguiente paso</span><b><?=e($sig['titulo'])?>: <?=e($sig['detalle'])?></b></div>
    <?php if($sig['clave']==='publicada'):?>
      <form method="post" action="<?=e(url('admin/actividades.php'))?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="volver" value="<?=$volver?>"><button class="btn acc" name="accion" value="publicar">Publicar ahora</button></form>
    <?php else:?><a class="btn acc" href="<?=e($sig['url'])?>"><?=e($sig['cta'])?> →</a><?php endif;?>
  </section>
<?php elseif($a['estado']==='archivada'):?>
  <section class="siguiente"><div><b>Esta actividad está archivada</b><span>No se ve en la página pública. Puedes restaurarla desde la lista de actividades.</span></div></section>
<?php else:?>
  <section class="siguiente listo"><div><b>Todo listo ✔</b><span><?=$estado==='abierta'?'Las familias ya pueden inscribirse. Comparte el enlace.':e(act_ventana($a)).'.'?></span></div>
    <a class="btn acc" href="<?=e($publica)?>" target="_blank" rel="noopener">Ver como familia ↗</a></section>
<?php endif;?>

<div class="twocol">
  <section class="panel">
    <div class="panelhead"><h2>Preparación</h2><span class="muted"><?=count(array_filter($pasos['items'],fn($i)=>$i['ok']))?> de <?=count($pasos['items'])?> listos</span></div>
    <ul class="checklist">
    <?php foreach($pasos['items'] as $it):$cls2=$it['ok']?'ok':($it['req']?'pend':'');?>
      <li class="<?=$cls2?>">
        <span class="marca"><?=$it['ok']?'✓':($it['req']?'!':'·')?></span>
        <span><b><?=e($it['titulo'])?><?=$it['req']?'':' <small>(recomendado)</small>'?></b><small><?=e($it['detalle'])?></small></span>
        <?php if(!$it['ok']||$it['clave']==='comisiones'||$it['clave']==='turnos'):?>
          <?php if($it['clave']==='publicada'&&!$it['ok']):?>
            <form method="post" action="<?=e(url('admin/actividades.php'))?>"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="volver" value="<?=$volver?>"><button class="btn small ghost" name="accion" value="publicar">Publicar</button></form>
          <?php else:?><a class="btn small ghost" href="<?=e($it['url'])?>"><?=$it['ok']?'Ver':e($it['cta'])?></a><?php endif;?>
        <?php else:?><span></span><?php endif;?>
      </li>
    <?php endforeach;?>
    </ul>
  </section>

  <section class="panel">
    <div class="panelhead"><h2>En números</h2></div>
    <?php if($lugares>0):?>
      <div class="progress" aria-label="Lugares ocupados"><i style="width:<?=$pct?>%"></i></div>
      <dl class="kv">
        <dt>Lugares ocupados</dt><dd><?=$ocupados?> de <?=$lugares?> (<?=$pct?>%)</dd>
        <dt>Lugares libres</dt><dd><?=$libres?></dd>
        <dt>Inscripciones confirmadas</dt><dd><?=(int)$a['inscritos']?><?=$a['automaticas']>0?' <small class="muted">('.(int)$a['automaticas'].' automáticas)</small>':''?></dd>
        <?php if($familias>0):?><dt>Familias sin inscribir</dt><dd><?=(int)$a['sin_inscribir']?> de <?=$familias?></dd><?php endif;?>
      </dl>
    <?php else:?>
      <p class="muted">Cuando definas comisiones, turnos y cupos verás aquí cuántos lugares hay y cuántos se ocuparon.</p>
    <?php endif;?>

    <h3 style="margin-top:18px">Enlace para compartir</h3>
    <div class="copiar"><input type="text" id="enlace" value="<?=e($publica)?>" readonly aria-label="Enlace público de la actividad"><button type="button" class="btn small" data-copy="#enlace">Copiar</button></div>
    <?=hint($a['estado']==='publicada'?'Envíalo por WhatsApp a las familias.':'Todavía es borrador: las familias no podrán abrirlo hasta que la publiques.')?>
  </section>
</div>

<section class="panel">
  <div class="panelhead"><h2>Calendario</h2><a class="btn small ghost" href="<?=e(url('admin/actividades.php?editar='.$id))?>">Cambiar fechas</a></div>
  <?php
    $ahora=new DateTimeImmutable();
    $hitos=[];
    if($a['modo_control']==='automatico'){
        $hitos[]=['Abre',$a['fecha_apertura']?dt_largo($a['fecha_apertura']):'Ya está abierta',$a['fecha_apertura']&&new DateTimeImmutable($a['fecha_apertura'])<$ahora];
        $hitos[]=['Cierra',$a['fecha_cierre']?dt_largo($a['fecha_cierre']):'Sin fecha de cierre',cierre_vencido($a)];
    }else{
        $hitos[]=['Inscripciones',$a['modo_control']==='forzar_abierto'?'Abiertas hasta que las cierres':'Cerradas manualmente',false];
    }
    if(!empty($a['asignacion_auto']))$hitos[]=['Asignación automática',!empty($a['asignacion_ejecutada_en'])?'Hecha el '.dt_largo($a['asignacion_ejecutada_en']):($a['modo_control']==='automatico'&&$a['fecha_cierre']?'Al cerrar: '.dt_largo($a['fecha_cierre']):'Activada (falta fecha de cierre)'),!empty($a['asignacion_ejecutada_en'])];
    $hitos[]=['Día de la actividad',$a['fecha_actividad']?fecha_larga($a['fecha_actividad']):'Sin fecha',$a['fecha_actividad']&&strtotime($a['fecha_actividad'].' 23:59')<time()];
  ?>
  <div class="linea"><?php foreach($hitos as [$k,$v,$pasado]):?><div class="<?=$pasado?'pasado':''?>"><small><?=e($k)?></small><b><?=e($v)?></b></div><?php endforeach;?></div>
</section>
<?php require __DIR__.'/../includes/admin-footer.php';
