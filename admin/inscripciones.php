<?php
require __DIR__.'/../includes/app.php';
$title='Inscripciones';
$subtitle='Consulta quién se inscribió y cancela registros si hace falta';
$me=require_admin();
$pdo=db();

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_ok()){flash('error','El formulario venció, inténtalo de nuevo.');go('admin/inscripciones.php');}
    $id=(int)($_POST['id']??0);
    $pdo->prepare("UPDATE inscripciones SET estado='cancelada' WHERE id=?")->execute([$id]);
    audit($me['id'],'cancelar','inscripciones',$id);
    flash('success','Inscripción cancelada: el lugar quedó libre otra vez.');
    go('admin/inscripciones.php'.(!empty($_POST['actividad'])?'?actividad='.(int)$_POST['actividad']:''));
}

$aid=(int)($_GET['actividad']??0);
$acts=$pdo->query('SELECT id,nombre FROM actividades ORDER BY nombre')->fetchAll();
$sql="SELECT i.*,a.nombre actividad,co.nombre comision,t.hora_inicio,t.hora_fin FROM inscripciones i JOIN actividades a ON a.id=i.actividad_id JOIN comisiones co ON co.id=i.comision_id JOIN turnos t ON t.id=i.turno_id".($aid?' WHERE i.actividad_id=?':'')." ORDER BY i.id DESC";
$s=$pdo->prepare($sql);$s->execute($aid?[$aid]:[]);$rows=$s->fetchAll();

require __DIR__.'/../includes/admin-header.php';
?>
<section class="panel">
  <div class="panelhead">
    <form method="get" class="inline"><div class="field"><label for="act">Mostrar</label>
      <select id="act" name="actividad" onchange="this.form.submit()"><option value="0">Todas las actividades</option>
      <?php foreach($acts as $a):?><option value="<?=$a['id']?>" <?=$aid===(int)$a['id']?'selected':''?>><?=e($a['nombre'])?></option><?php endforeach;?></select></div></form>
    <span class="muted"><?=count($rows)?> registro(s)</span>
  </div>
  <?php if(!$rows):?><div class="empty">Todavía no hay inscripciones.</div><?php else:?>
  <table><tr><th>Familia</th><th>Responsable</th><th>Contacto</th><th>Actividad</th><th>Comisión / turno</th><th>Estado</th><th></th></tr>
  <?php foreach($rows as $r):?><tr>
    <td><b><?=e($r['familia'])?></b><br><small class="muted">Código <?=e($r['codigo_confirmacion'])?></small></td>
    <td><?=e($r['responsable'])?></td><td><?=e($r['telefono'])?></td><td><?=e($r['actividad'])?></td>
    <td><?=e($r['comision'])?><br><?=e(hhmm($r['hora_inicio']).'–'.hhmm($r['hora_fin']))?></td>
    <td><span class="badge <?=$r['estado']==='confirmada'?'published':'archived'?>"><?=$r['estado']==='confirmada'?'Confirmada':'Cancelada'?></span></td>
    <td><?php if($r['estado']==='confirmada'):?>
      <form method="post"><input type="hidden" name="csrf" value="<?=e(csrf())?>"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="actividad" value="<?=$aid?>">
      <button class="btn small red" data-confirm="¿Cancelar la inscripción de <?=e($r['familia'])?>? El lugar quedará libre.">Cancelar</button></form><?php endif;?></td>
  </tr><?php endforeach;?></table>
  <?php endif;?>
</section>
<?php require __DIR__.'/../includes/admin-footer.php';
