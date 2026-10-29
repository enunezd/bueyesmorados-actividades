<?php
require __DIR__.'/../includes/app.php';
$title='Comisiones y turnos';
$subtitle='Define los grupos de trabajo, los horarios y cuántas familias caben en cada uno';
$me=require_admin();
$pdo=db();

$aid=(int)($_GET['actividad']??$_POST['actividad_id']??0);
$acts=$pdo->query('SELECT * FROM actividades ORDER BY FIELD(estado,\'publicada\',\'borrador\',\'archivada\'),fecha_actividad DESC,id DESC')->fetchAll();
$actividad=null;
foreach($acts as $a)if((int)$a['id']===$aid)$actividad=$a;
if(!$actividad&&$acts){$actividad=$acts[0];$aid=(int)$actividad['id'];}
$back='admin/estructura.php?actividad='.$aid;

// Capacidad más usada en la actividad (3 si todavía no hay cupos): se usa para los cupos nuevos.
function default_capacity(PDO $pdo,int $aid):int{
    $s=$pdo->prepare('SELECT capacidad FROM cupos WHERE actividad_id=? AND capacidad>0 GROUP BY capacidad ORDER BY COUNT(*) DESC,capacidad DESC LIMIT 1');
    $s->execute([$aid]);
    return (int)($s->fetchColumn()?:3);
}
// Crea los cupos que falten para cada pareja comisión × turno.
function sync_cupos(PDO $pdo,int $aid):void{
    $pdo->prepare('INSERT IGNORE INTO cupos(actividad_id,comision_id,turno_id,capacidad) SELECT ?,c.id,t.id,? FROM comisiones c CROSS JOIN turnos t WHERE c.actividad_id=? AND t.actividad_id=?')
        ->execute([$aid,default_capacity($pdo,$aid),$aid,$aid]);
}
function next_order(PDO $pdo,string $table,int $aid):int{
    $s=$pdo->prepare("SELECT COALESCE(MAX(orden),0)+1 FROM $table WHERE actividad_id=?");$s->execute([$aid]);return (int)$s->fetchColumn();
}
function valid_time(string $t):bool{return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$t);}
// Elimina una comisión o un turno solo si nunca tuvo inscripciones.
function delete_item(PDO $pdo,string $table,string $col,int $id,int $aid):?string{
    $s=$pdo->prepare("SELECT COUNT(*) FROM $table WHERE id=? AND actividad_id=?");$s->execute([$id,$aid]);
    if(!$s->fetchColumn())return 'No se encontró el elemento.';
    $s=$pdo->prepare("SELECT COUNT(*) FROM inscripciones WHERE $col=?");$s->execute([$id]);
    if((int)$s->fetchColumn()>0)return 'No se puede eliminar porque ya tiene inscripciones. Usa «Ocultar» para que las familias no lo vean.';
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM cupos WHERE $col=?")->execute([$id]);
    $pdo->prepare("DELETE FROM $table WHERE id=?")->execute([$id]);
    $pdo->commit();
    return null;
}

