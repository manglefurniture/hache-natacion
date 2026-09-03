<?php

declare(strict_types=1);

require __DIR__.'/../config/sharky-whatsapp-adapter.php';

function expect_adapter(bool $ok,string $message): void{if(!$ok){fwrite(STDERR,$message."\n");exit(1);}}

$payload=['entry'=>[['changes'=>[['value'=>['metadata'=>['phone_number_id'=>'pn1'],'messages'=>[
    ['id'=>'m1','from'=>'5219981112233','timestamp'=>'1788380000','type'=>'text','text'=>['body'=>'Hola'],'referral'=>['source_type'=>'ad','source_id'=>'ad-77','ctwa_clid'=>'clid-1','headline'=>'Intensivo septiembre']],
    ['id'=>'m2','from'=>'5219981112233','timestamp'=>'1788380001','type'=>'interactive','interactive'=>['type'=>'button_reply','button_reply'=>['id'=>'identity:student','title'=>'Ya soy alumno']]],
    ['id'=>'m3','from'=>'5219981112233','timestamp'=>'1788380002','type'=>'interactive','interactive'=>['type'=>'list_reply','list_reply'=>['id'=>'course:abc','title'=>'07/09/2026']]],
]]]]]]];
$events=hache_sharky_whatsapp_extract($payload);
expect_adapter(count($events)===3,'Debe extraer texto, botón y lista.');
expect_adapter(($events[0]['referral']['source_id']??'')==='ad-77','Debe conservar referral del anuncio.');
expect_adapter(($events[1]['interactive_id']??'')==='identity:student','Debe conservar el id del botón.');
expect_adapter(($events[2]['interactive_id']??'')==='course:abc','Debe conservar el id de lista.');

$clean=hache_sharky_whatsapp_clean_answer("Hola\nHola\n\nPrecio: 1200\nPrecio: 1200");
expect_adapter(substr_count($clean,'Hola')===1,'Debe quitar líneas repetidas consecutivas.');
expect_adapter(substr_count($clean,'Precio: 1200')===1,'Debe evitar repetición semántica exacta consecutiva.');
expect_adapter(mb_strlen(hache_sharky_whatsapp_clean_answer(str_repeat('texto largo. ',300)))<=1401,'Debe limitar una respuesta excesivamente larga.');

