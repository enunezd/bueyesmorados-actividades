<?php
require __DIR__.'/../includes/app.php';
require __DIR__.'/../includes/admin-actividad.php';
$me=require_admin();
$pdo=db();
asig_ejecutar_pendientes($pdo);

$aid=(int)($_GET['actividad']??$_POST['actividad_id']??0);
$a=act_actual($pdo,$aid);
$back='admin/sorteo.php?actividad='.$aid;
$preview=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_ok()){flash('error','El formulario venció, inténtalo de nuevo.');go($back);}
    $accion=$_POST['accion']??'';
    try{
        switch($accion){
        case 'activar':
        case 'desactivar':
            $pdo->prepare('UPDATE actividades SET asignacion_auto=? WHERE id=?')->execute([$accion==='activar'?1:0,$aid]);
            audit($me['id'],$accion.'_asignacion','actividades',$aid);
            flash('success',$accion==='activar'?'Asignación automática activada.':'Asignación automática desactivada: no se asignará nada por tu cuenta.');
            go($back);
        case 'preview':
            $semilla=asig_nueva_semilla();
            $sit=asig_situacion($pdo,$aid);
            $preview=['semilla'=>$semilla,'sit'=>$sit,'plan'=>asig_planificar($sit['familias'],$sit['cupos'],$semilla)];
            break;
        case 'confirmar':
            $r=asig_ejecutar($pdo,$aid,(int)($_POST['semilla']??0),'manual',$me['id'],(string)($_POST['hash']??''));
            flash('success','Sorteo realizado: '.plural(count($r['asignadas']),'familia asignada','familias asignadas').($r['sin_lugar']?' y '.plural($r['sin_lugar'],'quedó','quedaron').' sin lugar':'').'. Puedes revisar o mover cada asignación en Inscripciones.');
            go('admin/inscripciones.php?actividad='.$aid.'&origen=automatica');
        case 'deshacer':
            $r=asig_deshacer($pdo,$aid,$me['id']);
            flash('success','Asignación deshecha: se cancelaron '.plural($r['canceladas'],'inscripción automática','inscripciones automáticas').'. Las inscripciones hechas por las familias no se tocaron.'.($r['desactivada']?' Como el plazo ya terminó, la asignación automática quedó desactivada para que no se repita sola.':''));
            go($back);
        case 'exonerar':
            $f=(int)($_POST['familia_id']??0);
            if($f)$pdo->prepare('INSERT IGNORE INTO exoneraciones(actividad_id,familia_id) VALUES(?,?)')->execute([$aid,$f]);
            flash('success','Familia exonerada: no entrará al sorteo de esta actividad.');
            go($back);
        case 'quitar_exoneracion':
            $pdo->prepare('DELETE FROM exoneraciones WHERE actividad_id=? AND familia_id=?')->execute([$aid,(int)($_POST['familia_id']??0)]);
            flash('success','La familia vuelve a entrar al sorteo.');
            go($back);
        }
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if(!($e instanceof MensajeError))error_log('sorteo.php: '.$e->getMessage());
        flash('error',$e instanceof MensajeError?$e->getMessage():'No se pudo completar la acción.');
        go($back);
    }
}

$a=act_cargar($pdo,$aid); // con los datos al día
$sit=asig_situacion($pdo,$aid);
$pend=count($sit['familias']);$libres=$sit['libres'];
$auto=(int)$a['asignacion_auto']===1;
$ejecutada=$a['asignacion_ejecutada_en'];
$programable=$a['modo_control']==='automatico'&&!empty($a['fecha_cierre']);
$cerro=cierre_vencido($a);
$exoneradas=$pdo->prepare('SELECT f.id,f.nombre FROM exoneraciones x JOIN familias f ON f.id=x.familia_id WHERE x.actividad_id=? ORDER BY f.nombre');$exoneradas->execute([$aid]);$exoneradas=$exoneradas->fetchAll();
$candidatas=$pdo->prepare('SELECT f.id,f.nombre FROM familias f WHERE f.activa=1 AND NOT EXISTS(SELECT 1 FROM exoneraciones x WHERE x.actividad_id=? AND x.familia_id=f.id) ORDER BY f.nombre');$candidatas->execute([$aid]);$candidatas=$candidatas->fetchAll();

