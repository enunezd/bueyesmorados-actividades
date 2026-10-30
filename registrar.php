<?php
require __DIR__.'/includes/app.php';
require __DIR__.'/includes/publico.php';
session_start_safe(); // antes de enviar cualquier HTML: la sesión guarda el token del formulario
$pdo=db();

$cid=(int)($_GET['cupo']??$_POST['cupo_id']??0);
$q=$pdo->prepare("SELECT c.*,a.nombre actividad,a.estado,a.modo_control,a.fecha_apertura,a.fecha_cierre,a.fecha_actividad,
    co.nombre comision,co.reglas,t.hora_inicio,t.hora_fin,
    (SELECT COUNT(*) FROM inscripciones i WHERE i.cupo_id=c.id AND i.estado='confirmada') usados
  FROM cupos c JOIN actividades a ON a.id=c.actividad_id JOIN comisiones co ON co.id=c.comision_id JOIN turnos t ON t.id=c.turno_id
  WHERE c.id=? AND c.habilitado=1 AND co.activa=1 AND t.activo=1 AND a.estado='publicada'");
$q->execute([$cid]);$c=$q->fetch();
if(!$c)pub_error('No encontramos ese turno','El turno que buscas no existe o ya no está disponible.',404,[url(),'Ver actividades']);

$volverActividad=url('actividad.php?id='.$c['actividad_id']);
[$estado]=activity_state($c);
if($estado!=='abierta')pub_error('Inscripción no disponible',act_ventana($c).'.',403,[$volverActividad,'Volver a la actividad']);
$libres=max(0,(int)$c['capacidad']-(int)$c['usados']);
if($libres<=0)pub_error('Este turno ya se completó','Alguien más tomó el último lugar. Elige otra comisión u otro horario.',409,[$volverActividad,'Elegir otro turno']);

$hayLista=(int)$pdo->query('SELECT COUNT(*) FROM familias WHERE activa=1')->fetchColumn()>0;
$error='';
$v=['familia_texto'=>'','familia_id'=>'','fuera'=>false,'responsable'=>'','telefono'=>'','estudiante'=>'','observacion'=>''];

if($_SERVER['REQUEST_METHOD']==='POST'){
    foreach(['familia_texto','responsable','telefono','estudiante','observacion'] as $k)$v[$k]=trim(utf8_limpio((string)($_POST[$k]??'')));
    $v['familia_id']=(int)($_POST['familia_id']??0)?:'';
    $v['fuera']=!empty($_POST['fuera_de_lista']);
    try{
        if(!csrf_ok())throw new MensajeError('La sesión del formulario venció. Recarga la página e inténtalo de nuevo.');
        if(!empty($_POST['sitio_web']))throw new MensajeError('No se pudo enviar el formulario.');

        // 1) ¿Qué familia es? Se prefiere la elegida de la lista; si solo escribió el nombre, se busca el nombre exacto.
        $fam=null;
        if($hayLista){
            if($v['familia_id']){$s=$pdo->prepare('SELECT * FROM familias WHERE id=? AND activa=1');$s->execute([$v['familia_id']]);$fam=$s->fetch()?:null;}
            elseif($v['familia_texto']!==''){$s=$pdo->prepare('SELECT * FROM familias WHERE nombre=? AND activa=1');$s->execute([fam_nombre($v['familia_texto'])]);$fam=$s->fetch()?:null;}
        }
        if($fam)$nombreFamilia=$fam['nombre'];
        elseif($v['familia_texto']===''||mb_strlen($v['familia_texto'])<3)throw new MensajeError($hayLista?'Escribe tu apellido y elige tu familia de la lista.':'Escribe el nombre de tu familia.');
        elseif($hayLista&&!$v['fuera'])throw new MensajeError('No encontramos esa familia en la lista. Escribe tu apellido y elige de las opciones, o marca «No encuentro mi familia en la lista».');
        else $nombreFamilia=mb_substr(fam_nombre($v['familia_texto']),0,150);

        // 2) Datos de contacto
        if($v['responsable']==='')throw new MensajeError('Escribe el nombre de quien realiza la inscripción.');
        if(!telefono_valido($v['telefono']))throw new MensajeError('Escribe un teléfono válido (por ejemplo 987 654 321).');
        if(mb_strlen($v['responsable'])>150||mb_strlen($v['estudiante'])>150||mb_strlen($v['observacion'])>500)throw new MensajeError('Alguno de los textos es demasiado largo.');

        // 3) Guardar. Se bloquea la actividad para que dos inscripciones simultáneas no se pisen.
        $pdo->beginTransaction();
        $pdo->prepare('SELECT id FROM actividades WHERE id=? FOR UPDATE')->execute([$c['actividad_id']]);
        $s=$pdo->prepare('SELECT * FROM actividades WHERE id=?');$s->execute([$c['actividad_id']]);
        if(activity_state($s->fetch())[0]!=='abierta')throw new MensajeError('La inscripción acaba de cerrarse.');
        $s=$pdo->prepare('SELECT capacidad,habilitado FROM cupos WHERE id=?');$s->execute([$cid]);$cupo=$s->fetch();
        $s=$pdo->prepare("SELECT COUNT(*) FROM inscripciones WHERE cupo_id=? AND estado='confirmada'");$s->execute([$cid]);
        if(!$cupo||!$cupo['habilitado']||(int)$s->fetchColumn()>=(int)$cupo['capacidad'])throw new MensajeError('Este turno acaba de completarse. Elige otro.');
        if($fam){$s=$pdo->prepare("SELECT COUNT(*) FROM inscripciones WHERE actividad_id=? AND estado='confirmada' AND (familia_id=? OR familia=?)");$s->execute([$c['actividad_id'],$fam['id'],$nombreFamilia]);}
        else{$s=$pdo->prepare("SELECT COUNT(*) FROM inscripciones WHERE actividad_id=? AND estado='confirmada' AND familia=?");$s->execute([$c['actividad_id'],$nombreFamilia]);}
        if((int)$s->fetchColumn()>0)throw new MensajeError('Esta familia ya está inscrita en esta actividad. Si necesitas cambiar de turno, cancela la inscripción anterior desde «Consultar mi turno» con tu código.');
        $codigo=codigo_confirmacion($pdo);
        $pdo->prepare("INSERT INTO inscripciones(actividad_id,comision_id,turno_id,cupo_id,familia_id,familia,responsable,telefono,estudiante,grado_seccion,observacion,origen,codigo_confirmacion,ip_registro,navegador) VALUES(?,?,?,?,?,?,?,?,?,?,?,'familia',?,?,?)")
            ->execute([$c['actividad_id'],$c['comision_id'],$c['turno_id'],$cid,$fam['id']??null,$nombreFamilia,$v['responsable'],mb_substr($v['telefono'],0,30),$v['estudiante']?:null,$fam['secciones']??null,$v['observacion']?:null,$codigo,substr($_SERVER['REMOTE_ADDR']??'',0,45),substr($_SERVER['HTTP_USER_AGENT']??'',0,255)]);
        $pdo->commit();
        go('confirmacion.php?codigo='.$codigo);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if($e instanceof MensajeError)$error=$e->getMessage();
        else{error_log('registrar.php: '.$e->getMessage());$error='No pudimos guardar la inscripción. Inténtalo de nuevo en unos segundos.';}
    }
}

pub_inicio('Inscripción','Inscribir a tu familia','',['volver'=>[$volverActividad,'Elegir otro turno'],'privada'=>true]);
?>
<section class="card formcard">
  <div class="resumen-turno">
    <span class="grande"><?=e($c['comision'])?> · <?=e(hhmm($c['hora_inicio']).' – '.hhmm($c['hora_fin']))?></span>
    <?=e($c['actividad'])?> · <?=e(fecha_larga($c['fecha_actividad']))?> · <b><?=e(pub_quedan($libres))?></b>
    <?php if(trim((string)$c['reglas'])!==''):?><br><small><?=e($c['reglas'])?></small><?php endif;?>
  </div>

  <?php if($error):?><div class="alert error" role="alert"><?=e($error)?></div><?php endif;?>

  <form method="post" autocomplete="off" novalidate>
    <?=csrf_field()?>
    <input type="hidden" name="cupo_id" value="<?=$cid?>">
    <div class="trampa" aria-hidden="true"><label>No llenar este campo <input type="text" name="sitio_web" tabindex="-1" autocomplete="off"></label></div>

    <div class="campo">
      <label for="familia">Tu familia</label>
      <?php if($hayLista):?>
        <div class="picker" data-picker data-url="<?=e(url('buscar-familia.php'))?>" data-actividad="<?=(int)$c['actividad_id']?>">
          <input type="text" id="familia" name="familia_texto" value="<?=e($v['familia_texto'])?>" placeholder="Escribe tu apellido…" autocomplete="off" required aria-controls="lista-familias" aria-describedby="ayuda-familia">
          <input type="hidden" name="familia_id" value="<?=e($v['familia_id'])?>">
          <ul id="lista-familias" role="listbox" hidden></ul>
          <p class="sel" aria-live="polite"></p>
        </div>
        <small class="ayuda" id="ayuda-familia">Escribe tu apellido y elige tu familia de la lista (así evitamos errores y repetidos).</small>
        <label class="check"><input type="checkbox" name="fuera_de_lista" <?=$v['fuera']?'checked':''?>> <span>No encuentro mi familia en la lista (escribiré el apellido a mano)</span></label>
      <?php else:?>
        <input type="text" id="familia" name="familia_texto" value="<?=e($v['familia_texto'])?>" placeholder="Apellidos de la familia" required maxlength="150">
      <?php endif;?>
    </div>

    <div class="campo">
      <label for="responsable">Nombre de quien inscribe</label>
      <input type="text" id="responsable" name="responsable" value="<?=e($v['responsable'])?>" maxlength="150" autocomplete="name" required placeholder="Ej.: María Pérez">
    </div>
    <div class="campo">
      <label for="telefono">Teléfono / WhatsApp</label>
      <input type="tel" id="telefono" name="telefono" value="<?=e($v['telefono'])?>" maxlength="30" inputmode="tel" autocomplete="tel" required placeholder="Ej.: 987 654 321">
      <small class="ayuda">Solo lo usaremos para coordinar esta actividad.</small>
    </div>

    <details class="mas" <?=($v['estudiante']!==''||$v['observacion']!=='')?'open':''?>>
      <summary>Agregar estudiante o un comentario <span class="opcional">(opcional)</span></summary>
      <div class="campo"><label for="estudiante">Nombre del estudiante</label><input type="text" id="estudiante" name="estudiante" value="<?=e($v['estudiante'])?>" maxlength="150"></div>
      <div class="campo"><label for="obs">Comentario</label><textarea id="obs" name="observacion" rows="3" maxlength="500"><?=e($v['observacion'])?></textarea></div>
    </details>

    <button class="btn acc block">Confirmar inscripción</button>
    <p class="meta" style="text-align:center;margin:12px 0 0">Recibirás un código para consultar o cancelar tu inscripción.</p>
  </form>
</section>
<?php pub_fin();
