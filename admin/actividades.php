<?php
require __DIR__.'/../includes/app.php';
$title='Actividades';
$subtitle='Crea una actividad, agrégale comisiones y turnos, y ábrela a las familias';
$me=require_admin();
$pdo=db();
$error='';
$edit=null;

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

        if($nombre==='')$error='Escribe el nombre de la actividad.';
        elseif($modo==='automatico'&&$apertura&&$cierre&&$apertura>=$cierre)$error='La fecha de cierre debe ser posterior a la de apertura.';

        if(!$error){
            $data=[$nombre,trim($_POST['descripcion']??''),($_POST['fecha_actividad']??'')?:null,$apertura,$cierre,$modo,$estado,$mostrar];
            if($id){
                $pdo->prepare('UPDATE actividades SET nombre=?,descripcion=?,fecha_actividad=?,fecha_apertura=?,fecha_cierre=?,modo_control=?,estado=?,mostrar_cerrada=? WHERE id=?')->execute([...$data,$id]);
                audit($me['id'],'guardar','actividades',$id);
                flash('success','Cambios guardados.');
                go('admin/actividades.php');
            }
            $pdo->prepare('INSERT INTO actividades(nombre,descripcion,fecha_actividad,fecha_apertura,fecha_cierre,modo_control,estado,mostrar_cerrada) VALUES(?,?,?,?,?,?,?,?)')->execute($data);
            $id=(int)$pdo->lastInsertId();
            audit($me['id'],'crear','actividades',$id);
            flash('success','Actividad creada. Siguiente paso: agrega las comisiones y los turnos.');
            go('admin/estructura.php?actividad='.$id);
        }
        // Con error: se vuelve a mostrar el formulario con lo que escribió la persona.
        $edit=['id'=>$id,'nombre'=>$nombre,'descripcion'=>trim($_POST['descripcion']??''),'fecha_actividad'=>$_POST['fecha_actividad']??'','fecha_apertura'=>$apertura,'fecha_cierre'=>$cierre,'modo_control'=>$modo,'estado'=>$estado,'mostrar_cerrada'=>$mostrar];
    }elseif(in_array($accion,['publicar','ocultar','archivar','restaurar'],true)){
        $nuevo=['publicar'=>'publicada','ocultar'=>'borrador','archivar'=>'archivada','restaurar'=>'borrador'][$accion];
        $pdo->prepare('UPDATE actividades SET estado=? WHERE id=?')->execute([$nuevo,$id]);
        audit($me['id'],$accion,'actividades',$id);
        flash('success',['publicada'=>'Actividad publicada: ya se ve en la página pública.','borrador'=>'Actividad oculta: pasó a borrador.','archivada'=>'Actividad archivada.'][$nuevo]);
        go('admin/actividades.php');
    }elseif($accion==='eliminar'){
        $s=$pdo->prepare('SELECT COUNT(*) FROM inscripciones WHERE actividad_id=?');$s->execute([$id]);
        if((int)$s->fetchColumn()>0){
            flash('error','No se puede eliminar: ya tiene inscripciones. Puedes archivarla para ocultarla.');
        }else{
            $pdo->beginTransaction();
            foreach(['cupos','comisiones','turnos'] as $t)$pdo->prepare("DELETE FROM $t WHERE actividad_id=?")->execute([$id]);
            $pdo->prepare('DELETE FROM actividades WHERE id=?')->execute([$id]);
            $pdo->commit();
            audit($me['id'],'eliminar','actividades',$id);
            flash('success','Actividad eliminada.');
        }
        go('admin/actividades.php');
    }
}

if(!$edit&&isset($_GET['editar'])){
    $s=$pdo->prepare('SELECT * FROM actividades WHERE id=?');$s->execute([(int)$_GET['editar']]);$edit=$s->fetch()?:null;
}
$showForm=$edit||isset($_GET['nueva'])||$error;
$form=$edit??['id'=>0,'nombre'=>'','descripcion'=>'','fecha_actividad'=>'','fecha_apertura'=>null,'fecha_cierre'=>null,'modo_control'=>'forzar_abierto','estado'=>'borrador','mostrar_cerrada'=>1];