$title='Asignación automática';
$subtitle=$a['nombre'].' · Reparte al azar a las familias que no se inscribieron';
require __DIR__.'/../includes/admin-header.php';
act_tabs($a,'sorteo');
$cs=csrf_field().'<input type="hidden" name="actividad_id" value="'.$aid.'">';
?>

<section class="callout"><details <?=$auto||$ejecutada?'':'open'?>><summary>¿Cómo funciona?</summary><ol>
  <li>Cuando termina el plazo de inscripción, el sistema toma a las <b>familias de la lista que no se inscribieron</b> (menos las exoneradas).</li>
  <li>Las reparte <b>al azar</b> entre los lugares que quedaron libres, llenando primero los turnos más vacíos para que todos queden cubiertos.</li>
  <li>Si hay más familias que lugares, se sortea quiénes quedan asignadas; si hay más lugares, algunos quedan libres.</li>
  <li>Después puedes <b>mover o cancelar</b> cualquier asignación en <a href="<?=e(url('admin/inscripciones.php?actividad='.$aid))?>">Inscripciones</a>, o <b>deshacer</b> todo el sorteo y repetirlo.</li>
</ol></details></section>

<section class="panel">
  <div class="panelhead"><h2>Estado</h2>
    <form method="post"><?=$cs?>
      <?php if($auto):?><button class="btn small gray" name="accion" value="desactivar">Desactivar</button>
      <?php else:?><button class="btn small" name="accion" value="activar"<?=$cerro?' data-confirm="El plazo de esta actividad ya terminó: al activarla, el sorteo se hará de inmediato con las familias que falten. ¿Continuar?"':''?>>Activar asignación automática</button><?php endif;?>
    </form>
  </div>
  <?php if($ejecutada):?>
    <div class="alert success" style="margin:0">✔ El sorteo se realizó el <b><?=e(dt_largo($ejecutada))?></b>: <?=plural((int)$a['automaticas'],'familia asignada','familias asignadas')?> automáticamente.</div>
  <?php elseif(!$auto):?>
    <div class="alert info" style="margin:0"><b>Desactivada.</b> No se asignará nada por tu cuenta. Puedes activarla o hacer un sorteo manual abajo cuando quieras.</div>
  <?php elseif($programable&&!$cerro):?>
    <div class="alert success" style="margin:0">🎲 <b>Activada.</b> Se hará sola cuando cierre la inscripción: <b><?=e(dt_largo($a['fecha_cierre']))?></b> (en <?=e(tiempo_restante(new DateTimeImmutable($a['fecha_cierre'])))?>).
      <?php if($a['estado']!=='publicada'):?><br>⚠ La actividad debe estar <b>publicada</b> para que el sorteo se haga solo.<?php endif;?></div>
  <?php elseif($programable&&$cerro):?>
    <div class="alert warn" style="margin:0">⚠ El plazo ya terminó y todavía no se sorteó<?=$a['estado']!=='publicada'?' porque la actividad no está publicada':''?>. Haz el sorteo ahora abajo.</div>
  <?php else:?>
    <div class="alert warn" style="margin:0">⚠ Está activada, pero la inscripción no se cierra por fecha, así que no se hará sola. <a href="<?=e(url('admin/actividades.php?editar='.$aid))?>">Elige «Según fechas» y una fecha de cierre</a>, o haz el sorteo manualmente abajo.</div>
  <?php endif;?>
  <?php if(!$ejecutada&&(int)$a['automaticas']>0):?><p class="muted" style="margin-bottom:0">Hay <?=plural((int)$a['automaticas'],'inscripción asignada por sorteo','inscripciones asignadas por sorteo')?> (hecho manualmente).</p><?php endif;?>
  <?php if($ejecutada||(int)$a['automaticas']>0):?>
    <form method="post" style="margin-top:12px"><?=$cs?>
      <button class="btn small red" name="accion" value="deshacer" data-confirm="¿Deshacer la asignación automática? Se cancelarán las <?=(int)$a['automaticas']?> inscripciones asignadas por sorteo (las de las familias no se tocan) y podrás sortear de nuevo.">Deshacer la asignación automática</button>
    </form>
  <?php endif;?>
