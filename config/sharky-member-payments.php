<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-member-ops.php';

function hache_sharky_member_payments_schema_ready(PDO $pdo): bool
{
    try{$st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sharky_member_payment_intents'");$st->execute();return (int)$st->fetchColumn()===1;}catch(Throwable $e){return false;}
}

function hache_sharky_member_payment_external(string $studentId,string $kind,string $resourceId,int $installment=1): string
{
    $prefix=strtoupper($kind)==='MENSUALIDAD'?'m':'i';
    $base='sharky:member:'.$prefix.':'.substr(hash('sha256',$studentId.'|'.$kind.'|'.$resourceId),0,32);
    if(strtoupper($kind)==='INTENSIVO'&&$installment>1)return $base.':'.$installment;
    return $base;
}

function hache_sharky_member_payment_checkout_external(PDO $pdo,array $pending): ?string
{
    $studentId=trim((string)($pending['student_id']??''));$kind=strtoupper(trim((string)($pending['kind']??'')));$resourceId=trim((string)($pending['resource_id']??''));$base=(float)($pending['base']??0);
    if($studentId===''||$resourceId===''||$base<=0)return null;
    $root=hache_sharky_member_payment_external($studentId,$kind,$resourceId);
    if($kind!=='INTENSIVO')return $root;
    if(!hache_sharky_member_payments_schema_ready($pdo))return null;
    try{
        $st=$pdo->prepare("SELECT external_reference FROM sharky_member_payment_intents WHERE alumno_id=:a AND payment_kind='INTENSIVO' AND intensivo_id=:i AND status='PENDING' AND ABS(base_amount-:b)<=0.009 ORDER BY created_at DESC LIMIT 1");
        $st->execute([':a'=>$studentId,':i'=>$resourceId,':b'=>number_format($base,2,'.','')]);$pendingExternal=trim((string)($st->fetchColumn()?:''));if($pendingExternal!=='')return $pendingExternal;
        $st=$pdo->prepare("SELECT COUNT(*) FROM sharky_member_payment_intents WHERE alumno_id=:a AND payment_kind='INTENSIVO' AND intensivo_id=:i");$st->execute([':a'=>$studentId,':i'=>$resourceId]);$installment=max(1,(int)$st->fetchColumn()+1);
        return hache_sharky_member_payment_external($studentId,$kind,$resourceId,$installment);
    }catch(Throwable $e){error_log('[sharky-member-payment] external slot failed');return null;}
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
    return 'Ya tienes $'.$paid.' MXN registrados en tu curso y quedan $'.$due.' MXN pendientes. 😊 Puedes completar ese saldo desde aquí eligiendo nuevamente tu forma de pago.';
}

function hache_sharky_member_payment_pending_from_context(array $ctx): ?array
{
    $payment=$ctx['payment']??null;if(!is_array($payment)||($payment['pending']??false)!==true||(float)($payment['due']??0)<=0)return null;
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
    $pct=is_numeric($business['sharky_recargo_tarjeta_pct']??null)?(float)$business['sharky_recargo_tarjeta_pct']:5.0;$pct=max(0.0,min(30.0,$pct));$charged=hache_sharky_mp_card_total($base,$pct);$external=trim((string)($pending['external_reference']??''));if($external==='')$external=hache_sharky_member_payment_external($studentId,$kind,$resourceId);
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
            if($kind==='INTENSIVO'&&!empty($row['intensivo_id'])){
                $course=$pdo->prepare("SELECT precio FROM cursos_intensivos WHERE id=:i LIMIT 1 FOR UPDATE");$course->execute([':i'=>$row['intensivo_id']]);$coursePrice=$course->fetchColumn();if($coursePrice===false)throw new RuntimeException('Intensive payment target missing');
                $guard=$pdo->prepare("SELECT id,importe FROM pagos WHERE alumno_id=:a AND intensivo_id=:i AND tipo='INTENSIVO' AND estado='VALIDO' FOR UPDATE");$guard->execute([':a'=>$studentId,':i'=>$row['intensivo_id']]);$alreadyPaid=0.0;foreach($guard->fetchAll(PDO::FETCH_ASSOC) as $existingPayment){$alreadyPaid+=(float)$existingPayment['importe'];}
                if($alreadyPaid+$base>(float)$coursePrice+0.009)throw new RuntimeException('Approved MP payment exceeds the remaining intensive balance; manual reconciliation required');
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

function hache_sharky_member_payment_queue_payload_owned(PDO $pdo,string $contact,array $event,array $payload,string $suffix,?array $state=null): bool
{
    $deliveryLock=hache_sharky_orchestrator_delivery_lock($contact);if(!is_resource($deliveryLock))return false;
    try{
        if(!hache_sharky_lab_claim_early($pdo,$event,$contact,(string)($event['type']??'interactive')))return false;
        $deferred=[];
        if(is_array($state)){
            hache_sharky_db_state_defer_begin();
            hache_sharky_db_state_save($pdo,$contact,$state,86400);
            $deferred=hache_sharky_db_state_defer_take();
        }
        return hache_sharky_lab_queue_and_complete($pdo,$contact,$payload,(string)$event['id'].'|'.$suffix,(string)$event['id'],[],$deferred);
    }finally{hache_sharky_lab_release_delivery_lock($deliveryLock);}
}

function hache_sharky_member_payment_queue_owned(PDO $pdo,string $contact,array $event,string $body,string $suffix,?array $state=null): bool
{
    return hache_sharky_member_payment_queue_payload_owned($pdo,$contact,$event,hache_sharky_whatsapp_text_payload($contact,$body),$suffix,$state);
}

function hache_sharky_member_payment_method_payload(string $contact,float $pct): array
{
    $pctLabel=rtrim(rtrim(number_format(max(0.0,min(30.0,$pct)),2,'.',''),'0'),'.');
    return hache_sharky_member_buttons($contact,
        "¿Cómo prefieres pagar? 😊\n\nEfectivo es nuestra opción preferencial. Transferencia SPEI no tiene recargo y tarjeta se procesa por Mercado Pago (+{$pctLabel}%).",
        [
            ['id'=>'member:pay:cash','title'=>'Efectivo'],
            ['id'=>'member:pay:transfer','title'=>'Transferencia SPEI'],
            ['id'=>'member:pay:card','title'=>'Tarjeta'],
        ]
    );
}

function hache_sharky_member_payment_transfer_payload(string $contact,array $pending,array $business): array
{
    $institution=trim((string)($business['sharky_pago_institucion']??''));
    $beneficiary=trim((string)($business['sharky_pago_beneficiario']??''));
    $clabe=preg_replace('/\D+/','',(string)($business['sharky_pago_clabe']??''))?:'';
    $amount='$'.number_format((float)$pending['base'],2,'.',',').' MXN';
    $lines=['Perfecto 😊 Puedes hacer una transferencia SPEI por '.$amount.'.'];
    if($institution!=='')$lines[]='Institución: '.$institution;
    if($beneficiary!=='')$lines[]='Beneficiario: '.$beneficiary;
    if(strlen($clabe)===18){$lines[]='CLABE:';$lines[]=$clabe;}
    if($institution===''||$beneficiary===''||strlen($clabe)!==18)$lines[]='Si no ves todos los datos bancarios, avísame y te paso con el equipo.';
    $lines[]='';
    $lines[]='Cuando la hagas, envíame por aquí la foto o el comprobante. Lo dejaremos pendiente de verificación; no se marcará como pagado solo por recibir la imagen.';
    return hache_sharky_whatsapp_text_payload($contact,implode("\n",$lines));
}

function hache_sharky_member_payment_cash_payload(string $contact,array $pending): array
{
    $amount='$'.number_format((float)$pending['base'],2,'.',',').' MXN';
    return hache_sharky_whatsapp_text_payload($contact,'Perfecto 😊 Puedes cubrir '.$amount.' en efectivo con tu profe. Es nuestra opción preferencial y no tiene recargo.');
}

function hache_sharky_member_payment_card_payload(string $contact,array $pending,array $business): array
{
    $checkout=hache_sharky_member_payment_create_preference($pending,$business);
    if(($checkout['ok']??false)!==true)return ['ok'=>false,'payload'=>hache_sharky_whatsapp_text_payload($contact,'No pude abrir Mercado Pago en este momento. No se hizo ningún cargo. Puedes elegir efectivo o transferencia SPEI y te ayudo por aquí.')];
    $base=number_format((float)$checkout['base'],2,'.',',');$charged=number_format((float)$checkout['charged'],2,'.',',');$pct=(float)$checkout['surcharge_pct'];
    $pctLabel=rtrim(rtrim(number_format($pct,2,'.',''),'0'),'.');
    $body='Tu saldo es de $'.$base.' MXN. Con tarjeta serían $'.$charged.' MXN'.($pct>0?' (incluye '.$pctLabel.'% de procesamiento)':'').".\n\nPuedes pagar aquí:\n".(string)$checkout['url']."\n\nCuando Mercado Pago lo confirme, tu pago se actualizará automáticamente. 😊";
    return ['ok'=>true,'checkout'=>$checkout,'payload'=>hache_sharky_whatsapp_text_payload($contact,$body)];
}

function hache_sharky_member_payment_current_state(PDO $pdo,string $contact): array
{
    try{return hache_sharky_db_state_load($pdo,$contact);}catch(Throwable $e){return [];}
}

function hache_sharky_member_payment_restart_state(array $state,int $now): array
{
    $flow=hache_sharky_member_flow($state);
    if(is_array($flow)&&($flow['name']??'')==='member_payment_transfer')return hache_sharky_member_set_flow($state,null,$now);
    return $state;
}

function hache_sharky_member_payment_process_event(PDO $pdo,array $event,array $business): ?bool
{
    if(trim((string)($event['group_id']??''))!=='')return null;
    $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';if($contact==='')return null;
    $id=strtolower(trim((string)($event['interactive_id']??'')));
    $intent=hache_sharky_member_intent((string)($event['text']??''),$id);
    $identity=hache_sharky_business_identity_by_whatsapp($pdo,$contact);if(($identity['found']??false)!==true)return null;$studentId=(string)$identity['student_id'];

    if(is_array($event['member_payment']??null)){
        $state=hache_sharky_member_payment_current_state($pdo,$contact);$flow=hache_sharky_member_flow($state);
        $meta=$event['member_payment'];
        if(!is_array($flow)||($flow['name']??'')!=='member_payment_transfer'||($flow['step']??'')!=='evidence'||(string)($flow['student_id']??'')!==$studentId)return null;
        if((string)($meta['student_id']??'')!==$studentId||(string)($meta['resource_id']??'')!==(string)($flow['resource_id']??''))return null;
        $state=hache_sharky_member_set_flow($state,null,time());
        return hache_sharky_member_payment_queue_owned($pdo,$contact,$event,'Gracias, ya recibí tu comprobante 😊 Queda pendiente de verificación. Te avisamos por aquí si necesitamos algo más.','member-payment-transfer-proof',$state);
    }

    $ctx=null;
    if($intent==='payments'){
        hache_sharky_member_payment_reconcile_student($pdo,$studentId);
        $ctx=hache_sharky_member_student_context($pdo,$contact);
        $payment=$ctx['payment']??null;
    }

    $method=null;
    if($id==='member:pay:cash')$method='cash';
    elseif($id==='member:pay:transfer')$method='transfer';
    elseif($id==='member:pay:card')$method='card';
    elseif($id!=='member:pay')return null;

    $ctx??=hache_sharky_member_student_context($pdo,$contact);$payment=$ctx['payment']??null;$pending=hache_sharky_member_payment_pending_from_context($ctx);
    if(!$pending){
        $body=is_array($payment)&&hache_sharky_member_payment_partial_intensive($payment)
            ?hache_sharky_member_payment_partial_message($payment)
            :'Todo bien 😊 Ya no encuentro un saldo pendiente en tu inscripción actual.';
        return hache_sharky_member_payment_queue_owned($pdo,$contact,$event,$body,'member-payment-clear');
    }

    if($id==='member:pay'){
    // Reopening payment choice clears only a stale SPEI-proof wait so
    // an unpaid student can safely change method without changing the debt.
    $state=hache_sharky_member_payment_restart_state(hache_sharky_member_payment_current_state($pdo,$contact),time());
    $pct=is_numeric($business['sharky_recargo_tarjeta_pct']??null)?(float)$business['sharky_recargo_tarjeta_pct']:5.0;
    return hache_sharky_member_payment_queue_payload_owned($pdo,$contact,$event,hache_sharky_member_payment_method_payload($contact,$pct),'member-payment-method',$state);
}

    if($method==='cash'){
        $state=hache_sharky_member_payment_current_state($pdo,$contact);$state=hache_sharky_member_set_flow($state,null,time());
        return hache_sharky_member_payment_queue_payload_owned($pdo,$contact,$event,hache_sharky_member_payment_cash_payload($contact,$pending),'member-payment-cash',$state);
    }

    if($method==='transfer'){
        $state=hache_sharky_member_payment_current_state($pdo,$contact);
        $state=hache_sharky_member_set_flow($state,[
            'name'=>'member_payment_transfer','step'=>'evidence','student_id'=>$studentId,
            'kind'=>(string)$pending['kind'],'resource_id'=>(string)$pending['resource_id'],'amount'=>(float)$pending['base'],
        ],time());
        return hache_sharky_member_payment_queue_payload_owned($pdo,$contact,$event,hache_sharky_member_payment_transfer_payload($contact,$pending,$business),'member-payment-transfer',$state);
    }

    if(!hache_sharky_member_payments_schema_ready($pdo))return hache_sharky_member_payment_queue_owned($pdo,$contact,$event,'No pude abrir Mercado Pago en este momento. Puedes elegir efectivo o transferencia SPEI y te ayudo por aquí.','member-payment-schema');
    $external=hache_sharky_member_payment_checkout_external($pdo,$pending);
    if($external===null)return hache_sharky_member_payment_queue_owned($pdo,$contact,$event,'No pude preparar el pago con tarjeta en este momento. Puedes elegir efectivo o transferencia SPEI.','member-payment-external');
    $pending['external_reference']=$external;
    $card=hache_sharky_member_payment_card_payload($contact,$pending,$business);
    if(($card['ok']??false)!==true)return hache_sharky_member_payment_queue_payload_owned($pdo,$contact,$event,$card['payload'],'member-payment-unavailable');
    if(!hache_sharky_member_payment_store_intent($pdo,$pending,$card['checkout']))return hache_sharky_member_payment_queue_owned($pdo,$contact,$event,'No pude dejar listo el pago con tarjeta. No se hizo ningún cargo. Puedes elegir efectivo o transferencia SPEI.','member-payment-store-failed');
    return hache_sharky_member_payment_queue_payload_owned($pdo,$contact,$event,$card['payload'],'member-payment-checkout');
}
