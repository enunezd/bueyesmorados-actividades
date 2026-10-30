<?php
// Las familias consultan su turno (por nombre de familia o por código) y pueden cancelar con su código mientras la inscripción siga abierta.
require __DIR__.'/includes/app.php';
require __DIR__.'/includes/publico.php';
session_start_safe();
asig_ejecutar_pendientes();
$pdo=db();

$SQL_INSCRIPCION="SELECT i.*,a.nombre actividad,a.fecha_actividad,a.modo_control,a.fecha_apertura,a.fecha_cierre,a.estado actividad_estado,co.nombre comision,t.hora_inicio,t.hora_fin
  FROM inscripciones i JOIN actividades a ON a.id=i.actividad_id JOIN comisiones co ON co.id=i.comision_id JOIN turnos t ON t.id=i.turno_id ";

$codigo=strtoupper(preg_replace('/[^A-Za-z0-9]/','',(string)($_REQUEST['codigo']??'')));
$famId=(int)($_GET['familia_id']??0);
$texto=trim((string)($_GET['familia_texto']??''));
$aviso=null; // [tipo, texto]

// Freno: máximo 10 consultas con código cada 10 minutos por sesión.
$limitado=false;
if($codigo!==''){
    $_SESSION['consultas_codigo']=array_values(array_filter($_SESSION['consultas_codigo']??[],fn($t)=>$t>time()-600));
    if(count($_SESSION['consultas_codigo'])>=10){$limitado=true;$codigo='';}
    else $_SESSION['consultas_codigo'][]=time();
}
if($limitado)$aviso=['bad','Hiciste demasiadas consultas seguidas. Espera unos minutos e inténtalo de nuevo.'];

// Cancelar con código
if($_SERVER['REQUEST_METHOD']==='POST'&&!$limitado){
    try{
        if(!csrf_ok())throw new MensajeError('La sesión venció. Inténtalo de nuevo.');
        $s=$pdo->prepare($SQL_INSCRIPCION.'WHERE i.codigo_confirmacion=?');$s->execute([$codigo]);$i=$s->fetch();
        if(!$i||$i['estado']!=='confirmada')throw new MensajeError('No encontramos una inscripción activa con ese código.');
        if(activity_state($i)[0]!=='abierta'||$i['actividad_estado']!=='publicada')throw new MensajeError('La inscripción de esta actividad ya está cerrada, así que no se puede cambiar desde aquí. Escribe a la directiva.');
        $pdo->prepare("UPDATE inscripciones SET estado='cancelada',observacion=CONCAT(COALESCE(observacion,''),' · Cancelada por la familia el ".date('d/m/Y H:i')."') WHERE id=?")->execute([$i['id']]);
        audit(null,'cancelar_familia','inscripciones',(int)$i['id'],'código '.$codigo);
        $aviso=['success','Tu inscripción fue cancelada. El lugar quedó libre; si quieres, elige otro turno.'];
        $cancelada=$i;
    }catch(MensajeError $e){$aviso=['bad',$e->getMessage()];}
}

// Búsqueda por código
$porCodigo=null;
if($codigo!==''&&!$limitado){
    $s=$pdo->prepare($SQL_INSCRIPCION.'WHERE i.codigo_confirmacion=?');$s->execute([$codigo]);$porCodigo=$s->fetch()?:null;
    if(!$porCodigo&&!$aviso)$aviso=['bad','No encontramos ese código. Revisa que esté escrito igual que en tu confirmación.'];
}

// Búsqueda por familia
$fam=null;$coincidencias=[];$sinResultado=false;
if($famId){$s=$pdo->prepare('SELECT * FROM familias WHERE id=?');$s->execute([$famId]);$fam=$s->fetch()?:null;}
elseif($texto!==''){
    $m=fam_buscar($pdo,$texto,10);
    if(count($m)===1){$s=$pdo->prepare('SELECT * FROM familias WHERE id=?');$s->execute([$m[0]['id']]);$fam=$s->fetch();}
    elseif($m)$coincidencias=$m;
    else $sinResultado=true;
}
$delaFamilia=[];$abiertasSinLugar=[];
if($fam){
    $s=$pdo->prepare($SQL_INSCRIPCION."WHERE i.estado='confirmada' AND a.estado='publicada' AND (i.familia_id=? OR i.familia=?) ORDER BY a.fecha_actividad,a.id");
    $s->execute([$fam['id'],$fam['nombre']]);$delaFamilia=$s->fetchAll();
    $con=array_column($delaFamilia,'actividad_id');
    foreach($pdo->query("SELECT * FROM actividades WHERE estado='publicada' ORDER BY fecha_actividad")->fetchAll() as $a)
        if(activity_state($a)[0]==='abierta'&&!in_array($a['id'],$con))$abiertasSinLugar[]=$a;
}

pub_inicio('Consultar mi turno','Consultar mi turno','Mira en qué comisión y horario quedó tu familia.',['volver'=>[url(),'Todas las actividades']]);
?>
<?php if($aviso):?><div class="aviso <?=e($aviso[0])?>" role="status"><span><?=$aviso[0]==='success'?'✔':'⚠'?></span><span><?=e($aviso[1])?></span></div><?php endif;?>

