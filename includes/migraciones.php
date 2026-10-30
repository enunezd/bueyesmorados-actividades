<?php
// Migraciones de la base de datos. Solo AGREGAN (tablas, columnas, índices) y se pueden
// ejecutar las veces que sea: cada paso revisa si ya existe antes de crear algo.
// Uso:  php cli/migrar.php

function mig_existe(PDO $pdo,string $sql,array $par):bool{$s=$pdo->prepare($sql);$s->execute($par);return (int)$s->fetchColumn()>0;}
function mig_tabla(PDO $pdo,string $t):bool{return mig_existe($pdo,'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$t]);}
function mig_columna(PDO $pdo,string $t,string $c):bool{return mig_existe($pdo,'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$t,$c]);}
function mig_indice(PDO $pdo,string $t,string $i):bool{return mig_existe($pdo,'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$t,$i]);}
function mig_fk(PDO $pdo,string $t,string $n):bool{return mig_existe($pdo,"SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE='FOREIGN KEY'",[$t,$n]);}

function migraciones():array{
    $tabla='ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        '001_familias'=>function(PDO $pdo)use($tabla){
            $pdo->exec("CREATE TABLE IF NOT EXISTS familias(
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                nombre VARCHAR(150) NOT NULL,
                secciones VARCHAR(40) NULL,
                responsable VARCHAR(150) NULL,
                telefono VARCHAR(30) NULL,
                activa TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_familia_nombre(nombre)) $tabla");
        },
        '002_inscripciones_familia_y_origen'=>function(PDO $pdo){
            if(!mig_columna($pdo,'inscripciones','familia_id'))$pdo->exec('ALTER TABLE inscripciones ADD COLUMN familia_id INT UNSIGNED NULL AFTER cupo_id');
            if(!mig_columna($pdo,'inscripciones','origen'))$pdo->exec("ALTER TABLE inscripciones ADD COLUMN origen ENUM('familia','admin','automatica') NOT NULL DEFAULT 'familia' AFTER estado");
            if(!mig_indice($pdo,'inscripciones','idx_insc_familia'))$pdo->exec('ALTER TABLE inscripciones ADD INDEX idx_insc_familia(actividad_id,familia_id)');
            if(!mig_fk($pdo,'inscripciones','fk_insc_familia'))$pdo->exec('ALTER TABLE inscripciones ADD CONSTRAINT fk_insc_familia FOREIGN KEY(familia_id) REFERENCES familias(id) ON DELETE SET NULL');
        },
        '003_actividades_asignacion'=>function(PDO $pdo){
            if(!mig_columna($pdo,'actividades','asignacion_auto'))$pdo->exec('ALTER TABLE actividades ADD COLUMN asignacion_auto TINYINT(1) NOT NULL DEFAULT 0 AFTER mostrar_cerrada');
            if(!mig_columna($pdo,'actividades','asignacion_ejecutada_en'))$pdo->exec('ALTER TABLE actividades ADD COLUMN asignacion_ejecutada_en DATETIME NULL AFTER asignacion_auto');
        },
        '004_exoneraciones'=>function(PDO $pdo)use($tabla){
            $pdo->exec("CREATE TABLE IF NOT EXISTS exoneraciones(
                actividad_id INT UNSIGNED NOT NULL,
                familia_id INT UNSIGNED NOT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(actividad_id,familia_id),
                FOREIGN KEY(actividad_id) REFERENCES actividades(id) ON DELETE CASCADE,
                FOREIGN KEY(familia_id) REFERENCES familias(id) ON DELETE CASCADE) $tabla");
        },
    ];
}

// Aplica las migraciones pendientes y devuelve los nombres de las que se ejecutaron.
function migrar(PDO $pdo):array{
    $pdo->exec('CREATE TABLE IF NOT EXISTS migraciones(nombre VARCHAR(100) NOT NULL PRIMARY KEY,aplicada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $hechas=$pdo->query('SELECT nombre FROM migraciones')->fetchAll(PDO::FETCH_COLUMN);
    $aplicadas=[];
    foreach(migraciones() as $nombre=>$paso){
        if(in_array($nombre,$hechas,true))continue;
        $paso($pdo);
        $pdo->prepare('INSERT INTO migraciones(nombre) VALUES(?)')->execute([$nombre]);
        $aplicadas[]=$nombre;
    }
    return $aplicadas;
}