</section>

<div class="cards">
  <div class="stat"><b><?=$sit['total_familias']?></b><span>familias en la lista</span></div>
  <div class="stat"><b><?=$sit['inscritas']?></b><span>inscripciones confirmadas</span></div>
  <div class="stat <?=$pend?'warn':'ok'?>"><b><?=$pend?></b><span>familias sin inscribir<?=$sit['exoneradas']?' (sin contar '.$sit['exoneradas'].' exoneradas)':''?></span></div>
  <div class="stat <?=$libres?'':'bad'?>"><b><?=$libres?></b><span>lugares libres de <?=$sit['lugares']?></span></div>
</div>

<?php if($sit['total_familias']===0):?>
  <div class="alert warn">La lista de familias está vacía: no hay a quién asignar. <a href="<?=e(url('admin/familias.php'))?>">Cargar familias</a></div>
<?php elseif(!$sit['cupos']):?>
  <div class="alert warn">Esta actividad no tiene lugares definidos todavía. <a href="<?=e(url('admin/estructura.php?actividad='.$aid))?>">Definir comisiones, turnos y cupos</a></div>
<?php endif;?>

<section class="panel">
  <div class="panelhead"><h2>Hacer el sorteo ahora</h2></div>
  <p style="margin-top:0">
    <?php if($pend===0):?>No hay familias pendientes: todas están inscritas o exoneradas.
    <?php elseif($libres===0):?>No hay lugares libres: nadie sería asignado.
    <?php elseif($pend<=$libres):?>Si sorteas ahora, <b>las <?=$pend?> familias pendientes quedarían asignadas</b> y sobrarían <?=$libres-$pend?> lugares.
    <?php else:?>Si sorteas ahora, <b>se asignarían <?=$libres?> de las <?=$pend?> familias pendientes</b> al azar; <?=$pend-$libres?> quedarían sin lugar porque no hay más lugares.<?php endif;?>
  </p>
  <?php if($cerro===false&&$programable&&$a['estado']==='publicada'&&activity_state($a)[0]==='abierta'):?>
    <div class="alert warn">La inscripción todavía está abierta. Si sorteas ahora, las familias que se inscriban después tendrán menos lugares para elegir.</div>
  <?php endif;?>
  <?php if($pend>0&&$libres>0):?>
  <form method="post"><?=$cs?><button class="btn" name="accion" value="preview">🎲 Ver vista previa del sorteo</button></form>
  <?=hint('La vista previa no guarda nada: primero ves cómo quedaría y luego decides si confirmar.')?>
  <?php endif;?>

  <?php if($preview):
      $porCupo=[];foreach($preview['plan']['asignadas'] as $x)$porCupo[$x['cupo_id']][]=$x['familia']['nombre'];
      $nuevas=count($preview['plan']['asignadas']);$sin=$preview['plan']['sin_lugar'];?>
    <hr style="border:0;border-top:1px solid var(--line);margin:18px 0">
    <h3>Vista previa · semilla <span class="semilla"><?=$preview['semilla']?></span></h3>
    <p class="muted" style="margin-top:0">Así quedaría: <b><?=plural($nuevas,'familia asignada','familias asignadas')?></b><?=$sin?' y '.plural(count($sin),'familia sin lugar','familias sin lugar'):''?>. Aún no se guardó nada.</p>
    <div class="sorteo-grid">
    <?php foreach($preview['sit']['cupos'] as $c):$nom=$porCupo[$c['cupo_id']]??[];if(!$nom&&$c['libres']===0)continue;?>
      <div><h4><?=e($c['comision'])?> · <?=e(hhmm($c['hora_inicio']).'–'.hhmm($c['hora_fin']))?></h4>
        <small class="muted"><?=plural($c['usados'],'ya inscrita','ya inscritas')?> · <b><?=plural(count($nom),'nueva por sorteo','nuevas por sorteo')?></b> · capacidad <?=$c['capacidad']?></small>
        <?php if($nom):?><ul><?php foreach($nom as $n):?><li class="nuevo"><?=e($n)?></li><?php endforeach;?></ul><?php else:?><br><small class="muted">Sin familias nuevas</small><?php endif;?></div>
    <?php endforeach;?>
    </div>
    <?php if($sin):?><details style="margin-top:12px"><summary><b><?=plural(count($sin),'familia queda','familias quedan')?> sin lugar</b> (no hay más lugares libres)</summary><p class="muted"><?=e(implode(' · ',array_column($sin,'nombre')))?></p></details><?php endif;?>
    <div class="actions" style="margin-top:16px">
      <form method="post"><?=$cs?><input type="hidden" name="semilla" value="<?=$preview['semilla']?>"><input type="hidden" name="hash" value="<?=e($preview['sit']['hash'])?>">
        <button class="btn" name="accion" value="confirmar" data-confirm="¿Confirmar este reparto? Se crearán <?=$nuevas?> inscripciones. Podrás moverlas, cancelarlas o deshacer todo el sorteo.">✔ Confirmar y asignar</button></form>
      <form method="post"><?=$cs?><button class="btn ghost" name="accion" value="preview">Sortear de nuevo</button></form>
    </div>
  <?php endif;?>
