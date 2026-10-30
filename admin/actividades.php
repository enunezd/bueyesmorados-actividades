<?php
require __DIR__.'/../includes/app.php';
require __DIR__.'/../includes/admin-actividad.php';
$me=require_admin();
$pdo=db();
$error='';
$edit=null;

// Adónde volver tras una acción rápida (solo rutas internas del panel).
function volver_a(string $defecto):string{
    $v=(string)($_POST['volver']??'');
    return preg_match('#^admin/[a-z]+\.php(\?[A-Za-z0-9=&_-]*)?$#',$v)?$v:$defecto;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_ok()){flash('error','El formulario venció, inténtalo de nuevo.');go('admin/actividades.php');}
    $accion=$_POST['accion']??'guardar';
    $id=(int)($_POST['id']??0);

    if($accion==='guardar'){
        $nombre=trim($_POST['nombre']??'');
        $modo=in_array($_POST['modo_control']??'',['automatico','forzar_abierto','forzar_cerrado'],true)?$_POST['modo_control']:'forzar_abierto';
        $apertura=dt_from_input($_POST['fecha_apertura']??'');
        $cierre=dt_from_input($_POST['fecha_cierre']??'');
        $actual=null;
        if($id){$s=$pdo->prepare('SELECT estado FROM actividades WHERE id=?');$s->execute([$id]);$actual=$s->fetchColumn();}
        $estado=isset($_POST['publicada'])?'publicada':($actual==='archivada'?'archivada':'borrador');
        $mostrar=isset($_POST['ocultar_cerrada'])?0:1;
        $auto=isset($_POST['asignacion_auto'])?1:0;

        if($nombre==='')$error='Escribe el nombre de la actividad.';
        elseif($modo==='automatico'&&$apertura&&$cierre&&$apertura>=$cierre)$error='La fecha de cierre debe ser posterior a la de apertura.';

        if(!$error){
            $data=[$nombre,trim($_POST['descripcion']??''),($_POST['fecha_actividad']??'')?:null,$apertura,$cierre,$modo,$estado,$mostrar,$auto];
            if($id){
                $pdo->prepare('UPDATE actividades SET nombre=?,descripcion=?,fecha_actividad=?,fecha_apertura=?,fecha_cierre=?,modo_control=?,estado=?,mostrar_cerrada=?,asignacion_auto=? WHERE id=?')->execute([...$data,$id]);
                audit($me['id'],'guardar','actividades',$id);
                flash('success','Cambios guardados.');
                go('admin/actividad.php?id='.$id);
            }
            $pdo->prepare('INSERT INTO actividades(nombre,descripcion,fecha_actividad,fecha_apertura,fecha_cierre,modo_control,estado,mostrar_cerrada,asignacion_auto) VALUES(?,?,?,?,?,?,?,?,?)')->execute($data);
            $id=(int)$pdo->lastInsertId();
            audit($me['id'],'crear','actividades',$id);
            $msg='Actividad creada. Siguiente paso: agrega las comisiones y los turnos.';
            $desde=(int)($_POST['copiar_de']??0);
            if($desde){
                $r=copiar_estructura($pdo,$desde,$id);
                $msg='Actividad creada con la estructura copiada ('.plural($r['comisiones'],'comisión','comisiones').', '.plural($r['turnos'],'turno','turnos').'). Revisa los cupos y publícala cuando esté lista.';
            }
            flash('success',$msg);
            go('admin/estructura.php?actividad='.$id);
        }
        // Con error: se vuelve a mostrar el formulario con lo que escribió la persona.
        $edit=['id'=>$id,'nombre'=>$nombre,'descripcion'=>trim($_POST['descripcion']??''),'fecha_actividad'=>$_POST['fecha_actividad']??'','fecha_apertura'=>$apertura,'fecha_cierre'=>$cierre,'modo_control'=>$modo,'estado'=>$estado,'mostrar_cerrada'=>$mostrar,'asignacion_auto'=>$auto];
    }elseif(in_array($accion,['publicar','ocultar','archivar','restaurar'],true)){
        $nuevo=['publicar'=>'publicada','ocultar'=>'borrador','archivar'=>'archivada','restaurar'=>'borrador'][$accion];
        $pdo->prepare('UPDATE actividades SET estado=? WHERE id=?')->execute([$nuevo,$id]);
        audit($me['id'],$accion,'actividades',$id);
        flash('success',['publicar'=>'Actividad publicada: ya se ve en la página pública.','ocultar'=>'Actividad oculta: pasó a borrador.','archivar'=>'Actividad archivada. Se conserva con sus inscripciones.','restaurar'=>'Actividad restaurada como borrador.'][$accion]);
        go(volver_a('admin/actividades.php'));
    }elseif($accion==='duplicar'){
        $s=$pdo->prepare('SELECT * FROM actividades WHERE id=?');$s->execute([$id]);$o=$s->fetch();
        if($o){
            $pdo->prepare("INSERT INTO actividades(nombre,descripcion,modo_control,estado,mostrar_cerrada,asignacion_auto) VALUES(?,?, 'automatico','borrador',?,?)")->execute([mb_substr('Copia de '.$o['nombre'],0,150),$o['descripcion'],$o['mostrar_cerrada'],$o['asignacion_auto']]);
            $nuevoId=(int)$pdo->lastInsertId();
            copiar_estructura($pdo,$id,$nuevoId);
            audit($me['id'],'duplicar','actividades',$nuevoId,"desde $id");
            flash('success','Copia creada como borrador. Cambia el nombre y las fechas, y publícala cuando esté lista.');
            go('admin/actividades.php?editar='.$nuevoId);
        }
    }elseif($accion==='eliminar'){
        $s=$pdo->prepare('SELECT COUNT(*) FROM inscripciones WHERE actividad_id=?');$s->execute([$id]);
        if((int)$s->fetchColumn()>0){
            flash('error','No se puede eliminar: ya tiene inscripciones. Puedes archivarla para ocultarla.');
        }else{
            $pdo->beginTransaction();
            foreach(['exoneraciones','cupos','comisiones','turnos'] as $t)$pdo->prepare("DELETE FROM $t WHERE actividad_id=?")->execute([$id]);
            $pdo->prepare('DELETE FROM actividades WHERE id=?')->execute([$id]);
            $pdo->commit();
            audit($me['id'],'eliminar','actividades',$id);
            flash('success','Actividad eliminada.');
        }
        go('admin/actividades.php');
    }
}

