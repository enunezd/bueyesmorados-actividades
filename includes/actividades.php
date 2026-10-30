<?php
// Datos de las actividades: estadísticas, lista de pasos de preparación y copia de estructura.

// Columnas calculadas que acompañan a una actividad (alias "a"). El CAST evita el error de resta
// con números sin signo cuando hay más inscritas que la capacidad.
const ACT_STATS_SQL="a.*,
  (SELECT COUNT(*) FROM comisiones WHERE actividad_id=a.id AND activa=1) n_com,
  (SELECT COUNT(*) FROM turnos WHERE actividad_id=a.id AND activo=1) n_tur,
  (SELECT COALESCE(SUM(c.capacidad),0) FROM cupos c
     JOIN comisiones co ON co.id=c.comision_id AND co.activa=1 JOIN turnos t ON t.id=c.turno_id AND t.activo=1
    WHERE c.actividad_id=a.id AND c.habilitado=1) lugares,
  (SELECT COALESCE(SUM(GREATEST(CAST(c.capacidad AS SIGNED)-(SELECT COUNT(*) FROM inscripciones i WHERE i.cupo_id=c.id AND i.estado='confirmada'),0)),0) FROM cupos c
     JOIN comisiones co ON co.id=c.comision_id AND co.activa=1 JOIN turnos t ON t.id=c.turno_id AND t.activo=1
    WHERE c.actividad_id=a.id AND c.habilitado=1) libres,
  (SELECT COUNT(*) FROM inscripciones WHERE actividad_id=a.id AND estado='confirmada') inscritos,
  (SELECT COUNT(*) FROM inscripciones WHERE actividad_id=a.id) total_ins,
  (SELECT COUNT(*) FROM inscripciones WHERE actividad_id=a.id AND estado='confirmada' AND origen='automatica') automaticas,
  (SELECT COUNT(*) FROM familias f WHERE f.activa=1
     AND NOT EXISTS(SELECT 1 FROM exoneraciones x WHERE x.actividad_id=a.id AND x.familia_id=f.id)
     AND NOT EXISTS(SELECT 1 FROM inscripciones i WHERE i.actividad_id=a.id AND i.estado='confirmada' AND (i.familia_id=f.id OR i.familia=f.nombre))) sin_inscribir";

function act_cargar(PDO $pdo,int $id):?array{
    $s=$pdo->prepare('SELECT '.ACT_STATS_SQL.' FROM actividades a WHERE a.id=?');$s->execute([$id]);
    return $s->fetch()?:null;
}
function act_lista(PDO $pdo,string $where='1=1',string $orden="FIELD(a.estado,'publicada','borrador','archivada'),a.fecha_actividad DESC,a.id DESC"):array{
    return $pdo->query('SELECT '.ACT_STATS_SQL." FROM actividades a WHERE $where ORDER BY $orden")->fetchAll();
}
function act_familias_activas(PDO $pdo):int{return (int)$pdo->query('SELECT COUNT(*) FROM familias WHERE activa=1')->fetchColumn();}

// Lista de preparación de una actividad. Devuelve ['items'=>[…],'siguiente'=>item|null].
// Cada item: ok, req (obligatorio para publicar), titulo, detalle, url, cta.
function act_pasos(array $a,int $familias):array{
    $id=(int)$a['id'];
    $estructura=url('admin/estructura.php?actividad='.$id);
    $auto=$a['modo_control']==='automatico';
    $items=[
        ['clave'=>'comisiones','ok'=>$a['n_com']>0,'req'=>true,'titulo'=>'Comisiones','detalle'=>$a['n_com']>0?plural((int)$a['n_com'],'comisión creada','comisiones creadas'):'Crea los grupos de trabajo (por ejemplo Seguridad, Caja…).','url'=>$estructura,'cta'=>'Agregar comisiones'],
        ['clave'=>'turnos','ok'=>$a['n_tur']>0,'req'=>true,'titulo'=>'Turnos','detalle'=>$a['n_tur']>0?plural((int)$a['n_tur'],'turno creado','turnos creados'):'Define los horarios (por ejemplo 09:00 a 12:00).','url'=>$estructura,'cta'=>'Agregar turnos'],
        ['clave'=>'cupos','ok'=>$a['lugares']>0,'req'=>true,'titulo'=>'Cupos','detalle'=>$a['lugares']>0?plural((int)$a['lugares'],'lugar en total','lugares en total'):'Indica cuántas familias caben en cada turno.','url'=>$estructura.'#cupos','cta'=>'Definir cupos'],
        ['clave'=>'fechas','ok'=>!$auto||!empty($a['fecha_cierre']),'req'=>false,'titulo'=>'Fecha de cierre','detalle'=>$auto?(!empty($a['fecha_cierre'])?'Cierra el '.dt_largo($a['fecha_cierre']):'Sin fecha de cierre: la inscripción no se cierra sola.'):($a['modo_control']==='forzar_abierto'?'Abierta hasta que tú la cierres.':'Cerrada manualmente.'),'url'=>url('admin/actividades.php?editar='.$id),'cta'=>'Elegir fechas'],
        ['clave'=>'familias','ok'=>$familias>0,'req'=>false,'titulo'=>'Lista de familias','detalle'=>$familias>0?plural($familias,'familia cargada','familias cargadas'):'Carga la lista para que las familias se elijan de ella y para el sorteo.','url'=>url('admin/familias.php'),'cta'=>'Cargar familias'],
        ['clave'=>'publicada','ok'=>$a['estado']==='publicada','req'=>true,'titulo'=>'Publicada','detalle'=>$a['estado']==='publicada'?'Las familias ya la ven en la página pública.':'Mientras sea borrador, solo tú la ves.','url'=>url('admin/actividad.php?id='.$id),'cta'=>'Publicar'],
    ];
    $sig=null;
    foreach($items as $it)if($it['req']&&!$it['ok']){$sig=$it;break;}
    return ['items'=>$items,'siguiente'=>$sig];
}