if($_SERVER['REQUEST_METHOD']==='POST'&&$actividad){
    if(!csrf_ok()){flash('error','El formulario venció, inténtalo de nuevo.');go($back);}
    $accion=$_POST['accion']??'';
    $id=(int)($_POST['id']??0);
    $ok=null;$err=null;

    try{
        switch($accion){
        case 'add_comisiones':
            $n=0;
            $nombres=array_unique(array_filter(array_map(fn($l)=>mb_substr(trim($l),0,120),preg_split('/\R/',(string)($_POST['nombres']??'')))));
            $ins=$pdo->prepare('INSERT IGNORE INTO comisiones(actividad_id,nombre,orden) VALUES(?,?,?)');
            foreach($nombres as $nom){$ins->execute([$aid,$nom,next_order($pdo,'comisiones',$aid)]);$n+=$ins->rowCount();}
            sync_cupos($pdo,$aid);
            if(!$nombres)$err='Escribe al menos un nombre.';
            elseif(!$n)$err='Esas comisiones ya existían.';
            else $ok=$n===1?'Comisión agregada.':"$n comisiones agregadas.";
            break;
        case 'edit_comision':
            $nom=trim($_POST['nombre']??'');
            if($nom==='')$err='El nombre no puede quedar vacío.';
            else{$pdo->prepare('UPDATE comisiones SET nombre=?,descripcion=?,reglas=? WHERE id=? AND actividad_id=?')->execute([mb_substr($nom,0,120),trim($_POST['descripcion']??''),trim($_POST['reglas']??''),$id,$aid]);$ok='Comisión actualizada.';}
            break;
        case 'toggle_comision':
            $pdo->prepare('UPDATE comisiones SET activa=1-activa WHERE id=? AND actividad_id=?')->execute([$id,$aid]);$ok='Listo.';
            break;
        case 'delete_comision':
            $err=delete_item($pdo,'comisiones','comision_id',$id,$aid);if(!$err)$ok='Comisión eliminada.';
            break;

        case 'add_turno':
        case 'add_turnos':
            $ini=(string)($_POST['hora_inicio']??'');$fin=(string)($_POST['hora_fin']??'');
            if(!valid_time($ini)){$err='Indica la hora de inicio.';break;}
            $tramos=[];
            if($accion==='add_turno'){
                if(!valid_time($fin)||$fin<=$ini){$err='La hora de fin debe ser posterior a la de inicio.';break;}
                $tramos[]=[$ini,$fin];
            }else{
                $dur=max(15,min(720,(int)($_POST['duracion']??0)));$cant=max(1,min(12,(int)($_POST['cantidad']??0)));
                $t=new DateTimeImmutable("2000-01-01 $ini");
                for($i=0;$i<$cant;$i++){$e2=$t->modify("+$dur minutes");if($e2->format('Y-m-d')!=='2000-01-01')break;$tramos[]=[$t->format('H:i'),$e2->format('H:i')];$t=$e2;}
                if(!$tramos){$err='Con esos datos los turnos pasan de medianoche.';break;}
            }
            $n=0;$ins=$pdo->prepare('INSERT IGNORE INTO turnos(actividad_id,hora_inicio,hora_fin,orden) VALUES(?,?,?,?)');
            foreach($tramos as [$a1,$a2]){$ins->execute([$aid,$a1,$a2,next_order($pdo,'turnos',$aid)]);$n+=$ins->rowCount();}
            sync_cupos($pdo,$aid);
            if(!$n)$err='Ese turno ya existe.';else $ok=$n===1?'Turno agregado.':"$n turnos agregados.";
            break;
        case 'edit_turno':
            $ini=(string)($_POST['hora_inicio']??'');$fin=(string)($_POST['hora_fin']??'');
            if(!valid_time($ini)||!valid_time($fin)||$fin<=$ini)$err='La hora de fin debe ser posterior a la de inicio.';
            else{$pdo->prepare('UPDATE turnos SET hora_inicio=?,hora_fin=? WHERE id=? AND actividad_id=?')->execute([$ini,$fin,$id,$aid]);$ok='Turno actualizado.';}
            break;
        case 'toggle_turno':
            $pdo->prepare('UPDATE turnos SET activo=1-activo WHERE id=? AND actividad_id=?')->execute([$id,$aid]);$ok='Listo.';
            break;
        case 'delete_turno':
            $err=delete_item($pdo,'turnos','turno_id',$id,$aid);if(!$err)$ok='Turno eliminado.';
            break;

        case 'guardar_cupos':
        case 'cupos_todos':
            $q=$pdo->prepare("SELECT c.id,c.capacidad,(SELECT COUNT(*) FROM inscripciones i WHERE i.cupo_id=c.id AND i.estado='confirmada') usados FROM cupos c WHERE c.actividad_id=?");
            $q->execute([$aid]);
            $upd=$pdo->prepare('UPDATE cupos SET capacidad=?,habilitado=? WHERE id=?');
            $bajo=[];
            foreach($q->fetchAll() as $c){
                $cap=$accion==='cupos_todos'?(int)$_POST['capacidad']:(isset($_POST['cap'][$c['id']])?(int)$_POST['cap'][$c['id']]:(int)$c['capacidad']);
                $cap=max(0,min(999,$cap));
                if($cap<(int)$c['usados']){$bajo[]=1;$cap=(int)$c['usados'];}
                $upd->execute([$cap,$cap>0?1:0,$c['id']]);
            }
            $ok='Cupos guardados.'.($bajo?' En '.count($bajo).' casilla(s) no se bajó del número de familias ya inscritas.':'');
            break;
        }
    }catch(Throwable $ex){
        if($pdo->inTransaction())$pdo->rollBack();
        $err=($ex instanceof PDOException&&($ex->errorInfo[1]??0)==1062)?'Ya existe un elemento con ese nombre u horario.':'No se pudo completar la acción.';
        error_log($ex->getMessage());
    }
    if($err)flash('error',$err);elseif($ok){flash('success',$ok);audit($me['id'],$accion,'estructura',$id?:null,"actividad $aid");}
    go($back);
}

