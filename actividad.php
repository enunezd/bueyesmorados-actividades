<?php
require __DIR__.'/includes/app.php';
require __DIR__.'/includes/publico.php';
asig_ejecutar_pendientes();
$pdo=db();

$s=$pdo->prepare('SELECT * FROM actividades WHERE id=?');$s->execute([(int)($_GET['id']??0)]);$a=$s->fetch();
$vistaPrevia=false;
if($a&&$a['estado']!=='publicada'){if(admin())$vistaPrevia=true;else $a=false;}
if(!$a)pub_error('Actividad no encontrada','Esa actividad no existe o todavía no está publicada.',404,[url(),'Ver actividades']);

[$code,$label]=activity_state($a);
$abierta=$code==='abierta'&&!$vistaPrevia;

$q=$pdo->prepare("SELECT c.id cupo_id,co.id comision_id,co.nombre comision,co.reglas,t.hora_inicio,t.hora_fin,c.capacidad,
    (SELECT COUNT(*) FROM inscripciones i WHERE i.cupo_id=c.id AND i.estado='confirmada') usados
  FROM cupos c JOIN comisiones co ON co.id=c.comision_id JOIN turnos t ON t.id=c.turno_id
  WHERE c.actividad_id=? AND c.habilitado=1 AND co.activa=1 AND t.activo=1
  ORDER BY co.orden,co.nombre,t.hora_inicio,t.hora_fin");
$q->execute([$a['id']]);
$comisiones=[];$lugares=0;$libres=0;
foreach($q->fetchAll() as $r){
    $r['libres']=max(0,(int)$r['capacidad']-(int)$r['usados']);
    $lugares+=(int)$r['capacidad'];$libres+=$r['libres'];
    $comisiones[$r['comision_id']]['nombre']=$r['comision'];
    $comisiones[$r['comision_id']]['reglas']=$r['reglas'];
    $comisiones[$r['comision_id']]['turnos'][]=$r;
}
$sorteado=!empty($a['asignacion_ejecutada_en']);

pub_inicio($a['nombre'],$a['nombre'],'📅 '.e(fecha_larga($a['fecha_actividad'])),['volver'=>[url(),'Todas las actividades']]);
?>
<?php if($vistaPrevia):?>
  <div class="aviso warn"><span>👁</span><span><b>Vista previa para administradores.</b> Esta actividad todavía no es pública (<?=e($a['estado'])?>). <a href="<?=e(url('admin/actividad.php?id='.$a['id']))?>">Volver al panel</a></span></div>
<?php endif;?>

<section class="card intro">
  <?php if(trim((string)$a['descripcion'])!==''):?><p><?=nl2br(e($a['descripcion']))?></p><?php endif;?>
  <p><span class="badge <?=e($code)?>"><?=e($label)?></span> <span class="meta"><?=e(act_ventana($a))?></span></p>
  <?php if($lugares>0):?>
    <div class="resumen-lugares"><span class="barra" aria-hidden="true"><i style="width:<?=round(($lugares-$libres)*100/$lugares)?>%"></i></span>
    <span class="cuenta"><?=$libres>0?plural($libres,'lugar libre','lugares libres').' de '.$lugares:'Todos los lugares están ocupados'?></span></div>
  <?php endif;?>
  <?php if($abierta&&(int)$a['asignacion_auto']&&$a['modo_control']==='automatico'&&$a['fecha_cierre']):?>
    <div class="aviso info" style="margin:14px 0 0"><span>🎲</span><span><b>Importante:</b> las familias que no se inscriban antes del cierre serán asignadas <b>al azar</b> a los lugares que queden libres. Si prefieres elegir tu comisión y horario, inscríbete ahora.</span></div>
  <?php elseif($sorteado):?>
    <div class="aviso ok" style="margin:14px 0 0"><span>🎲</span><span>El sorteo de lugares ya se realizó. <a href="<?=e(url('mi-turno.php'))?>">Consulta en qué turno quedó tu familia</a>.</span></div>
  <?php endif;?>
</section>

<?php if(!$comisiones):?>
  <section class="card vacio-card"><h2>Aún no hay turnos disponibles</h2><p>Vuelve a consultar pronto.</p></section>
<?php else:?>
  <section class="comisiones" aria-label="Comisiones y turnos">
  <?php foreach($comisiones as $c):?>
    <article class="card comision">
      <h3><?=e($c['nombre'])?></h3>
      <?php if(trim((string)$c['reglas'])!==''):?><p class="reglas"><?=nl2br(e($c['reglas']))?></p><?php endif;?>
      <ul class="turnos">
      <?php foreach($c['turnos'] as $t):$lleno=$t['libres']<=0;?>
        <li class="turno <?=$lleno?'lleno':''?>">
          <span class="hora"><?=e(hhmm($t['hora_inicio']).' – '.hhmm($t['hora_fin']))?></span>
          <span class="cupo"><?=pub_pips((int)$t['capacidad'],(int)$t['usados'])?><span class="cuenta"><?=e(pub_quedan($t['libres']))?></span></span>
          <?php if($abierta&&!$lleno):?><a class="btn acc" href="<?=e(url('registrar.php?cupo='.(int)$t['cupo_id']))?>" aria-label="Elegir <?=e($c['nombre'].' '.hhmm($t['hora_inicio']))?>">Elegir</a><?php endif;?>
        </li>
      <?php endforeach;?>
      </ul>
    </article>
  <?php endforeach;?>
  </section>
<?php endif;?>
<?php pub_fin();
