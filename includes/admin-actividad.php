<?php
// Pestañas de una actividad (Resumen · Comisiones y turnos · Inscripciones · Asignación · Datos).
function act_tabs(array $a,string $activa):void{
    $id=(int)$a['id'];
    $tabs=[
        'resumen'=>['Resumen',url('admin/actividad.php?id='.$id),''],
        'estructura'=>['Comisiones y turnos',url('admin/estructura.php?actividad='.$id),''],
        'inscripciones'=>['Inscripciones',url('admin/inscripciones.php?actividad='.$id),'<span class="num">'.(int)$a['inscritos'].'</span>'],
        'sorteo'=>['Asignación automática',url('admin/sorteo.php?actividad='.$id),!empty($a['asignacion_auto'])&&empty($a['asignacion_ejecutada_en'])?'<span class="pto" title="Activada"></span>':''],
        'datos'=>['Datos',url('admin/actividades.php?editar='.$id),''],
    ];
    echo '<nav class="tabs" aria-label="Secciones de la actividad">';
    foreach($tabs as $clave=>[$texto,$href,$extra])
        echo '<a href="'.e($href).'" class="'.($clave===$activa?'on':'').'"'.($clave===$activa?' aria-current="page"':'').'>'.e($texto).$extra.'</a>';
    echo '<a class="aparte" href="'.e(url('actividad.php?id='.$id)).'" target="_blank" rel="noopener">Ver como familia ↗</a></nav>';
}

// Carga la actividad de ?actividad= / ?id= (o la primera disponible) o redirige a la lista.
function act_actual(PDO $pdo,int $id):array{
    $a=$id?act_cargar($pdo,$id):null;
    if(!$a){flash('error','No encontramos esa actividad.');go('admin/actividades.php');}
    return $a;
}