$co=$tu=$cupo=$co_ins=$tu_ins=[];
if($actividad){
    $s=$pdo->prepare('SELECT * FROM comisiones WHERE actividad_id=? ORDER BY orden,nombre');$s->execute([$aid]);$co=$s->fetchAll();
    $s=$pdo->prepare('SELECT * FROM turnos WHERE actividad_id=? ORDER BY hora_inicio,hora_fin');$s->execute([$aid]);$tu=$s->fetchAll();
    $s=$pdo->prepare("SELECT c.*,(SELECT COUNT(*) FROM inscripciones i WHERE i.cupo_id=c.id AND i.estado='confirmada') usados FROM cupos c WHERE c.actividad_id=?");$s->execute([$aid]);
    foreach($s->fetchAll() as $c)$cupo[$c['comision_id']][$c['turno_id']]=$c;
    foreach(['comision_id'=>'co_ins','turno_id'=>'tu_ins'] as $col=>$var){
        $s=$pdo->prepare("SELECT $col,COUNT(*) FROM inscripciones WHERE actividad_id=? GROUP BY $col");$s->execute([$aid]);$$var=$s->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
$co_act=array_values(array_filter($co,fn($x)=>$x['activa']));
$tu_act=array_values(array_filter($tu,fn($x)=>$x['activo']));
$total_cupos=0;foreach($cupo as $row)foreach($row as $c)if($c['habilitado'])$total_cupos+=$c['capacidad'];
[$cls,$txt]=$actividad?activity_summary($actividad):['',''];

require __DIR__.'/../includes/admin-header.php';
$csrf='<input type="hidden" name="csrf" value="'.e(csrf()).'"><input type="hidden" name="actividad_id" value="'.$aid.'">';
?>
<?php if(!$actividad):?>
  <section class="panel empty">Primero crea una actividad.<br><br><a class="btn" href="<?=e(url('admin/actividades.php?nueva=1'))?>">Crear actividad</a></section>
<?php require __DIR__.'/../includes/admin-footer.php';return;endif;?>

<section class="panel">
  <div class="panelhead">
    <form method="get" class="inline"><div class="field"><label for="act">Actividad</label>
      <select id="act" name="actividad" onchange="this.form.submit()"><?php foreach($acts as $a):?><option value="<?=$a['id']?>" <?=$aid==$a['id']?'selected':''?>><?=e($a['nombre'])?></option><?php endforeach;?></select></div></form>
    <div><span class="badge <?=e($cls)?>"><?=e($txt)?></span>
      <a class="btn small ghost" href="<?=e(url('admin/actividades.php?editar='.$aid))?>">Editar actividad</a></div>
  </div>
</section>

<section class="steps">
  <a class="step <?=$co?'done':'todo'?>" href="#comisiones"><b>1. Comisiones</b><span><?=$co?count($co).' creadas':'Falta agregar'?></span></a>
  <a class="step <?=$tu?'done':'todo'?>" href="#turnos"><b>2. Turnos</b><span><?=$tu?count($tu).' creados':'Falta agregar'?></span></a>
  <a class="step <?=$total_cupos?'done':'todo'?>" href="#cupos"><b>3. Cupos</b><span><?=$total_cupos?$total_cupos.' lugares en total':'Se crean al tener 1 y 2'?></span></a>
  <a class="step <?=$actividad['estado']==='publicada'?'done':'todo'?>" href="<?=e(url('admin/actividades.php'))?>"><b>4. Publicar</b><span><?=$actividad['estado']==='publicada'?'Publicada':'Aún es borrador'?></span></a>
</section>

<section class="guide"><details <?=($co||$tu)?'':'open'?>><summary>¿Qué es cada cosa?</summary><ul>
  <li><b>Comisión</b>: un grupo de trabajo, por ejemplo «Seguridad» o «Caja».</li>
  <li><b>Turno</b>: un horario, por ejemplo 09:00 a 12:00.</li>
  <li><b>Cupo</b>: cuántas familias caben en cada comisión y turno. Se crea solo al combinar comisiones y turnos; tú solo ajustas el número.</li>
</ul></details></section>

<div class="twocol">
<section class="panel" id="comisiones">
  <div class="panelhead"><h2>Comisiones</h2></div>
  <?php if(!$co):?><div class="empty">Aún no hay comisiones.</div><?php endif;?>
  <?php foreach($co as $c):$tiene=(int)($co_ins[$c['id']]??0);?>
    <div class="item <?=$c['activa']?'':'off'?>">
      <div><b><?=e($c['nombre'])?></b><?php if(!$c['activa']):?> <span class="badge draft">Oculta</span><?php endif;?>
        <?php if($c['reglas']):?><br><small class="muted"><?=e($c['reglas'])?></small><?php endif;?></div>
      <form method="post" class="actions"><?=$csrf?><input type="hidden" name="id" value="<?=$c['id']?>">
        <button class="btn small ghost" name="accion" value="toggle_comision"><?=$c['activa']?'Ocultar':'Mostrar'?></button>
        <?php if(!$tiene):?><button class="btn small red" name="accion" value="delete_comision" data-confirm="¿Eliminar la comisión «<?=e($c['nombre'])?>»?">Eliminar</button><?php endif;?>
      </form>
      <details><summary>Editar</summary>
        <form method="post"><?=$csrf?><input type="hidden" name="accion" value="edit_comision"><input type="hidden" name="id" value="<?=$c['id']?>">
          <div class="field"><label>Nombre</label><input name="nombre" required maxlength="120" value="<?=e($c['nombre'])?>"></div>
          <div class="field"><label>Reglas o indicaciones</label><textarea name="reglas" rows="2"><?=e($c['reglas'])?></textarea><?=hint('Se muestran a las familias bajo el nombre de la comisión.')?></div>
          <button class="btn small">Guardar</button>
        </form>
      </details>
    </div>
  <?php endforeach;?>
  <form method="post" style="margin-top:16px"><?=$csrf?><input type="hidden" name="accion" value="add_comisiones">
    <div class="field"><label for="nombres">Agregar comisiones</label>
      <textarea id="nombres" name="nombres" rows="3" placeholder="Seguridad&#10;Caja&#10;Tómbola"></textarea>
      <?=hint('Escribe una por línea: puedes agregar varias de una vez.')?></div>
    <button class="btn">Agregar</button>
  </form>
</section>

<section class="panel" id="turnos">
  <div class="panelhead"><h2>Turnos</h2></div>
  <?php if(!$tu):?><div class="empty">Aún no hay turnos.</div><?php endif;?>
  <?php foreach($tu as $t):$tiene=(int)($tu_ins[$t['id']]??0);?>
    <div class="item <?=$t['activo']?'':'off'?>">
      <div><b><?=e(hhmm($t['hora_inicio']).' – '.hhmm($t['hora_fin']))?></b><?php if(!$t['activo']):?> <span class="badge draft">Oculto</span><?php endif;?></div>
      <form method="post" class="actions"><?=$csrf?><input type="hidden" name="id" value="<?=$t['id']?>">
        <button class="btn small ghost" name="accion" value="toggle_turno"><?=$t['activo']?'Ocultar':'Mostrar'?></button>
        <?php if(!$tiene):?><button class="btn small red" name="accion" value="delete_turno" data-confirm="¿Eliminar el turno <?=e(hhmm($t['hora_inicio']).' – '.hhmm($t['hora_fin']))?>?">Eliminar</button><?php endif;?>
      </form>
      <details><summary>Cambiar horario</summary>
        <form method="post" class="inline"><?=$csrf?><input type="hidden" name="accion" value="edit_turno"><input type="hidden" name="id" value="<?=$t['id']?>">
          <div class="field"><label>Inicio</label><input type="time" name="hora_inicio" required value="<?=e(hhmm($t['hora_inicio']))?>"></div>
          <div class="field"><label>Fin</label><input type="time" name="hora_fin" required value="<?=e(hhmm($t['hora_fin']))?>"></div>
          <button class="btn small">Guardar</button>
        </form>
      </details>
    </div>
  <?php endforeach;?>
  <form method="post" class="inline" style="margin-top:16px"><?=$csrf?><input type="hidden" name="accion" value="add_turno">
    <div class="field"><label for="hi">Inicio</label><input id="hi" type="time" name="hora_inicio" required></div>
    <div class="field"><label for="hf">Fin</label><input id="hf" type="time" name="hora_fin" required></div>
    <button class="btn">Agregar turno</button>
  </form>
  <details class="adv" style="margin-top:14px"><summary>Crear varios turnos seguidos</summary>
    <form method="post" class="inline" style="margin-top:12px"><?=$csrf?><input type="hidden" name="accion" value="add_turnos">
      <div class="field"><label>Desde las</label><input type="time" name="hora_inicio" required value="09:00"></div>
      <div class="field"><label>Cada turno dura</label><select name="duracion"><option value="60">1 hora</option><option value="90">1 h 30 min</option><option value="120">2 horas</option><option value="180" selected>3 horas</option><option value="240">4 horas</option></select></div>
      <div class="field"><label>Cantidad</label><input type="number" name="cantidad" min="1" max="12" value="3" style="width:80px"></div>
      <button class="btn ghost">Crear</button>
    </form>
    <?=hint('Ejemplo: desde 09:00, de 3 horas, cantidad 3 → 09:00–12:00, 12:00–15:00 y 15:00–18:00.')?>
  </details>
</section>
</div>

<section class="panel" id="cupos">
  <div class="panelhead"><h2>Cupos: familias por comisión y turno</h2>
    <?php if($co_act&&$tu_act):?><form method="post" class="inline"><?=$csrf?><input type="hidden" name="accion" value="cupos_todos">
      <div class="field"><label>Poner a todos</label><input type="number" name="capacidad" min="0" max="999" value="<?=default_capacity($pdo,$aid)?>" style="width:80px"></div>
      <button class="btn small ghost" data-confirm="¿Cambiar todos los cupos a este número?">Aplicar</button></form><?php endif;?>
  </div>
  <?php if(!$co_act||!$tu_act):?>
    <div class="empty">Agrega al menos una comisión y un turno para definir los cupos.</div>
  <?php else:?>
  <form method="post"><?=$csrf?><input type="hidden" name="accion" value="guardar_cupos">
    <table class="matrix"><tr><th>Comisión</th><?php foreach($tu_act as $t):?><th><?=e(hhmm($t['hora_inicio']).'–'.hhmm($t['hora_fin']))?></th><?php endforeach;?></tr>
    <?php foreach($co_act as $c):?><tr><td><b><?=e($c['nombre'])?></b></td>
      <?php foreach($tu_act as $t):$q=$cupo[$c['id']][$t['id']]??null;?><td>
        <?php if($q):?><input type="number" name="cap[<?=$q['id']?>]" min="<?=(int)$q['usados']?>" max="999" value="<?=$q['habilitado']?(int)$q['capacidad']:0?>" aria-label="Cupos de <?=e($c['nombre'].' '.hhmm($t['hora_inicio']))?>"><small><?=(int)$q['usados']?> inscritas</small><?php endif;?>
      </td><?php endforeach;?></tr>
    <?php endforeach;?></table>
    <?=hint('Escribe cuántas familias caben en cada casilla. Pon 0 si en ese turno no se necesita esa comisión.')?>
    <p><button class="btn">Guardar cupos</button></p>
  </form>
  <?php endif;?>
</section>
<?php require __DIR__.'/../includes/admin-footer.php';