if(!$edit&&isset($_GET['editar'])){
    $edit=act_cargar($pdo,(int)$_GET['editar']);
}
$showForm=$edit||isset($_GET['nueva'])||$error;
$form=$edit??['id'=>0,'nombre'=>'','descripcion'=>'','fecha_actividad'=>'','fecha_apertura'=>null,'fecha_cierre'=>null,'modo_control'=>'forzar_abierto','estado'=>'borrador','mostrar_cerrada'=>1,'asignacion_auto'=>0];

$familias=act_familias_activas($pdo);
$rows=act_lista($pdo);

if($showForm&&$form['id']){$title=$form['nombre']!==''?$form['nombre']:'Editar actividad';$subtitle='Datos de la actividad';}
elseif($showForm){$title='Nueva actividad';$subtitle='Solo necesitas el nombre; lo demás lo puedes ajustar después';}
else{$title='Actividades';$subtitle='Crea una actividad, agrégale comisiones y turnos, y ábrela a las familias';}
require __DIR__.'/../includes/admin-header.php';
if($showForm&&$form['id']&&($ta=act_cargar($pdo,(int)$form['id'])))act_tabs($ta,'datos');
?>
<?php if($showForm):?>
<section class="panel">
  <div class="panelhead"><h2><?=$form['id']?'Datos de la actividad':'Nueva actividad'?></h2><?php if(!$form['id']):?><a class="btn gray small" href="<?=e(url('admin/actividades.php'))?>">Cancelar</a><?php endif;?></div>
  <?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
  <form method="post" class="formgrid">
    <?=csrf_field()?>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="<?=(int)$form['id']?>">

    <div class="field"><label for="nombre">Nombre de la actividad</label>
      <input id="nombre" type="text" name="nombre" required maxlength="150" value="<?=e($form['nombre'])?>" placeholder="Ej.: Bingo 2026" autofocus>
    </div>
    <div class="field"><label for="fecha">Fecha del evento</label>
      <input id="fecha" type="date" name="fecha_actividad" value="<?=e($form['fecha_actividad'])?>">
      <?=hint('Es el día en que se realiza la actividad (opcional).')?>
    </div>
    <div class="field full"><label for="desc">Descripción</label>
      <textarea id="desc" name="descripcion" rows="3" placeholder="Qué se hará, dónde, qué deben llevar las familias…"><?=e($form['descripcion'])?></textarea>
      <?=hint('Las familias la ven en la página pública.')?>
    </div>

    <div class="field full"><label>¿Cuándo pueden inscribirse las familias?</label>
      <div class="radios">
        <label><input type="radio" name="modo_control" value="forzar_abierto" <?=$form['modo_control']==='forzar_abierto'?'checked':''?>><span><b>Abierto ahora</b>Se pueden inscribir hasta que lo cierres tú.</span></label>
        <label><input type="radio" name="modo_control" value="automatico" <?=$form['modo_control']==='automatico'?'checked':''?>><span><b>Según fechas</b>Se abre y se cierra solo en las fechas que elijas. Aquí se activa la asignación automática.</span></label>
        <label><input type="radio" name="modo_control" value="forzar_cerrado" <?=$form['modo_control']==='forzar_cerrado'?'checked':''?>><span><b>Cerrado</b>Nadie puede inscribirse por ahora.</span></label>
      </div>
    </div>
    <div class="field full" id="fechas" <?=$form['modo_control']==='automatico'?'':'hidden'?>>
      <div class="formgrid">
        <div class="field"><label for="ap">Se abre el</label><input id="ap" type="datetime-local" name="fecha_apertura" value="<?=e(dt_to_input($form['fecha_apertura']))?>"><?=hint('Si lo dejas vacío, ya está abierto.')?></div>
        <div class="field"><label for="ci">Se cierra el</label><input id="ci" type="datetime-local" name="fecha_cierre" value="<?=e(dt_to_input($form['fecha_cierre']))?>"><?=hint('Si lo dejas vacío, no se cierra solo.')?></div>
        <div class="field full check"><label><input type="checkbox" name="asignacion_auto" <?=!empty($form['asignacion_auto'])?'checked':''?>><span><b>Asignar automáticamente al cerrar</b><br>Cuando termine el plazo, las familias de la lista que no se inscribieron se reparten <b>al azar</b> en los lugares que queden libres. Podrás revisar el resultado y deshacerlo.</span></label></div>
      </div>
    </div>

    <?php if(!$form['id']):
      $otras=array_filter($rows,fn($r)=>$r['n_com']>0||$r['n_tur']>0);?>
      <?php if($otras):?>
      <div class="field full"><label for="copiar">Empezar con la estructura de otra actividad <span class="muted">(opcional)</span></label>
        <select id="copiar" name="copiar_de"><option value="0">No, empezar desde cero</option>
          <?php foreach($otras as $o):?><option value="<?=$o['id']?>"><?=e($o['nombre'])?> — <?=plural((int)$o['n_com'],'comisión','comisiones')?>, <?=plural((int)$o['n_tur'],'turno','turnos')?></option><?php endforeach;?></select>
        <?=hint('Copia las comisiones, los turnos y los cupos (sin inscripciones). Ahorra mucho tiempo si repites una actividad.')?></div>
      <?php endif;?>
    <?php endif;?>

    <div class="field full check"><label><input type="checkbox" name="publicada" <?=$form['estado']==='publicada'?'checked':''?>><span><b>Publicar en la página pública</b><br>Mientras esté sin marcar es un borrador y solo tú la ves. Publícala cuando ya tenga comisiones, turnos y cupos.</span></label></div>
    <div class="field full">
      <details class="adv"><summary>Opciones avanzadas</summary>
        <div class="field check" style="margin-top:12px"><label><input type="checkbox" name="ocultar_cerrada" <?=$form['mostrar_cerrada']?'':'checked'?>><span>Ocultar la actividad cuando las inscripciones estén cerradas<br><small class="hint">Por defecto sigue visible, marcada como «Cerrada».</small></span></label></div>
      </details>
    </div>
    <div class="field full"><button class="btn"><?=$form['id']?'Guardar cambios':'Crear y continuar →'?></button></div>
  </form>