// Copia comisiones, turnos y cupos de una actividad a otra (sin inscripciones). Devuelve los totales copiados.
function copiar_estructura(PDO $pdo,int $desde,int $hacia):array{
    $r=['comisiones'=>0,'turnos'=>0,'cupos'=>0];
    $pdo->beginTransaction();
    try{
        $mapCom=[];$mapTur=[];
        $ins=$pdo->prepare('INSERT IGNORE INTO comisiones(actividad_id,nombre,descripcion,reglas,activa,orden) VALUES(?,?,?,?,?,?)');
        $buscar=$pdo->prepare('SELECT id FROM comisiones WHERE actividad_id=? AND nombre=?');
        $s=$pdo->prepare('SELECT * FROM comisiones WHERE actividad_id=? ORDER BY orden,nombre');$s->execute([$desde]);
        foreach($s->fetchAll() as $c){
            $ins->execute([$hacia,$c['nombre'],$c['descripcion'],$c['reglas'],$c['activa'],$c['orden']]);
            if($ins->rowCount())$r['comisiones']++;
            $buscar->execute([$hacia,$c['nombre']]);$mapCom[(int)$c['id']]=(int)$buscar->fetchColumn();
        }
        $ins=$pdo->prepare('INSERT IGNORE INTO turnos(actividad_id,nombre,hora_inicio,hora_fin,activo,orden) VALUES(?,?,?,?,?,?)');
        $buscar=$pdo->prepare('SELECT id FROM turnos WHERE actividad_id=? AND hora_inicio=? AND hora_fin=?');
        $s=$pdo->prepare('SELECT * FROM turnos WHERE actividad_id=? ORDER BY hora_inicio,hora_fin');$s->execute([$desde]);
        foreach($s->fetchAll() as $t){
            $ins->execute([$hacia,$t['nombre'],$t['hora_inicio'],$t['hora_fin'],$t['activo'],$t['orden']]);
            if($ins->rowCount())$r['turnos']++;
            $buscar->execute([$hacia,$t['hora_inicio'],$t['hora_fin']]);$mapTur[(int)$t['id']]=(int)$buscar->fetchColumn();
        }
        $ins=$pdo->prepare('INSERT IGNORE INTO cupos(actividad_id,comision_id,turno_id,capacidad,habilitado) VALUES(?,?,?,?,?)');
        $s=$pdo->prepare('SELECT * FROM cupos WHERE actividad_id=?');$s->execute([$desde]);
        foreach($s->fetchAll() as $c){
            if(!isset($mapCom[(int)$c['comision_id']],$mapTur[(int)$c['turno_id']]))continue;
            $ins->execute([$hacia,$mapCom[(int)$c['comision_id']],$mapTur[(int)$c['turno_id']],$c['capacidad'],$c['habilitado']]);
            $r['cupos']+=$ins->rowCount();
        }
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    return $r;
}

// Texto de la ventana de inscripción de una actividad: "Inscripciones abiertas hasta el … (quedan 8 días)".
function act_ventana(array $a):string{
    [$code]=activity_state($a);
    if($code==='abierta'){
        if($a['modo_control']==='automatico'&&!empty($a['fecha_cierre']))
            return 'Inscripciones abiertas hasta el '.dt_largo($a['fecha_cierre']).' (quedan '.tiempo_restante(new DateTimeImmutable($a['fecha_cierre'])).')';
        return 'Inscripciones abiertas';
    }
    if($code==='proxima')return 'Las inscripciones abren el '.dt_largo($a['fecha_apertura']);
    return 'Inscripciones cerradas'.(cierre_vencido($a)?' desde el '.dt_largo($a['fecha_cierre']):'');
}
