<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-orchestrator-db.php';
require_once __DIR__.'/sharky-mercadopago.php';

const HACHE_SHARKY_MEMBER_EVIDENCE_KIND = 'member_absence_evidence';
const HACHE_SHARKY_MEMBER_PAYMENT_PROOF_KIND = HACHE_SHARKY_MEMBER_EVIDENCE_KIND;
const HACHE_SHARKY_MEMBER_TIMEZONE = 'America/Cancun';

function hache_sharky_member_phone(string $contact): ?string
{
    $digits=preg_replace('/\D+/','',$contact)?:'';
    if(strlen($digits)===13&&str_starts_with($digits,'521'))$digits='52'.substr($digits,3);
    $e164='+'.$digits;
    return telefono_es_e164($e164)?$e164:null;
}

function hache_sharky_member_schema_ready(PDO $pdo): bool
{
    try{
        $required=['profesores','profesor_horarios','profesor_cancelaciones','sharky_ausencia_evidencias'];
        $marks=implode(',',array_fill(0,count($required),'?'));
        $st=$pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ($marks)");
        $st->execute($required);
        return count(array_unique(array_map('strval',$st->fetchAll(PDO::FETCH_COLUMN))))===count($required);
    }catch(Throwable $e){return false;}
}

function hache_sharky_member_coteaching_ready(PDO $pdo): bool
{
    try{
        $st=$pdo->prepare("SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') columns_in_order,MIN(non_unique) non_unique FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='profesor_cancelaciones' AND index_name='uq_profesor_cancelacion_profesor_sesion' GROUP BY index_name");
        $st->execute();$row=$st->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)||(string)($row['columns_in_order']??'')!=='profesor_id,sesion_id'||(int)($row['non_unique']??1)!==0)return false;
        $legacy=$pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='profesor_cancelaciones' AND index_name='uq_profesor_cancelacion_sesion'");
        $legacy->execute();
        return (int)$legacy->fetchColumn()===0;
    }catch(Throwable $e){return false;}
}

function hache_sharky_member_teacher_by_whatsapp(PDO $pdo,string $contact): array
{
    if(!hache_sharky_member_schema_ready($pdo))return ['found'=>false,'reason'=>'schema_unavailable'];
    $phone=hache_sharky_member_phone($contact);if($phone===null)return ['found'=>false,'reason'=>'invalid_phone'];
    $st=$pdo->prepare("SELECT id,nombre,whatsapp,correo,activo FROM profesores WHERE whatsapp=:w LIMIT 2");
    $st->execute([':w'=>$phone]);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
    if(!$rows)return ['found'=>false,'phone'=>$phone];
    if(count($rows)>1)return ['found'=>false,'conflict'=>true,'reason'=>'duplicate_phone','phone'=>$phone];
    $row=$rows[0];if((int)$row['activo']!==1)return ['found'=>false,'inactive'=>true,'reason'=>'inactive_teacher','phone'=>$phone];
    return ['found'=>true,'teacher_id'=>(string)$row['id'],'name'=>(string)$row['nombre'],'phone'=>$phone,'email'=>$row['correo']??null];
}

function hache_sharky_member_flow(array $state): ?array
{
    $value=$state['commercial_context']['_member_ops']??null;
    return is_array($value)?$value:null;
}

function hache_sharky_member_set_flow(array $state,?array $flow,int $now): array
{
    if(!is_array($state['commercial_context']??null))$state['commercial_context']=[];
    if($flow===null)unset($state['commercial_context']['_member_ops']);
    else{$flow['updated_at']=$now;$state['commercial_context']['_member_ops']=$flow;}
    $state['updated_at']=$now;
    return $state;
}

function hache_sharky_member_flow_expired(?array $flow,int $now): bool
{
    if(!is_array($flow))return false;
    return $now-(int)($flow['updated_at']??0)>HACHE_SHARKY_FLOW_TTL;
}

function hache_sharky_member_normalize(string $text): string
{
    return hache_sharky_orchestrator_normalize(preg_replace('/\s+/u',' ',trim($text))??trim($text));
}

function hache_sharky_member_intent(string $text,string $interactiveId=''): ?string
{
    $id=strtolower(trim($interactiveId));
    if($id==='member:class_today')return 'class_today';
    if($id==='member:payments'||$id==='member:pay'||str_starts_with($id,'member:pay:'))return 'payments';
    if($id==='member:absence')return 'absence';
    if($id==='member:repos')return 'repos';
    if($id==='member:teacher_agenda')return 'teacher_agenda';
    if($id==='member:teacher_cancel')return 'teacher_cancel';
    if(str_starts_with($id,'member:tc:'))return 'teacher_cancel_select';
    if(in_array($id,['member:tc_confirm','member:tc_abort','member:absence_no_evidence','member:absence_add_evidence','member:absence_confirm','member:absence_abort'],true))return $id;
    if(str_starts_with($id,'member:absence_date:'))return 'absence_date';

    $t=hache_sharky_member_normalize($text);if($t==='')return null;
    if(preg_match('/^(?:hola|buenos dias|buenas tardes|buenas noches|hey|que tal|holi)[!. ]*$/u',$t)===1)return 'greeting';
    if(preg_match('/\b(?:cancelar|cancela|cancelo|suspender|suspende)\b.{0,35}\b(?:mi clase|clase|turno|sesion)\b/u',$t)===1)return 'teacher_cancel';
    if(preg_match('/\b(?:hay|tengo|tenemos|habra|se dara|se mantiene|cancelaron|cancelada|cancelo|suspendieron)\b.{0,45}\b(?:clase|clases|sesion|turno)\b/u',$t)===1
        ||preg_match('/\b(?:clase|clases|sesion|turno)\b.{0,45}\b(?:hoy|cancelada|cancelaron|suspendida)\b/u',$t)===1)return 'class_today';
    if(preg_match('/\b(?:pago|pagos|pagar|debo|deuda|mensualidad|pendiente de pago|cuanto debo)\b/u',$t)===1)return 'payments';
    if(preg_match('/\b(?:reportar|registrar|avisar)\b.{0,35}\b(?:ausencia|falta)\b/u',$t)===1
        ||preg_match('/\b(?:voy a faltar|no voy a poder ir|no podre ir|no ire a clase|faltare)\b/u',$t)===1)return 'absence';
    if(preg_match('/\b(?:reposicion|reposiciones|reponer)\b/u',$t)===1)return 'repos';
    if(preg_match('/\b(?:mis clases|mi agenda|que clases tengo|turnos de hoy)\b/u',$t)===1)return 'teacher_agenda';
    return null;
}

