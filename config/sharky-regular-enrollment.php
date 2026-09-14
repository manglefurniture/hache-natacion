<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-commerce-flows.php';
require_once __DIR__.'/sharky-action-recovery.php';
require_once __DIR__.'/sharky-age-policy.php';

const HACHE_SHARKY_REGULAR_FLOW_KIND='regular_enrollment';
const HACHE_SHARKY_REGULAR_FLOW_NAME='Hache_Sharky_Regular_Enrollment_v1';
const HACHE_SHARKY_REGULAR_FLOW_ENV='WHATSAPP_REGULAR_ENROLLMENT_FLOW_ID';

function hache_sharky_regular_flow_cache_path(): string
{
    $dir=hache_sharky_whatsapp_birthdate_flow_cache_dir();
    return $dir===''?'':$dir.'/regular-enrollment-v1.id';
}

function hache_sharky_regular_flow_cached_id(): ?string
{
    $configured=preg_replace('/\D+/','',hache_sharky_whatsapp_flow_secret(HACHE_SHARKY_REGULAR_FLOW_ENV))?:'';
    if($configured!=='')return $configured;
    $path=hache_sharky_regular_flow_cache_path();if($path===''||!is_file($path))return null;
    $id=preg_replace('/\D+/','',trim((string)@file_get_contents($path)))?:'';
    return $id!==''?$id:null;
}

function hache_sharky_regular_flow_cache_id(string $flowId): bool
{
    $flowId=preg_replace('/\D+/','',$flowId)?:'';$path=hache_sharky_regular_flow_cache_path();
    if($flowId===''||$path==='')return false;
    $ok=@file_put_contents($path,$flowId,LOCK_EX)!==false;if($ok)@chmod($path,0600);return $ok;
}

function hache_sharky_regular_flow_existing(array $data): ?array
{
    foreach(($data['data']??[]) as $flow){
        if(!is_array($flow)||trim((string)($flow['name']??''))!==HACHE_SHARKY_REGULAR_FLOW_NAME)continue;
        $id=preg_replace('/\D+/','',(string)($flow['id']??''))?:'';if($id==='')continue;
        return ['id'=>$id,'status'=>strtoupper(trim((string)($flow['status']??'')))];
    }
    return null;
}

function hache_sharky_regular_flow_prime(array $payload,?callable $secretResolver=null): ?string
{
    $cached=hache_sharky_regular_flow_cached_id();if($cached!==null)return $cached;
    $secretResolver??=static fn(string $name):string=>hache_sharky_whatsapp_flow_secret($name);
    $configured=preg_replace('/\D+/','',(string)$secretResolver(HACHE_SHARKY_REGULAR_FLOW_ENV))?:'';
    if($configured!==''){hache_sharky_regular_flow_cache_id($configured);return $configured;}
    $waba=hache_sharky_whatsapp_birthdate_flow_first_waba($payload);$token=trim((string)$secretResolver('WHATSAPP_ACCESS_TOKEN'));
    if($waba===''||$token==='')return null;
    $version=trim((string)$secretResolver('WHATSAPP_GRAPH_VERSION'));if(preg_match('/^v\d+\.\d+$/',$version)!==1)$version='v26.0';
    $base='https://graph.facebook.com/'.rawurlencode($version);
    $list=hache_sharky_whatsapp_flow_graph_json('GET',$base.'/'.rawurlencode($waba).'/flows?fields=id%2Cname%2Cstatus&limit=100',$token);
    $existing=is_array($list)?hache_sharky_regular_flow_existing($list):null;
    if(is_array($existing)&&$existing['status']==='PUBLISHED'){hache_sharky_regular_flow_cache_id((string)$existing['id']);return (string)$existing['id'];}
    $flowId=is_array($existing)?(string)$existing['id']:'';
    if($flowId===''){
        $created=hache_sharky_whatsapp_flow_graph_json('POST',$base.'/'.rawurlencode($waba).'/flows',$token,['name'=>HACHE_SHARKY_REGULAR_FLOW_NAME,'categories'=>'["SIGN_UP"]']);
        $flowId=preg_replace('/\D+/','',(string)($created['id']??''))?:'';if($flowId==='')return null;
    }
    if(!hache_sharky_commerce_flow_upload($base,$flowId,$token,'regular-enrollment-v1.json'))return null;
    $published=hache_sharky_whatsapp_flow_graph_json('POST',$base.'/'.rawurlencode($flowId).'/publish',$token,[]);
    if(!is_array($published)){
        $check=hache_sharky_whatsapp_flow_graph_json('GET',$base.'/'.rawurlencode($waba).'/flows?fields=id%2Cname%2Cstatus&limit=100',$token);
        $after=is_array($check)?hache_sharky_regular_flow_existing($check):null;
        if(!is_array($after)||$after['status']!=='PUBLISHED')return null;
    }
    hache_sharky_regular_flow_cache_id($flowId);return $flowId;
}