</section>

<div class="twocol">
<section class="panel">
  <div class="panelhead"><h2>Familias que no participan <span class="badge info"><?=count($exoneradas)?></span></h2></div>
  <p class="muted" style="margin-top:0">Las familias exoneradas no entran al sorteo de esta actividad (por ejemplo, quienes ya colaboran de otra forma).</p>
  <?php foreach($exoneradas as $f):?>
    <div class="item"><span><?=e($f['nombre'])?></span>
      <form method="post"><?=$cs?><input type="hidden" name="familia_id" value="<?=$f['id']?>"><button class="btn small ghost" name="accion" value="quitar_exoneracion">Quitar</button></form></div>
  <?php endforeach;?>
  <?php if($candidatas):?>
  <form method="post" class="inline" style="margin-top:12px"><?=$cs?><input type="hidden" name="accion" value="exonerar">
    <div class="field" style="flex:1;min-width:180px"><label for="exo">Exonerar a la familia</label><select id="exo" name="familia_id"><option value="">Elige una familia…</option><?php foreach($candidatas as $f):?><option value="<?=$f['id']?>"><?=e($f['nombre'])?></option><?php endforeach;?></select></div>
    <button class="btn ghost">Exonerar</button></form>
  <?php endif;?>
</section>

<section class="panel">
  <div class="panelhead"><h2>Familias sin inscribir <span class="badge <?=$pend?'warn':'ok'?>"><?=$pend?></span></h2>
    <?php if($pend):?><a class="btn small ghost" href="<?=e(url('admin/exportar.php?tipo=pendientes&actividad='.$aid))?>">Descargar CSV</a><?php endif;?></div>
  <?php if(!$pend):?><div class="empty">Todas las familias activas ya están inscritas (o exoneradas).</div><?php else:?>
  <details><summary style="cursor:pointer;color:var(--p);font-weight:700">Ver la lista</summary>
    <p style="columns:2;column-gap:20px;margin:10px 0 0"><?php foreach($sit['familias'] as $f):?><?=e($f['nombre'])?><br><?php endforeach;?></p></details>
  <?php endif;?>
</section>
</div>
<?php require __DIR__.'/../includes/admin-footer.php';