function hache_sharky_member_buttons(string $to,string $body,array $buttons): array
{
    $items=[];
    foreach(array_slice($buttons,0,3) as $button){
        if(!is_array($button))continue;$id=mb_substr(trim((string)($button['id']??'')),0,200);$title=mb_substr(trim((string)($button['title']??'')),0,20);
        if($id!==''&&$title!=='')$items[]=['type'=>'reply','reply'=>['id'=>$id,'title'=>$title]];
    }
    if(!$items)return hache_sharky_whatsapp_text_payload($to,$body);
    return ['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$to,'type'=>'interactive','interactive'=>['type'=>'button','body'=>['text'=>mb_substr($body,0,1024)],'action'=>['buttons'=>$items]]];
}

function hache_sharky_member_list(string $to,string $body,string $buttonText,array $rows): array
{
    $out=[];
    foreach(array_slice($rows,0,10) as $row){
        if(!is_array($row))continue;$id=mb_substr(trim((string)($row['id']??'')),0,200);$title=mb_substr(trim((string)($row['title']??'')),0,24);$description=mb_substr(trim((string)($row['description']??'')),0,72);
        if($id===' '||$id===''||$title==='')continue;$item=['id'=>$id,'title'=>$title];if($description!=='')$item['description']=$description;$out[]=$item;
    }
    if(!$out)return hache_sharky_whatsapp_text_payload($to,$body);
    return ['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$to,'type'=>'interactive','interactive'=>['type'=>'list','body'=>['text'=>mb_substr($body,0,1024)],'action'=>['button'=>mb_substr($buttonText,0,20),'sections'=>[['title'=>'Opciones','rows'=>$out]]]]];
}

function hache_sharky_member_student_context(PDO $pdo,string $contact,?string $today=null): array
{
    $identity=hache_sharky_business_identity_by_whatsapp($pdo,$contact);
    if(($identity['found']??false)!==true)return ['found'=>false,'identity'=>$identity];
    $today=$today?: (new DateTimeImmutable('today',new DateTimeZone(HACHE_SHARKY_MEMBER_TIMEZONE)))->format('Y-m-d');
    $studentId=(string)$identity['student_id'];
    $st=$pdo->prepare("SELECT a.id,a.nombre,a.sede_id,a.horario_preferido_id,a.plan_actual_id,a.estado_administrativo,s.clave sede_clave,s.nombre sede_nombre,h.hora_inicio regular_inicio,h.hora_fin regular_fin,p.nombre plan_nombre,p.sesiones_semana,p.precio plan_precio FROM alumnos a JOIN sedes s ON s.id=a.sede_id LEFT JOIN horarios h ON h.id=a.horario_preferido_id LEFT JOIN planes p ON p.id=a.plan_actual_id WHERE a.id=:a LIMIT 1");
    $st->execute([':a'=>$studentId]);$student=$st->fetch(PDO::FETCH_ASSOC)?:[];

    $intensiveSql="SELECT cia.curso_intensivo_id course_id,cia.horario_id,cia.reposiciones_justificadas,cia.reposiciones_cancelacion,ci.fecha_inicio,ci.fecha_fin,ci.precio,ci.estado,h.hora_inicio,h.hora_fin FROM curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id JOIN horarios h ON h.id=cia.horario_id ";
    $st=$pdo->prepare($intensiveSql."WHERE cia.alumno_id=:a AND :hoy BETWEEN ci.fecha_inicio AND ci.fecha_fin AND ci.estado NOT IN ('CANCELADO','FINALIZADO','TERMINADO') ORDER BY ci.fecha_inicio DESC LIMIT 1");
    $st->execute([':a'=>$studentId,':hoy'=>$today]);$intensive=$st->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$intensive&&empty($student['plan_actual_id'])){
        $st=$pdo->prepare($intensiveSql."WHERE cia.alumno_id=:a AND ci.fecha_inicio>:hoy AND ci.estado NOT IN ('CANCELADO','FINALIZADO','TERMINADO') ORDER BY ci.fecha_inicio ASC LIMIT 1");
        $st->execute([':a'=>$studentId,':hoy'=>$today]);$intensive=$st->fetch(PDO::FETCH_ASSOC)?:null;
    }
    $program=$intensive?'intensive':'regular';$scheduleId=$intensive?(string)$intensive['horario_id']:(string)($student['horario_preferido_id']??'');

    $session=null;
    if($scheduleId!==''){$st=$pdo->prepare("SELECT id,fecha,estado,motivo_cancelacion,cerrada FROM sesiones WHERE horario_id=:h AND fecha=:f ORDER BY created_at DESC LIMIT 1");$st->execute([':h'=>$scheduleId,':f'=>$today]);$session=$st->fetch(PDO::FETCH_ASSOC)?:null;}

    $payment=null;
    if($program==='intensive'&&is_array($intensive)){
        $st=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN estado='VALIDO' THEN importe ELSE 0 END),0) paid FROM pagos WHERE alumno_id=:a AND intensivo_id=:c AND tipo='INTENSIVO'");$st->execute([':a'=>$studentId,':c'=>$intensive['course_id']]);$paid=(float)$st->fetchColumn();$price=(float)$intensive['precio'];$due=max(0,round($price-$paid,2));
        $payment=['kind'=>'intensive','course_id'=>(string)$intensive['course_id'],'price'=>$price,'paid'=>$paid,'due'=>$due,'pending'=>$due>0.009];
    }else{
        $st=$pdo->prepare("SELECT id,periodo_inicio,periodo_fin,importe_a_cobrar,COALESCE(importe_cobrado,0) importe_cobrado,estado FROM mensualidades WHERE alumno_id=:a AND sede_id=:s AND estado='PENDIENTE' ORDER BY (CURDATE() BETWEEN periodo_inicio AND periodo_fin) DESC,periodo_inicio ASC LIMIT 1");
        $st->execute([':a'=>$studentId,':s'=>$student['sede_id']??'']);$m=$st->fetch(PDO::FETCH_ASSOC)?:null;
        if($m)$payment=['kind'=>'monthly','monthly_id'=>(string)$m['id'],'period_from'=>(string)$m['periodo_inicio'],'period_to'=>(string)$m['periodo_fin'],'due'=>max(0,(float)$m['importe_a_cobrar']-(float)$m['importe_cobrado']),'pending'=>true];
        else $payment=['kind'=>'monthly','due'=>0.0,'pending'=>false];
    }

    $repos=0;
    if($program==='intensive'&&is_array($intensive))$repos=(int)$intensive['reposiciones_justificadas']+(int)$intensive['reposiciones_cancelacion'];
    else{try{$st=$pdo->prepare("SELECT COUNT(*) FROM reposiciones_regulares WHERE alumno_id=:a AND estado='DISPONIBLE'");$st->execute([':a'=>$studentId]);$repos=(int)$st->fetchColumn();}catch(Throwable $e){$repos=0;}}

    return ['found'=>true,'identity'=>$identity,'student'=>$student,'program'=>$program,'intensive'=>$intensive,'schedule_id'=>$scheduleId,'session_today'=>$session,'payment'=>$payment,'repos_available'=>$repos,'today'=>$today];
}

