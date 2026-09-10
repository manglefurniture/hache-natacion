<?php
declare(strict_types=1);

require_once __DIR__.'/telefono.php';
require_once __DIR__.'/sharky-outbox.php';

const HACHE_SHARKY_TEMPLATE_LANGUAGE_MX = 'es_MX';
const HACHE_SHARKY_TEMPLATE_PAYMENT_CONFIRMED = 'hache_pago_confirmado';
const HACHE_SHARKY_TEMPLATE_ENROLLMENT_CONFIRMED = 'hache_inscripcion_confirmada_mx';
const HACHE_SHARKY_TEMPLATE_COURSE_START = 'hache_inicio_curso';
const HACHE_SHARKY_TEMPLATE_CLASS_CANCELLED = 'hache_clase_cancelada';
const HACHE_SHARKY_TEMPLATE_MAKEUP_CONFIRMED = 'hache_reposicion_confirmada';
const HACHE_SHARKY_TEMPLATE_SCHEDULE_CHANGED = 'hache_cambio_horario';
const HACHE_SHARKY_TEMPLATE_RESUME_ENROLLMENT = 'hache_retomar_inscripcion';
const HACHE_SHARKY_TEMPLATE_ENROLLMENT_OPEN = 'hache_inscripciones_abiertas_mx';
const HACHE_SHARKY_TEMPLATE_INTENSIVE_CONTINUITY = 'hache_continuidad_intensivo';

/** @return array{digits:string,e164:string}|null */
function hache_sharky_template_phone(string $value): ?array
{
    $digits=telefono_digitos($value);
    if(strlen($digits)===13&&str_starts_with($digits,'521'))$digits='52'.substr($digits,3);
    if(strlen($digits)===10)$digits='52'.$digits;
    $e164='+'.$digits;
    if($digits===''||!telefono_es_e164($e164))return null;
    return ['digits'=>$digits,'e164'=>$e164];
}

function hache_sharky_template_text(string $value,int $max=900): string
{
    $value=preg_replace('/[\x00-\x1F\x7F]+/u',' ',trim($value))??'';
    $value=preg_replace('/\s+/u',' ',$value)??'';
    return mb_substr(trim($value),0,max(1,$max));
}

/** @param list<string> $bodyParameters */
function hache_sharky_template_payload(string $to,string $templateName,array $bodyParameters=[]): array
{
    $parameters=[];
    foreach($bodyParameters as $value){
        $parameters[]=['type'=>'text','text'=>hache_sharky_template_text($value)];
    }
    $template=[
        'name'=>$templateName,
        'language'=>['code'=>HACHE_SHARKY_TEMPLATE_LANGUAGE_MX],
    ];
    if($parameters!==[]){
        $template['components']=[['type'=>'body','parameters'=>$parameters]];
    }
    return [
        'messaging_product'=>'whatsapp',
        'to'=>$to,
        'type'=>'template',
        'template'=>$template,
    ];
}

/** @return array{ok:bool,reason:string,queued:bool} */
function hache_sharky_template_enqueue(PDO $pdo,string $phone,string $templateName,array $bodyParameters,string $dedupeSeed): array
{
    if(hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED')!=='1'){
        return ['ok'=>false,'reason'=>'SHARKY_DISABLED','queued'=>false];
    }
    $normalized=hache_sharky_template_phone($phone);
    if($normalized===null)return ['ok'=>false,'reason'=>'INVALID_PHONE','queued'=>false];
    if(!preg_match('/^[a-z0-9_]{1,512}$/',$templateName))return ['ok'=>false,'reason'=>'INVALID_TEMPLATE','queued'=>false];
    $payload=hache_sharky_template_payload($normalized['digits'],$templateName,$bodyParameters);
    $queued=hache_sharky_outbox_enqueue_raw($pdo,$normalized['digits'],$payload,'template|'.$dedupeSeed,time());
    return ['ok'=>$queued,'reason'=>$queued?'QUEUED':'OUTBOX_UNAVAILABLE','queued'=>$queued];
}