<?php if($porCodigo&&$porCodigo['estado']==='confirmada'):
    $abierta=activity_state($porCodigo)[0]==='abierta'&&$porCodigo['actividad_estado']==='publicada';?>
  <section class="card formcard">
    <h2 style="margin-top:0;color:var(--pd)"><?=e($porCodigo['actividad'])?></h2>
    <dl class="datos">
      <dt>Familia</dt><dd><?=e($porCodigo['familia'])?></dd>
      <dt>Fecha</dt><dd><?=e(fecha_larga($porCodigo['fecha_actividad']))?></dd>
      <dt>Comisión</dt><dd><?=e($porCodigo['comision'])?></dd>
      <dt>Horario</dt><dd><?=e(hhmm($porCodigo['hora_inicio']).' – '.hhmm($porCodigo['hora_fin']))?></dd>
      <dt>Código</dt><dd><?=e($porCodigo['codigo_confirmacion'])?></dd>
      <?php if($porCodigo['origen']==='automatica'):?><dt>Origen</dt><dd>Asignada automáticamente en el sorteo</dd><?php endif;?>
    </dl>
    <div class="acciones">
      <?php if($porCodigo['fecha_actividad']):?><a class="btn" href="<?=e(url('calendario.php?codigo='.$porCodigo['codigo_confirmacion']))?>">📅 Agregar al calendario</a><?php endif;?>
      <?php if($abierta):?>
        <form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="codigo" value="<?=e($porCodigo['codigo_confirmacion'])?>">
          <button class="btn ghost" data-confirm="¿Cancelar esta inscripción? El lugar quedará libre para otra familia.">Cancelar mi inscripción</button></form>
      <?php endif;?>
    </div>
    <?php if(!$abierta):?><p class="meta">La inscripción de esta actividad ya cerró. Para hacer cambios, escribe a la directiva.</p><?php endif;?>
  </section>
<?php elseif(!empty($cancelada)):?>
  <section class="card formcard centrado"><a class="btn acc" href="<?=e(url('actividad.php?id='.$cancelada['actividad_id']))?>">Elegir otro turno</a></section>
<?php endif;?>

<?php if($fam):?>
  <section class="card formcard">
    <h2 style="margin-top:0;color:var(--pd)">Familia <?=e($fam['nombre'])?></h2>
    <?php if(!$delaFamilia):?><p>Esta familia todavía no tiene turnos confirmados.</p><?php endif;?>
    <?php foreach($delaFamilia as $i):?>
      <div class="aviso <?=$i['origen']==='automatica'?'warn':'ok'?>" style="display:block">
        <b><?=e($i['actividad'])?></b> · <?=e(fecha_larga($i['fecha_actividad']))?><br>
        <?=e($i['comision'])?> · <?=e(hhmm($i['hora_inicio']).' – '.hhmm($i['hora_fin']))?><br>
        <small><?=$i['origen']==='automatica'?'🎲 Asignada automáticamente en el sorteo':'✔ Inscripción realizada por la familia'?></small>
      </div>
    <?php endforeach;?>
    <?php foreach($abiertasSinLugar as $a):?>
      <div class="aviso info" style="display:block"><b><?=e($a['nombre'])?></b>: aún no tienen turno.
        <a href="<?=e(url('actividad.php?id='.$a['id']))?>">Elegir turno</a></div>
    <?php endforeach;?>
    <p class="meta">Para cancelar o cambiar una inscripción necesitas el código que recibiste al inscribirte.</p>
  </section>
<?php endif;?>

<?php if($coincidencias):?>
  <section class="card formcard"><h2 style="margin-top:0;color:var(--pd)">¿Cuál es tu familia?</h2>
    <ul style="padding-left:18px;line-height:2"><?php foreach($coincidencias as $f):?><li><a href="<?=e(url('mi-turno.php?familia_id='.$f['id']))?>"><?=e($f['nombre'])?></a></li><?php endforeach;?></ul></section>
<?php endif;?>
<?php if($sinResultado):?><div class="aviso warn"><span>⚠</span><span>No encontramos una familia con ese apellido. Prueba escribiendo solo una parte.</span></div><?php endif;?>

<section class="grid">
  <form class="card pad" method="get" autocomplete="off">
    <h2 style="margin-top:0;color:var(--pd);font-size:20px">Buscar por mi familia</h2>
    <div class="campo">
      <div class="picker" data-picker data-autosubmit data-url="<?=e(url('buscar-familia.php'))?>">
        <input type="text" name="familia_texto" value="<?=e($texto)?>" placeholder="Escribe tu apellido…" aria-label="Apellido de tu familia">
        <input type="hidden" name="familia_id" value="">
        <ul role="listbox" hidden></ul><p class="sel" aria-live="polite"></p>
      </div>
      <small class="ayuda">Elige tu familia de la lista y verás tus turnos.</small>
    </div>
    <button class="btn block">Buscar</button>
  </form>
  <form class="card pad" method="get" autocomplete="off">
    <h2 style="margin-top:0;color:var(--pd);font-size:20px">Tengo mi código</h2>
    <div class="campo">
      <input type="text" name="codigo" value="<?=e($codigo)?>" placeholder="Ej.: 84D1F047DE" maxlength="20" style="text-transform:uppercase" aria-label="Código de confirmación">
      <small class="ayuda">Con tu código puedes cancelar tu inscripción mientras siga abierta.</small>
    </div>
    <button class="btn ghost block">Consultar con código</button>
  </form>
</section>
<?php pub_fin();