$confirmed=hache_sharky_orchestrator_state(null,1788382800);
$confirmed['identity']=array_replace($confirmed['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$confirmed['commercial_context']=array_replace($confirmed['commercial_context'],['program'=>'intensive','sede_clave'=>'PALAPAS']);
$guarded=hache_sharky_whatsapp_enforce_confirmed_context('El intensivo cuesta $1,200. ¿Buscas intensivo o clases regulares? ¿En qué sede: Monteverde o Palapas?',$confirmed);
expect_adapter(str_contains($guarded,'cuesta $1,200'),'El enforcement debe conservar la respuesta útil del modelo.');
expect_adapter(!hache_sharky_whatsapp_answer_asks_slot($guarded,'program'),'El payload final no puede volver a preguntar un programa confirmado.');
expect_adapter(!hache_sharky_whatsapp_answer_asks_slot($guarded,'sede'),'El payload final no puede volver a preguntar una sede confirmada.');
expect_adapter(hache_sharky_whatsapp_answer_asks_slot($guarded,'age'),'Tras bloquear preguntas repetidas debe continuar con el único slot realmente pendiente.');
$confirmedAge=$confirmed;$confirmedAge['commercial_context']['age']=43;
$fullyGuarded=hache_sharky_whatsapp_enforce_confirmed_context('¿Intensivo o regular? ¿Palapas o Monteverde?',$confirmedAge);
expect_adapter($fullyGuarded==='Perfecto, ya tengo esos datos.','Con discovery completo no debe inventar otro slot ni reenviar preguntas contradictorias.');
$identityOnce='Claro. Antes de seguir, ¿ya eres alumno de Hache Natación?';
expect_adapter(hache_sharky_whatsapp_answer_asks_slot($identityOnce,'identity'),'Debe detectar cuando el modelo ya incluyó la pregunta de identidad para no duplicarla.');

$decision=hache_sharky_orchestrator_decision('x','Elige',['type'=>'buttons','buttons'=>[
    hache_sharky_orchestrator_button('a','Uno'),hache_sharky_orchestrator_button('b','Dos'),hache_sharky_orchestrator_button('c','Tres'),hache_sharky_orchestrator_button('d','Cuatro')
]]);
$render=hache_sharky_whatsapp_render('529981112233',$decision);
expect_adapter(($render['type']??'')==='interactive','Los botones deben producir payload interactivo.');
expect_adapter(count($render['interactive']['action']['buttons']??[])===3,'WhatsApp debe recibir máximo tres botones.');

$listOptions=[];for($i=1;$i<=12;$i++)$listOptions[]=['id'=>'x'.$i,'title'=>'Opción '.$i,'description'=>'Descripción'];
$render=hache_sharky_whatsapp_render('529981112233',hache_sharky_orchestrator_decision('x','Elige',['type'=>'list','options'=>$listOptions]));
expect_adapter(count($render['interactive']['action']['sections'][0]['rows']??[])===10,'La lista debe limitarse a diez opciones.');

$birth=hache_sharky_business_validate_birthdate('2000-01-01',12,'2026-09-02');
expect_adapter(($birth['age']??0)===26,'Debe calcular la edad de forma determinista.');
$rejected=false;try{hache_sharky_business_validate_birthdate('2020-01-01',12,'2026-09-02');}catch(HacheSharkyBusinessException $e){$rejected=$e->codeName==='MIN_AGE';}
expect_adapter($rejected,'Debe rechazar menores antes de emitir una acción.');

$state=hache_sharky_orchestrator_flow(hache_sharky_orchestrator_state(null,1788382800),'identify_student','verify',['return_to'=>'absence'],1788382800);
$resumed=hache_sharky_whatsapp_resume_verified_state($state,['verification'=>['verified'=>true,'student_id'=>'stu-1','name'=>'Ariel','sede_clave'=>'MONTEVERDE','status'=>'ACTIVO']],1788382810);
expect_adapter(($resumed['identity']['verified']??false)===true,'La verificación web debe convertir el contacto en alumno verificado.');
expect_adapter(($resumed['flow']['name']??'')==='absence'&&($resumed['flow']['step']??'')==='offer','Después de verificar debe retomar el flujo de ausencia.');

$controlled=hache_sharky_orchestrator_flow(hache_sharky_orchestrator_state(null,1788382800),'register_intensive','confirm',['name'=>'Juan'],1788382800);
expect_adapter(hache_sharky_whatsapp_is_side_question($controlled,['text'=>'¿Aceptan tarjeta?','interactive_id'=>''])===true,'Una duda durante confirmación debe tratarse como pregunta lateral.');
expect_adapter(hache_sharky_whatsapp_is_side_question($controlled,['text'=>'confirmo','interactive_id'=>''])===false,'Confirmar no debe confundirse con pregunta lateral.');

$freshPresentationState=hache_sharky_orchestrator_state(null,1788382800);
expect_adapter(($freshPresentationState['assistant_presentation_queued']??true)===false,'Una conversación nueva no debe asumir que Sharky ya se presentó.');
$flowBeforePresentation=hache_sharky_orchestrator_flow($freshPresentationState,'register_intensive','offer',[],1788382810);
expect_adapter(($flowBeforePresentation['assistant_presentation_queued']??true)===false,'Abrir un flujo controlado no equivale a haber encolado la presentación de Sharky.');
$restoredPresentationState=hache_sharky_orchestrator_state(['assistant_presentation_queued'=>true],1788382820);
expect_adapter(($restoredPresentationState['assistant_presentation_queued']??false)===true,'La marca durable de presentación encolada debe sobrevivir la normalización del estado.');

$labSource=file_get_contents(__DIR__.'/../config/sharky-lab-worker.php')?:'';
expect_adapter(str_contains($labSource,'function hache_sharky_lab_presentation_queued'),'El lab debe consultar una marca durable de presentación encolada.');
expect_adapter(str_contains($labSource,'function hache_sharky_lab_answer_contains_presentation'),'El lab debe reconocer la presentación real antes de marcarla.');
expect_adapter(str_contains($labSource,'function hache_sharky_lab_mark_presentation_queued'),'La presentación debe marcarse sobre el estado diferido que comparte frontera con el outbox.');
expect_adapter(!str_contains($labSource,"count(\$seen)>1||is_array(\$state['flow']??null)"),'Mensajes vistos o un flujo activo no deben fingir que la presentación ya salió.');
expect_adapter(str_contains($labSource,"if(hache_sharky_lab_presentation_queued(\$state))\$history[]=['role'=>'assistant','content'=>'Ya me presenté como Sharky; la conversación ya está en curso.'];"),'Solo una presentación ya encolada debe suprimir el saludo de turnos posteriores.');
$markPos=strpos($labSource,'$deferredState=hache_sharky_lab_mark_presentation_queued($deferredState,$out);');
$queuePos=$markPos===false?false:strpos($labSource,'return hache_sharky_lab_queue_and_complete($pdo,$contact,$out',$markPos);
expect_adapter($markPos!==false&&$queuePos!==false&&$markPos<$queuePos,'La marca debe decidirse desde el payload final y persistirse en la misma frontera que lo encola.');

echo "SHARKY_WHATSAPP_ADAPTER_REGRESSION_OK\n";