function hache_sharky_member_first_name(array $ctx): string
{
    $name=trim((string)($ctx['identity']['name']??''));
    return $name!==''?(preg_split('/\s+/u',$name)[0]??$name):'';
}

function hache_sharky_member_date_label(string $date): string
{
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d)return $date;
    $months=[1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
    return (int)$d->format('j').' de '.($months[(int)$d->format('n')]??$d->format('m'));
}

function hache_sharky_member_student_greeting(array $ctx): string
{
    $first=hache_sharky_member_first_name($ctx);if($first==='')$first='hola';
    $program=($ctx['program']??'regular')==='intensive'?'tu curso intensivo':'tus clases';
    return '¡Hola, '.$first.'! 👋 Qué gusto verte. Puedo ayudarte con '.$program.', pagos, ausencias y reposiciones.';
}

function hache_sharky_member_class_message(array $ctx): string
{
    $first=hache_sharky_member_first_name($ctx);$prefix=$first!==''?$first.', ':'';$session=$ctx['session_today']??null;
    $time='';if(is_array($ctx['intensive']??null))$time=substr((string)$ctx['intensive']['hora_inicio'],0,5);else $time=substr((string)($ctx['student']['regular_inicio']??''),0,5);
    if(($ctx['program']??'regular')==='intensive'&&is_array($ctx['intensive']??null)){
        $today=(string)($ctx['today']??'');$ci=$ctx['intensive'];
        if($today<(string)$ci['fecha_inicio'])return $prefix.'tu curso intensivo todavía no empieza. Comienza el '.hache_sharky_member_date_label((string)$ci['fecha_inicio']).($time!==''?' a las '.$time:'').'. 😊';
        if($today>(string)$ci['fecha_fin'])return $prefix.'tu curso intensivo ya terminó. Si quieres, puedo ayudarte a revisar cómo seguir nadando. 😊';
    }
    if(is_array($session)&&strtoupper((string)($session['estado']??''))==='CANCELADA'){
        $reason=trim((string)($session['motivo_cancelacion']??''));
        return $prefix.'hoy no tendremos la clase'.($time!==''?' de las '.$time:'').' 😕.'.($reason!==''?' Se canceló por '.$reason.'.':' Si necesitas ayuda con algo más, aquí estoy.');
    }
    return 'Sí'.($first!==''?', '.$first:'').' 😊 Tu clase de hoy'.($time!==''?' a las '.$time:'').' sigue programada. Si hubiera algún cambio, te aviso por aquí.';
}

function hache_sharky_member_payment_message(array $ctx): string
{
    $payment=$ctx['payment']??null;if(!is_array($payment)||($payment['pending']??false)!==true)return 'Todo bien 😊 No tienes pagos pendientes en este momento.';
    $due=(float)($payment['due']??0);$label=($payment['kind']??'')==='intensive'?'de tu curso intensivo':'de tu mensualidad';
    return 'Tienes pendiente $'.number_format($due,2,'.',',').' MXN '.$label.'. Si quieres, puedes pagarlo desde aquí.';
}

