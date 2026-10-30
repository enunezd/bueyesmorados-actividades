<?php
require __DIR__.'/includes/app.php';
require __DIR__.'/includes/publico.php';
$pdo=db();

$q=$pdo->prepare("SELECT i.*,a.nombre actividad,a.fecha_actividad,co.nombre comision,t.hora_inicio,t.hora_fin
  FROM inscripciones i JOIN actividades a ON a.id=i.actividad_id JOIN comisiones co ON co.id=i.comision_id JOIN turnos t ON t.id=i.turno_id
  WHERE i.codigo_confirmacion=?");
$q->execute([strtoupper(trim((string)($_GET['codigo']??'')))]);$i=$q->fetch();
if(!$i)pub_error('Confirmación no encontrada','No encontramos una inscripción con ese código.',404,[url('mi-turno.php'),'Consultar mi turno']);

$cancelada=$i['estado']==='cancelada';
pub_inicio($cancelada?'Inscripción cancelada':'Inscripción confirmada',$cancelada?'Inscripción cancelada':'¡Listo, tu familia está inscrita!','',['privada'=>true]);
?>
<section class="card formcard centrado">
  <?php if($cancelada):?>
    <div class="aviso bad"><span>✖</span><span>Esta inscripción fue cancelada.</span></div>
  <?php else:?>
    <div class="exito"><div class="tilde" aria-hidden="true">✓</div><p class="lead" style="margin:0">Guarda este código para consultar o cancelar tu inscripción:</p><div class="codigo"><?=e($i['codigo_confirmacion'])?></div></div>
  <?php endif;?>
  <dl class="datos">
    <dt>Familia</dt><dd><?=e($i['familia'])?></dd>
    <dt>Actividad</dt><dd><?=e($i['actividad'])?></dd>
    <dt>Fecha</dt><dd><?=e(fecha_larga($i['fecha_actividad']))?></dd>
    <dt>Comisión</dt><dd><?=e($i['comision'])?></dd>
    <dt>Horario</dt><dd><?=e(hhmm($i['hora_inicio']).' – '.hhmm($i['hora_fin']))?></dd>
  </dl>
  <?php if(!$cancelada):?>
    <p class="meta">📸 Te recomendamos tomar una captura de esta pantalla.</p>
    <div class="acciones" style="justify-content:center">
      <?php if($i['fecha_actividad']):?><a class="btn" href="<?=e(url('calendario.php?codigo='.$i['codigo_confirmacion']))?>">📅 Agregar al calendario</a><?php endif;?>
      <a class="btn ghost" href="<?=e(url('mi-turno.php?codigo='.$i['codigo_confirmacion']))?>">Cambiar o cancelar</a>
    </div>
  <?php endif;?>
  <p style="margin:20px 0 0"><a href="<?=e(url())?>">← Volver a las actividades</a></p>
</section>
<?php pub_fin();