function hache_sharky_payment_concept(array $row): string
{
    $type=strtoupper(trim((string)($row['tipo']??'')));
    if($type==='INSCRIPCION')return 'inscripción';
    if($type==='INTENSIVO')return 'curso intensivo';
    if($type==='MENSUALIDAD'){
        $month=(int)($row['mes']??0);$year=(int)($row['anio']??0);
        $months=[1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
        if(isset($months[$month])&&$year>=2000&&$year<=2100)return 'mensualidad de '.$months[$month].' '.$year;
        return 'mensualidad';
    }
    return 'pago';
}

function hache_sharky_payment_amount_text(float $amount): string
{
    $formatted=number_format($amount,2,'.',',');
    if(str_ends_with($formatted,'.00'))$formatted=substr($formatted,0,-3);
    return $formatted;
}

function hache_sharky_enrollment_date_text(string $date): string
{
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$parsed||$parsed->format('Y-m-d')!==$date)return hache_sharky_template_text($date,80);
    $months=[1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
    return (int)$parsed->format('j').' de '.$months[(int)$parsed->format('n')].' de '.$parsed->format('Y');
}

function hache_sharky_enrollment_time_text(string $time): string
{
    foreach(['H:i:s','H:i'] as $format){
        $parsed=DateTimeImmutable::createFromFormat('!'.$format,$time);
        if($parsed&&$parsed->format($format)===$time)return $parsed->format('g:i A');
    }
    return hache_sharky_template_text($time,80);
}

function hache_sharky_enrollment_schedule(PDO $pdo,array $row,array $detail=[]): string
{
    $explicit=hache_sharky_template_text((string)($detail['horario']??''),80);
    if($explicit!=='')return $explicit;

    $scheduleId=trim((string)($row['horario_preferido_id']??''));
    if($scheduleId===''){
        $st=$pdo->prepare("SELECT cia.horario_id FROM curso_intensivo_alumnos cia INNER JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id WHERE cia.alumno_id=:a ORDER BY ci.fecha_inicio DESC LIMIT 1");
        $st->execute([':a'=>(string)$row['id']]);
        $scheduleId=trim((string)($st->fetchColumn()?:''));
    }
    if($scheduleId!==''){
        $st=$pdo->prepare("SELECT hora_inicio FROM horarios WHERE id=:h LIMIT 1");
        $st->execute([':h'=>$scheduleId]);
        $start=trim((string)($st->fetchColumn()?:''));
        if($start!=='')return hache_sharky_enrollment_time_text($start);
    }
    return '';
}

/** @return array{ok:bool,reason:string,queued:bool} */
function hache_sharky_notify_enrollment_confirmed(PDO $pdo,array $student,array $detail=[]): array
{
    try{
        $studentId=trim((string)($student['id']??$student['alumno_id']??''));
        $phone=trim((string)($student['whatsapp']??''));
        if($studentId===''){
            $normalized=hache_sharky_template_phone($phone);
            if($normalized===null)return ['ok'=>false,'reason'=>'ENROLLMENT_CONTACT_INCOMPLETE','queued'=>false];
            $st=$pdo->prepare("SELECT a.id,a.nombre,a.whatsapp,a.fecha_inicio,a.horario_preferido_id,a.estado_administrativo,s.nombre sede_nombre FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id WHERE a.whatsapp=:w LIMIT 1");
            $st->execute([':w'=>$normalized['e164']]);
        }else{
            $st=$pdo->prepare("SELECT a.id,a.nombre,a.whatsapp,a.fecha_inicio,a.horario_preferido_id,a.estado_administrativo,s.nombre sede_nombre FROM alumnos a INNER JOIN sedes s ON s.id=a.sede_id WHERE a.id=:id LIMIT 1");
            $st->execute([':id'=>$studentId]);
        }
        $row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row)return ['ok'=>false,'reason'=>'ENROLLMENT_NOT_FOUND','queued'=>false];
        if(strtoupper((string)$row['estado_administrativo'])==='BAJA')return ['ok'=>false,'reason'=>'ENROLLMENT_INACTIVE','queued'=>false];

        $studentId=(string)$row['id'];
        $name=hache_sharky_template_text((string)$row['nombre'],120);
        $phone=trim((string)$row['whatsapp']);
        $schedule=hache_sharky_enrollment_schedule($pdo,$row,$detail);
        $site=hache_sharky_template_text((string)$row['sede_nombre'],120);
        $courseStart=trim((string)($detail['curso_inicio']??''));
        $startRaw=$courseStart!==''?$courseStart:(string)$row['fecha_inicio'];
        $startDate=hache_sharky_enrollment_date_text($startRaw);
        if($studentId===''||$name===''||$phone===''||$schedule===''||$site===''||$startDate===''){
            return ['ok'=>false,'reason'=>'ENROLLMENT_CONTEXT_INCOMPLETE','queued'=>false];
        }
        $eventScope=($courseStart!==''?'intensive':'base').'|'.$startRaw.'|'.$schedule;
        return hache_sharky_template_enqueue(
            $pdo,
            $phone,
            HACHE_SHARKY_TEMPLATE_ENROLLMENT_CONFIRMED,
            [$name,$schedule,$site,$startDate],
            'enrollment-confirmed|student:'.$studentId.'|event:'.hash('sha256',$eventScope)
        );
    }catch(Throwable $e){
        error_log('[sharky-template] enrollment confirmation enqueue failed');
        return ['ok'=>false,'reason'=>'INTERNAL_ERROR','queued'=>false];
    }
}