</section>
<?php if($form['id']):?>
<section class="panel">
  <div class="panelhead"><h2>Otras acciones</h2></div>
  <form method="post" class="actions"><?=csrf_field()?><input type="hidden" name="id" value="<?=(int)$form['id']?>">
    <button class="btn ghost small" name="accion" value="duplicar">Duplicar actividad</button>
    <?php if(($ta['total_ins']??0)==0):?><button class="btn red small" name="accion" value="eliminar" data-confirm="¿Eliminar «<?=e($form['nombre'])?>» con sus comisiones y turnos? No se puede deshacer.">Eliminar</button>
    <?php else:?><span class="muted">No se puede eliminar porque ya tiene inscripciones; puedes archivarla desde la lista.</span><?php endif;?>
  </form>
</section>
<?php endif;?>
<?php else:?>
<section class="callout">
  <details <?=$rows?'':'open'?>><summary>¿Cómo se prepara una actividad?</summary>
    <ol>
      <li><b>Crea la actividad</b> (nombre y fecha).</li>
      <li><b>Agrega comisiones</b>: los grupos de trabajo (Seguridad, Caja…).</li>
      <li><b>Agrega turnos</b>: los horarios (09:00–12:00…). Los cupos se crean solos.</li>
      <li><b>Carga la lista de familias</b> una sola vez (menú Familias).</li>
      <li><b>Publícala</b> para que las familias puedan inscribirse. Si quieres, activa la asignación automática para que al cierre se reparta a quienes falten.</li>
    </ol>
  </details>
