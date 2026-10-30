<?php
require __DIR__.'/includes/app.php';
require __DIR__.'/includes/publico.php';
asig_ejecutar_pendientes();
$pdo=db();

$filas=act_lista($pdo,"a.estado='publicada'",'a.orden,a.fecha_actividad,a.nombre');

$visibles=[];
foreach($filas as $a){
    [$code,$label]=activity_state($a);
    if($code==='cerrada'&&!$a['mostrar_cerrada'])continue;
    $a['code']=$code;$a['label']=$label;
    $visibles[]=$a;
}

pub_inicio('Actividades','Actividades de la promo','Elige una actividad, escoge tu comisión y turno, y confirma la inscripción de tu familia en menos de un minuto.');
?>
<?php if(!$visibles):?>
  <section class="card vacio-card"><h2>Por ahora no hay actividades publicadas</h2><p>Vuelve a consultar pronto.</p></section>
<?php else:?>
  <section class="grid">
  <?php foreach($visibles as $a):
      $lugares=(int)$a['lugares'];$libres=(int)$a['libres'];$ocupados=$lugares-$libres;
      $abierta=$a['code']==='abierta';
  ?>
    <article class="card act">
      <span class="badge <?=e($a['code'])?>"><?=e($a['label'])?></span>
      <h2><?=e($a['nombre'])?></h2>
      <p class="meta"><b>📅 <?=e(fecha_larga($a['fecha_actividad']))?></b></p>
      <?php if(trim((string)$a['descripcion'])!==''):?><p><?=nl2br(e($a['descripcion']))?></p><?php endif;?>
      <p class="meta"><?=e(act_ventana($a))?></p>
      <?php if($lugares>0):?>
        <div class="resumen-lugares"><span class="barra" aria-hidden="true"><i style="width:<?=$lugares?round($ocupados*100/$lugares):0?>%"></i></span>
        <span class="cuenta"><?=$libres>0?plural($libres,'lugar libre','lugares libres').' de '.$lugares:'Todos los lugares están ocupados'?></span></div>
      <?php endif;?>
      <?php if($abierta&&(int)$a['asignacion_auto']&&$a['modo_control']==='automatico'&&$a['fecha_cierre']):?>
        <p class="meta">🎲 Las familias que no se inscriban serán asignadas al azar a los lugares libres cuando cierre la inscripción.</p>
      <?php endif;?>
      <div class="acciones">
        <?php if($abierta&&$libres>0):?><a class="btn acc" href="<?=e(url('actividad.php?id='.$a['id']))?>">Ver turnos y registrarme</a>
        <?php elseif($abierta):?><a class="btn ghost" href="<?=e(url('actividad.php?id='.$a['id']))?>">Ver turnos</a>
        <?php else:?><a class="btn ghost" href="<?=e(url('actividad.php?id='.$a['id']))?>">Ver detalle</a><?php endif;?>
      </div>
    </article>
  <?php endforeach;?>
  </section>
  <section class="card pad" style="margin-top:18px">
    <div class="acciones" style="justify-content:space-between">
      <div><b>¿Ya te inscribiste o te asignaron un turno?</b><br><span class="meta">Consulta en qué comisión y horario quedó tu familia.</span></div>
      <a class="btn ghost" href="<?=e(url('mi-turno.php'))?>">Consultar mi turno</a>
    </div>
  </section>
<?php endif;?>
<?php pub_fin();
