<?php
// Lista de familias de la promoción: búsqueda, importación y utilidades.

const FAM_SIN_TILDES=['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N'];

// Forma en que se guarda y se muestra un nombre de familia: mayúsculas y espacios simples.
function fam_nombre(string $s):string{return mb_strtoupper(trim(preg_replace('/\s+/u',' ',utf8_limpio($s))??''),'UTF-8');}
// Forma para comparar nombres: igual que fam_nombre pero sin tildes ni ñ.
function fam_clave(string $s):string{return strtr(fam_nombre($s),FAM_SIN_TILDES);}

// Secciones como "A,C" (letras únicas y ordenadas) a partir de cualquier texto: "a / c", "A-C"…
function fam_secciones(?string $s):?string{
    $solo=preg_replace('/[^A-Z]/','',strtoupper((string)$s))??'';
    if($solo==='')return null; // en PHP 8.0 str_split('') devuelve [''], por eso se revisa antes
    $letras=array_values(array_unique(str_split($solo)));
    sort($letras);
    return implode(',',$letras);
}

// Búsqueda para el selector de familia. Cada palabra escrita debe aparecer en el nombre
// (la colación de la base ignora mayúsculas y tildes). Con $actividad, marca cuáles ya están inscritas.
function fam_buscar(PDO $pdo,string $q,int $limite=8,int $actividad=0):array{
    $tokens=array_slice(array_values(array_filter(preg_split('/\s+/u',trim($q))?:[],fn($t)=>$t!=='')),0,4);
    if(!$tokens)return [];
    $where=[];$par=[];
    foreach($tokens as $t){$where[]='f.nombre LIKE ?';$par[]='%'.addcslashes($t,'%_\\').'%';}
    $par[]=addcslashes($tokens[0],'%_\\').'%';
    $ya=$actividad>0
        ?",EXISTS(SELECT 1 FROM inscripciones i WHERE i.actividad_id=".(int)$actividad." AND i.estado='confirmada' AND (i.familia_id=f.id OR i.familia=f.nombre)) ya"
        :',0 ya';
    $s=$pdo->prepare("SELECT f.id,f.nombre,f.secciones$ya FROM familias f WHERE f.activa=1 AND ".implode(' AND ',$where)." ORDER BY (f.nombre LIKE ?) DESC,f.nombre LIMIT ".max(1,$limite));
    $s->execute($par);
    return $s->fetchAll();
}

// Texto pegado o archivo CSV → filas ['nombre','secciones','responsable','telefono'] y líneas omitidas.
// Acepta una familia por línea, o columnas separadas por ; , o tabulación: familia; secciones; responsable; teléfono.
function fam_parsear(string $texto):array{
    $texto=preg_replace('/^\xEF\xBB\xBF/','',$texto)??'';
    if(!mb_check_encoding($texto,'UTF-8'))$texto=mb_convert_encoding($texto,'UTF-8','ISO-8859-1');
    $filas=[];$omitidas=[];$primera=true;
    $col=['nombre'=>0,'secciones'=>1,'responsable'=>2,'telefono'=>3]; // sin fila de títulos: por posición
    $titulos=['nombre'=>['FAMILIA','FAMILIAS','NOMBRE','APELLIDOS','NOMBRE FAMILIA','NOMBRE_FAMILIA'],'secciones'=>['SECCION','SECCIONES','SECC'],'responsable'=>['RESPONSABLE','APODERADO'],'telefono'=>['TELEFONO','CELULAR','CEL','FONO']];
    foreach(preg_split('/\R/u',$texto)?:[] as $i=>$linea){
        $linea=trim($linea);
        if($linea==='')continue;
        $sep=null;
        foreach(["\t",';',','] as $c)if(strpos($linea,$c)!==false){$sep=$c;break;}
        $cols=$sep===null?[$linea]:array_map('trim',str_getcsv($linea,$sep,'"','\\'));
        if($primera){
            $primera=false;
            // ¿Es una fila de títulos? Se ubican las columnas por su nombre (así se ignora, por ejemplo, una columna Password).
            $nuevo=['nombre'=>null,'secciones'=>null,'responsable'=>null,'telefono'=>null];
            foreach($cols as $idx=>$t)foreach($titulos as $campo=>$lista)if(in_array(fam_clave((string)$t),$lista,true))$nuevo[$campo]=$idx;
            if(array_filter($nuevo,fn($v)=>$v!==null)){
                $col=['nombre'=>$nuevo['nombre']??0,'secciones'=>$nuevo['secciones'],'responsable'=>$nuevo['responsable'],'telefono'=>$nuevo['telefono']];
                continue;
            }
        }
        $dato=fn(string $campo)=>$col[$campo]===null?'':trim((string)($cols[$col[$campo]]??''));
        $nombre=fam_nombre(trim($dato('nombre'),"\"' "));
        if(mb_strlen($nombre)<3){$omitidas[]='Línea '.($i+1).': el nombre es muy corto';continue;}
        if(mb_strlen($nombre)>150){$omitidas[]='Línea '.($i+1).': el nombre es muy largo';continue;}
        $filas[]=[
            'nombre'=>$nombre,
            'secciones'=>fam_secciones($dato('secciones')),
            'responsable'=>($r=$dato('responsable'))!==''?mb_substr($r,0,150):null,
            'telefono'=>($t=$dato('telefono'))!==''?mb_substr($t,0,30):null,
        ];
    }
    // Nombres repetidos dentro de la misma lista: se conserva uno y se completan sus datos vacíos con el otro.
    $indice=[];$unicas=[];$repetidas=0;
    foreach($filas as $f){
        $k=fam_clave($f['nombre']);
        if(isset($indice[$k])){$repetidas++;foreach(['secciones','responsable','telefono'] as $c)$unicas[$indice[$k]][$c]??=$f[$c];continue;}
        $indice[$k]=count($unicas);$unicas[]=$f;
    }
    return ['filas'=>$unicas,'omitidas'=>$omitidas,'repetidas'=>$repetidas];
}

