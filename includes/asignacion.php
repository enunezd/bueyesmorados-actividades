<?php
// Asignación automática: al cerrarse la inscripción, las familias de la lista que no se
// inscribieron se reparten al azar en los lugares que quedaron libres.
//
//  - asig_planificar()  : función pura (sin base de datos): decide quién va a qué casilla.
//  - asig_situacion()   : lee de la base quiénes faltan y qué lugares hay libres.
//  - asig_ejecutar()    : aplica el reparto dentro de una transacción (una sola vez a la vez).
//  - asig_deshacer()    : cancela lo asignado automáticamente para poder repetir el sorteo.
//  - asig_ejecutar_pendientes(): lo llaman las páginas y cli/asignar-pendientes.php; actúa
//    solo en las actividades cuya fecha de cierre ya pasó.

// Generador pseudoaleatorio con semilla (xorshift32): la misma semilla da siempre el mismo
// reparto, así la vista previa coincide con lo que luego se confirma y el sorteo se puede auditar.
function asig_prng(int $semilla):callable{
    $x=(($semilla*2654435761)&0xFFFFFFFF)?:0x9E3779B9;
    $paso=function()use(&$x):int{$x^=($x<<13)&0xFFFFFFFF;$x^=$x>>17;$x^=($x<<5)&0xFFFFFFFF;return $x;};
    for($i=0;$i<8;$i++)$paso();
    return fn(int $n):int=>$paso()%$n;
}
function asig_nueva_semilla():int{return random_int(1,2147483646);}
function asig_barajar(array $a,callable $rnd):array{
    for($i=count($a)-1;$i>0;$i--){$j=$rnd($i+1);[$a[$i],$a[$j]]=[$a[$j],$a[$i]];}
    return $a;
}

// Reparte las familias entre las casillas con lugar. Las familias se ordenan al azar y cada una
// va a una de las casillas MENOS llenas (proporcionalmente), elegida al azar entre empatadas:
// así todos los turnos se cubren de forma pareja en vez de dejar unos vacíos y otros llenos.
//   $familias: [['id'=>..,'nombre'=>..],…]    $cupos: [['cupo_id'=>..,'capacidad'=>..,'usados'=>..],…]
// Devuelve ['asignadas'=>[['familia'=>[…],'cupo_id'=>..],…],'sin_lugar'=>[familias…]].
function asig_planificar(array $familias,array $cupos,int $semilla):array{
    $rnd=asig_prng($semilla);
    $estado=[];
    foreach($cupos as $c)$estado[(int)$c['cupo_id']]=['cap'=>(int)$c['capacidad'],'usados'=>(int)$c['usados']];
    $asignadas=[];$sin=[];
    foreach(asig_barajar($familias,$rnd) as $f){
        $mejores=[];$minU=0;$minC=1;
        foreach($estado as $id=>$s){
            if($s['usados']>=$s['cap'])continue;
            if(!$mejores||$s['usados']*$minC<$minU*$s['cap']){$mejores=[$id];$minU=$s['usados'];$minC=$s['cap'];}
            elseif($s['usados']*$minC===$minU*$s['cap'])$mejores[]=$id;
        }
        if(!$mejores){$sin[]=$f;continue;}
        $id=$mejores[$rnd(count($mejores))];
        $estado[$id]['usados']++;
        $asignadas[]=['familia'=>$f,'cupo_id'=>$id];
    }
    return ['asignadas'=>$asignadas,'sin_lugar'=>$sin];
}

