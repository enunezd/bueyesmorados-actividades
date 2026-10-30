<?php
require __DIR__.'/../includes/app.php';
$me=require_admin();
$pdo=db();

// Escribe un CSV (con BOM y «;» para que Excel lo abra bien) y termina.
function csv_descargar(array $cols,array $filas,string $nombre){
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename='.$nombre.'-'.date('Ymd-His').'.csv');
    $f=fopen('php://output','w');fwrite($f,chr(0xEF).chr(0xBB).chr(0xBF));fputcsv($f,$cols,';');
    foreach($filas as $r)fputcsv($f,array_values($r),';');
    fclose($f);exit;
}

// Si llega ?tipo=… se descarga un CSV; si no, se muestra el menú.
$tipo=$_GET['tipo']??'';
$aid=(int)($_GET['actividad']??0);
if($tipo==='inscripciones'){
    $st=$pdo->prepare("SELECT i.codigo_confirmacion,i.familia,i.responsable,i.telefono,i.estudiante,i.grado_seccion,a.nombre actividad,co.nombre comision,t.hora_inicio,t.hora_fin,
        CASE i.origen WHEN 'automatica' THEN 'Sorteo' WHEN 'admin' THEN 'Directiva' ELSE 'Familia' END origen,i.estado,i.creado_en
      FROM inscripciones i JOIN actividades a ON a.id=i.actividad_id JOIN comisiones co ON co.id=i.comision_id JOIN turnos t ON t.id=i.turno_id
      ".($aid?'WHERE i.actividad_id=?':'')." ORDER BY a.nombre,co.nombre,t.hora_inicio,i.familia");
    $st->execute($aid?[$aid]:[]);
    csv_descargar(['Código','Familia','Responsable','Teléfono','Estudiante','Sección','Actividad','Comisión','Inicio','Fin','Origen','Estado','Registro'],$st->fetchAll(),'inscripciones-tigres-azules');
}elseif($tipo==='pendientes'&&$aid){
    $sit=asig_situacion($pdo,$aid);
    csv_descargar(['Familia','Sección'],array_map(fn($f)=>[$f['nombre'],$f['secciones']],$sit['familias']),'familias-sin-inscribir');
}elseif($tipo==='familias'){
    csv_descargar(['Familia','Secciones','Responsable','Teléfono','Estado'],array_map(fn($f)=>[$f['nombre'],$f['secciones'],$f['responsable'],$f['telefono'],$f['activa']?'Activa':'Inactiva'],$pdo->query('SELECT * FROM familias ORDER BY nombre')->fetchAll()),'familias-tigres-azules');
}elseif($tipo!==''){
    http_response_code(404);exit('Exportación no disponible');
}

$acts=$pdo->query('SELECT id,nombre FROM actividades ORDER BY nombre')->fetchAll();
$title='Exportar datos';
$subtitle='Descarga listas en CSV (se abren con Excel o Google Sheets)';
require __DIR__.'/../includes/admin-header.php';
?>
<section class="panel">
  <div class="panelhead"><h2>Inscripciones</h2></div>
  <form method="get" class="inline"><input type="hidden" name="tipo" value="inscripciones">
    <div class="field"><label for="a">Actividad</label><select id="a" name="actividad"><option value="0">Todas las actividades</option><?php foreach($acts as $a):?><option value="<?=$a['id']?>"><?=e($a['nombre'])?></option><?php endforeach;?></select></div>
    <button class="btn">Descargar inscripciones</button></form>
  <?=hint('Incluye familia, responsable, teléfono, comisión, turno, quién la hizo (familia, sorteo o directiva) y su estado.')?>
</section>
<section class="panel">
  <div class="panelhead"><h2>Familias sin inscribir</h2></div>
  <form method="get" class="inline"><input type="hidden" name="tipo" value="pendientes">
    <div class="field"><label for="b">Actividad</label><select id="b" name="actividad" required><?php foreach($acts as $a):?><option value="<?=$a['id']?>"><?=e($a['nombre'])?></option><?php endforeach;?></select></div>
    <button class="btn ghost">Descargar</button></form>
  <?=hint('Familias de la lista que todavía no tienen turno en esa actividad (sin contar las exoneradas).')?>
</section>
<section class="panel">
  <div class="panelhead"><h2>Lista de familias</h2><a class="btn ghost" href="<?=e(url('admin/exportar.php?tipo=familias'))?>">Descargar lista de familias</a></div>
</section>
<?php require __DIR__.'/../includes/admin-footer.php';
