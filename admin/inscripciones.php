<?php
require __DIR__.'/../includes/app.php';
require __DIR__.'/../includes/admin-actividad.php';
$me=require_admin();
$pdo=db();
asig_ejecutar_pendientes($pdo);

$aid=(int)($_GET['actividad']??$_POST['actividad_id']??0);
$act=$aid?act_cargar($pdo,$aid):null;
if($aid&&!$act)$aid=0;

// Cupos con lugar libre (para mover o inscribir): [actividad_id => [[id, texto, libres], …]]
function cupos_con_lugar(PDO $pdo,int $aid=0):array{
    $q=$pdo->query("SELECT c.id,c.actividad_id,co.nombre comision,t.hora_inicio,t.hora_fin,c.capacidad,
        (SELECT COUNT(*) FROM inscripciones i WHERE i.cupo_id=c.id AND i.estado='confirmada') usados
      FROM cupos c JOIN comisiones co ON co.id=c.comision_id AND co.activa=1 JOIN turnos t ON t.id=c.turno_id AND t.activo=1
      WHERE c.habilitado=1".($aid?' AND c.actividad_id='.(int)$aid:'')." ORDER BY co.orden,co.nombre,t.hora_inicio");
    $o=[];
    foreach($q->fetchAll() as $c){
        $libres=(int)$c['capacidad']-(int)$c['usados'];
        if($libres>0)$o[(int)$c['actividad_id']][]=['id'=>(int)$c['id'],'texto'=>$c['comision'].' · '.hhmm($c['hora_inicio']).'–'.hhmm($c['hora_fin']).' (quedan '.$libres.')','libres'=>$libres];
    }
    return $o;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $vol=preg_match('/^[A-Za-z0-9=&_%+.\-]*$/',(string)($_POST['qs']??''))?(string)$_POST['qs']:'';
    $volver='admin/inscripciones.php'.($vol!==''?'?'.$vol:'');
    if(!csrf_ok()){flash('error','El formulario venció, inténtalo de nuevo.');go($volver);}
    $accion=$_POST['accion']??'';
    $id=(int)($_POST['id']??0);
    try{
        $pdo->beginTransaction();
        if($accion==='cancelar'){
            $pdo->prepare("UPDATE inscripciones SET estado='cancelada',observacion=CONCAT(COALESCE(observacion,''),' · Cancelada por la directiva el ".date('d/m/Y H:i')."') WHERE id=? AND estado='confirmada'")->execute([$id]);
            audit($me['id'],'cancelar','inscripciones',$id);
            $msg='Inscripción cancelada: el lugar quedó libre otra vez.';
        }else{
            // Mover, vincular y agregar: se bloquea la actividad para que nadie más ocupe el lugar mientras tanto.
            if($accion==='agregar')$actId=(int)($_POST['actividad_id']??0);
            else{$s=$pdo->prepare('SELECT actividad_id FROM inscripciones WHERE id=?');$s->execute([$id]);$actId=(int)$s->fetchColumn();}
            if(!$actId)throw new MensajeError('No se encontró la inscripción.');
            $pdo->prepare('SELECT id FROM actividades WHERE id=? FOR UPDATE')->execute([$actId]);

            $libreEn=function(int $cupoId)use($pdo,$actId):array{
                $s=$pdo->prepare('SELECT c.*,(SELECT COUNT(*) FROM inscripciones i WHERE i.cupo_id=c.id AND i.estado=\'confirmada\') usados FROM cupos c JOIN comisiones co ON co.id=c.comision_id AND co.activa=1 JOIN turnos t ON t.id=c.turno_id AND t.activo=1 WHERE c.id=? AND c.actividad_id=? AND c.habilitado=1');
                $s->execute([$cupoId,$actId]);$c=$s->fetch();
                if(!$c)throw new MensajeError('Ese turno no está disponible.');
                if((int)$c['usados']>=(int)$c['capacidad'])throw new MensajeError('Ese turno ya está completo.');
                return $c;
            };
            if($accion==='mover'){
                $c=$libreEn((int)($_POST['cupo_id']??0));
                $pdo->prepare("UPDATE inscripciones SET comision_id=?,turno_id=?,cupo_id=?,origen=IF(origen='automatica','admin',origen),observacion=CONCAT(COALESCE(observacion,''),' · Movida por la directiva el ".date('d/m/Y H:i')."') WHERE id=? AND estado='confirmada'")->execute([$c['comision_id'],$c['turno_id'],$c['id'],$id]);
                audit($me['id'],'mover','inscripciones',$id,'cupo '.$c['id']);
                $msg='Inscripción movida al nuevo turno.';
            }elseif($accion==='vincular'||$accion==='agregar'){
                $s=$pdo->prepare('SELECT * FROM familias WHERE id=? AND activa=1');$s->execute([(int)($_POST['familia_id']??0)]);$f=$s->fetch();
                if(!$f)throw new MensajeError('Elige una familia de la lista.');
                $s=$pdo->prepare("SELECT COUNT(*) FROM inscripciones WHERE actividad_id=? AND estado='confirmada' AND id<>? AND (familia_id=? OR familia=?)");$s->execute([$actId,$id,$f['id'],$f['nombre']]);
                if((int)$s->fetchColumn()>0)throw new MensajeError('La familia '.$f['nombre'].' ya tiene una inscripción en esta actividad.');
                if($accion==='vincular'){
                    $pdo->prepare('UPDATE inscripciones SET familia_id=?,familia=? WHERE id=?')->execute([$f['id'],$f['nombre'],$id]);
                    audit($me['id'],'vincular','inscripciones',$id,$f['nombre']);
                    $msg='Inscripción vinculada a la familia '.$f['nombre'].'.';
                }else{
                    $c=$libreEn((int)($_POST['cupo_id']??0));
                    $res=trim($_POST['responsable']??'')?:(string)$f['responsable'];$tel=trim($_POST['telefono']??'')?:(string)$f['telefono'];
                    $codigo=codigo_confirmacion($pdo);
                    $pdo->prepare("INSERT INTO inscripciones(actividad_id,comision_id,turno_id,cupo_id,familia_id,familia,responsable,telefono,grado_seccion,observacion,origen,codigo_confirmacion) VALUES(?,?,?,?,?,?,?,?,?,?,'admin',?)")
                        ->execute([$actId,$c['comision_id'],$c['turno_id'],$c['id'],$f['id'],$f['nombre'],mb_substr($res,0,150),mb_substr($tel,0,30),$f['secciones'],'Inscrita por la directiva el '.date('d/m/Y H:i'),$codigo]);
                    audit($me['id'],'agregar','inscripciones',(int)$pdo->lastInsertId(),$f['nombre']);
                    $msg='Familia '.$f['nombre'].' inscrita (código '.$codigo.').';
                }
            }else throw new MensajeError('Acción desconocida.');
        }
        $pdo->commit();
        flash('success',$msg);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if(!($e instanceof MensajeError))error_log('inscripciones.php: '.$e->getMessage());
        flash('error',$e instanceof MensajeError?$e->getMessage():'No se pudo completar la acción.');
    }
    go($volver);
}

// Filtros
$est=in_array($_GET['estado']??'',['confirmada','cancelada','todas'],true)?$_GET['estado']:'confirmada';
$ori=in_array($_GET['origen']??'',['familia','admin','automatica'],true)?$_GET['origen']:'';
$q=trim((string)($_GET['q']??''));
$where=['1=1'];$par=[];
if($aid){$where[]='i.actividad_id=?';$par[]=$aid;}
if($est!=='todas'){$where[]='i.estado=?';$par[]=$est;}
if($ori!==''){$where[]='i.origen=?';$par[]=$ori;}
foreach(array_slice(array_filter(preg_split('/\s+/u',$q)),0,4) as $t){$where[]='(i.familia LIKE ? OR i.responsable LIKE ? OR i.telefono LIKE ? OR i.codigo_confirmacion LIKE ?)';$l='%'.addcslashes($t,'%_\\').'%';array_push($par,$l,$l,$l,$l);}
$st=$pdo->prepare("SELECT i.*,a.nombre actividad,a.fecha_actividad,co.nombre comision,t.hora_inicio,t.hora_fin
  FROM inscripciones i JOIN actividades a ON a.id=i.actividad_id JOIN comisiones co ON co.id=i.comision_id JOIN turnos t ON t.id=i.turno_id
  WHERE ".implode(' AND ',$where)." ORDER BY i.id DESC LIMIT 500");
$st->execute($par);$rows=$st->fetchAll();
$acts=$pdo->query('SELECT id,nombre FROM actividades ORDER BY nombre')->fetchAll();
$hayLista=act_familias_activas($pdo)>0;
$libres=cupos_con_lugar($pdo,$aid);
$todas=$hayLista?$pdo->query('SELECT id,nombre FROM familias WHERE activa=1 ORDER BY nombre')->fetchAll():[];
$qsActual=http_build_query(array_filter(['actividad'=>$aid?:null,'estado'=>$est==='confirmada'?null:$est,'origen'=>$ori?:null,'q'=>$q?:null]));
$origenes=['familia'=>['info','Familia'],'admin'=>['draft','Directiva'],'automatica'=>['warn','Sorteo']];

$title='Inscripciones';
$subtitle=$act?$act['nombre'].' · Quién se inscribió y dónde':'Quién se inscribió, en qué turno y cómo';
require __DIR__.'/../includes/admin-header.php';
if($act)act_tabs($act,'inscripciones');
$cs=csrf_field().'<input type="hidden" name="qs" value="'.e($qsActual).'">';
?>

<?php if($act&&!empty($libres[$aid])&&$hayLista):
    $s=$pdo->prepare("SELECT f.id,f.nombre FROM familias f WHERE f.activa=1 AND NOT EXISTS(SELECT 1 FROM inscripciones i WHERE i.actividad_id=? AND i.estado='confirmada' AND (i.familia_id=f.id OR i.familia=f.nombre)) ORDER BY f.nombre");$s->execute([$aid]);$sinLugar=$s->fetchAll();?>
<section class="panel">
  <details><summary style="cursor:pointer;color:var(--p);font-weight:700">＋ Inscribir una familia manualmente</summary>
    <form method="post" class="formgrid" style="margin-top:14px"><?=$cs?><input type="hidden" name="accion" value="agregar"><input type="hidden" name="actividad_id" value="<?=$aid?>">
      <div class="field"><label for="fa">Familia</label><select id="fa" name="familia_id" required><option value="">Elige una familia…</option><?php foreach($sinLugar as $f):?><option value="<?=$f['id']?>"><?=e($f['nombre'])?></option><?php endforeach;?></select><?=hint('Solo aparecen las familias que aún no tienen turno en esta actividad.')?></div>
      <div class="field"><label for="cu">Comisión y turno</label><select id="cu" name="cupo_id" required><?php foreach($libres[$aid] as $c):?><option value="<?=$c['id']?>"><?=e($c['texto'])?></option><?php endforeach;?></select></div>
      <div class="field"><label for="re">Responsable <span class="muted">(opcional)</span></label><input id="re" type="text" name="responsable" maxlength="150"></div>
      <div class="field"><label for="te">Teléfono <span class="muted">(opcional)</span></label><input id="te" type="tel" name="telefono" maxlength="30"></div>
      <div class="field full"><button class="btn">Inscribir familia</button></div>
    </form>
  </details>
</section>
<?php endif;?>

<section class="panel">
  <form method="get" class="inline" style="margin-bottom:14px">
    <?php if(!$act):?><div class="field"><label for="act">Actividad</label><select id="act" name="actividad"><option value="0">Todas</option><?php foreach($acts as $x):?><option value="<?=$x['id']?>"><?=e($x['nombre'])?></option><?php endforeach;?></select></div>
    <?php else:?><input type="hidden" name="actividad" value="<?=$aid?>"><?php endif;?>
    <div class="field"><label for="est">Estado</label><select id="est" name="estado"><option value="confirmada" <?=$est==='confirmada'?'selected':''?>>Confirmadas</option><option value="cancelada" <?=$est==='cancelada'?'selected':''?>>Canceladas</option><option value="todas" <?=$est==='todas'?'selected':''?>>Todas</option></select></div>
    <div class="field"><label for="ori">Origen</label><select id="ori" name="origen"><option value="">Todos</option><option value="familia" <?=$ori==='familia'?'selected':''?>>Se inscribió la familia</option><option value="automatica" <?=$ori==='automatica'?'selected':''?>>Asignada por sorteo</option><option value="admin" <?=$ori==='admin'?'selected':''?>>Puesta por la directiva</option></select></div>
    <div class="field"><label for="q">Buscar</label><input id="q" type="search" name="q" value="<?=e($q)?>" placeholder="Familia, responsable, teléfono o código"></div>
    <button class="btn ghost">Filtrar</button>
    <?php if($q!==''||$ori!==''||$est!=='confirmada'||(!$act&&$aid)):?><a class="btn link" href="<?=e(url('admin/inscripciones.php'.($act?'?actividad='.$aid:'')))?>">Quitar filtros</a><?php endif;?>
  </form>

  <p class="muted" style="margin:0 0 10px"><b><?=count($rows)?></b> <?=count($rows)===1?'inscripción':'inscripciones'?><?=count($rows)===500?' (mostrando las 500 más recientes)':''?></p>
  <?php if(!$rows):?><div class="empty">No hay inscripciones con estos filtros.</div><?php else:?>
  <div style="overflow:auto"><table class="lista">
    <tr><th>Familia</th><th>Contacto</th><?php if(!$act):?><th>Actividad</th><?php endif;?><th>Comisión / turno</th><th>Origen</th><th>Estado</th><th></th></tr>
    <?php foreach($rows as $r):$conf=$r['estado']==='confirmada';
        [$oc,$ot]=$origenes[$r['origen']]??['draft',$r['origen']];
        $msj=$r['origen']==='automatica'
            ?"Hola, familia {$r['familia']}. Les escribimos de Tigres Azules: como no se inscribieron, les asignamos por sorteo en «{$r['actividad']}»: comisión {$r['comision']}, turno ".hhmm($r['hora_inicio']).'–'.hhmm($r['hora_fin']).($r['fecha_actividad']?', '.fecha_larga($r['fecha_actividad']):'').". Si no pueden, avísennos. ¡Gracias!"
            :"Hola, familia {$r['familia']}. Les confirmamos su inscripción en «{$r['actividad']}»: comisión {$r['comision']}, turno ".hhmm($r['hora_inicio']).'–'.hhmm($r['hora_fin']).($r['fecha_actividad']?', '.fecha_larga($r['fecha_actividad']):'').". Su código es {$r['codigo_confirmacion']}. ¡Gracias!";
        $wa=wa_url($r['telefono'],$msj);?>
    <tr class="<?=$conf?'':'off'?>">
      <td><b><?=e($r['familia'])?></b><br><small class="muted">Código <?=e($r['codigo_confirmacion'])?></small>
        <?php if($hayLista&&!$r['familia_id']):?><br><span class="badge warn" title="El nombre se escribió a mano y no coincide con la lista">Fuera de la lista</span><?php endif;?></td>
      <td><?=e($r['responsable'])?><br><small class="muted"><?=e($r['telefono'])?></small><?php if($wa&&$conf):?> <a class="btn small ghost" href="<?=e($wa)?>" target="_blank" rel="noopener" title="Escribir por WhatsApp">WhatsApp</a><?php endif;?></td>
      <?php if(!$act):?><td><?=e($r['actividad'])?></td><?php endif;?>
      <td><?=e($r['comision'])?><br><small class="muted"><?=e(hhmm($r['hora_inicio']).'–'.hhmm($r['hora_fin']))?></small></td>
      <td><span class="badge <?=$oc?>"><?=e($ot)?></span></td>
      <td><span class="badge <?=$conf?'published':'archived'?>"><?=$conf?'Confirmada':'Cancelada'?></span></td>
      <td><?php if($conf):?>
        <details><summary class="btn small ghost" style="display:inline-block">Acciones</summary>
          <?php $opc=array_filter($libres[(int)$r['actividad_id']]??[],fn($c)=>$c['id']!==(int)$r['cupo_id']);?>
          <?php if($opc):?><form method="post" class="inline" style="margin-top:10px"><?=$cs?><input type="hidden" name="accion" value="mover"><input type="hidden" name="id" value="<?=$r['id']?>">
            <div class="field"><label>Mover a</label><select name="cupo_id"><?php foreach($opc as $c):?><option value="<?=$c['id']?>"><?=e($c['texto'])?></option><?php endforeach;?></select></div><button class="btn small">Mover</button></form><?php endif;?>
          <?php if($hayLista&&!$r['familia_id']):?>
            <form method="post" class="inline" style="margin-top:10px"><?=$cs?><input type="hidden" name="accion" value="vincular"><input type="hidden" name="id" value="<?=$r['id']?>">
              <div class="field"><label>Es la familia</label><select name="familia_id" required><option value="">Elige…</option><?php foreach($todas as $f):?><option value="<?=$f['id']?>"><?=e($f['nombre'])?></option><?php endforeach;?></select></div><button class="btn small ghost">Vincular</button></form><?php endif;?>
          <form method="post" style="margin-top:10px"><?=$cs?><input type="hidden" name="id" value="<?=$r['id']?>">
            <button class="btn small red" name="accion" value="cancelar" data-confirm="¿Cancelar la inscripción de <?=e($r['familia'])?>? El lugar quedará libre.">Cancelar inscripción</button></form>
        </details><?php endif;?></td>
    </tr><?php endforeach;?>
  </table></div>
  <?php endif;?>
</section>
<?php require __DIR__.'/../includes/admin-footer.php';
