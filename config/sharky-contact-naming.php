<?php

declare(strict_types=1);

const HACHE_SHARKY_CONTACT_SIGLA_PREFIX='sharky_contact_sigla_';

function hache_sharky_contact_naming_clean(string $value): string
{
    $value=preg_replace('/[\x00-\x1F\x7F]+/u',' ',trim($value))??'';
    $value=preg_replace('/\s+/u',' ',$value)??'';
    return trim($value);
}

function hache_sharky_contact_naming_person(string $fullName): string
{
    $clean=hache_sharky_contact_naming_clean($fullName);
    if($clean==='')return '';
    $parts=array_values(array_filter(preg_split('/\s+/u',$clean)?:[],static fn(string $part):bool=>$part!==''));
    if(!$parts)return '';
    $selected=array_slice($parts,0,min(2,count($parts)));
    return mb_strtoupper(implode(' ',$selected),'UTF-8');
}

function hache_sharky_contact_naming_time(string $time): string
{
    if(!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/',trim($time),$m))return '';
    $hour=(int)$m[1];$minute=(int)$m[2];
    if($hour<0||$hour>23||$minute<0||$minute>59)return '';
    $suffix=$hour>=12?'PM':'AM';
    $display=$hour%12;if($display===0)$display=12;
    return $display.($minute===0?'':':'.str_pad((string)$minute,2,'0',STR_PAD_LEFT)).' '.$suffix;
}

function hache_sharky_contact_naming_date(string $date): string
{
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',trim($date));
    if(!$d||$d->format('Y-m-d')!==trim($date))return '';
    $months=[1=>'ENE',2=>'FEB',3=>'MAR',4=>'ABR',5=>'MAY',6=>'JUN',7=>'JUL',8=>'AGO',9=>'SEP',10=>'OCT',11=>'NOV',12=>'DIC'];
    return ($months[(int)$d->format('n')]??'').' '.(int)$d->format('j');
}

function hache_sharky_contact_naming_config_key(string $sedeClave): string
{
    $slug=strtolower(preg_replace('/[^A-Za-z0-9_]+/','_',trim($sedeClave))??'');
    $slug=trim($slug,'_');
    return $slug===''?'':HACHE_SHARKY_CONTACT_SIGLA_PREFIX.$slug;
}

function hache_sharky_contact_naming_default_sigla(string $sedeClave): string
{
    $clave=strtoupper(preg_replace('/[^A-Za-z0-9]+/','',trim($sedeClave))??'');
    if($clave==='MONTEVERDE')return 'MV';
    if($clave==='PALAPAS')return 'PAL';
    return mb_substr($clave,0,3,'UTF-8');
}

function hache_sharky_contact_naming_sigla_valid(string $value): bool
{
    return preg_match('/^[A-Za-z0-9]{1,6}$/',trim($value))===1;
}

function hache_sharky_contact_naming_config_value_valid(string $key,string $value): bool
{
    return str_starts_with($key,HACHE_SHARKY_CONTACT_SIGLA_PREFIX)
        &&preg_match('/^sharky_contact_sigla_[a-z0-9_]+$/',$key)===1
        &&hache_sharky_contact_naming_sigla_valid($value);
}

function hache_sharky_contact_naming_sigla(PDO $pdo,string $sedeClave): string
{
    $fallback=hache_sharky_contact_naming_default_sigla($sedeClave);
    $key=hache_sharky_contact_naming_config_key($sedeClave);
    if($key==='')return $fallback;
    try{
        $st=$pdo->prepare('SELECT valor FROM configuracion WHERE clave=:c LIMIT 1');
        $st->execute([':c'=>$key]);$value=trim((string)$st->fetchColumn());
        if(hache_sharky_contact_naming_sigla_valid($value))return strtoupper($value);
    }catch(Throwable $e){}
    return $fallback;
}

/** @return array<int,array{clave:string,valor:string,descripcion:string,tipo:string,etiqueta:string}> */
function hache_sharky_contact_naming_config_rows(PDO $pdo): array
{
    $rows=[];
    try{
        $sites=$pdo->query('SELECT clave,nombre FROM sedes WHERE activo=1 ORDER BY nombre,clave')->fetchAll(PDO::FETCH_ASSOC);
        foreach($sites as $site){
            $clave=trim((string)($site['clave']??''));if($clave==='')continue;
            $key=hache_sharky_contact_naming_config_key($clave);if($key==='')continue;
            $rows[]=[
                'clave'=>$key,
                'valor'=>hache_sharky_contact_naming_sigla($pdo,$clave),
                'descripcion'=>'Sigla usada al nombrar contactos de la sede '.trim((string)($site['nombre']??$clave)).'. Se aplica a cursos intensivos y clases regulares.',
                'tipo'=>'text',
                'etiqueta'=>'Sigla de contactos — '.trim((string)($site['nombre']??$clave)),
            ];
        }
    }catch(Throwable $e){}
    return $rows;
}

function hache_sharky_contact_naming_config_description(PDO $pdo,string $key): string
{
    foreach(hache_sharky_contact_naming_config_rows($pdo) as $row){
        if(($row['clave']??'')===$key)return (string)$row['descripcion'];
    }
    return 'Sigla configurable para nombres de contactos de Hache Natación.';
}

/**
 * Returns the authoritative class identity used only for contact naming.
 * An active/upcoming intensive takes precedence; otherwise the regular schedule
 * attached to the student is used.
 *
 * @return array{program:string,sede_clave:string,start_date:string,start_time:string}|null
 */
function hache_sharky_contact_naming_student_context(PDO $pdo,string $studentId): ?array
{
    $studentId=trim($studentId);if($studentId==='')return null;
    try{
        $st=$pdo->prepare("SELECT a.id,a.plan_actual_id,a.horario_preferido_id,s.clave sede_clave,h.hora_inicio regular_inicio
            FROM alumnos a
            JOIN sedes s ON s.id=a.sede_id
            LEFT JOIN horarios h ON h.id=a.horario_preferido_id
            WHERE a.id=:a LIMIT 1");
        $st->execute([':a'=>$studentId]);$student=$st->fetch(PDO::FETCH_ASSOC);
        if(!$student)return null;

        $intensive=null;
        if(hache_sharky_contact_book_table_exists($pdo,'curso_intensivo_alumnos')&&hache_sharky_contact_book_table_exists($pdo,'cursos_intensivos')){
            $st=$pdo->prepare("SELECT ci.fecha_inicio,h.hora_inicio
                FROM curso_intensivo_alumnos cia
                JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id
                JOIN horarios h ON h.id=cia.horario_id
                WHERE cia.alumno_id=:a
                  AND ci.estado NOT IN ('CANCELADO','FINALIZADO','TERMINADO')
                  AND ci.fecha_fin>=CURDATE()
                ORDER BY (CURDATE() BETWEEN ci.fecha_inicio AND ci.fecha_fin) DESC,ci.fecha_inicio ASC
                LIMIT 1");
            $st->execute([':a'=>$studentId]);$intensive=$st->fetch(PDO::FETCH_ASSOC)?:null;
        }
        if(is_array($intensive))return [
            'program'=>'INTENSIVE','sede_clave'=>(string)$student['sede_clave'],
            'start_date'=>(string)$intensive['fecha_inicio'],'start_time'=>(string)$intensive['hora_inicio'],
        ];
        $regularTime=trim((string)($student['regular_inicio']??''));
        if($regularTime!=='')return [
            'program'=>'REGULAR','sede_clave'=>(string)$student['sede_clave'],
            'start_date'=>'','start_time'=>$regularTime,
        ];
        return null;
    }catch(Throwable $e){return null;}
}

function hache_sharky_contact_naming_student(PDO $pdo,string $studentId,string $fullName): string
{
    $person=hache_sharky_contact_naming_person($fullName);if($person==='')return '';
    $ctx=hache_sharky_contact_naming_student_context($pdo,$studentId);if(!is_array($ctx))return $person.' — ALUMNO HACHE';
    $sigla=hache_sharky_contact_naming_sigla($pdo,(string)$ctx['sede_clave']);
    $time=hache_sharky_contact_naming_time((string)$ctx['start_time']);
    if($sigla===''||$time==='')return $person.' — ALUMNO HACHE';
    if(($ctx['program']??'')==='INTENSIVE'){
        $date=hache_sharky_contact_naming_date((string)$ctx['start_date']);
        if($date!=='')return mb_substr($sigla.' '.$date.' - '.$person.' ('.$time.')',0,180,'UTF-8');
    }
    return mb_substr($sigla.' - '.$person.' ('.$time.')',0,180,'UTF-8');
}

function hache_sharky_contact_naming_generic(string $baseName,string $role,string $digits): string
{
    $person=hache_sharky_contact_naming_person($baseName);
    $label=match($role){'TEACHER'=>'COACH HACHE','PROSPECT'=>'PROSPECTO HACHE','STUDENT'=>'ALUMNO HACHE',default=>'CONTACTO HACHE'};
    if($person!=='')return mb_substr($person.' — '.$label,0,180,'UTF-8');
    $last4=substr($digits,-4);
    return $label.($last4!==''?' · '.$last4:'');
}

function hache_sharky_contact_naming_desired(PDO $pdo,array $identity,string $digits): string
{
    if(($identity['role']??'')==='STUDENT'){
        $name=hache_sharky_contact_naming_student($pdo,(string)($identity['student_id']??''),(string)($identity['base_name']??''));
        if($name!=='')return $name;
    }
    return hache_sharky_contact_naming_generic((string)($identity['base_name']??''),(string)($identity['role']??''),$digits);
}
