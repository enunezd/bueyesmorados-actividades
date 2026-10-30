<?php
require __DIR__.'/../includes/app.php';
require __DIR__.'/../includes/admin-actividad.php';
$me=require_admin();
$pdo=db();

// Sin ?actividad= se abre la primera actividad no archivada.
$aid=(int)($_GET['actividad']??$_POST['actividad_id']??0);
if(!$aid){$aid=(int)$pdo->query("SELECT id FROM actividades WHERE estado<>'archivada' ORDER BY FIELD(estado,'publicada','borrador'),fecha_actividad DESC,id DESC LIMIT 1")->fetchColumn();}
$a=act_actual($pdo,$aid);
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
// Sube o baja una comisión en el orden en que se muestra a las familias.
function mover_comision(PDO $pdo,int $aid,int $id,string $dir):bool{
    $s=$pdo->prepare('SELECT id FROM comisiones WHERE actividad_id=? ORDER BY orden,nombre,id');$s->execute([$aid]);
    $ids=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
    $i=array_search($id,$ids,true);
    $j=$dir==='up'?(int)$i-1:(int)$i+1;
    if($i===false||$j<0||$j>=count($ids))return false;
    [$ids[$i],$ids[$j]]=[$ids[$j],$ids[$i]];
    $u=$pdo->prepare('UPDATE comisiones SET orden=? WHERE id=?');
    foreach($ids as $n=>$cid)$u->execute([$n+1,$cid]);
    return true;
}
// Cambia la capacidad de varios cupos sin bajarla de las familias ya inscritas. Devuelve cuántos no se pudieron bajar.
function fijar_capacidad(PDO $pdo,string $donde,array $par,int $cap):int{
    $q=$pdo->prepare("SELECT c.id,(SELECT COUNT(*) FROM inscripciones i WHERE i.cupo_id=c.id AND i.estado='confirmada') usados FROM cupos c WHERE $donde");
    $q->execute($par);
    $upd=$pdo->prepare('UPDATE cupos SET capacidad=?,habilitado=? WHERE id=?');
    $bajo=0;$cap=max(0,min(999,$cap));
    foreach($q->fetchAll() as $c){$n=$cap;if($n<(int)$c['usados']){$bajo++;$n=(int)$c['usados'];}$upd->execute([$n,$n>0?1:0,$c['id']]);}
    return $bajo;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_ok()){flash('error','El formulario venció, inténtalo de nuevo.');go($back);}
    $accion=$_POST['accion']??'';
    $id=(int)($_POST['id']??0);
    $ok=null;$err=null;

    try{
        switch($accion){
        case 'copiar_de':
            $r=copiar_estructura($pdo,(int)($_POST['desde']??0),$aid);
            $ok='Estructura copiada: '.plural($r['comisiones'],'comisión','comisiones').', '.plural($r['turnos'],'turno','turnos').' y '.plural($r['cupos'],'cupo','cupos').'.';
            break;

        case 'add_comisiones':
            $n=0;
            $nombres=array_unique(array_filter(array_map(fn($l)=>mb_substr(trim(utf8_limpio($l)),0,120),preg_split('/\R/',(string)($_POST['nombres']??'')))));
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
            else{$pdo->prepare('UPDATE comisiones SET nombre=?,reglas=? WHERE id=? AND actividad_id=?')->execute([mb_substr(utf8_limpio($nom),0,120),utf8_limpio(trim($_POST['reglas']??'')),$id,$aid]);$ok='Comisión actualizada.';}
            break;
        case 'toggle_comision':
            $pdo->prepare('UPDATE comisiones SET activa=1-activa WHERE id=? AND actividad_id=?')->execute([$id,$aid]);$ok='Listo.';
            break;
        case 'subir_comision':
        case 'bajar_comision':
            if(mover_comision($pdo,$aid,$id,$accion==='subir_comision'?'up':'down'))$ok='Orden actualizado.';
            break;
        case 'delete_comision':
            $err=delete_item($pdo,'comisiones','comision_id',$id,$aid);if(!$err)$ok='Comisión eliminada.';
            break;
        case 'cupos_fila':
            $bajo=fijar_capacidad($pdo,'c.actividad_id=? AND c.comision_id=?',[$aid,$id],(int)($_POST['capacidad']??0));
            $ok='Cupos de la comisión actualizados.'.($bajo?" En $bajo casilla(s) no se bajó del número de familias ya inscritas.":'');
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
        case 'cupos_columna':
            $bajo=fijar_capacidad($pdo,'c.actividad_id=? AND c.turno_id=?',[$aid,$id],(int)($_POST['capacidad']??0));
            $ok='Cupos del turno actualizados.'.($bajo?" En $bajo casilla(s) no se bajó del número de familias ya inscritas.":'');
            break;

        case 'guardar_cupos':
            $q=$pdo->prepare("SELECT c.id,c.capacidad,(SELECT COUNT(*) FROM inscripciones i WHERE i.cupo_id=c.id AND i.estado='confirmada') usados FROM cupos c WHERE c.actividad_id=?");
            $q->execute([$aid]);
            $upd=$pdo->prepare('UPDATE cupos SET capacidad=?,habilitado=? WHERE id=?');
            $bajo=0;
            foreach($q->fetchAll() as $c){
                $cap=isset($_POST['cap'][$c['id']])?(int)$_POST['cap'][$c['id']]:(int)$c['capacidad'];
                $cap=max(0,min(999,$cap));
                if($cap<(int)$c['usados']){$bajo++;$cap=(int)$c['usados'];}
                $upd->execute([$cap,$cap>0?1:0,$c['id']]);
            }
            $ok='Cupos guardados.'.($bajo?" En $bajo casilla(s) no se bajó del número de familias ya inscritas.":'');
            break;
        case 'cupos_todos':
            $bajo=fijar_capacidad($pdo,'c.actividad_id=?',[$aid],(int)($_POST['capacidad']??0));
            $ok='Cupos actualizados.'.($bajo?" En $bajo casilla(s) no se bajó del número de familias ya inscritas.":'');
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
$s=$pdo->prepare('SELECT * FROM comisiones WHERE actividad_id=? ORDER BY orden,nombre,id');$s->execute([$aid]);$co=$s->fetchAll();
$s=$pdo->prepare('SELECT * FROM turnos WHERE actividad_id=? ORDER BY hora_inicio,hora_fin');$s->execute([$aid]);$tu=$s->fetchAll();
$s=$pdo->prepare("SELECT c.*,(SELECT COUNT(*) FROM inscripciones i WHERE i.cupo_id=c.id AND i.estado='confirmada') usados FROM cupos c WHERE c.actividad_id=?");$s->execute([$aid]);
foreach($s->fetchAll() as $c)$cupo[$c['comision_id']][$c['turno_id']]=$c;
$co_conf=$tu_conf=[];
foreach(['comision_id'=>['co_ins','co_conf'],'turno_id'=>['tu_ins','tu_conf']] as $col=>[$total,$conf]){
    $s=$pdo->prepare("SELECT $col,COUNT(*) FROM inscripciones WHERE actividad_id=? GROUP BY $col");$s->execute([$aid]);$$total=$s->fetchAll(PDO::FETCH_KEY_PAIR);
    $s=$pdo->prepare("SELECT $col,COUNT(*) FROM inscripciones WHERE actividad_id=? AND estado='confirmada' GROUP BY $col");$s->execute([$aid]);$$conf=$s->fetchAll(PDO::FETCH_KEY_PAIR);
}
$co_act=array_values(array_filter($co,fn($x)=>$x['activa']));
$tu_act=array_values(array_filter($tu,fn($x)=>$x['activo']));
$familias=act_familias_activas($pdo);
$otras=array_filter(act_lista($pdo,'a.id<>'.(int)$aid),fn($r)=>$r['n_com']>0||$r['n_tur']>0);

$title='Comisiones y turnos';
$subtitle=$a['nombre'].' · Define los grupos de trabajo, los horarios y cuántas familias caben en cada uno';
require __DIR__.'/../includes/admin-header.php';
act_tabs($a,'estructura');
$cs=csrf_field().'<input type="hidden" name="actividad_id" value="'.$aid.'">';
$ncupos=count($co_act)*count($tu_act);
?>

<?php if(!$co&&!$tu&&$otras):?>
<section class="panel">
  <div class="panelhead"><h2>¿Repites una actividad anterior?</h2></div>
  <form method="post" class="inline"><?=$cs?><input type="hidden" name="accion" value="copiar_de">
    <div class="field"><label for="desde">Copiar comisiones, turnos y cupos de</label>
      <select id="desde" name="desde"><?php foreach($otras as $o):?><option value="<?=$o['id']?>"><?=e($o['nombre'])?> (<?=plural((int)$o['n_com'],'comisión','comisiones')?>, <?=plural((int)$o['n_tur'],'turno','turnos')?>)</option><?php endforeach;?></select></div>
    <button class="btn ghost">Copiar estructura</button>
  </form>
  <?=hint('No se copian inscripciones. Después puedes cambiar lo que necesites.')?>
</section>
<?php endif;?>

<section class="callout"><details <?=($co||$tu)?'':'open'?>><summary>¿Qué es cada cosa?</summary><ul>
  <li><b>Comisión</b>: un grupo de trabajo, por ejemplo «Seguridad» o «Caja».</li>
  <li><b>Turno</b>: un horario, por ejemplo 09:00 a 12:00.</li>
  <li><b>Cupo</b>: cuántas familias caben en cada comisión y turno. Se crean solos al combinar comisiones y turnos; tú solo ajustas el número.</li>
</ul></details></section>

<div class="twocol">
<section class="panel" id="comisiones">
  <div class="panelhead"><h2>Comisiones <span class="badge info"><?=count($co)?></span></h2></div>
  <?php if(!$co):?><div class="empty">Aún no hay comisiones.<br>Escribe los nombres abajo, uno por línea.</div><?php endif;?>
  <?php foreach($co as $n=>$c):$tiene=(int)($co_ins[$c['id']]??0);?>
    <div class="item <?=$c['activa']?'':'off'?>">
      <div><b><?=e($c['nombre'])?></b><?php if(!$c['activa']):?> <span class="badge draft">Oculta</span><?php endif;?>
        <?php if($n_conf=(int)($co_conf[$c['id']]??0)):?> <small class="muted">· <?=plural($n_conf,'familia inscrita','familias inscritas')?></small><?php endif;?>
        <?php if(trim((string)$c['reglas'])!==''):?><br><small class="muted"><?=e($c['reglas'])?></small><?php endif;?></div>
      <form method="post" class="actions"><?=$cs?><input type="hidden" name="id" value="<?=$c['id']?>">
        <?php if($n>0):?><button class="btn small ghost" name="accion" value="subir_comision" title="Subir" aria-label="Subir <?=e($c['nombre'])?>">↑</button><?php endif;?>
        <?php if($n<count($co)-1):?><button class="btn small ghost" name="accion" value="bajar_comision" title="Bajar" aria-label="Bajar <?=e($c['nombre'])?>">↓</button><?php endif;?>
        <button class="btn small ghost" name="accion" value="toggle_comision"><?=$c['activa']?'Ocultar':'Mostrar'?></button>
        <?php if(!$tiene):?><button class="btn small red" name="accion" value="delete_comision" data-confirm="¿Eliminar la comisión «<?=e($c['nombre'])?>»?">Eliminar</button><?php endif;?>
      </form>
      <details><summary>Editar</summary>
        <form method="post"><?=$cs?><input type="hidden" name="accion" value="edit_comision"><input type="hidden" name="id" value="<?=$c['id']?>">
          <div class="field"><label>Nombre</label><input type="text" name="nombre" required maxlength="120" value="<?=e($c['nombre'])?>"></div>
          <div class="field"><label>Indicaciones para las familias</label><textarea name="reglas" rows="2" placeholder="Ej.: llegar 15 minutos antes"><?=e($c['reglas'])?></textarea><?=hint('Se muestran bajo el nombre de la comisión en la página pública.')?></div>
          <button class="btn small">Guardar</button>
        </form>
        <?php if($c['activa']&&$tu_act):?>
        <form method="post" class="inline sub"><?=$cs?><input type="hidden" name="accion" value="cupos_fila"><input type="hidden" name="id" value="<?=$c['id']?>">
          <div class="field"><label>Cupos en todos los turnos de esta comisión</label><input type="number" name="capacidad" min="0" max="999" value="<?=default_capacity($pdo,$aid)?>" style="width:90px"></div>
          <button class="btn small ghost">Aplicar a la fila</button>
        </form><?php endif;?>
      </details>
    </div>
  <?php endforeach;?>
  <form method="post" style="margin-top:16px"><?=$cs?><input type="hidden" name="accion" value="add_comisiones">
    <div class="field"><label for="nombres">Agregar comisiones</label>
      <textarea id="nombres" name="nombres" rows="3" placeholder="Seguridad&#10;Caja&#10;Tómbola"></textarea>
      <div class="chips" data-chips="Seguridad,Caja,Tómbola,Comida,Limpieza,Decoración,Logística,Recepción" data-target="#nombres" aria-label="Sugerencias"></div>
      <?=hint('Escribe una por línea o toca las sugerencias: puedes agregar varias de una vez.')?></div>
    <button class="btn">Agregar</button>
  </form>
</section>

<section class="panel" id="turnos">
  <div class="panelhead"><h2>Turnos <span class="badge info"><?=count($tu)?></span></h2></div>
  <?php if(!$tu):?><div class="empty">Aún no hay turnos.<br>Crea uno o varios seguidos abajo.</div><?php endif;?>
  <?php foreach($tu as $t):$tiene=(int)($tu_ins[$t['id']]??0);$rango=hhmm($t['hora_inicio']).' – '.hhmm($t['hora_fin']);?>
    <div class="item <?=$t['activo']?'':'off'?>">
      <div><b><?=e($rango)?></b><?php if(!$t['activo']):?> <span class="badge draft">Oculto</span><?php endif;?>
        <?php if($n_conf=(int)($tu_conf[$t['id']]??0)):?> <small class="muted">· <?=plural($n_conf,'familia inscrita','familias inscritas')?></small><?php endif;?></div>
      <form method="post" class="actions"><?=$cs?><input type="hidden" name="id" value="<?=$t['id']?>">
        <button class="btn small ghost" name="accion" value="toggle_turno"><?=$t['activo']?'Ocultar':'Mostrar'?></button>
        <?php if(!$tiene):?><button class="btn small red" name="accion" value="delete_turno" data-confirm="¿Eliminar el turno <?=e($rango)?>?">Eliminar</button><?php endif;?>
      </form>
      <details><summary>Cambiar horario</summary>
        <form method="post" class="inline"><?=$cs?><input type="hidden" name="accion" value="edit_turno"><input type="hidden" name="id" value="<?=$t['id']?>">
          <div class="field"><label>Inicio</label><input type="time" name="hora_inicio" required value="<?=e(hhmm($t['hora_inicio']))?>"></div>
          <div class="field"><label>Fin</label><input type="time" name="hora_fin" required value="<?=e(hhmm($t['hora_fin']))?>"></div>
          <button class="btn small">Guardar</button>
        </form>
        <?php if($t['activo']&&$co_act):?>
        <form method="post" class="inline sub"><?=$cs?><input type="hidden" name="accion" value="cupos_columna"><input type="hidden" name="id" value="<?=$t['id']?>">
          <div class="field"><label>Cupos en todas las comisiones de este turno</label><input type="number" name="capacidad" min="0" max="999" value="<?=default_capacity($pdo,$aid)?>" style="width:90px"></div>
          <button class="btn small ghost">Aplicar a la columna</button>
        </form><?php endif;?>
      </details>
    </div>
  <?php endforeach;?>
  <form method="post" class="inline" style="margin-top:16px"><?=$cs?><input type="hidden" name="accion" value="add_turno">
    <div class="field"><label for="hi">Inicio</label><input id="hi" type="time" name="hora_inicio" required></div>
    <div class="field"><label for="hf">Fin</label><input id="hf" type="time" name="hora_fin" required></div>
    <button class="btn">Agregar turno</button>
  </form>
  <details class="adv" style="margin-top:14px" <?=$tu?'':'open'?>><summary>Crear varios turnos seguidos</summary>
    <form method="post" class="inline" style="margin-top:12px" data-turnos-preview><?=$cs?><input type="hidden" name="accion" value="add_turnos">
      <div class="field"><label>Desde las</label><input type="time" name="hora_inicio" required value="09:00"></div>
      <div class="field"><label>Cada turno dura</label><select name="duracion"><option value="60">1 hora</option><option value="90">1 h 30 min</option><option value="120">2 horas</option><option value="180" selected>3 horas</option><option value="240">4 horas</option></select></div>
      <div class="field"><label>Cantidad</label><input type="number" name="cantidad" min="1" max="12" value="3" style="width:80px"></div>
      <button class="btn ghost">Crear</button>
      <p class="hint" data-preview style="width:100%;margin:2px 0 0"></p>
    </form>
  </details>
</section>
</div>

<section class="panel" id="cupos">
  <div class="panelhead"><h2>Cupos: familias por comisión y turno</h2>
    <?php if($co_act&&$tu_act):?><form method="post" class="inline"><?=$cs?><input type="hidden" name="accion" value="cupos_todos">
      <div class="field"><label>Poner a todos</label><input type="number" name="capacidad" min="0" max="999" value="<?=default_capacity($pdo,$aid)?>" style="width:80px"></div>
      <button class="btn small ghost" data-confirm="¿Cambiar todos los cupos a este número?">Aplicar</button></form><?php endif;?>
  </div>
  <?php if(!$co_act||!$tu_act):?>
    <div class="empty">Agrega al menos una comisión y un turno para definir los cupos.</div>
  <?php else:
    $totCol=array_fill_keys(array_column($tu_act,'id'),0);$totalGeneral=0;?>
  <?php if($familias>0):
      $pend=(int)$a['sin_inscribir'];$lib=(int)$a['libres'];?>
    <div class="alert <?=$pend>$lib?'warn':'info'?>" style="margin-bottom:14px">
      <?php if($pend<=$lib):?>Hay lugar para todas: <b><?=plural($pend,'familia sin inscribir','familias sin inscribir')?></b> y <b><?=plural($lib,'lugar libre','lugares libres')?></b>.
      <?php else:
        $necesita=$ncupos?(int)ceil(((int)$a['lugares']-$lib+$pend)/$ncupos):0;?>
        Hay <b><?=plural($pend,'familia sin inscribir','familias sin inscribir')?></b> pero solo <b><?=plural($lib,'lugar libre','lugares libres')?></b>: <b>faltan <?=$pend-$lib?></b> para que todas tengan uno.
        <?php if($necesita>0):?>
        <form method="post" style="display:inline"><?=$cs?><input type="hidden" name="accion" value="cupos_todos"><input type="hidden" name="capacidad" value="<?=$necesita?>"><button class="btn small ghost" data-confirm="¿Poner <?=$necesita?> cupos en cada casilla?">Usar <?=$necesita?> en cada casilla</button></form><?php endif;?>
        <br><small>Si no hace falta que todas participen, déjalo así: el sorteo elegirá al azar quiénes ocupan los lugares libres.</small>
      <?php endif;?>
    </div>
  <?php endif;?>
  <form method="post" data-dirty id="f-cupos"><?=$cs?><input type="hidden" name="accion" value="guardar_cupos">
    <div style="overflow:auto"><table class="matrix"><thead><tr><th>Comisión</th><?php foreach($tu_act as $t):?><th><?=e(hhmm($t['hora_inicio']).'–'.hhmm($t['hora_fin']))?></th><?php endforeach;?><th>Total</th></tr></thead>
    <tbody>
    <?php foreach($co_act as $c):$totFila=0;?><tr><td><b><?=e($c['nombre'])?></b></td>
      <?php foreach($tu_act as $t):$q=$cupo[$c['id']][$t['id']]??null;$val=$q?($q['habilitado']?(int)$q['capacidad']:0):0;$totFila+=$val;$totCol[$t['id']]+=$val;?><td>
        <?php if($q):?><input type="number" name="cap[<?=$q['id']?>]" min="<?=(int)$q['usados']?>" max="999" value="<?=$val?>" class="<?=$val===0?'cero':''?>" aria-label="Cupos de <?=e($c['nombre'].' '.hhmm($t['hora_inicio']))?>"><small><?=(int)$q['usados']?> inscritas</small><?php endif;?>
      </td><?php endforeach;?><td class="tot"><?=$totFila?><?php $totalGeneral+=$totFila;?></td></tr>
    <?php endforeach;?></tbody>
    <tfoot><tr><td>Total</td><?php foreach($tu_act as $t):?><td><?=$totCol[$t['id']]?></td><?php endforeach;?><td><?=$totalGeneral?></td></tr></tfoot></table></div>
    <?=hint('Escribe cuántas familias caben en cada casilla. Pon 0 si en ese turno no se necesita esa comisión. No se puede bajar de las familias ya inscritas.')?>
    <div class="barra-guardar"><span data-dirty-aviso hidden>⚠ Tienes cambios sin guardar.</span><span class="muted">Total: <?=$totalGeneral?> lugares</span><button class="btn">Guardar cupos</button></div>
  </form>
  <?php endif;?>
</section>
<?php require __DIR__.'/../includes/admin-footer.php';