/** @return array{ok:bool,reason:string,queued:bool} */
function hache_sharky_notify_payment_confirmed(PDO $pdo,int $folio): array
{
    if($folio<=0)return ['ok'=>false,'reason'=>'INVALID_FOLIO','queued'=>false];
    try{
        $st=$pdo->prepare(
            "SELECT p.folio,p.tipo,p.importe,p.estado,p.fecha,p.intensivo_id,\n"
            ."a.nombre,a.whatsapp,m.mes,m.anio,ci.fecha_fin intensivo_fecha_fin\n"
            ."FROM pagos p\n"
            ."INNER JOIN alumnos a ON a.id=p.alumno_id\n"
            ."LEFT JOIN mensualidades m ON m.id=p.mensualidad_id\n"
            ."LEFT JOIN cursos_intensivos ci ON ci.id=p.intensivo_id\n"
            ."WHERE p.folio=:folio LIMIT 1"
        );
        $st->execute([':folio'=>$folio]);$row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row)return ['ok'=>false,'reason'=>'PAYMENT_NOT_FOUND','queued'=>false];
        if(strtoupper((string)$row['estado'])!=='VALIDO')return ['ok'=>false,'reason'=>'PAYMENT_NOT_VALID','queued'=>false];
        if(strtoupper((string)$row['tipo'])==='INTENSIVO'&&!empty($row['intensivo_fecha_fin'])){
            $today=(new DateTimeImmutable('today',new DateTimeZone('America/Cancun')))->format('Y-m-d');
            if((string)$row['intensivo_fecha_fin']<$today)return ['ok'=>false,'reason'=>'HISTORICAL_PAYMENT','queued'=>false];
        }
        $name=hache_sharky_template_text((string)$row['nombre'],120);
        $phone=trim((string)$row['whatsapp']);
        $amount=(float)$row['importe'];
        if($name===''||$phone===''||$amount<=0)return ['ok'=>false,'reason'=>'PAYMENT_CONTACT_INCOMPLETE','queued'=>false];
        return hache_sharky_template_enqueue(
            $pdo,
            $phone,
            HACHE_SHARKY_TEMPLATE_PAYMENT_CONFIRMED,
            [$name,hache_sharky_payment_amount_text($amount),hache_sharky_payment_concept($row)],
            'payment-confirmed|folio:'.$folio
        );
    }catch(Throwable $e){
        error_log('[sharky-template] payment confirmation enqueue failed');
        return ['ok'=>false,'reason'=>'INTERNAL_ERROR','queued'=>false];
    }
}