function hache_sharky_regular_flow_extract_events(array $payload): array
{
    $out=[];
    foreach(($payload['entry']??[]) as $entry){
        if(!is_array($entry))continue;$waba=preg_replace('/\D+/','',(string)($entry['id']??''))?:'';
        foreach(($entry['changes']??[]) as $change){
            $value=is_array($change['value']??null)?$change['value']:[];$phoneId=trim((string)($value['metadata']['phone_number_id']??''));
            foreach(($value['messages']??[]) as $message){
                if(!is_array($message)||($message['type']??'')!=='interactive')continue;
                $interactive=$message['interactive']??null;if(!is_array($interactive)||($interactive['type']??'')!=='nfm_reply')continue;
                $data=json_decode((string)($interactive['nfm_reply']['response_json']??''),true);if(!is_array($data))continue;
                if(strtolower(trim((string)($data['flow_kind']??'')))!==HACHE_SHARKY_REGULAR_FLOW_KIND)continue;
                $action=strtolower(trim((string)($data['user_action']??'')));if(!in_array($action,['submit','cancel'],true))continue;
                $id=trim((string)($message['id']??''));$from=preg_replace('/\D+/','',(string)($message['from']??''))?:'';if($id===''||$from==='')continue;
                $event=['id'=>$id,'from'=>$from,'type'=>'interactive','kind'=>HACHE_SHARKY_REGULAR_FLOW_KIND,'text'=>'','interactive_id'=>'','regular_enrollment'=>$data,'phone_number_id'=>$phoneId,'timestamp_ms'=>((int)($message['timestamp']??time()))*1000];
                if($waba!=='')$event['waba_id']=$waba;if(is_array($message['referral']??null))$event['referral']=$message['referral'];$out[]=$event;
            }
        }
    }
    return $out;
}

function hache_sharky_regular_phone(string $contact): string
{
    $digits=preg_replace('/\D+/','',$contact)?:'';if(strlen($digits)===13&&str_starts_with($digits,'521'))$digits='52'.substr($digits,3);
    $phone='+'.$digits;if(!telefono_es_e164($phone))throw new HacheSharkyBusinessException('El WhatsApp no es válido.','INVALID_PHONE');return $phone;
}

