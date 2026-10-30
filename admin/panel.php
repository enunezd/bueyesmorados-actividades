<?php
require __DIR__.'/../includes/app.php';
$title='Inicio';
$subtitle='Resumen de lo que está pasando';
$me=require_admin();
$pdo=db();
asig_ejecutar_pendientes($pdo);

$familias=act_familias_activas($pdo);
$acts=act_lista($pdo,"a.estado<>'archivada'");

// Avisos que merecen atención (lo más importante primero).
$avisos=[];
if($familias===0)$avisos[]=['warn','Todavía no hay familias cargadas. Con la lista, las familias eligen su nombre al inscribirse y puedes usar la asignación automática.',url('admin/familias.php'),'Cargar familias'];
$abiertasLibres=0;$publicadas=0;
foreach($acts as $a){
    $id=(int)$a['id'];
    $publicada=$a['estado']==='publicada';
    if($publicada){$publicadas++;if(activity_state($a)[0]==='abierta')$abiertasLibres+=(int)$a['libres'];}
    $auto=(int)$a['asignacion_auto']===1;
    if($publicada&&cierre_vencido($a)&&!$auto&&empty($a['asignacion_ejecutada_en'])&&$a['sin_inscribir']>0&&$a['libres']>0)
        $avisos[]=['warn','«'.$a['nombre'].'» ya cerró y quedan '.plural((int)$a['sin_inscribir'],'familia sin inscribir','familias sin inscribir').' y '.plural((int)$a['libres'],'lugar libre','lugares libres').'.',url('admin/sorteo.php?actividad='.$id),'Hacer la asignación'];
    if($publicada&&$auto&&empty($a['asignacion_ejecutada_en'])&&$a['modo_control']==='automatico'&&$a['fecha_cierre']&&!cierre_vencido($a))
        $avisos[]=['info','🎲 «'.$a['nombre'].'»: la asignación automática se hará sola el '.dt_largo($a['fecha_cierre']).' (en '.tiempo_restante(new DateTimeImmutable($a['fecha_cierre'])).').',url('admin/sorteo.php?actividad='.$id),'Ver detalles'];
    if(!$publicada&&$a['estado']==='borrador'){$sig=act_pasos($a,$familias)['siguiente'];if($sig)$avisos[]=['info','«'.$a['nombre'].'» es un borrador. Siguiente paso: '.mb_strtolower($sig['titulo']).'.',$sig['url'],$sig['cta']];}
}
$totalIns=(int)$pdo->query("SELECT COUNT(*) FROM inscripciones WHERE estado='confirmada'")->fetchColumn();
$ultimas=$pdo->query("SELECT i.familia,i.origen,i.creado_en,a.nombre actividad,co.nombre comision,t.hora_inicio,t.hora_fin
  FROM inscripciones i JOIN actividades a ON a.id=i.actividad_id JOIN comisiones co ON co.id=i.comision_id JOIN turnos t ON t.id=i.turno_id
  WHERE i.estado='confirmada' ORDER BY i.id DESC LIMIT 8")->fetchAll();
$origenes=['familia'=>['info','Familia'],'admin'=>['draft','Directiva'],'automatica'=>['warn','Sorteo']];

require __DIR__.'/../includes/admin-header.php';
?>
<?php foreach($avisos as [$tipo,$texto,$href,$cta]):?>
  <div class="alert <?=$tipo?>" style="display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap"><span><?=e($texto)?></span><a class="btn small <?=$tipo==='warn'?'':'ghost'?>" href="<?=e($href)?>"><?=e($cta)?> →</a></div>
<?php endforeach;?>

<section class="cards">
  <div class="stat"><b><?=$publicadas?></b><span>actividades publicadas</span></div>
  <div class="stat"><b><?=$totalIns?></b><span>inscripciones confirmadas</span></div>
  <div class="stat"><b><?=$abiertasLibres?></b><span>lugares libres en actividades abiertas</span></div>
  <div class="stat"><b><?=$familias?></b><span>familias en la lista</span></div>
</section>

<section class="panel">
  <div class="panelhead"><h2>Tus actividades</h2><a class="btn" href="<?=e(url('admin/actividades.php?nueva=1'))?>">+ Nueva actividad</a></div>
  <?php if(!$acts):?><div class="empty">Todavía no hay actividades.<br><br><a class="btn" href="<?=e(url('admin/actividades.php?nueva=1'))?>">Crear la primera actividad</a></div><?php endif;?>
  <?php foreach($acts as $a):[$cls,$txt]=activity_summary($a);$lug=(int)$a['lugares'];$pct=$lug>0?(int)round(($lug-(int)$a['libres'])*100/$lug):0;?>
    <div class="item">
      <div style="flex:1;min-width:240px">
        <a href="<?=e(url('admin/actividad.php?id='.$a['id']))?>" style="font-weight:700;font-size:17px;text-decoration:none"><?=e($a['nombre'])?></a> <span class="badge <?=e($cls)?>"><?=e($txt)?></span><br>
        <small class="muted">📅 <?=e(fecha_larga($a['fecha_actividad']))?></small>
        <?php if($lug>0):?><div class="progress" style="max-width:340px;margin:7px 0 3px"><i style="width:<?=$pct?>%"></i></div><small class="muted"><?=(int)$a['inscritos']?> inscritas · <?=(int)$a['libres']?> libres de <?=$lug?></small><?php endif;?>
      </div>
      <div class="actions"><a class="btn small" href="<?=e(url('admin/actividad.php?id='.$a['id']))?>">Abrir</a><a class="btn small ghost" href="<?=e(url('admin/inscripciones.php?actividad='.$a['id']))?>">Inscripciones</a></div>
    </div>
  <?php endforeach;?>
</section>

<section class="panel">
  <div class="panelhead"><h2>Últimas inscripciones</h2><a class="btn small ghost" href="<?=e(url('admin/inscripciones.php'))?>">Ver todas</a></div>
  <?php if(!$ultimas):?><div class="empty">Aún no hay inscripciones.</div><?php else:?>
  <div style="overflow:auto"><table class="lista"><tr><th>Familia</th><th>Actividad</th><th>Comisión</th><th>Turno</th><th>Origen</th><th>Fecha</th></tr>
  <?php foreach($ultimas as $r):[$oc,$ot]=$origenes[$r['origen']]??['draft',$r['origen']];?>
    <tr><td><b><?=e($r['familia'])?></b></td><td><?=e($r['actividad'])?></td><td><?=e($r['comision'])?></td><td><?=e(hhmm($r['hora_inicio']).'–'.hhmm($r['hora_fin']))?></td><td><span class="badge <?=$oc?>"><?=e($ot)?></span></td><td><?=e(fmt_dt($r['creado_en']))?></td></tr>
  <?php endforeach;?></table></div><?php endif;?>
</section>
<?php require __DIR__.'/../includes/admin-footer.php';