</section>
<?php endif;?>

<?php if(!$showForm||$form['id']==0):?>
<section class="panel">
  <div class="panelhead"><h2>Tus actividades</h2><?php if(!$showForm):?><a class="btn" href="?nueva=1">+ Nueva actividad</a><?php endif;?></div>
  <?php if(!$rows):?>
    <div class="empty">Todavía no hay actividades.<br><br><a class="btn" href="?nueva=1">Crear la primera actividad</a></div>
  <?php endif;?>
  <?php foreach($rows as $r):[$cls,$txt]=activity_summary($r);$pasos=act_pasos($r,$familias);$sig=$pasos['siguiente'];$pct=$r['lugares']>0?round(($r['lugares']-$r['libres'])*100/$r['lugares']):0;?>
    <div class="item <?=$r['estado']==='archivada'?'off':''?>">
      <div style="flex:1;min-width:260px">
        <a href="<?=e(url('admin/actividad.php?id='.$r['id']))?>" style="font-weight:700;font-size:17px;text-decoration:none"><?=e($r['nombre'])?></a>
        <span class="badge <?=e($cls)?>"><?=e($txt)?></span><br>
        <small class="muted">📅 <?=e(fecha_larga($r['fecha_actividad']))?> · <?=plural((int)$r['n_com'],'comisión','comisiones')?> · <?=plural((int)$r['n_tur'],'turno','turnos')?></small>
        <?php if($r['lugares']>0):?>
          <div class="progress" style="max-width:340px;margin:7px 0 3px" title="<?=$pct?>% ocupado"><i style="width:<?=$pct?>%"></i></div>
          <small class="muted"><?=(int)$r['inscritos']?> inscritas · <?=(int)$r['libres']?> lugares libres de <?=(int)$r['lugares']?></small>
        <?php endif;?>
        <?php if($sig&&$r['estado']!=='archivada'):?><br><small><b style="color:#8a5a00">Siguiente paso:</b> <a href="<?=e($sig['url'])?>"><?=e($sig['cta'])?> →</a></small><?php endif;?>
      </div>
      <div class="actions">
        <a class="btn small" href="<?=e(url('admin/actividad.php?id='.$r['id']))?>">Abrir</a>
        <form method="post" style="display:contents"><?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>">
          <?php if($r['estado']==='borrador'):?><button class="btn small ghost" name="accion" value="publicar">Publicar</button>
          <?php elseif($r['estado']==='publicada'):?><button class="btn small ghost" name="accion" value="ocultar" data-confirm="¿Ocultar la actividad? Dejará de verse en la página pública.">Ocultar</button>
          <?php else:?><button class="btn small ghost" name="accion" value="restaurar">Restaurar</button><?php endif;?>
          <?php if($r['total_ins']>0):?>
            <?php if($r['estado']!=='archivada'):?><button class="btn small gray" name="accion" value="archivar" data-confirm="¿Archivar la actividad? Se conserva con sus inscripciones.">Archivar</button><?php endif;?>
          <?php else:?>
            <button class="btn small red" name="accion" value="eliminar" data-confirm="¿Eliminar «<?=e($r['nombre'])?>» con sus comisiones y turnos? No se puede deshacer.">Eliminar</button>
          <?php endif;?>
        </form>
      </div>
    </div>
  <?php endforeach;?>
</section>
<?php endif;?>
<?php require __DIR__.'/../includes/admin-footer.php';