// Agrega las familias nuevas y actualiza las que ya existen. Con $desactivarOtras, las que no
// aparecen en la lista pasan a inactivas (nunca se borran: conservan su historial).
// Con $simular solo cuenta lo que pasaría, sin escribir nada.
function fam_importar(PDO $pdo,array $filas,bool $desactivarOtras=false,bool $simular=false):array{
    $r=['nuevas'=>0,'actualizadas'=>0,'sin_cambios'=>0,'desactivadas'=>0];
    $vistas=[];
    $busca=$pdo->prepare('SELECT * FROM familias WHERE nombre=?');
    $inserta=$pdo->prepare('INSERT INTO familias(nombre,secciones,responsable,telefono) VALUES(?,?,?,?)');
    $actualiza=$pdo->prepare('UPDATE familias SET secciones=?,responsable=?,telefono=?,activa=1 WHERE id=?');
    foreach($filas as $f){
        $busca->execute([$f['nombre']]);
        $ex=$busca->fetch();
        if(!$ex){
            if(!$simular){$inserta->execute([$f['nombre'],$f['secciones'],$f['responsable'],$f['telefono']]);$vistas[(int)$pdo->lastInsertId()]=true;}
            $r['nuevas']++;
            continue;
        }
        $vistas[(int)$ex['id']]=true;
        $sec=$f['secciones']??$ex['secciones'];$res=$f['responsable']??$ex['responsable'];$tel=$f['telefono']??$ex['telefono'];
        if($sec===$ex['secciones']&&$res===$ex['responsable']&&$tel===$ex['telefono']&&(int)$ex['activa']===1){$r['sin_cambios']++;continue;}
        if(!$simular)$actualiza->execute([$sec,$res,$tel,$ex['id']]);
        $r['actualizadas']++;
    }
    if($desactivarOtras&&$filas){
        $fuera=$vistas?'AND id NOT IN ('.implode(',',array_map('intval',array_keys($vistas))).')':'';
        $r['desactivadas']=$simular
            ?(int)$pdo->query("SELECT COUNT(*) FROM familias WHERE activa=1 $fuera")->fetchColumn()
            :(int)$pdo->exec("UPDATE familias SET activa=0 WHERE activa=1 $fuera");
    }
    return $r;
}

// Último nombre y teléfono de contacto conocidos de una familia (de sus inscripciones anteriores o de la lista).
function fam_contactos(PDO $pdo,array $ids):array{
    $ids=array_values(array_unique(array_map('intval',$ids)));
    if(!$ids)return [];
    $in=implode(',',$ids);
    $out=[];
    foreach($pdo->query("SELECT id,responsable,telefono FROM familias WHERE id IN ($in)")->fetchAll() as $f)
        $out[(int)$f['id']]=['responsable'=>(string)$f['responsable'],'telefono'=>(string)$f['telefono']];
    $q=$pdo->query("SELECT i.familia_id,i.responsable,i.telefono FROM inscripciones i JOIN (SELECT familia_id,MAX(id) mid FROM inscripciones WHERE familia_id IN ($in) AND telefono<>'' AND origen<>'automatica' GROUP BY familia_id) m ON m.mid=i.id");
    foreach($q->fetchAll() as $c)$out[(int)$c['familia_id']]=['responsable'=>(string)$c['responsable'],'telefono'=>(string)$c['telefono']];
    return $out;
}