$rows=$pdo->query("SELECT a.*,
  (SELECT COUNT(*) FROM comisiones WHERE actividad_id=a.id) n_com,
  (SELECT COUNT(*) FROM turnos WHERE actividad_id=a.id) n_tur,
  (SELECT COALESCE(SUM(capacidad),0) FROM cupos WHERE actividad_id=a.id AND habilitado=1) cupos,
  (SELECT COUNT(*) FROM inscripciones WHERE actividad_id=a.id AND estado='confirmada') inscritos,
  (SELECT COUNT(*) FROM inscripciones WHERE actividad_id=a.id) total_ins
  FROM actividades a ORDER BY FIELD(a.estado,'publicada','borrador','archivada'),a.fecha_actividad DESC,a.id DESC")->fetchAll();

require __DIR__.'/../includes/admin-header.php';
?>
<?php if($showForm):?>
<section class="panel">
  <div class="panelhead"><h2><?=$form['id']?'Editar actividad':'Nueva actividad'?></h2><a class="btn gray small" href="<?=e(url('admin/actividades.php'))?>">Cancelar</a></div>
  <?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
  <form method="post" class="formgrid">
    <input type="hidden" name="csrf" value="<?=e(csrf())?>">
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="<?=(int)$form['id']?>">

    <div class="field"><label for="nombre">Nombre de la actividad</label>
      <input id="nombre" name="nombre" required maxlength="150" value="<?=e($form['nombre'])?>" placeholder="Ej.: Bingo 2026" autofocus>
    </div>
    <div class="field"><label for="fecha">Fecha del evento</label>
      <input id="fecha" type="date" name="fecha_actividad" value="<?=e($form['fecha_actividad'])?>">
      <?=hint('Es la fecha en que se realiza la actividad (opcional).')?>
    </div>
    <div class="field full"><label for="desc">Descripción</label>
      <textarea id="desc" name="descripcion" rows="3" placeholder="Qué se hará, dónde, qué deben llevar las familias…"><?=e($form['descripcion'])?></textarea>
      <?=hint('Las familias la ven en la página pública.')?>
    </div>

    <div class="field full"><label>¿Cuándo pueden inscribirse las familias?</label>
      <div class="radios">
        <label><input type="radio" name="modo_control" value="forzar_abierto" <?=$form['modo_control']==='forzar_abierto'?'checked':''?>><span><b>Abierto ahora</b>Se pueden inscribir hasta que lo cierres tú.</span></label>
        <label><input type="radio" name="modo_control" value="automatico" <?=$form['modo_control']==='automatico'?'checked':''?>><span><b>Según fechas</b>Se abre y se cierra solo en las fechas que elijas.</span></label>
        <label><input type="radio" name="modo_control" value="forzar_cerrado" <?=$form['modo_control']==='forzar_cerrado'?'checked':''?>><span><b>Cerrado</b>Nadie puede inscribirse por ahora.</span></label>
      </div>
    </div>
    <div class="field full" id="fechas" <?=$form['modo_control']==='automatico'?'':'hidden'?>>
      <div class="formgrid">
        <div class="field"><label for="ap">Se abre el</label><input id="ap" type="datetime-local" name="fecha_apertura" value="<?=e(dt_to_input($form['fecha_apertura']))?>"><?=hint('Si lo dejas vacío, ya está abierto.')?></div>
        <div class="field"><label for="ci">Se cierra el</label><input id="ci" type="datetime-local" name="fecha_cierre" value="<?=e(dt_to_input($form['fecha_cierre']))?>"><?=hint('Si lo dejas vacío, no se cierra solo.')?></div>
      </div>
    </div>

    <div class="field full check"><label><input type="checkbox" name="publicada" <?=$form['estado']==='publicada'?'checked':''?>> Publicar en la página pública</label>
      <?=hint('Mientras esté sin marcar es un borrador: solo tú la ves. Te recomendamos publicarla cuando ya tenga comisiones, turnos y cupos.')?>
    </div>
    <div class="field full">
      <details class="adv"><summary>Opciones avanzadas</summary>
        <div class="field check" style="margin-top:12px"><label><input type="checkbox" name="ocultar_cerrada" <?=$form['mostrar_cerrada']?'':'checked'?>> Ocultar la actividad cuando las inscripciones estén cerradas</label>
        <?=hint('Por defecto sigue visible, marcada como «Cerrada».')?></div>
      </details>
    </div>
    <div class="field full"><button class="btn"><?=$form['id']?'Guardar cambios':'Crear y continuar →'?></button></div>
  </form>
</section>
<?php else:?>
<section class="guide">
  <details <?=$rows?'':'open'?>><summary>¿Cómo se prepara una actividad?</summary>
    <ul>
      <li><b>1. Crea la actividad</b> (nombre y fecha).</li>
      <li><b>2. Agrega comisiones</b>: los grupos de trabajo (Seguridad, Caja…).</li>
      <li><b>3. Agrega turnos</b>: los horarios (09:00–12:00…).</li>
      <li><b>4. Define los cupos</b>: cuántas familias caben en cada comisión y turno.</li>
      <li><b>5. Publícala</b> para que las familias puedan inscribirse.</li>
    </ul>
  </details>
</section>
<?php endif;?>

<section class="panel">
  <div class="panelhead"><h2>Tus actividades</h2><?php if(!$showForm):?><a class="btn" href="?nueva=1">+ Nueva actividad</a><?php endif;?></div>
  <?php if(!$rows):?>
    <div class="empty">Todavía no hay actividades.<br><br><a class="btn" href="?nueva=1">Crear la primera actividad</a></div>
  <?php endif;?>
  <?php foreach($rows as $r):[$cls,$txt]=activity_summary($r);?>
    <div class="item <?=$r['estado']==='archivada'?'off':''?>">
      <div>
        <b><?=e($r['nombre'])?></b> <span class="badge <?=e($cls)?>"><?=e($txt)?></span><br>
        <small class="muted"><?=e(fmt_date($r['fecha_actividad']))?> · <?=(int)$r['n_com']?> comisiones · <?=(int)$r['n_tur']?> turnos · <?=(int)$r['inscritos']?> de <?=(int)$r['cupos']?> cupos ocupados</small>
        <?php if($r['estado']!=='archivada'&&($r['n_com']==0||$r['n_tur']==0)):?>
          <small class="hint">⚠ Falta agregar <?=$r['n_com']==0?'comisiones':''?><?=($r['n_com']==0&&$r['n_tur']==0)?' y ':''?><?=$r['n_tur']==0?'turnos':''?> antes de publicar.</small>
        <?php endif;?>
      </div>
      <div class="actions">
        <a class="btn small" href="<?=e(url('admin/estructura.php?actividad='.$r['id']))?>">Comisiones y turnos</a>
        <a class="btn small ghost" href="?editar=<?=$r['id']?>">Editar</a>
        <form method="post" style="display:contents"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="id" value="<?=$r['id']?>">
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
<?php require __DIR__.'/../includes/admin-footer.php';
