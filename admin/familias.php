<?php
require __DIR__.'/../includes/app.php';
$title='Familias';
$subtitle='La lista de familias de la promo: se usa para elegir familia al inscribirse y para el sorteo';
$me=require_admin();
$pdo=db();
$preview=null; // resultado de «Revisar» una importación

// Texto de la importación: lo pegado y/o el archivo subido.
function texto_importacion():string{
    $t=(string)($_POST['texto']??'');
    if(!empty($_FILES['archivo']['tmp_name'])&&is_uploaded_file($_FILES['archivo']['tmp_name'])&&($_FILES['archivo']['size']??0)<2000000)
        $t.="\n".file_get_contents($_FILES['archivo']['tmp_name']);
    return $t;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_ok()){flash('error','El formulario venció, inténtalo de nuevo.');go('admin/familias.php');}
    $accion=$_POST['accion']??'';
    $id=(int)($_POST['id']??0);
    try{
        switch($accion){
        case 'importar_revisar':
        case 'importar_confirmar':
            $p=fam_parsear(texto_importacion());
            $desactivar=!empty($_POST['desactivar']);
            if(!$p['filas'])throw new MensajeError('No encontramos familias en el texto. Pega una familia por línea (por ejemplo: PEREZ GARCIA).');
            if($accion==='importar_revisar'){
                $preview=['p'=>$p,'r'=>fam_importar($pdo,$p['filas'],$desactivar,true),'desactivar'=>$desactivar,'texto'=>texto_importacion()];
                break;
            }
            $pdo->beginTransaction();
            $r=fam_importar($pdo,$p['filas'],$desactivar);
            $pdo->commit();
            audit($me['id'],'importar','familias',null,json_encode($r));
            flash('success','Importación lista: '.plural($r['nuevas'],'familia nueva','familias nuevas').', '.plural($r['actualizadas'],'actualizada','actualizadas').($r['desactivadas']?', '.plural($r['desactivadas'],'desactivada','desactivadas'):'').'.');
            go('admin/familias.php');
        case 'agregar':
        case 'editar':
            $nombre=fam_nombre((string)($_POST['nombre']??''));
            if(mb_strlen($nombre)<3)throw new MensajeError('Escribe el nombre de la familia (por ejemplo: PEREZ GARCIA).');
            $sec=fam_secciones($_POST['secciones']??'');$res=trim($_POST['responsable']??'')?:null;$tel=trim($_POST['telefono']??'')?:null;
            try{
                if($accion==='agregar')$pdo->prepare('INSERT INTO familias(nombre,secciones,responsable,telefono) VALUES(?,?,?,?)')->execute([$nombre,$sec,$res,$tel]);
                else $pdo->prepare('UPDATE familias SET nombre=?,secciones=?,responsable=?,telefono=? WHERE id=?')->execute([$nombre,$sec,$res,$tel,$id]);
            }catch(PDOException $e){
                if(($e->errorInfo[1]??0)==1062)throw new MensajeError('Ya existe una familia con ese nombre.');
                throw $e;
            }
            audit($me['id'],$accion,'familias',$id?:null,$nombre);
            flash('success',$accion==='agregar'?'Familia agregada.':'Familia actualizada.');
            go('admin/familias.php');
        case 'toggle':
            $pdo->prepare('UPDATE familias SET activa=1-activa WHERE id=?')->execute([$id]);
            flash('success','Listo.');
            go('admin/familias.php');
        case 'eliminar':
            $s=$pdo->prepare('SELECT nombre FROM familias WHERE id=?');$s->execute([$id]);$nom=$s->fetchColumn();
            $s=$pdo->prepare('SELECT COUNT(*) FROM inscripciones WHERE familia_id=? OR familia=?');$s->execute([$id,$nom]);
            if((int)$s->fetchColumn()>0)throw new MensajeError('No se puede eliminar: la familia tiene inscripciones. Puedes desactivarla para que no aparezca ni entre al sorteo.');
            $pdo->prepare('DELETE FROM familias WHERE id=?')->execute([$id]);
            audit($me['id'],'eliminar','familias',$id,(string)$nom);
            flash('success','Familia eliminada.');
            go('admin/familias.php');
        }
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        if(!($e instanceof MensajeError))error_log('familias.php: '.$e->getMessage());
        flash('error',$e instanceof MensajeError?$e->getMessage():'No se pudo completar la acción.');
        go('admin/familias.php');
    }
}