function hache_sharky_member_teacher_sessions(PDO $pdo,string $teacherId,string $from,string $to): array
{
    $st=$pdo->prepare("SELECT se.id session_id,se.fecha,se.estado,se.motivo_cancelacion,se.cerrada,h.id horario_id,h.hora_inicio,h.hora_fin,s.clave sede_clave,s.nombre sede_nombre,pc.id teacher_cancel_id,pc.motivo teacher_cancel_reason FROM profesor_horarios ph JOIN horarios h ON h.id=ph.horario_id JOIN sedes s ON s.id=h.sede_id JOIN sesiones se ON se.horario_id=h.id LEFT JOIN profesor_cancelaciones pc ON pc.profesor_id=ph.profesor_id AND pc.sesion_id=se.id WHERE ph.profesor_id=:p AND ph.activo=1 AND h.activo=1 AND se.fecha BETWEEN :d AND :h ORDER BY se.fecha,h.hora_inicio LIMIT 20");
    $st->execute([':p'=>$teacherId,':d'=>$from,':h'=>$to]);return $st->fetchAll(PDO::FETCH_ASSOC);
}

function hache_sharky_member_cancel_teacher_session(PDO $pdo,string $teacherId,string $sessionId,string $reason,string $actionKey): array
{
    if(!hache_sharky_member_coteaching_ready($pdo))throw new HacheSharkyBusinessException('La operación de profesores todavía no está lista.','TEACHER_COTEACHING_SCHEMA_UNAVAILABLE',503);
    $reason=preg_replace('/\s+/u',' ',trim($reason))??'';if($reason===''||mb_strlen($reason)>500)throw new HacheSharkyBusinessException('El motivo es obligatorio y no puede exceder 500 caracteres.','INVALID_REASON',422);
    $actor=hache_sharky_business_actor_id($pdo);
    $pdo->beginTransaction();
    try{
        $st=$pdo->prepare("SELECT se.id,se.fecha,se.horario_id,se.estado,se.cerrada,h.sede_id FROM sesiones se JOIN horarios h ON h.id=se.horario_id JOIN profesor_horarios ph ON ph.horario_id=h.id AND ph.profesor_id=:p AND ph.activo=1 JOIN profesores pr ON pr.id=ph.profesor_id AND pr.activo=1 WHERE se.id=:s LIMIT 1 FOR UPDATE");
        $st->execute([':p'=>$teacherId,':s'=>$sessionId]);$session=$st->fetch(PDO::FETCH_ASSOC);
        if(!$session)throw new HacheSharkyBusinessException('Esa clase no está asignada al profesor o ya no existe.','TEACHER_SESSION_SCOPE',403);
        $existing=$pdo->prepare("SELECT id FROM profesor_cancelaciones WHERE profesor_id=:p AND sesion_id=:s LIMIT 1 FOR UPDATE");$existing->execute([':p'=>$teacherId,':s'=>$sessionId]);
        if($existing->fetchColumn()){$pdo->rollBack();return ['ok'=>true,'duplicate'=>true,'class_cancelled'=>(string)$session['estado']==='CANCELADA','session_id'=>$sessionId,'remaining_teachers'=>0,'reposiciones_intensivo_generadas'=>0,'code'=>'TEACHER_ALREADY_UNAVAILABLE'];}
        if((string)$session['estado']==='CANCELADA'){$pdo->rollBack();return ['ok'=>true,'duplicate'=>true,'class_cancelled'=>true,'session_id'=>$sessionId,'remaining_teachers'=>0,'reposiciones_intensivo_generadas'=>0,'code'=>'ALREADY_CANCELLED'];}
        if((int)($session['cerrada']??0)===1)throw new HacheSharkyBusinessException('Esa clase ya fue cerrada y no puede modificarse desde Sharky.','SESSION_ALREADY_CLOSED',409);

        $id=(string)$pdo->query('SELECT UUID()')->fetchColumn();$st=$pdo->prepare("INSERT INTO profesor_cancelaciones(id,profesor_id,sesion_id,motivo,source,action_key) VALUES(:id,:p,:s,:m,'SHARKY',:a)");$st->execute([':id'=>$id,':p'=>$teacherId,':s'=>$sessionId,':m'=>$reason,':a'=>hash('sha256',$actionKey)]);

        $coverage=$pdo->prepare("SELECT COUNT(*) FROM profesor_horarios ph JOIN profesores pr ON pr.id=ph.profesor_id AND pr.activo=1 LEFT JOIN profesor_cancelaciones pc ON pc.profesor_id=ph.profesor_id AND pc.sesion_id=:s WHERE ph.horario_id=:h AND ph.activo=1 AND pc.id IS NULL");
        $coverage->execute([':s'=>$sessionId,':h'=>$session['horario_id']]);$remaining=(int)$coverage->fetchColumn();
        if($remaining>0){$pdo->commit();return ['ok'=>true,'duplicate'=>false,'class_cancelled'=>false,'session_id'=>$sessionId,'remaining_teachers'=>$remaining,'reposiciones_intensivo_generadas'=>0,'code'=>'TEACHER_UNAVAILABLE'];}

        $st=$pdo->prepare("UPDATE sesiones SET estado='CANCELADA',motivo_cancelacion=:m,cerrada=1,fecha_cierre=NOW(),cerrada_por=:u WHERE id=:s AND estado<>'CANCELADA' AND cerrada=0");
        $st->execute([':m'=>$reason,':u'=>$actor,':s'=>$sessionId]);if($st->rowCount()!==1)throw new HacheSharkyBusinessException('La clase cambió de estado antes de confirmar.','SESSION_CHANGED',409);
        $repos=0;
        if(!empty($session['horario_id'])){$r=$pdo->prepare("UPDATE curso_intensivo_alumnos cia JOIN cursos_intensivos ci ON ci.id=cia.curso_intensivo_id SET cia.reposiciones_cancelacion=cia.reposiciones_cancelacion+1 WHERE cia.horario_id=:h AND ci.sede_id=:site AND ci.estado IN ('PROGRAMADO','EN_CURSO') AND :f BETWEEN ci.fecha_inicio AND ci.fecha_fin");$r->execute([':h'=>$session['horario_id'],':site'=>$session['sede_id'],':f'=>$session['fecha']]);$repos=$r->rowCount();}
        $pdo->commit();return ['ok'=>true,'duplicate'=>false,'class_cancelled'=>true,'session_id'=>$sessionId,'remaining_teachers'=>0,'reposiciones_intensivo_generadas'=>$repos,'code'=>'SESSION_CANCELLED'];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function hache_sharky_member_execute_teacher_cancel(PDO $pdo,string $contact,array $action,string $idempotencyKey): array
{
    $teacherId=trim((string)($action['teacher_id']??''));$sessionId=trim((string)($action['session_id']??''));
    if(($action['requires_revalidation']??false)!==true)return ['ok'=>false,'code'=>'REVALIDATION_REQUIRED','message'=>'El cambio necesita revalidación.'];
    $contactHash=hache_sharky_orchestrator_contact_hash($contact);$existing=hache_sharky_action_recovery_status($pdo,$idempotencyKey);
    if($existing&&(string)$existing['status']==='COMPLETED')return ['ok'=>true,'duplicate'=>true,'code'=>(string)($existing['result_code']??'ALREADY_COMPLETED'),'message'=>(string)($existing['result_message']??'Ese cambio ya había sido procesado.'),'result'=>$existing['result']??null];
    $owner=null;if(!hache_sharky_action_recovery_claim($pdo,$idempotencyKey,'cancel_teacher_session',$contactHash,null,$action,$owner)||!is_string($owner)||$owner==='')return ['ok'=>false,'retryable'=>true,'code'=>'ACTION_CLAIM_FAILED','message'=>'No pude asegurar el cambio.'];
    try{
        $identity=hache_sharky_member_teacher_by_whatsapp($pdo,$contact);if(($identity['found']??false)!==true||(string)$identity['teacher_id']!==$teacherId)throw new HacheSharkyBusinessException('No pude revalidar la identidad del profesor.','TEACHER_IDENTITY_MISMATCH',403);
        $result=hache_sharky_member_cancel_teacher_session($pdo,$teacherId,$sessionId,(string)($action['reason']??''),$idempotencyKey);$code=(string)($result['code']??'TEACHER_UNAVAILABLE');$remaining=(int)($result['remaining_teachers']??0);
        if($code==='TEACHER_UNAVAILABLE')$message='Listo. Registré que no podrás asistir. La clase sigue programada porque '.($remaining===1?'queda otro profesor asignado.':'quedan '.$remaining.' profesores asignados.');
        elseif($code==='SESSION_CANCELLED')$message='Listo. Registré que no podrás asistir y, como ya no queda otro profesor disponible, la clase quedó cancelada. El cambio ya está visible para los alumnos.';
        elseif($code==='TEACHER_ALREADY_UNAVAILABLE')$message='Ya estaba registrado que no asistirás a esa clase.';
        elseif($code==='ALREADY_CANCELLED')$message='Esa clase ya estaba cancelada.';
        else $message='Listo. El cambio quedó registrado.';
        if(!hache_sharky_action_recovery_finish($pdo,$idempotencyKey,true,$code,$result,$message,$owner))return hache_sharky_action_audit_pending_result();
        return ['ok'=>true,'code'=>$code,'message'=>$message,'result'=>$result];
    }catch(HacheSharkyBusinessException $e){hache_sharky_action_recovery_finish($pdo,$idempotencyKey,false,$e->codeName,null,$e->getMessage(),$owner);return ['ok'=>false,'code'=>$e->codeName,'message'=>$e->getMessage()];}
    catch(Throwable $e){hache_sharky_action_recovery_finish($pdo,$idempotencyKey,false,'INTERNAL',null,'No se pudo registrar el cambio.',$owner);return ['ok'=>false,'code'=>'INTERNAL','message'=>'No se pudo registrar el cambio.'];}
}

function hache_sharky_member_parse_date(string $text,string $today): ?string
{
    $t=hache_sharky_member_normalize($text);$base=DateTimeImmutable::createFromFormat('!Y-m-d',$today);if(!$base)return null;
    if(in_array($t,['hoy','la de hoy'],true))return $today;
    if(in_array($t,['manana','mañana','la de manana'],true))return $base->modify('+1 day')->format('Y-m-d');
    if(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$t,$m)===1){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$t);return $d&&$d->format('Y-m-d')===$t?$t:null;}
    if(preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?$/',$t,$m)===1){$year=isset($m[3])&&$m[3]!==''?(int)$m[3]:(int)$base->format('Y');$value=sprintf('%04d-%02d-%02d',$year,(int)$m[2],(int)$m[1]);$d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);return $d&&$d->format('Y-m-d')===$value?$value:null;}
    return null;
}

function hache_sharky_member_extract_media_events(PDO $pdo,array $payload): array
{
    $out=[];
    foreach(($payload['entry']??[]) as $entry)foreach(($entry['changes']??[]) as $change){
        $value=$change['value']??null;if(!is_array($value))continue;$phoneId=trim((string)($value['metadata']['phone_number_id']??''));
        foreach(($value['messages']??[]) as $m){
            if(!is_array($m))continue;$type=(string)($m['type']??'');if(!in_array($type,['image','document'],true))continue;
            $from=preg_replace('/\D+/','',(string)($m['from']??''))?:'';$id=trim((string)($m['id']??''));if($from===''||$id==='')continue;
            try{$state=hache_sharky_db_state_load($pdo,$from);}catch(Throwable $e){continue;}$flow=hache_sharky_member_flow($state);if(!is_array($flow))continue;
            $kind=null;$memberPayment=null;
            if(($flow['name']??'')==='absence'&&($flow['step']??'')==='evidence')$kind=HACHE_SHARKY_MEMBER_EVIDENCE_KIND;
            elseif(($flow['name']??'')==='member_payment_transfer'&&($flow['step']??'')==='evidence'){
                $kind=HACHE_SHARKY_MEMBER_PAYMENT_PROOF_KIND;
                $memberPayment=['student_id'=>(string)($flow['student_id']??''),'kind'=>(string)($flow['kind']??''),'resource_id'=>(string)($flow['resource_id']??''),'amount'=>(float)($flow['amount']??0)];
            }
            if($kind===null)continue;
            $media=is_array($m[$type]??null)?$m[$type]:[];$mediaId=trim((string)($media['id']??''));if($mediaId==='')continue;
            $event=['id'=>$id,'from'=>$from,'type'=>$type,'kind'=>$kind,'text'=>'','interactive_id'=>'','phone_number_id'=>$phoneId,'timestamp_ms'=>((int)($m['timestamp']??time()))*1000,'media_id'=>$mediaId,'filename'=>$type==='document'?mb_substr(trim((string)($media['filename']??'')),0,255):null];
            if(is_array($memberPayment))$event['member_payment']=$memberPayment;
            $out[]=$event;
        }
    }
    return $out;
}

function hache_sharky_member_store_evidence(PDO $pdo,string $absenceId,string $studentId,array $media): void
{
    if(!hache_sharky_member_schema_ready($pdo))return;$type=(string)($media['type']??'');if(!in_array($type,['image','document'],true))return;
    $st=$pdo->prepare("INSERT IGNORE INTO sharky_ausencia_evidencias(id,ausencia_id,alumno_id,source_message_id,media_id,media_type,filename) VALUES(UUID(),:aus,:a,:m,:media,:t,:f)");$st->execute([':aus'=>$absenceId,':a'=>$studentId,':m'=>(string)($media['id']??''),':media'=>(string)($media['media_id']??''),':t'=>$type,':f'=>$media['filename']??null]);
}

function hache_sharky_member_queue(PDO $pdo,string $contact,array $event,array $state,array $payload,string $suffix): bool
{
    $eventId=(string)($event['id']??'member');$state['last_user_text']=mb_substr(trim((string)($event['text']??'')),0,700);hache_sharky_db_state_save($pdo,$contact,$state,86400);$deferred=hache_sharky_db_state_defer_take();
    return hache_sharky_lab_queue_and_complete($pdo,$contact,$payload,$eventId.'|member|'.$suffix,$eventId,[],$deferred);
}

function hache_sharky_member_process_event(PDO $pdo,array $event,array $business=[]): ?bool
{
    $groupId=trim((string)($event['group_id']??''));if($groupId!=='')return null;
    $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';if($contact==='')return null;
    $teacher=hache_sharky_member_teacher_by_whatsapp($pdo,$contact);$student=hache_sharky_member_student_context($pdo,$contact);
    $flow=null;try{$state=hache_sharky_db_state_load($pdo,$contact);$flow=hache_sharky_member_flow($state);}catch(Throwable $e){return null;}
    $now=time();if(hache_sharky_member_flow_expired($flow,$now)){$state=hache_sharky_member_set_flow($state,null,$now);$flow=null;}
    $id=strtolower(trim((string)($event['interactive_id']??'')));$text=trim((string)($event['text']??''));$intent=hache_sharky_member_intent($text,$id);
    $isEvidence=(string)($event['kind']??'')===HACHE_SHARKY_MEMBER_EVIDENCE_KIND;
    $teacherFlow=is_array($flow)&&str_starts_with((string)($flow['name']??''),'teacher_');$studentFlow=is_array($flow)&&($flow['name']??'')==='absence';
    if(($teacher['found']??false)!==true&&($student['found']??false)!==true&&!$teacherFlow&&!$studentFlow)return null;
    $teacherOwned=($teacher['found']??false)===true&&($teacherFlow||in_array($intent,['teacher_agenda','teacher_cancel','teacher_cancel_select','greeting','member:tc_confirm','member:tc_abort'],true));
    if($teacherOwned&&!hache_sharky_member_coteaching_ready($pdo))return null;

    $deliveryLock=hache_sharky_orchestrator_delivery_lock($contact);if(!is_resource($deliveryLock))return false;
    hache_sharky_db_state_defer_begin();
    try{
        if(!hache_sharky_lab_claim_early($pdo,$event,$contact,(string)($event['type']??'message'))){hache_sharky_db_state_defer_cancel();return false;}
        $today=(new DateTimeImmutable('today',new DateTimeZone(HACHE_SHARKY_MEMBER_TIMEZONE)))->format('Y-m-d');

        if(($teacher['found']??false)===true&&($teacherFlow||in_array($intent,['teacher_agenda','teacher_cancel','teacher_cancel_select','greeting','member:tc_confirm','member:tc_abort'],true))){
            $teacherId=(string)$teacher['teacher_id'];$name=(string)$teacher['name'];
            if($id==='member:tc_abort'){$state=hache_sharky_member_set_flow($state,null,$now);return hache_sharky_member_queue($pdo,$contact,$event,$state,hache_sharky_whatsapp_text_payload($contact,'Listo, no hice ningún cambio.'),'teacher-abort');}
            if(is_array($flow)&&($flow['name']??'')==='teacher_cancel'&&($flow['step']??'')==='reason'&&$id===''&&$text!==''){$reason=preg_replace('/\s+/u',' ',trim($text))??'';if($reason===''||mb_strlen($reason)>500){$payload=hache_sharky_whatsapp_text_payload($contact,'Necesito un motivo breve de hasta 500 caracteres.');return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'teacher-reason-invalid');}$flow['reason']=$reason;$flow['step']='confirm';$state=hache_sharky_member_set_flow($state,$flow,$now);$payload=hache_sharky_member_buttons($contact,'Voy a registrar que no podrás asistir a esa clase. Si queda otro profesor asignado, la clase seguirá programada; si no queda ninguno, se cancelará y avisaremos a los alumnos. ¿Confirmas?',[['id'=>'member:tc_confirm','title'=>'Sí, confirmar'],['id'=>'member:tc_abort','title'=>'No']]);return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'teacher-confirm');}
            if($id==='member:tc_confirm'&&is_array($flow)&&($flow['name']??'')==='teacher_cancel'&&($flow['step']??'')==='confirm'){$action=['type'=>'cancel_teacher_session','teacher_id'=>$teacherId,'session_id'=>(string)($flow['session_id']??''),'reason'=>(string)($flow['reason']??''),'requires_revalidation'=>true];$result=hache_sharky_member_execute_teacher_cancel($pdo,$contact,$action,(string)$event['id'].'|'.(string)($flow['session_id']??''));$state=hache_sharky_member_set_flow($state,null,$now);$payload=hache_sharky_whatsapp_text_payload($contact,(string)($result['message']??'No pude completar la cancelación.'));return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'teacher-cancel-execute');}
            if(str_starts_with($id,'member:tc:')){$sessionId=substr($id,10);$sessions=hache_sharky_member_teacher_sessions($pdo,$teacherId,$today,(new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d'));$match=null;foreach($sessions as $s)if((string)$s['session_id']===$sessionId&&(int)($s['cerrada']??0)===0&&empty($s['teacher_cancel_id'])){$match=$s;break;}if(!$match){$payload=hache_sharky_whatsapp_text_payload($contact,'Esa clase ya no está disponible entre tus turnos activos.');return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'teacher-scope-miss');}$state=hache_sharky_member_set_flow($state,['name'=>'teacher_cancel','step'=>'reason','teacher_id'=>$teacherId,'session_id'=>$sessionId],$now);$payload=hache_sharky_whatsapp_text_payload($contact,'Cuéntame brevemente por qué no podrás asistir.');return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'teacher-reason');}
            $to=(new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');$sessions=hache_sharky_member_teacher_sessions($pdo,$teacherId,$today,$to);
            if($intent==='teacher_cancel'){$rows=[];foreach($sessions as $s){if((string)$s['estado']==='CANCELADA'||(int)($s['cerrada']??0)===1||!empty($s['teacher_cancel_id']))continue;$rows[]=['id'=>'member:tc:'.(string)$s['session_id'],'title'=>date('d/m',strtotime((string)$s['fecha'])).' '.substr((string)$s['hora_inicio'],0,5),'description'=>(string)$s['sede_nombre'].' · '.substr((string)$s['hora_inicio'],0,5).'–'.substr((string)$s['hora_fin'],0,5)];}$payload=$rows?hache_sharky_member_list($contact,'¿A cuál clase no podrás asistir? Solo te muestro tus turnos asignados.','Elegir clase',$rows):hache_sharky_whatsapp_text_payload($contact,'No encuentro clases pendientes de confirmar contigo en los próximos 7 días.');return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'teacher-cancel-list');}
            $todayRows=array_values(array_filter($sessions,static fn(array $s):bool=>(string)$s['fecha']===$today));$lines=[];foreach($todayRows as $s){$status=(string)$s['estado']==='CANCELADA'?'CANCELADA':(!empty($s['teacher_cancel_id'])?'NO ASISTIRÁS':((int)($s['cerrada']??0)===1?'cerrada':'programada'));$lines[]=substr((string)$s['hora_inicio'],0,5).' · '.(string)$s['sede_nombre'].' · '.$status;}$body='¡Hola, '.(preg_split('/\s+/u',$name)[0]??$name).'! 👋'.($lines?"\n\nHoy tienes:\n• ".implode("\n• ",$lines):' Hoy no encuentro sesiones asignadas a ti.');$payload=hache_sharky_member_buttons($contact,$body,[['id'=>'member:teacher_agenda','title'=>'Mi agenda'],['id'=>'member:teacher_cancel','title'=>'No podré asistir']]);return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'teacher-home');
        }

        if(($student['found']??false)===true){$studentId=(string)$student['identity']['student_id'];
            if($id==='member:absence_abort'){$state=hache_sharky_member_set_flow($state,null,$now);return hache_sharky_member_queue($pdo,$contact,$event,$state,hache_sharky_whatsapp_text_payload($contact,'Listo, no registré la ausencia.'),'absence-abort');}
            if($isEvidence&&is_array($flow)&&($flow['name']??'')==='absence'&&($flow['step']??'')==='evidence'){
                $date=(string)($flow['date']??'');if($date===''||hache_sharky_member_parse_date($date,$today)!==$date){$state=hache_sharky_member_set_flow($state,null,$now);return hache_sharky_member_queue($pdo,$contact,$event,$state,hache_sharky_whatsapp_text_payload($contact,'Perdí la fecha de esa ausencia. Prefiero no registrar nada mal: vuelve a tocar “Reportar ausencia” y lo hacemos de nuevo.'),'absence-date-lost');}
                $flow['evidence']=['id'=>(string)$event['id'],'media_id'=>(string)($event['media_id']??''),'type'=>(string)$event['type'],'filename'=>$event['filename']??null];$flow['step']='confirm';$state=hache_sharky_member_set_flow($state,$flow,$now);$payload=hache_sharky_member_buttons($contact,'Gracias, ya recibí el justificante 😊 ¿Confirmo tu ausencia para el '.date('d/m/Y',strtotime($date)).'?',[['id'=>'member:absence_confirm','title'=>'Confirmar'],['id'=>'member:absence_abort','title'=>'Cancelar']]);return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'absence-evidence');
            }
            if($id==='member:absence_add_evidence'&&is_array($flow)&&($flow['name']??'')==='absence'){$flow['step']='evidence';$state=hache_sharky_member_set_flow($state,$flow,$now);$payload=hache_sharky_whatsapp_text_payload($contact,'Envíame aquí una foto o documento del justificante. Quedará vinculado a esta ausencia. 😊');return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'absence-await-evidence');}
            if($id==='member:absence_no_evidence'&&is_array($flow)&&($flow['name']??'')==='absence'){$flow['step']='confirm';$state=hache_sharky_member_set_flow($state,$flow,$now);$payload=hache_sharky_member_buttons($contact,'¿Confirmo tu ausencia para el '.date('d/m/Y',strtotime((string)$flow['date'])).'?',[['id'=>'member:absence_confirm','title'=>'Confirmar'],['id'=>'member:absence_abort','title'=>'Cancelar']]);return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'absence-no-evidence');}
            if($id==='member:absence_confirm'&&is_array($flow)&&($flow['name']??'')==='absence'&&($flow['step']??'')==='confirm'){
                $date=(string)($flow['date']??'');$action=['type'=>'create_absence','student_id'=>$studentId,'date_from'=>$date,'date_to'=>$date,'reason'=>(string)$flow['reason'],'requires_revalidation'=>true];$result=hache_sharky_execute_action($pdo,$contact,$action,(string)$event['id'].'|absence|'.$studentId,['today'=>$today]);$absenceId=trim((string)($result['result']['absence_id']??''));if(($result['ok']??false)===true&&$absenceId!==''&&is_array($flow['evidence']??null))hache_sharky_member_store_evidence($pdo,$absenceId,$studentId,$flow['evidence']);$state=hache_sharky_member_set_flow($state,null,$now);$first=hache_sharky_member_first_name($student);$message=($result['ok']??false)===true?'Listo'.($first!==''?', '.$first:'').' 😊 Ya quedó registrada tu ausencia para '.hache_sharky_member_date_label($date).'.':(string)($result['message']??'No pude registrar la ausencia.');$payload=hache_sharky_whatsapp_text_payload($contact,$message);return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'absence-execute');
            }
            if(is_array($flow)&&($flow['name']??'')==='absence'&&($flow['step']??'')==='reason'&&$id===''&&$text!==''){$reason=preg_replace('/\s+/u',' ',trim($text))??'';if($reason===''||mb_strlen($reason)>500){$payload=hache_sharky_whatsapp_text_payload($contact,'Cuéntame el motivo en una frase breve, por favor.');return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'absence-reason-invalid');}$flow['reason']=$reason;$flow['step']='evidence_choice';$state=hache_sharky_member_set_flow($state,$flow,$now);$payload=hache_sharky_member_buttons($contact,'Gracias. Si quieres, puedes adjuntar algún justificante; es opcional.',[['id'=>'member:absence_add_evidence','title'=>'Adjuntar evidencia'],['id'=>'member:absence_no_evidence','title'=>'Sin evidencia']]);return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'absence-evidence-choice');}
            if(is_array($flow)&&($flow['name']??'')==='absence'&&($flow['step']??'')==='date'){$selected=null;if(str_starts_with($id,'member:absence_date:')){$token=substr($id,20);if($token==='today')$selected=$today;elseif($token==='tomorrow')$selected=(new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');}if($selected===null)$selected=hache_sharky_member_parse_date($text,$today);if($selected===null||$selected<$today){$payload=hache_sharky_whatsapp_text_payload($contact,'No pude ubicar esa fecha. Puedes escribirla como DD/MM o YYYY-MM-DD.');return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'absence-date-invalid');}$flow['date']=$selected;$flow['step']='reason';$state=hache_sharky_member_set_flow($state,$flow,$now);$payload=hache_sharky_whatsapp_text_payload($contact,'Perfecto 😊 ¿Cuál es el motivo?');return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'absence-reason-prompt');}
            if($intent==='absence'){$state=hache_sharky_member_set_flow($state,['name'=>'absence','step'=>'date','student_id'=>$studentId],$now);$payload=hache_sharky_member_buttons($contact,'Claro 😊 ¿Para qué fecha quieres avisar tu ausencia?',[['id'=>'member:absence_date:today','title'=>'Hoy'],['id'=>'member:absence_date:tomorrow','title'=>'Mañana']]);return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'absence-start');}
            if($intent==='class_today'){$payload=hache_sharky_whatsapp_text_payload($contact,hache_sharky_member_class_message($student));return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'class-today');}
            if($intent==='repos'){$count=(int)($student['repos_available']??0);$payload=hache_sharky_whatsapp_text_payload($contact,$count>0?'Tienes '.$count.' reposición'.($count===1?'':'es').' disponible'.($count===1?'':'s').' 😊':'Por ahora no tienes reposiciones disponibles.');return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'repos');}
            if($intent==='payments'){$body=hache_sharky_member_payment_message($student);$payment=$student['payment']??null;$payload=is_array($payment)&&($payment['pending']??false)===true?hache_sharky_member_buttons($contact,$body,[['id'=>'member:pay','title'=>'Pagar ahora']]):hache_sharky_whatsapp_text_payload($contact,$body);return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'payments');}
            if($intent==='greeting'){$payload=hache_sharky_member_buttons($contact,hache_sharky_member_student_greeting($student),[['id'=>'member:class_today','title'=>'Mi clase hoy'],['id'=>'member:payments','title'=>'Pagos'],['id'=>'member:absence','title'=>'Reportar ausencia']]);return hache_sharky_member_queue($pdo,$contact,$event,$state,$payload,'student-home');}
        }
        hache_sharky_db_state_defer_cancel();return null;
    }catch(Throwable $e){hache_sharky_db_state_defer_cancel();error_log('[sharky-member-ops] '.$e->getMessage());return false;}
    finally{hache_sharky_lab_release_delivery_lock($deliveryLock);}
}