// Familias activas sin inscribir (ni exoneradas) y casillas con lugares libres de una actividad.
function asig_situacion(PDO $pdo,int $aid):array{
    $aid=(int)$aid;
    $familias=$pdo->query("SELECT f.id,f.nombre,f.secciones FROM familias f
        WHERE f.activa=1
          AND NOT EXISTS(SELECT 1 FROM exoneraciones x WHERE x.actividad_id=$aid AND x.familia_id=f.id)
          AND NOT EXISTS(SELECT 1 FROM inscripciones i WHERE i.actividad_id=$aid AND i.estado='confirmada' AND (i.familia_id=f.id OR i.familia=f.nombre))
        ORDER BY f.id")->fetchAll();
    $cupos=$pdo->query("SELECT c.id cupo_id,c.comision_id,c.turno_id,c.capacidad,
          (SELECT COUNT(*) FROM inscripciones i WHERE i.cupo_id=c.id AND i.estado='confirmada') usados,
          co.nombre comision,co.orden,t.hora_inicio,t.hora_fin
        FROM cupos c
        JOIN comisiones co ON co.id=c.comision_id AND co.activa=1
        JOIN turnos t ON t.id=c.turno_id AND t.activo=1
        WHERE c.actividad_id=$aid AND c.habilitado=1 ORDER BY c.id")->fetchAll();
    $libres=0;$lugares=0;
    foreach($cupos as &$c){
        $c['cupo_id']=(int)$c['cupo_id'];$c['capacidad']=(int)$c['capacidad'];$c['usados']=(int)$c['usados'];
        $c['libres']=max(0,$c['capacidad']-$c['usados']);
        $libres+=$c['libres'];$lugares+=$c['capacidad'];
    }
    unset($c);
    $q=fn(string $sql)=>(int)$pdo->query($sql)->fetchColumn();
    return [
        'familias'=>$familias,
        'cupos'=>$cupos,
        'lugares'=>$lugares,
        'libres'=>$libres,
        'ocupados'=>$lugares-$libres,
        'total_familias'=>$q('SELECT COUNT(*) FROM familias WHERE activa=1'),
        'exoneradas'=>$q("SELECT COUNT(*) FROM exoneraciones x JOIN familias f ON f.id=x.familia_id AND f.activa=1 WHERE x.actividad_id=$aid"),
        'inscritas'=>$q("SELECT COUNT(*) FROM inscripciones WHERE actividad_id=$aid AND estado='confirmada'"),
        // Cambia si alguien se inscribe, cancela o se modifica un cupo: permite detectar una vista previa vencida.
        'hash'=>md5(json_encode([array_column($familias,'id'),array_map(fn($c)=>[$c['cupo_id'],$c['capacidad'],$c['usados']],$cupos)])),
    ];
}

// Aplica el sorteo. $modo: 'auto' (lo dispara la fecha de cierre; solo corre una vez) o 'manual'.
// Con $hash se exige que nada haya cambiado desde la vista previa. Lanza MensajeError si no se puede.
function asig_ejecutar(PDO $pdo,int $aid,int $semilla,string $modo='manual',?int $adminId=null,?string $hash=null):array{
    $pdo->beginTransaction();
    try{
        // Bloquear la actividad impide inscripciones simultáneas y que el sorteo corra dos veces a la vez.
        $s=$pdo->prepare('SELECT * FROM actividades WHERE id=? FOR UPDATE');$s->execute([$aid]);$act=$s->fetch();
        if(!$act)throw new MensajeError('La actividad no existe.');
        if($modo==='auto'&&(!(int)$act['asignacion_auto']||$act['asignacion_ejecutada_en']!==null||$act['estado']!=='publicada'||!cierre_vencido($act))){
            $pdo->rollBack();
            return ['omitida'=>true];
        }
        $sit=asig_situacion($pdo,$aid);
        if($hash!==null&&$hash!==$sit['hash'])throw new MensajeError('Algo cambió desde la vista previa (una inscripción, un cupo…). Vuelve a sortear para ver el reparto actualizado.');
        $plan=asig_planificar($sit['familias'],$sit['cupos'],$semilla);
        $porCupo=[];foreach($sit['cupos'] as $c)$porCupo[$c['cupo_id']]=$c;
        $contactos=fam_contactos($pdo,array_map(fn($a)=>$a['familia']['id'],$plan['asignadas']));
        $cuando=date('d/m/Y H:i');
        $ins=$pdo->prepare("INSERT INTO inscripciones(actividad_id,comision_id,turno_id,cupo_id,familia_id,familia,responsable,telefono,grado_seccion,observacion,estado,origen,codigo_confirmacion) VALUES(?,?,?,?,?,?,?,?,?,?,'confirmada','automatica',?)");
        $hechas=[];
        foreach($plan['asignadas'] as $a){
            $f=$a['familia'];$c=$porCupo[$a['cupo_id']];$k=$contactos[(int)$f['id']]??['responsable'=>'','telefono'=>''];
            $codigo=codigo_confirmacion($pdo);
            $ins->execute([$aid,$c['comision_id'],$c['turno_id'],$c['cupo_id'],$f['id'],$f['nombre'],$k['responsable'],$k['telefono'],$f['secciones'],"Asignada automáticamente en el sorteo del $cuando",$codigo]);
            $hechas[]=['familia'=>$f['nombre'],'comision'=>$c['comision'],'turno'=>hhmm($c['hora_inicio']).'–'.hhmm($c['hora_fin']),'codigo'=>$codigo];
        }
        if($modo==='auto')$pdo->prepare('UPDATE actividades SET asignacion_ejecutada_en=? WHERE id=?')->execute([date('Y-m-d H:i:s'),$aid]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    audit($adminId,$modo==='auto'?'sorteo_automatico':'sorteo_manual','actividades',$aid,'semilla='.$semilla.' asignadas='.count($hechas).' sin_lugar='.count($plan['sin_lugar']));
    return ['omitida'=>false,'semilla'=>$semilla,'asignadas'=>$hechas,'sin_lugar'=>count($plan['sin_lugar'])];
}

// Cancela lo asignado automáticamente (no toca inscripciones hechas por familias ni movidas a mano).
// Si el plazo de la actividad ya venció, también desactiva la asignación automática: de lo contrario
// la siguiente visita al sitio volvería a sortear de inmediato y nunca se podría deshacer.
// Devuelve ['canceladas'=>n,'desactivada'=>bool].
function asig_deshacer(PDO $pdo,int $aid,?int $adminId=null):array{
    $pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT * FROM actividades WHERE id=? FOR UPDATE');$s->execute([$aid]);$act=$s->fetch();
        $s=$pdo->prepare("UPDATE inscripciones SET estado='cancelada',observacion=CONCAT(COALESCE(observacion,''),' · Asignación deshecha el ".date('d/m/Y H:i')."') WHERE actividad_id=? AND origen='automatica' AND estado='confirmada'");
        $s->execute([$aid]);$n=$s->rowCount();
        $desactivar=$act&&(int)$act['asignacion_auto']===1&&cierre_vencido($act);
        $pdo->prepare('UPDATE actividades SET asignacion_ejecutada_en=NULL'.($desactivar?',asignacion_auto=0':'').' WHERE id=?')->execute([$aid]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    audit($adminId,'sorteo_deshecho','actividades',$aid,"canceladas=$n".($desactivar?' asignacion_auto=0':''));
    return ['canceladas'=>$n,'desactivada'=>$desactivar];
}

// Ejecuta el sorteo de toda actividad publicada, con asignación automática activada, cuya fecha
// de cierre ya pasó y que aún no se sorteó. Devuelve cuántas se sortearon. Nunca lanza errores.
function asig_ejecutar_pendientes(?PDO $pdo=null):int{
    try{
        $pdo??=db();
        $filas=$pdo->query("SELECT id,fecha_cierre FROM actividades WHERE asignacion_auto=1 AND asignacion_ejecutada_en IS NULL AND estado='publicada' AND modo_control='automatico' AND fecha_cierre IS NOT NULL")->fetchAll();
    }catch(Throwable $e){
        return 0; // por ejemplo, la migración todavía no se aplicó
    }
    $n=0;
    foreach($filas as $f){
        if(new DateTimeImmutable()<=new DateTimeImmutable($f['fecha_cierre']))continue;
        try{
            $r=asig_ejecutar($pdo,(int)$f['id'],asig_nueva_semilla(),'auto');
            if(empty($r['omitida']))$n++;
        }catch(Throwable $e){
            error_log('Asignación automática de la actividad '.$f['id'].': '.$e->getMessage());
        }
    }
    return $n;
}
