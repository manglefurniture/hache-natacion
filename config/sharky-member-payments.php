<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-member-ops.php';

function hache_sharky_member_payments_schema_ready(PDO $pdo): bool
{
    try{$st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sharky_member_payment_intents'");$st->execute();return (int)$st->fetchColumn()===1;}catch(Throwable $e){return false;}
}

function hache_sharky_member_payment_external(string $studentId,string $kind,string $resourceId): string
{
    $prefix=strtoupper($kind)==='MENSUALIDAD'?'m':'i';
    return 'sharky:member:'.$prefix.':'.substr(hash('sha256',$studentId.'|'.$kind.'|'.$resourceId),0,32);
}

function hache_sharky_member_payment_partial_intensive(array $payment): bool
{
    return ($payment['kind']??'')==='intensive'
        && ($payment['pending']??false)===true
        && (float)($payment['paid']??0)>0.009
        && (float)($payment['due']??0)>0.009;
}

function hache_sharky_member_payment_partial_message(array $payment): string
{
    $paid=number_format((float)($payment['paid']??0),2,'.',',');
    $due=number_format((float)($payment['due']??0),2,'.',',');
    return 'Tu curso ya tiene un pago parcial registrado de $'.$paid.' MXN y quedan $'.$due.' MXN pendientes. Para no crear un segundo pago incompatible con el registro del intensivo, Sharky no abrirá otro cobro automático. El equipo puede ayudarte a completar ese saldo por este mismo chat.';
}

function hache_sharky_member_payment_pending_from_context(array $ctx): ?array
{
    $payment=$ctx['payment']??null;if(!is_array($payment)||($payment['pending']??false)!==true||(float)($payment['due']??0)<=0)return null;
    // Intensivos mantienen el invariante histórico de un único pago válido por
    // alumno/curso. Si ya existe un abono parcial, no se crea otro checkout que
    // después no pueda reconciliarse contra `pagos`.
    if(hache_sharky_member_payment_partial_intensive($payment))return null;
    $studentId=trim((string)($ctx['identity']['student_id']??''));if($studentId==='')return null;
    if(($payment['kind']??'')==='monthly'){
        $resource=trim((string)($payment['monthly_id']??''));if($resource==='')return null;
        return ['student_id'=>$studentId,'kind'=>'MENSUALIDAD','resource_id'=>$resource,'monthly_id'=>$resource,'intensive_id'=>null,'base'=>(float)$payment['due']];
    }
    $resource=trim((string)($payment['course_id']??''));if($resource==='')return null;
    return ['student_id'=>$studentId,'kind'=>'INTENSIVO','resource_id'=>$resource,'monthly_id'=>null,'intensive_id'=>$resource,'base'=>(float)$payment['due']];
}

function hache_sharky_member_payment_create_preference(array $pending,array $business): array
{
    $studentId=(string)$pending['student_id'];$kind=(string)$pending['kind'];$resourceId=(string)$pending['resource_id'];$base=(float)$pending['base'];
    if($studentId===''||$resourceId===''||$base<=0)return ['ok'=>false,'reason'=>'INVALID_PAYMENT'];
    $credential=hache_sharky_mp_credentials();if(!is_array($credential)||($credential['active']??false)!==true)return ['ok'=>false,'reason'=>'MP_UNAVAILABLE'];$token=trim((string)($credential['access_token']??''));if($token==='')return ['ok'=>false,'reason'=>'MP_UNAVAILABLE'];
    $pct=is_numeric($business['sharky_recargo_tarjeta_pct']??null)?(float)$business['sharky_recargo_tarjeta_pct']:5.0;$pct=max(0.0,min(30.0,$pct));$charged=hache_sharky_mp_card_total($base,$pct);$external=hache_sharky_member_payment_external($studentId,$kind,$resourceId);
    $title=$kind==='MENSUALIDAD'?'Mensualidad Hache Natación':'Curso intensivo Hache Natación';
    $metadata=['source'=>'sharky_member','student_id'=>$studentId,'payment_kind'=>strtolower($kind)];if($kind==='MENSUALIDAD')$metadata['monthly_id']=$resourceId;else $metadata['course_id']=$resourceId;
    $response=hache_sharky_mp_request('POST','/checkout/preferences',$token,['items'=>[['id'=>$kind==='MENSUALIDAD'?'sharky-mensualidad':'sharky-intensivo-member','title'=>$title,'description'=>'Pago iniciado por alumno registrado con Sharky','quantity'=>1,'currency_id'=>'MXN','unit_price'=>$charged]],'external_reference'=>$external,'metadata'=>$metadata,'statement_descriptor'=>'HACHE NATACION']);
    if(!is_array($response))return ['ok'=>false,'reason'=>'MP_CREATE_FAILED'];$environment=(string)($credential['environment']??'PRODUCTION');$url=trim((string)($environment==='TEST'?($response['sandbox_init_point']??''):($response['init_point']??'')));if($url==='')$url=trim((string)($response['init_point']??$response['sandbox_init_point']??''));$preferenceId=trim((string)($response['id']??''));if($url===''||$preferenceId==='')return ['ok'=>false,'reason'=>'MP_INVALID_RESPONSE'];
    return ['ok'=>true,'url'=>$url,'preference_id'=>$preferenceId,'external_reference'=>$external,'base'=>$base,'charged'=>$charged,'surcharge_pct'=>$pct];
}

function hache_sharky_member_payment_store_intent(PDO $pdo,array $pending,array $checkout): bool
{
    if(!hache_sharky_member_payments_schema_ready($pdo))return false;
    try{$st=$pdo->prepare("INSERT INTO sharky_member_payment_intents(id,external_reference,alumno_id,payment_kind,mensualidad_id,intensivo_id,base_amount,charged_amount,preference_id,status) VALUES(UUID(),:e,:a,:k,:m,:i,:b,:c,:p,'PENDING') ON DUPLICATE KEY UPDATE base_amount=VALUES(base_amount),charged_amount=VALUES(charged_amount),preference_id=VALUES(preference_id),status=IF(status='APPROVED','APPROVED','PENDING'),updated_at=NOW()");$st->execute([':e'=>$checkout['external_reference'],':a'=>$pending['student_id'],':k'=>$pending['kind'],':m'=>$pending['monthly_id'],':i'=>$pending['intensive_id'],':b'=>number_format((float)$checkout['base'],2,'.',''),':c'=>number_format((float)$checkout['charged'],2,'.',''),':p'=>$checkout['preference_id']]);return true;}catch(Throwable $e){error_log('[sharky-member-payment] intent store failed');return false;}
}

function hache_sharky_member_payment_reconcile_intent(PDO $pdo,array $intent): array
{
    $external=trim((string)($intent['external_reference']??''));if($external==='')return ['state'=>'invalid'];$status=hache_sharky_mp_status_by_external_reference($external);$state=(string)($status['state']??'unavailable');
    if($state!=='approved'){
        if(in_array($state,['failed'],true)){try{$pdo->prepare("UPDATE sharky_member_payment_intents SET status='FAILED' WHERE external_reference=:e AND status<>'APPROVED'")->execute([':e'=>$external]);}catch(Throwable $e){}}
        return ['state'=>$state];
    }
    $pdo->beginTransaction();
    try{
        $st=$pdo->prepare("SELECT * FROM sharky_member_payment_intents WHERE external_reference=:e LIMIT 1 FOR UPDATE");$st->execute([':e'=>$external]);$row=$st->fetch(PDO::FETCH_ASSOC);if(!$row){$pdo->rollBack();return ['state'=>'missing'];}if((string)$row['status']==='APPROVED'&&!empty($row['reconciled_at'])){$pdo->commit();return ['state'=>'approved','duplicate'=>true];}
        $studentId=(string)$row['alumno_id'];$base=(float)$row['base_amount'];$kind=(string)$row['payment_kind'];$marker='Sharky MP '.$external;
        $st=$pdo->prepare("SELECT id FROM pagos WHERE alumno_id=:a AND estado='VALIDO' AND observacion=:o LIMIT 1");$st->execute([':a'=>$studentId,':o'=>$marker]);$existing=$st->fetchColumn();
        if(!$existing){
            // A checkout is offered only when an intensive has no valid payment,
            // but revalidate inside the reconciliation transaction as a final
            // guard against an administrative payment racing with MP approval.
            if($kind==='INTENSIVO'&&!empty($row['intensivo_id'])){
                $guard=$pdo->prepare("SELECT id FROM pagos WHERE alumno_id=:a AND intensivo_id=:i AND tipo='INTENSIVO' AND estado='VALIDO' LIMIT 1 FOR UPDATE");
                $guard->execute([':a'=>$studentId,':i'=>$row['intensivo_id']]);
                if($guard->fetchColumn())throw new RuntimeException('Intensive already has a valid payment; approved MP intent requires manual reconciliation');
            }
            $actor=hache_sharky_business_actor_id($pdo);$paymentId=(string)$pdo->query('SELECT UUID()')->fetchColumn();$st=$pdo->prepare("INSERT INTO pagos(id,alumno_id,mensualidad_id,intensivo_id,tipo,importe,metodo,fecha,estado,observacion,created_by) VALUES(:id,:a,:m,:i,:t,:imp,'MERCADO_PAGO',NOW(),'VALIDO',:o,:u)");$st->execute([':id'=>$paymentId,':a'=>$studentId,':m'=>$row['mensualidad_id'],':i'=>$row['intensivo_id'],':t'=>$kind,':imp'=>number_format($base,2,'.',''),':o'=>$marker,':u'=>$actor]);
            if($kind==='MENSUALIDAD'&&!empty($row['mensualidad_id'])){$m=$pdo->prepare("SELECT importe_a_cobrar,COALESCE(importe_cobrado,0) importe_cobrado FROM mensualidades WHERE id=:id AND alumno_id=:a LIMIT 1 FOR UPDATE");$m->execute([':id'=>$row['mensualidad_id'],':a'=>$studentId]);$monthly=$m->fetch(PDO::FETCH_ASSOC);if(!$monthly)throw new RuntimeException('Monthly payment target missing');$newPaid=min((float)$monthly['importe_a_cobrar'],(float)$monthly['importe_cobrado']+$base);$newState=$newPaid+0.009>=(float)$monthly['importe_a_cobrar']?'PAGADA':'PENDIENTE';$u=$pdo->prepare("UPDATE mensualidades SET importe_cobrado=:p,estado=:e,fecha_pago=IF(:e2='PAGADA',NOW(),fecha_pago) WHERE id=:id");$u->execute([':p'=>number_format($newPaid,2,'.',''),':e'=>$newState,':e2'=>$newState,':id'=>$row['mensualidad_id']]);}
        }
        $pdo->prepare("UPDATE sharky_member_payment_intents SET status='APPROVED',reconciled_at=COALESCE(reconciled_at,NOW()) WHERE id=:id")->execute([':id'=>$row['id']]);$pdo->commit();return ['state'=>'approved','duplicate'=>(bool)$existing];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[sharky-member-payment] reconciliation failed');return ['state'=>'error'];}
}

function hache_sharky_member_payment_reconcile_student(PDO $pdo,string $studentId): int
{
    if(!hache_sharky_member_payments_schema_ready($pdo)||$studentId==='')return 0;
    try{$st=$pdo->prepare("SELECT * FROM sharky_member_payment_intents WHERE alumno_id=:a AND status='PENDING' AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) ORDER BY created_at DESC LIMIT 5");$st->execute([':a'=>$studentId]);$count=0;foreach($st->fetchAll(PDO::FETCH_ASSOC) as $intent){$result=hache_sharky_member_payment_reconcile_intent($pdo,$intent);if(($result['state']??'')==='approved')$count++;}return $count;}catch(Throwable $e){return 0;}
}

function hache_sharky_member_payment_queue_owned(PDO $pdo,string $contact,array $event,string $body,string $suffix): bool
{
    $deliveryLock=hache_sharky_orchestrator_delivery_lock($contact);if(!is_resource($deliveryLock))return false;
    try{
        if(!hache_sharky_lab_claim_early($pdo,$event,$contact,(string)($event['type']??'interactive')))return false;
        $payload=hache_sharky_whatsapp_text_payload($contact,$body);
        return hache_sharky_lab_queue_and_complete($pdo,$contact,$payload,(string)$event['id'].'|'.$suffix,(string)$event['id']);
    }finally{hache_sharky_lab_release_delivery_lock($deliveryLock);}
}

/**
 * On a generic payment query this reconciles already-created intents. A partial
 * intensive balance is answered here (without a checkout button), while normal
 * zero-paid balances continue to member-ops. `member:pay` owns the checkout turn.
 */
function hache_sharky_member_payment_process_event(PDO $pdo,array $event,array $business): ?bool
{
    if(trim((string)($event['group_id']??''))!=='')return null;$contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';if($contact==='')return null;$id=strtolower(trim((string)($event['interactive_id']??'')));$intent=hache_sharky_member_intent((string)($event['text']??''),$id);
    $identity=hache_sharky_business_identity_by_whatsapp($pdo,$contact);if(($identity['found']??false)!==true)return null;$studentId=(string)$identity['student_id'];
    $ctx=null;
    if($intent==='payments'){
        hache_sharky_member_payment_reconcile_student($pdo,$studentId);
        $ctx=hache_sharky_member_student_context($pdo,$contact);
        $payment=$ctx['payment']??null;
        if($id!=='member:pay'&&is_array($payment)&&hache_sharky_member_payment_partial_intensive($payment)){
            return hache_sharky_member_payment_queue_owned($pdo,$contact,$event,hache_sharky_member_payment_partial_message($payment),'member-payment-partial');
        }
    }
    if($id!=='member:pay')return null;
    $ctx??=hache_sharky_member_student_context($pdo,$contact);$payment=$ctx['payment']??null;$pending=hache_sharky_member_payment_pending_from_context($ctx);
    $deliveryLock=hache_sharky_orchestrator_delivery_lock($contact);if(!is_resource($deliveryLock))return false;
    try{
        if(!hache_sharky_lab_claim_early($pdo,$event,$contact,(string)($event['type']??'interactive')))return false;
        if(!$pending){
            $body=is_array($payment)&&hache_sharky_member_payment_partial_intensive($payment)
                ?hache_sharky_member_payment_partial_message($payment)
                :'Ya no encuentro un saldo pendiente en tu inscripción activa. ✅';
            $payload=hache_sharky_whatsapp_text_payload($contact,$body);return hache_sharky_lab_queue_and_complete($pdo,$contact,$payload,(string)$event['id'].'|member-payment-clear',(string)$event['id']);
        }
        if(!hache_sharky_member_payments_schema_ready($pdo)){$payload=hache_sharky_whatsapp_text_payload($contact,'El checkout está temporalmente fuera de servicio. No hice ningún cargo; el equipo puede ayudarte por este chat.');return hache_sharky_lab_queue_and_complete($pdo,$contact,$payload,(string)$event['id'].'|member-payment-schema',(string)$event['id']);}
        $external=hache_sharky_member_payment_external($studentId,(string)$pending['kind'],(string)$pending['resource_id']);$existing=['external_reference'=>$external];$reconciled=hache_sharky_member_payment_reconcile_intent($pdo,$existing);if(($reconciled['state']??'')==='approved'){$fresh=hache_sharky_member_student_context($pdo,$contact);$payload=hache_sharky_whatsapp_text_payload($contact,'Tu pago ya aparece aprobado. '.hache_sharky_member_payment_message($fresh));return hache_sharky_lab_queue_and_complete($pdo,$contact,$payload,(string)$event['id'].'|member-payment-approved',(string)$event['id']);}
        $checkout=hache_sharky_member_payment_create_preference($pending,$business);if(($checkout['ok']??false)!==true||!hache_sharky_member_payment_store_intent($pdo,$pending,$checkout)){$payload=hache_sharky_whatsapp_text_payload($contact,'No pude abrir un checkout seguro en este momento. No se realizó ningún cargo. Intenta de nuevo más tarde o pide apoyo al equipo.');return hache_sharky_lab_queue_and_complete($pdo,$contact,$payload,(string)$event['id'].'|member-payment-unavailable',(string)$event['id']);}
        $base=number_format((float)$checkout['base'],2,'.',',');$charged=number_format((float)$checkout['charged'],2,'.',',');$pct=(float)$checkout['surcharge_pct'];$body='Saldo a cubrir: $'.$base.' MXN. Pago con tarjeta: $'.$charged.' MXN'.($pct>0?' (incluye '.$pct.'% de procesamiento)':'').".\n\nAbre este enlace seguro de Mercado Pago:\n".(string)$checkout['url']."\n\nCuando Mercado Pago lo apruebe, Sharky reconciliará el pago con tu registro.";$payload=hache_sharky_whatsapp_text_payload($contact,$body);return hache_sharky_lab_queue_and_complete($pdo,$contact,$payload,(string)$event['id'].'|member-payment-checkout',(string)$event['id']);
    }finally{hache_sharky_lab_release_delivery_lock($deliveryLock);}
}