// Filtros de la lista
$q=trim((string)($_GET['q']??''));
$sec=strtoupper(preg_replace('/[^A-Za-z]/','',(string)($_GET['seccion']??'')));
$est=in_array($_GET['estado']??'',['activas','inactivas','todas'],true)?$_GET['estado']:'activas';
$where=['1=1'];$par=[];
if($est==='activas')$where[]='f.activa=1';elseif($est==='inactivas')$where[]='f.activa=0';
if($sec!==''){$where[]='FIND_IN_SET(?,f.secciones)';$par[]=$sec;}
foreach(array_slice(array_filter(preg_split('/\s+/u',$q)),0,4) as $t){$where[]='f.nombre LIKE ?';$par[]='%'.addcslashes($t,'%_\\').'%';}
$st=$pdo->prepare("SELECT f.*,
    (SELECT COUNT(*) FROM inscripciones i WHERE (i.familia_id=f.id OR i.familia=f.nombre) AND i.estado='confirmada') n_ins,
    (SELECT COUNT(*) FROM inscripciones i WHERE (i.familia_id=f.id OR i.familia=f.nombre)) n_total
  FROM familias f WHERE ".implode(' AND ',$where)." ORDER BY f.nombre LIMIT 600");
$st->execute($par);$rows=$st->fetchAll();

$totales=$pdo->query('SELECT COUNT(*) total,SUM(activa=1) activas,SUM(activa=0) inactivas,SUM(activa=1 AND telefono IS NOT NULL AND telefono<>"") con_tel FROM familias')->fetch();
$porSec=[];
foreach($pdo->query('SELECT secciones FROM familias WHERE activa=1 AND secciones IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $ss)
    foreach(explode(',',$ss) as $letra)$porSec[$letra]=($porSec[$letra]??0)+1;
ksort($porSec);
$hayLista=(int)$totales['total']>0;

require __DIR__.'/../includes/admin-header.php';
?>
<?php if($preview):?>
<section class="panel">
  <div class="panelhead"><h2>Revisa antes de importar</h2></div>
  <div class="cards">
    <div class="stat ok"><b><?=(int)$preview['r']['nuevas']?></b><span>familias nuevas</span></div>
    <div class="stat"><b><?=(int)$preview['r']['actualizadas']?></b><span>se actualizan</span></div>
    <div class="stat"><b><?=(int)$preview['r']['sin_cambios']?></b><span>sin cambios</span></div>
    <?php if($preview['desactivar']):?><div class="stat <?=$preview['r']['desactivadas']?'warn':''?>"><b><?=(int)$preview['r']['desactivadas']?></b><span>se desactivarían</span></div><?php endif;?>
  </div>
  <?php if($preview['p']['repetidas']):?><p class="muted">Había <?=plural((int)$preview['p']['repetidas'],'nombre repetido','nombres repetidos')?> en la lista; se tomó uno solo de cada uno.</p><?php endif;?>
  <?php if($preview['p']['omitidas']):?><div class="alert warn"><b>Se omitieron <?=count($preview['p']['omitidas'])?> línea(s):</b><br><?=e(implode(' · ',array_slice($preview['p']['omitidas'],0,8)))?></div><?php endif;?>
  <p class="muted">Primeras de la lista: <?=e(implode(', ',array_slice(array_column($preview['p']['filas'],'nombre'),0,8)))?>…</p>
  <form method="post" class="actions"><?=csrf_field()?><input type="hidden" name="accion" value="importar_confirmar">
    <textarea name="texto" hidden><?=e($preview['texto'])?></textarea>
    <?php if($preview['desactivar']):?><input type="hidden" name="desactivar" value="1"><?php endif;?>
    <button class="btn">Confirmar e importar</button>
    <a class="btn gray" href="<?=e(url('admin/familias.php'))?>">Cancelar</a>
  </form>
</section>
<?php endif;?>

<div class="cards">
  <div class="stat"><b><?=(int)$totales['activas']?></b><span>familias activas</span></div>
  <?php foreach($porSec as $letra=>$n):?><div class="stat"><b><?=$n?></b><span>sección <?=e($letra)?></span></div><?php endforeach;?>
  <?php if((int)$totales['inactivas']):?><div class="stat"><b><?=(int)$totales['inactivas']?></b><span>inactivas</span></div><?php endif;?>
  <div class="stat"><b><?=(int)$totales['con_tel']?></b><span>con teléfono guardado</span></div>
</div>

<div class="twocol">
  <section class="panel">
    <div class="panelhead"><h2>Importar lista</h2></div>
    <?php if(!$hayLista):?><div class="alert info">Todavía no hay familias. Pega la lista (una por línea) o sube un archivo CSV.</div><?php endif;?>
    <form method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="accion" value="importar_revisar">
      <div class="field"><label for="texto">Pega la lista aquí</label>
        <textarea id="texto" name="texto" rows="5" placeholder="PEREZ GARCIA&#10;LOPEZ DIAZ; B&#10;RAMOS TORRES; A,C; María Ramos; 987654321"></textarea>
        <?=hint('Una familia por línea. Opcional, separado por «;» o coma: sección(es), responsable y teléfono. También entiende un CSV con títulos (Familia, Sección, Responsable, Teléfono).')?></div>
      <div class="field"><label for="archivo">…o sube un archivo CSV / TXT</label><input id="archivo" type="file" name="archivo" accept=".csv,.txt,text/csv,text/plain"></div>
      <div class="field check"><label><input type="checkbox" name="desactivar"><span>Desactivar las familias que <b>no</b> estén en esta lista<br><small class="hint">Útil al cargar la lista de un nuevo año. No se borra nada: conservan su historial.</small></span></label></div>
      <button class="btn">Revisar importación</button>
    </form>
  </section>

  <section class="panel">
    <div class="panelhead"><h2>Agregar una familia</h2></div>
    <form method="post" class="formgrid"><?=csrf_field()?><input type="hidden" name="accion" value="agregar">
      <div class="field full"><label for="n">Familia (apellidos)</label><input id="n" type="text" name="nombre" required maxlength="150" placeholder="Ej.: PEREZ GARCIA"></div>
      <div class="field"><label for="s">Sección(es)</label><input id="s" type="text" name="secciones" maxlength="20" placeholder="A o A,C"></div>
      <div class="field"><label for="t">Teléfono</label><input id="t" type="tel" name="telefono" maxlength="30"></div>
      <div class="field full"><label for="r">Responsable</label><input id="r" type="text" name="responsable" maxlength="150"></div>
      <div class="field full"><button class="btn">Agregar familia</button></div>
    </form>
  </section>
</div>

<section class="panel">
  <div class="panelhead"><h2>Lista de familias <span class="badge info"><?=count($rows)?></span></h2></div>
  <form method="get" class="inline" style="margin-bottom:14px">
    <div class="field"><label for="q">Buscar</label><input id="q" type="search" name="q" value="<?=e($q)?>" placeholder="Apellido…"></div>
    <div class="field"><label for="sec">Sección</label><select id="sec" name="seccion"><option value="">Todas</option><?php foreach($porSec as $letra=>$n):?><option value="<?=e($letra)?>" <?=$sec===$letra?'selected':''?>><?=e($letra)?> (<?=$n?>)</option><?php endforeach;?></select></div>
    <div class="field"><label for="est">Estado</label><select id="est" name="estado"><option value="activas" <?=$est==='activas'?'selected':''?>>Activas</option><option value="inactivas" <?=$est==='inactivas'?'selected':''?>>Inactivas</option><option value="todas" <?=$est==='todas'?'selected':''?>>Todas</option></select></div>
    <button class="btn ghost">Filtrar</button>
    <?php if($q!==''||$sec!==''||$est!=='activas'):?><a class="btn link" href="<?=e(url('admin/familias.php'))?>">Quitar filtros</a><?php endif;?>
  </form>
  <?php if(!$rows):?><div class="empty"><?=$hayLista?'Ninguna familia coincide con el filtro.':'Aún no hay familias cargadas.'?></div><?php else:?>
  <div style="overflow:auto"><table class="lista">
    <tr><th>Familia</th><th>Sección</th><th>Contacto</th><th>Inscripciones</th><th>Estado</th><th></th></tr>
    <?php foreach($rows as $f):?><tr class="<?=$f['activa']?'':'off'?>">
      <td><b><?=e($f['nombre'])?></b></td>
      <td><?=e(str_replace(',',', ',(string)$f['secciones']))?></td>
      <td><?=e($f['responsable']??'')?><?=$f['responsable']&&$f['telefono']?'<br>':''?><small class="muted"><?=e($f['telefono']??'')?></small></td>
      <td><?=(int)$f['n_ins']?><?php if((int)$f['n_total']>(int)$f['n_ins']):?> <small class="muted">(+<?=(int)$f['n_total']-(int)$f['n_ins']?> canceladas)</small><?php endif;?></td>
      <td><span class="badge <?=$f['activa']?'published':'draft'?>"><?=$f['activa']?'Activa':'Inactiva'?></span></td>
      <td>
        <details><summary class="btn small ghost" style="display:inline-block">Editar</summary>
          <form method="post" class="formgrid" style="margin-top:10px;min-width:280px"><?=csrf_field()?><input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="<?=$f['id']?>">
            <div class="field full"><label>Familia</label><input type="text" name="nombre" required maxlength="150" value="<?=e($f['nombre'])?>"></div>
            <div class="field"><label>Sección(es)</label><input type="text" name="secciones" maxlength="20" value="<?=e($f['secciones']??'')?>"></div>
            <div class="field"><label>Teléfono</label><input type="tel" name="telefono" maxlength="30" value="<?=e($f['telefono']??'')?>"></div>
            <div class="field full"><label>Responsable</label><input type="text" name="responsable" maxlength="150" value="<?=e($f['responsable']??'')?>"></div>
            <div class="field full"><button class="btn small">Guardar</button></div>
          </form>
          <form method="post" class="actions" style="margin-top:8px"><?=csrf_field()?><input type="hidden" name="id" value="<?=$f['id']?>">
            <button class="btn small ghost" name="accion" value="toggle"><?=$f['activa']?'Desactivar':'Activar'?></button>
            <?php if((int)$f['n_total']===0):?><button class="btn small red" name="accion" value="eliminar" data-confirm="¿Eliminar la familia <?=e($f['nombre'])?>?">Eliminar</button><?php endif;?>
          </form>
        </details>
      </td>
    </tr><?php endforeach;?>
  </table></div>
  <?php endif;?>
</section>
<?php require __DIR__.'/../includes/admin-footer.php';