function hache_sharky_business_register_regular(PDO $pdo,array $action,int $minAge=12,int $maxAge=65,?string $today=null): array
{
    $sede=strtoupper(trim((string)($action['sede_clave']??'')));$scheduleId=trim((string)($action['schedule_id']??''));$planId=trim((string)($action['plan_id']??''));
    $name=preg_replace('/\s+/u',' ',trim((string)($action['name']??'')))??'';$birthdate=trim((string)($action['birthdate']??''));$phone=hache_sharky_regular_phone((string)($action['contact_phone']??''));
    $profile=strtolower(trim((string)($action['level_profile']??'')));
    if(!in_array($sede,['MONTEVERDE','PALAPAS'],true)||$scheduleId===''||$planId==='')throw new HacheSharkyBusinessException('Faltan datos para completar la inscripción.','MISSING_DATA');
    if(!in_array($profile,['intermediate','advanced'],true))throw new HacheSharkyBusinessException('Debes confirmar si tu perfil es intermedio o avanzado.','INVALID_PROFILE');
    if(mb_strlen($name)<4||mb_strlen($name)>180)throw new HacheSharkyBusinessException('El nombre completo no es válido.','INVALID_NAME');
    $age=hache_sharky_business_validate_birthdate($birthdate,max(1,$minAge),$today);$maxAge=max($minAge,$maxAge);
    if((int)$age['age']>$maxAge)throw new HacheSharkyBusinessException('La persona supera la edad máxima atendida por este servicio.','MAX_AGE');
    $todayObj=new DateTimeImmutable($today?:'today');$start=$todayObj->format('Y-m-d');

    $st=$pdo->prepare('SELECT id,nombre FROM sedes WHERE clave=:c AND activo=1 LIMIT 1');$st->execute([':c'=>$sede]);$site=$st->fetch(PDO::FETCH_ASSOC);
    if(!$site)throw new HacheSharkyBusinessException('La sede ya no está disponible.','SITE_UNAVAILABLE',409);$siteId=(string)$site['id'];
    $pdo->beginTransaction();
    try{
        regla_bloquear_identidades_alumnos($pdo);
        $st=$pdo->prepare('SELECT id FROM sedes WHERE id=:s AND activo=1 LIMIT 1 FOR UPDATE');$st->execute([':s'=>$siteId]);if(!$st->fetchColumn())throw new HacheSharkyBusinessException('La sede ya no está disponible.','SITE_UNAVAILABLE',409);
        $st=$pdo->prepare('SELECT id FROM alumnos WHERE whatsapp=:w LIMIT 1');$st->execute([':w'=>$phone]);if($st->fetchColumn())throw new HacheSharkyBusinessException('Este WhatsApp ya tiene un registro.','PHONE_ALREADY_REGISTERED',409);
        $st=$pdo->prepare('SELECT id,hora_inicio,hora_fin FROM horarios WHERE id=:h AND sede_id=:s AND activo=1 AND regular=1 LIMIT 1 FOR UPDATE');$st->execute([':h'=>$scheduleId,':s'=>$siteId]);$schedule=$st->fetch(PDO::FETCH_ASSOC);if(!$schedule)throw new HacheSharkyBusinessException('El horario seleccionado dejó de estar disponible.','SCHEDULE_UNAVAILABLE',409);
        $st=$pdo->prepare('SELECT id,nombre,sesiones_semana,precio FROM planes WHERE id=:p AND sede_id=:s AND activo=1 AND sesiones_semana IN (3,5) LIMIT 1 FOR UPDATE');$st->execute([':p'=>$planId,':s'=>$siteId]);$plan=$st->fetch(PDO::FETCH_ASSOC);if(!$plan)throw new HacheSharkyBusinessException('El plan seleccionado dejó de estar disponible.','PLAN_UNAVAILABLE',409);

        $studentId=hache_sharky_business_uuid($pdo);$tempPassword=password_temporal_segura();$hash=password_hash($tempPassword,PASSWORD_DEFAULT);
        $profileLabel=$profile==='advanced'?'AVANZADO':'INTERMEDIO';
        $st=$pdo->prepare("INSERT INTO alumnos(id,sede_id,nombre,fecha_nacimiento,whatsapp,correo,fecha_inicio,horario_preferido_id,plan_actual_id,estado_administrativo,observaciones) VALUES(:id,:s,:n,:birth,:w,NULL,:f,:h,:p,'PENDIENTE',:o)");
        $st->execute([':id'=>$studentId,':s'=>$siteId,':n'=>$name,':birth'=>$birthdate,':w'=>$phone,':f'=>$start,':h'=>$scheduleId,':p'=>$planId,':o'=>'Registro conversacional Sharky REGULAR. Perfil declarado: '.$profileLabel.'. Pendiente de coordinación de pago.']);
        $username=hache_sharky_business_username($pdo,$name);$userId=hache_sharky_business_uuid($pdo);
        $st=$pdo->prepare("INSERT INTO usuarios(id,usuario,password_hash,rol,activo,debe_cambiar_password,alumno_id) VALUES(:id,:u,:p,'ALUMNO',1,1,:a)");$st->execute([':id'=>$userId,':u'=>$username,':p'=>$hash,':a'=>$studentId]);
        $st=$pdo->prepare("INSERT INTO registros_publicos(id,alumno_id,sede_id,tipo,horario_id,fecha_inicio_intensivo) VALUES(:id,:a,:s,'REGULAR',:h,NULL)");$st->execute([':id'=>hache_sharky_business_uuid($pdo),':a'=>$studentId,':s'=>$siteId,':h'=>$scheduleId]);
        $pdo->commit();
        return ['ok'=>true,'student_id'=>$studentId,'username'=>$username,'temporary_password'=>$tempPassword,'sede_clave'=>$sede,'sede_nombre'=>(string)$site['nombre'],'plan_id'=>$planId,'plan_name'=>(string)$plan['nombre'],'sessions_per_week'=>(int)$plan['sesiones_semana'],'plan_price'=>(float)$plan['precio'],'schedule_id'=>$scheduleId,'schedule_label'=>substr((string)$schedule['hora_inicio'],0,5).'–'.substr((string)$schedule['hora_fin'],0,5),'level_profile'=>$profile,'code'=>'CREATED'];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/**
 * Recover an exact Sharky-created regular registration after a worker committed
 * the student transaction but lost the action/delivery boundary. The identity
 * lock plus the exact marker/sede/plan/schedule/profile match prevents an
 * unrelated public/admin registration from being mistaken for this event.
 */
function hache_sharky_regular_registration_recover_locked(PDO $pdo,string $contact,array $action): ?array
{
    $phone=hache_sharky_regular_phone($contact);$name=preg_replace('/\s+/u',' ',trim((string)($action['name']??'')))??'';
    $sede=strtoupper(trim((string)($action['sede_clave']??'')));$scheduleId=trim((string)($action['schedule_id']??''));$planId=trim((string)($action['plan_id']??''));$birthdate=trim((string)($action['birthdate']??''));$profile=strtolower(trim((string)($action['level_profile']??'')));
    if($name===''||!in_array($sede,['MONTEVERDE','PALAPAS'],true)||$scheduleId===''||$planId===''||$birthdate===''||!in_array($profile,['intermediate','advanced'],true))return null;
    $profileLabel=$profile==='advanced'?'AVANZADO':'INTERMEDIO';$marker='Registro conversacional Sharky REGULAR. Perfil declarado: '.$profileLabel.'. Pendiente de coordinación de pago.';
    $pdo->beginTransaction();
    try{
        regla_bloquear_identidades_alumnos($pdo);
        $st=$pdo->prepare("SELECT a.id student_id,s.clave sede_clave,s.nombre sede_nombre,p.id plan_id,p.nombre plan_name,p.sesiones_semana,p.precio,h.id schedule_id,h.hora_inicio,h.hora_fin,u.id portal_user_id,u.usuario username FROM alumnos a JOIN sedes s ON s.id=a.sede_id JOIN planes p ON p.id=a.plan_actual_id JOIN horarios h ON h.id=a.horario_preferido_id JOIN usuarios u ON u.alumno_id=a.id AND u.rol='ALUMNO' AND u.activo=1 JOIN registros_publicos rp ON rp.alumno_id=a.id AND rp.tipo='REGULAR' AND rp.horario_id=h.id WHERE a.whatsapp=:w AND a.nombre=:n AND a.fecha_nacimiento=:birth AND a.observaciones=:marker AND s.clave=:s AND p.id=:p AND h.id=:h LIMIT 1 FOR UPDATE");
        $st->execute([':w'=>$phone,':n'=>$name,':birth'=>$birthdate,':marker'=>$marker,':s'=>$sede,':p'=>$planId,':h'=>$scheduleId]);$row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row){$pdo->commit();return null;}
        $temporaryPassword=password_temporal_segura();$passwordHash=password_hash($temporaryPassword,PASSWORD_DEFAULT);
        $st=$pdo->prepare('UPDATE usuarios SET password_hash=:p,debe_cambiar_password=1 WHERE id=:u AND alumno_id=:a');$st->execute([':p'=>$passwordHash,':u'=>(string)$row['portal_user_id'],':a'=>(string)$row['student_id']]);
        if($st->rowCount()!==1)throw new RuntimeException('Unable to rotate recovered regular portal credential');
        $pdo->commit();
        return ['ok'=>true,'student_id'=>(string)$row['student_id'],'username'=>(string)$row['username'],'temporary_password'=>$temporaryPassword,'sede_clave'=>(string)$row['sede_clave'],'sede_nombre'=>(string)$row['sede_nombre'],'plan_id'=>(string)$row['plan_id'],'plan_name'=>(string)$row['plan_name'],'sessions_per_week'=>(int)$row['sesiones_semana'],'plan_price'=>(float)$row['precio'],'schedule_id'=>(string)$row['schedule_id'],'schedule_label'=>substr((string)$row['hora_inicio'],0,5).'–'.substr((string)$row['hora_fin'],0,5),'level_profile'=>$profile,'code'=>'RECOVERED','recovered'=>true];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function hache_sharky_regular_enrollment_process(PDO $pdo,array $event,array $business,int $minAge=12,int $maxAge=65): bool
{
    if(($event['kind']??'')!==HACHE_SHARKY_REGULAR_FLOW_KIND)return false;
    $contact=preg_replace('/\D+/','',(string)($event['from']??''))?:'';$eventId=trim((string)($event['id']??''));if($contact===''||$eventId==='')return false;
    $policy=hache_sharky_age_policy($pdo,$business);$minAge=(int)$policy['min'];$maxAge=(int)$policy['max'];
    $lock=hache_sharky_orchestrator_delivery_lock($contact);if(!is_resource($lock))return false;
    try{
        if(!hache_sharky_lab_claim_early($pdo,$event,$contact,HACHE_SHARKY_REGULAR_FLOW_KIND))return false;
        hache_sharky_db_state_defer_begin();
        $state=hache_sharky_db_state_load($pdo,$contact);$flow=is_array($state['flow']??null)?$state['flow']:[];$data=is_array($event['regular_enrollment']??null)?$event['regular_enrollment']:[];
        $action=strtolower(trim((string)($data['user_action']??'')));
        if($action==='cancel'){
            $sede=strtoupper((string)($flow['data']['sede_clave']??($state['commercial_context']['sede_clave']??'')));
            if(function_exists('hache_sharky_meta_supported_source')&&hache_sharky_meta_supported_source($state)&&($state['commercial_context']['program']??'')==='regular'&&in_array($sede,['MONTEVERDE','PALAPAS'],true)&&defined('HACHE_SHARKY_META_FLOW')&&function_exists('hache_sharky_meta_venue_detail')){
                $state['commercial_context']['sede_clave']=$sede;$state['commercial_context']['meta_step']='venue_detail';$state=hache_sharky_orchestrator_flow($state,HACHE_SHARKY_META_FLOW,'venue_detail',['sede_clave'=>$sede],time());
                $decision=hache_sharky_meta_venue_detail($pdo,$state,$sede);
            }else{
                $state=hache_sharky_orchestrator_clear_flow($state);$decision=hache_sharky_orchestrator_decision('regular_enrollment_cancelled','Entendido. Cancelé el formulario y no registré nada. Puedes continuar desde la sede que habías elegido.');
            }
            hache_sharky_db_state_save($pdo,$contact,$state);$deferred=hache_sharky_db_state_defer_take();$payload=hache_sharky_whatsapp_render($contact,$decision);
            return hache_sharky_lab_queue_and_complete($pdo,$contact,$payload,$eventId.'|regular-cancel',$eventId,[],$deferred);
        }
        if(($flow['name']??'')!=='register_regular'||($flow['step']??'')!=='form'){
            hache_sharky_db_state_defer_cancel();
            $decision=hache_sharky_orchestrator_decision('regular_enrollment_stale','Ese formulario pertenece a una inscripción anterior. No hice cambios; te dejo con el equipo para revisarlo.',[],['type'=>'human_takeover']);
            if(!hache_sharky_lab_mark_handoff_pending($pdo,$eventId))return false;
            if(!hache_sharky_takeover_mark($contact,'regular_enrollment_stale','Regular enrollment stale'))return false;
            $payload=hache_sharky_outbox_allow_during_takeover(hache_sharky_whatsapp_render($contact,$decision));
            return hache_sharky_lab_queue_and_complete($pdo,$contact,$payload,$eventId.'|regular-stale',$eventId);
        }
        $expected=strtoupper((string)($flow['data']['sede_clave']??''));$submitted=strtoupper(trim((string)($data['venue_key']??'')));
        if($expected===''||$expected!==$submitted)throw new HacheSharkyBusinessException('La sede del formulario ya no coincide con la selección vigente.','SITE_MISMATCH',409);
        $profile=strtolower(trim((string)($data['level_profile']??'')));
        $registrationAction=['sede_clave'=>$expected,'schedule_id'=>(string)($data['schedule_id']??''),'plan_id'=>(string)($data['plan_id']??''),'name'=>(string)($data['full_name']??''),'birthdate'=>(string)($data['birthdate']??''),'contact_phone'=>$contact,'level_profile'=>$profile];
        $idempotencyKey=$eventId.'|register_regular';$existing=hache_sharky_action_recovery_status($pdo,$idempotencyKey);$result=null;
        $successMessage='Recibí tu inscripción a clases regulares. Ya tengo tu sede, plan y horario. Ahora te dejo con una persona del equipo para revisar contigo el pago de inscripción y mensualidad.';
        if(is_array($existing)&&(string)($existing['status']??'')==='COMPLETED'){
            $result=is_array($existing['result']??null)?$existing['result']:null;
            if(($existing['result_decrypt_failed']??false)===true||!is_array($result)){
                $result=hache_sharky_regular_registration_recover_locked($pdo,$contact,$registrationAction);
                if(!is_array($result))throw new RuntimeException('Completed regular enrollment could not be reconciled safely');
                if(!hache_sharky_action_recovery_reseal_completed($pdo,$idempotencyKey,'RECOVERED',$result,$successMessage))throw new RuntimeException('Unable to reseal recovered regular enrollment result');
            }
        }else{
            if(hache_sharky_action_lease_active($existing)){hache_sharky_db_state_defer_cancel();return false;}
            if(is_array($existing)&&(string)($existing['status']??'')==='FAILED')throw new HacheSharkyBusinessException(trim((string)($existing['result_message']??''))?:'La inscripción anterior no pudo completarse.',trim((string)($existing['result_code']??''))?:'REGULAR_ACTION_FAILED',409);
            $ownerToken=null;$contactHash=hache_sharky_orchestrator_contact_hash($contact);
            if(!hache_sharky_action_recovery_claim($pdo,$idempotencyKey,'register_regular',$contactHash,null,$registrationAction,$ownerToken)||!is_string($ownerToken)||$ownerToken===''){hache_sharky_db_state_defer_cancel();return false;}
            try{
                try{$result=hache_sharky_business_register_regular($pdo,$registrationAction,$minAge,$maxAge,hache_sharky_lab_today());}
                catch(HacheSharkyBusinessException $registrationError){
                    if($registrationError->codeName!=='PHONE_ALREADY_REGISTERED')throw $registrationError;
                    $result=hache_sharky_regular_registration_recover_locked($pdo,$contact,$registrationAction);if(!is_array($result))throw $registrationError;
                }
                $resultCode=(string)($result['code']??'CREATED');
                if(!hache_sharky_action_recovery_finish($pdo,$idempotencyKey,true,$resultCode,$result,$successMessage,$ownerToken)){hache_sharky_db_state_defer_cancel();return false;}
            }catch(HacheSharkyBusinessException $registrationError){
                if(!hache_sharky_action_recovery_finish($pdo,$idempotencyKey,false,$registrationError->codeName,null,$registrationError->getMessage(),$ownerToken)){hache_sharky_db_state_defer_cancel();return false;}
                throw $registrationError;
            }
        }
        if(!is_array($result))throw new RuntimeException('Regular enrollment completed without a recoverable result');
        $state=hache_sharky_orchestrator_clear_flow($state);unset($state['commercial_context']['meta_step']);$state['commercial_context']['age']=(new DateTimeImmutable((string)$data['birthdate']))->diff(new DateTimeImmutable(hache_sharky_lab_today()))->y;$state['commercial_context']['swim_level']=$profile;
        $decision=hache_sharky_orchestrator_decision('regular_enrollment_received','✅ '.$successMessage,[],['type'=>'human_takeover']);hache_sharky_db_state_save($pdo,$contact,$state);
        if(!hache_sharky_lab_mark_handoff_pending($pdo,$eventId)){hache_sharky_db_state_defer_cancel();return false;}
        if(!hache_sharky_takeover_mark($contact,'regular_enrollment_payment','Regular enrollment requires human payment coordination')){hache_sharky_db_state_defer_cancel();return false;}
        $deferred=hache_sharky_db_state_defer_take();$payload=hache_sharky_outbox_allow_during_takeover(hache_sharky_whatsapp_render($contact,$decision));
        return hache_sharky_lab_queue_and_complete($pdo,$contact,$payload,$eventId.'|regular-created|'.(string)$result['student_id'],$eventId,[],$deferred);
    }catch(HacheSharkyBusinessException $e){
        $state=isset($state)&&is_array($state)?hache_sharky_orchestrator_clear_flow($state):[];if($state)unset($state['commercial_context']['meta_step']);
        $ageProblem=in_array($e->codeName,['MIN_AGE','MAX_AGE'],true);$message=$ageProblem?'Hache Natación atiende personas de '.$minAge.' a '.$maxAge.' años. Te dejo con el equipo para revisar tu caso.':'No pude completar la inscripción de forma segura. Te dejo con el equipo para revisarlo contigo.';
        $decision=hache_sharky_orchestrator_decision('regular_enrollment_handoff',$message,[],['type'=>'human_takeover']);if($state)hache_sharky_db_state_save($pdo,$contact,$state);
        if(!hache_sharky_lab_mark_handoff_pending($pdo,$eventId)){hache_sharky_db_state_defer_cancel();return false;}
        if(!hache_sharky_takeover_mark($contact,'regular_enrollment_error','Regular enrollment validation failed: '.$e->codeName)){hache_sharky_db_state_defer_cancel();return false;}
        $deferred=hache_sharky_db_state_defer_take();$payload=hache_sharky_outbox_allow_during_takeover(hache_sharky_whatsapp_render($contact,$decision));
        return hache_sharky_lab_queue_and_complete($pdo,$contact,$payload,$eventId.'|regular-error|'.$e->codeName,$eventId,[],is_array($deferred)?$deferred:null);
    }catch(Throwable $e){hache_sharky_db_state_defer_cancel();error_log('[sharky-regular] enrollment processing failed');return false;}finally{hache_sharky_orchestrator_unlock($lock);}
}
