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
expect_adapter(!hache_sharky_whatsapp_answer_asks_slot($guarded,'age'),'WhatsApp no debe insertar la edad como pregunta rutinaria de discovery.');
expect_adapter(hache_sharky_whatsapp_commercial_ready($confirmed),'Programa y sede confirmados deben bastar para cerrar orientación comercial sin exigir edad.');
$offerWithoutAge=hache_sharky_whatsapp_registration_offer_from_context($confirmed,1788382805);
expect_adapter(($offerWithoutAge[0]['flow']['step']??'')==='offer','El intensivo debe poder abrir el offer sin edad capturada.');
expect_adapter(!str_contains($offerWithoutAge[1]['message']??'','años'),'El offer no debe inventar ni exigir edad cuando no fue proporcionada.');

$confirmedAge=$confirmed;$confirmedAge['commercial_context']['age']=43;
$fullyGuarded=hache_sharky_whatsapp_enforce_confirmed_context('¿Intensivo o regular? ¿Palapas o Monteverde?',$confirmedAge);
expect_adapter($fullyGuarded==='Perfecto, ya tengo esos datos.','Con discovery completo no debe inventar otro slot ni reenviar preguntas contradictorias.');
$identityOnce='Claro. Antes de seguir, ¿ya eres alumno de Hache Natación?';
expect_adapter(hache_sharky_whatsapp_answer_asks_slot($identityOnce,'identity'),'Debe detectar cuando el modelo ya incluyó la pregunta de identidad para no duplicarla.');

// Post-discovery: se reutiliza el offer controlado existente y la edad solo se incluye si fue aportada.
expect_adapter(hache_sharky_whatsapp_commercial_ready($confirmedAge),'Prospecto con programa y sede confirmados debe considerarse listo.');
$readyMessage=hache_sharky_whatsapp_commercial_ready_message($confirmedAge);
expect_adapter(str_contains($readyMessage,'curso intensivo')&&str_contains($readyMessage,'Palapas Protudec')&&str_contains($readyMessage,'43 años'),'El resumen puede conservar una edad que el usuario sí proporcionó.');
expect_adapter(str_contains($readyMessage,'horarios')&&str_contains($readyMessage,'inscripción'),'El mensaje contextual debe explicar opciones sin exigir una frase literal.');
[$offerState,$offerDecision]=hache_sharky_whatsapp_registration_offer_from_context($confirmedAge,1788382810);
expect_adapter(($offerState['flow']['name']??'')==='register_intensive'&&($offerState['flow']['step']??'')==='offer','Discovery de intensivo debe abrir el offer controlado.');
expect_adapter(($offerState['flow']['data']['sede_clave']??'')==='PALAPAS','El offer controlado debe reutilizar la sede confirmada.');
expect_adapter(($offerDecision['kind']??'')==='registration_offer','La transición post-discovery debe usar la decisión de registro existente.');
expect_adapter(($offerDecision['ui']['type']??'')==='buttons','El consentimiento debe pedirse con botones interactivos.');
expect_adapter(($offerDecision['ui']['buttons'][0]['id']??'')==='flow:yes','El primer botón debe reutilizar flow:yes.');
expect_adapter(hache_sharky_whatsapp_registration_offer_active($offerState),'El helper debe reconocer el offer de intensivo activo.');
expect_adapter(hache_sharky_whatsapp_offer_affirmation('Si quiero'),'“Si quiero” debe reconocerse como afirmación natural solo para un offer activo.');
expect_adapter(hache_sharky_whatsapp_offer_affirmation('Sí, quiero'),'“Sí, quiero” con coma debe reconocerse.');
expect_adapter(hache_sharky_whatsapp_offer_affirmation('Claro que sí'),'“Claro que sí” debe reconocerse.');
expect_adapter(!hache_sharky_whatsapp_offer_affirmation('Si quiero saber el precio'),'Una frase sustantiva no debe convertirse en consentimiento.');
$offerPayload=hache_sharky_whatsapp_render('529981112233',$offerDecision);
expect_adapter(($offerPayload['type']??'')==='interactive','El offer post-discovery debe renderizarse como WhatsApp interactive.');
expect_adapter(count($offerPayload['interactive']['action']['buttons']??[])===3,'El offer debe mantener Sí, No y Cancelar.');
expect_adapter(hache_sharky_whatsapp_low_information_reengagement('??'),'Solo signos debe tratarse como reenganche, no como nueva conversación libre.');
expect_adapter(hache_sharky_whatsapp_low_information_reengagement('Hola'),'Un saludo con contexto completo debe reenganchar la conversación vigente.');
expect_adapter(!hache_sharky_whatsapp_low_information_reengagement('¿Cuánto cuesta?'),'Una pregunta sustantiva debe seguir llegando al modelo.');
expect_adapter(hache_sharky_whatsapp_turn_is_discovery_only('43'),'Una respuesta corta de discovery sigue siendo reconocible aunque edad ya no sea obligatoria.');
expect_adapter(!hache_sharky_whatsapp_turn_is_discovery_only('43. ¿Cuánto cuesta?'),'Completar un dato junto con una pregunta debe conservar la pregunta para el modelo.');
expect_adapter(!hache_sharky_whatsapp_turn_is_discovery_only('43 cuanto cuesta'),'Una pregunta sin signos tampoco puede ser absorbida por un cierre determinista.');
$reintro=hache_sharky_whatsapp_enforce_no_reintroduction('¡Hola! Soy Sharky, asistente IA de Hache Natación en Cancún.',$confirmedAge,'Hola');
expect_adapter(!str_contains(hache_sharky_orchestrator_normalize($reintro),'soy sharky'),'Una conversación ya encaminada no puede volver a presentarse como primer contacto.');
expect_adapter(str_contains($reintro,'Sigo contigo'),'Si la re-presentación era todo el mensaje debe reemplazarse por reenganche contextual.');
$identityAsked='Soy Sharky, asistente IA de Hache Natación en Cancún.';
expect_adapter(hache_sharky_whatsapp_enforce_no_reintroduction($identityAsked,$confirmedAge,'¿Quién eres?')===$identityAsked,'Si el usuario pregunta explícitamente quién es Sharky, sí puede responder su identidad.');

// Review hardening: detectar repreguntas de una sola opción sin comerse preguntas de otros dominios.
expect_adapter(hache_sharky_whatsapp_question_targets_slot('¿Prefieres el curso intensivo?','program'),'Una repregunta de programa de una sola opción debe detectarse.');
expect_adapter(hache_sharky_whatsapp_question_targets_slot('¿Prefieres tomar clases en Palapas?','sede'),'Una repregunta de sede de una sola opción debe detectarse.');
expect_adapter(!hache_sharky_whatsapp_question_targets_slot('¿Cuál modalidad de pago prefieres?','program'),'Modalidad de pago no debe confundirse con modalidad de curso.');
expect_adapter(!hache_sharky_whatsapp_question_targets_slot('¿Qué clases de tarjeta aceptan?','program'),'Una duda sobre tarjetas no debe clasificarse como discovery de programa.');
$singleGuard=hache_sharky_whatsapp_enforce_confirmed_context('¿Prefieres el curso intensivo?',$confirmedAge);
expect_adapter(!hache_sharky_whatsapp_answer_asks_slot($singleGuard,'program'),'El payload final debe bloquear repreguntas de programa de una sola opción.');
$venueGuard=hache_sharky_whatsapp_enforce_confirmed_context('¿Prefieres tomar clases en Palapas?',$confirmedAge);
expect_adapter(!hache_sharky_whatsapp_answer_asks_slot($venueGuard,'sede'),'El payload final debe bloquear repreguntas de sede de una sola opción.');
$paymentQuestion='Aceptamos transferencia y tarjeta. ¿Cuál modalidad de pago prefieres?';
expect_adapter(hache_sharky_whatsapp_enforce_confirmed_context($paymentQuestion,$confirmedAge)===$paymentQuestion,'Una pregunta legítima de pago debe sobrevivir intacta al enforcement.');
$mixed=hache_sharky_whatsapp_enforce_confirmed_context('El intensivo cuesta $1,200; prefieres intensivo o clases regulares?',$confirmed);
expect_adapter(str_contains($mixed,'cuesta $1,200'),'Una repregunta tras punto y coma no debe borrar la información útil previa.');
expect_adapter(!hache_sharky_whatsapp_answer_asks_slot($mixed,'program'),'La repregunta tras punto y coma debe eliminarse.');
expect_adapter(!hache_sharky_whatsapp_answer_asks_slot($mixed,'age'),'Después de eliminar la repregunta no debe introducirse edad como nuevo interrogatorio.');

// Regla comercial explícita: Hache Natación no ofrece nado libre ni uso de alberca sin clase.
expect_adapter(hache_sharky_whatsapp_nado_libre_request('¿Tienen nado libre?'),'Debe reconocer la frase nado libre.');
expect_adapter(hache_sharky_whatsapp_nado_libre_request('¿Puedo nadar sin clases?'),'Debe reconocer solicitud de nadar sin clases.');
expect_adapter(hache_sharky_whatsapp_nado_libre_request('¿Se puede usar la alberca por mi cuenta?'),'Debe reconocer solicitud de usar la alberca por cuenta propia.');
expect_adapter(!hache_sharky_whatsapp_nado_libre_request('¿Puedo tomar clases regulares?'),'Una pregunta normal sobre clases no debe disparar la regla de nado libre.');
expect_adapter(str_contains(hache_sharky_whatsapp_nado_libre_message(),'no ofrece nado libre'),'La respuesta determinista debe negar explícitamente el nado libre.');

// Capturas productivas 2026-09-03: lluvia, sede natural y preferencia matutina/vespertina.
expect_adapter(hache_sharky_whatsapp_weather_cancellation_request('En caso de lluvia las clases se quedan o se cancelan?'),'Una pregunta de lluvia y cancelación debe interceptarse determinísticamente.');
expect_adapter(hache_sharky_whatsapp_weather_cancellation_request('¿Hay clase si hay tormenta eléctrica?'),'Tormenta eléctrica debe entrar en la política meteorológica.');
expect_adapter(!hache_sharky_whatsapp_weather_cancellation_request('¿Hay clases regulares?'),'Una pregunta normal de clases no debe disparar la política de clima.');
$weather=hache_sharky_whatsapp_weather_cancellation_message();
expect_adapter(str_contains($weather,'lluvia normal no cancela')&&str_contains($weather,'tormenta eléctrica'),'La política debe decir que solo tormenta eléctrica cancela.');
expect_adapter(hache_sharky_whatsapp_detect_venue_preference('Las Palapas me queda mejor')==='PALAPAS','Una preferencia natural con sede antes del verbo debe recordar Palapas.');
expect_adapter(hache_sharky_whatsapp_detect_venue_preference('Monteverde me conviene más')==='MONTEVERDE','Una preferencia natural debe recordar Monteverde.');
expect_adapter(hache_sharky_whatsapp_daypart('Matutino')==='morning','Matutino debe interpretarse como turno de mañana.');
expect_adapter(hache_sharky_whatsapp_daypart('Vespertino')==='evening','Vespertino debe interpretarse como turno posterior al mediodía.');
expect_adapter(hache_sharky_whatsapp_daypart('¿Cuánto cuesta?')===null,'Una pregunta de precio no debe confundirse con preferencia de turno.');

// El prospecto nuevo se guía por experiencia antes de sede.
$prospect=hache_sharky_orchestrator_state(null,1788382820);
$prospect['identity']=array_replace($prospect['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
[$qualifyState,$qualifyDecision]=hache_sharky_whatsapp_qualification_start($prospect,1788382820);
expect_adapter(($qualifyState['flow']['name']??'')==='qualify_prospect'&&($qualifyState['flow']['step']??'')==='swim','Nuevo prospecto debe entrar a calificación por experiencia acuática.');
expect_adapter(str_contains(hache_sharky_orchestrator_normalize((string)($qualifyDecision['message']??'')),'sabes nadar'),'La primera pregunta comercial del nuevo debe ser si ya sabe nadar.');
expect_adapter(!str_contains(hache_sharky_orchestrator_normalize((string)($qualifyDecision['message']??'')),'monteverde'),'La primera pregunta comercial no debe empezar por sede.');
expect_adapter(($qualifyDecision['ui']['buttons'][0]['id']??'')==='qualify:swims'&&($qualifyDecision['ui']['buttons'][1]['id']??'')==='qualify:beginner','La calificación debe ofrecer respuestas claras sobre experiencia.');

$adapterSource=file_get_contents(__DIR__.'/../config/sharky-whatsapp-adapter.php')?:'';
expect_adapter(str_contains($adapterSource,"'nado_libre_unavailable'"),'El adapter debe interceptar nado libre antes de delegar al LLM.');
expect_adapter(str_contains($adapterSource,"'weather_cancellation_policy'"),'El adapter debe interceptar lluvia/tormenta antes de delegar al LLM.');
expect_adapter(str_contains($adapterSource,'hache_sharky_whatsapp_registration_offer_from_context'),'El adapter debe reutilizar el offer controlado al completar discovery.');
expect_adapter(str_contains($adapterSource,"\$event['interactive_id']='flow:yes'"),'Una afirmación natural dentro del offer debe mapearse al consentimiento interactivo existente.');
expect_adapter(str_contains($adapterSource,"'qualify_prospect'"),'La experiencia del prospecto debe quedar en un flujo controlado y persistible.');
expect_adapter(str_contains($adapterSource,"h.regular=1"),'La respuesta Matutino/Vespertino debe usar horarios regulares activos del backend.');
expect_adapter(str_contains($adapterSource,'Antes de seguir, necesito saber una sola cosa: ¿ya eres alumno de Hache Natación?'),'La identificación inicial debe ser una pregunta única y determinista.');
expect_adapter(!str_contains($adapterSource,"in_array((string)(\$decision['kind']??''),['conversation','conversation_identity_prompt'],true)"),'El identity prompt no debe volver a pasar por el LLM para añadir preguntas comerciales.');
expect_adapter(str_contains($adapterSource,'hache_sharky_whatsapp_enforce_no_reintroduction'),'Toda conversación libre debe pasar por el guard contra re-presentaciones tardías.');

// La fuente de verdad del modelo debe coincidir con las nuevas reglas y aceptar system solo en WhatsApp loopback.
$v2Source=file_get_contents(__DIR__.'/../public/api/sharky-v2.php')?:'';
expect_adapter(!str_contains($v2Source,'mensuales; hasta 2 reposiciones según reglas vigentes'),'La fuente de verdad no puede seguir afirmando las 2 reposiciones falsas.');
expect_adapter(str_contains($v2Source,'NO afirmes que el plan de 3 clases por semana incluye “hasta 2 reposiciones”'),'La fuente de verdad debe bloquear expresamente la regla falsa observada.');
expect_adapter(str_contains($v2Source,'Solo se cancelan cuando hay tormenta eléctrica')||str_contains($v2Source,'se cancelan únicamente cuando hay tormenta eléctrica'),'La fuente de verdad debe contener la regla de tormenta eléctrica.');
expect_adapter(str_contains($v2Source,'ORIENTACIÓN DEL PROSPECTO NUEVO'),'La fuente de verdad debe guiar al nuevo por experiencia antes de sede.');
expect_adapter(str_contains($v2Source,'INSTRUCCIONES INTERNAS DEL ORQUESTADOR WHATSAPP'),'El endpoint debe incorporar las instrucciones system confiables del worker.');
expect_adapter(str_contains($v2Source,"if (\$channel === 'whatsapp')")&&str_contains($v2Source,"(\$turn['role'] ?? '') !== 'system'"),'Los turnos system deben aceptarse solo tras resolver canal WhatsApp loopback.');

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
expect_adapter(($birth['age']??0)===26,'El validador de nacimiento sigue vigente para el flujo transaccional hasta la misión separada que lo retire.');
$rejected=false;try{hache_sharky_business_validate_birthdate('2020-01-01',12,'2026-09-02');}catch(HacheSharkyBusinessException $e){$rejected=$e->codeName==='MIN_AGE';}
expect_adapter($rejected,'La protección transaccional de edad no se modifica en este PR.');

$state=hache_sharky_orchestrator_flow(hache_sharky_orchestrator_state(null,1788382800),'identify_student','verify',['return_to'=>'absence'],1788382800);
$resumed=hache_sharky_whatsapp_resume_verified_state($state,['verification'=>['verified'=>true,'student_id'=>'stu-1','name'=>'Ariel','sede_clave'=>'MONTEVERDE','status'=>'ACTIVO']],1788382810);
expect_adapter(($resumed['identity']['verified']??false)===true,'La verificación web debe convertir el contacto en alumno verificado.');
expect_adapter(($resumed['flow']['name']??'')==='absence'&&($resumed['flow']['step']??'')==='offer','Después de verificar debe retomar el flujo de ausencia.');

$controlled=hache_sharky_orchestrator_flow(hache_sharky_orchestrator_state(null,1788382800),'register_intensive','confirm',['name'=>'Juan'],1788382800);
expect_adapter(hache_sharky_whatsapp_is_side_question($controlled,['text'=>'¿Aceptan tarjeta?','interactive_id'=>''])===true,'Una duda durante confirmación debe tratarse como pregunta lateral.');
expect_adapter(hache_sharky_whatsapp_is_side_question($controlled,['text'=>'confirmo','interactive_id'=>''])===false,'Confirmar no debe confundirse con pregunta lateral.');
$qualControlled=hache_sharky_orchestrator_flow($prospect,'qualify_prospect','sede',[],1788382830);
expect_adapter(hache_sharky_whatsapp_is_side_question($qualControlled,['text'=>'¿Dónde está Monteverde?','interactive_id'=>''])===true,'Una duda lateral durante la calificación debe responderse sin perder el camino guiado.');

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


// Captura productiva 20:45–20:46: un cierre comercial nunca puede caer de nuevo a swim discovery.
$closing=hache_sharky_orchestrator_state(null,1788486300);
$closing['identity']=array_replace($closing['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$closing['commercial_context']=array_replace($closing['commercial_context'],['program'=>'intensive','sede_clave'=>'MONTEVERDE']);
$closing=hache_sharky_orchestrator_flow($closing,'qualify_prospect','swim',[],1788486300);
$closing=hache_sharky_whatsapp_reconcile_qualification_context($closing);
expect_adapter(!is_array($closing['flow']??null),'Complete commercial context must invalidate an obsolete qualification flow before a short reply is processed.');
expect_adapter(hache_sharky_whatsapp_payment_choice('El 100')===100,'Payment choice 100% must become a durable confirmation flow.');
expect_adapter(hache_sharky_whatsapp_payment_choice('50%')===50,'Payment choice 50% must be recognized.');
[$payState,$payDecision]=hache_sharky_whatsapp_payment_confirmation_start($closing,100,1788486301);
expect_adapter(($payState['flow']['name']??'')==='commercial_payment'&&($payState['flow']['step']??'')==='confirm','Payment choice must persist as a controlled confirmation flow.');
expect_adapter(hache_sharky_whatsapp_payment_confirmation('Ok'),'Short Ok must confirm only the active durable payment question.');
expect_adapter(hache_sharky_whatsapp_interactive_is_current($payState,['interactive_id'=>'flow:yes']),'Payment confirmation button must be current only while commercial_payment is active.');
$payResult=hache_sharky_whatsapp_payment_flow_input($payState,['interactive_id'=>'flow:yes','text'=>'Ok'],1788486302);
expect_adapter(is_array($payResult),'Payment confirmation must produce a controlled next step.');
expect_adapter(($payResult[0]['commercial_context']['payment_choice_pct']??null)===100,'Confirmed payment percentage must survive in durable commercial context.');
expect_adapter(($payResult[0]['flow']['name']??'')==='register_intensive'&&($payResult[0]['flow']['step']??'')==='offer','Ok after 100% must advance to registration offer, never restart swim discovery.');

// Visible non-mutating qualification buttons may recover after the 30-minute flow TTL, but only for a bounded window.
$expired=hache_sharky_orchestrator_state(null,1788480000);
$expired['identity']=array_replace($expired['identity'],['kind'=>'prospect','verified'=>true,'source'=>'self_declared']);
$expired['updated_at']=1788480000;
$recovered=hache_sharky_whatsapp_recover_safe_qualification_interactive($expired,['interactive_id'=>'qualify:swims','text'=>'Ya sé nadar'],1788485160);
expect_adapter(($recovered['flow']['name']??'')==='qualify_prospect'&&($recovered['flow']['step']??'')==='swim','A still-visible Ya sé nadar button must recover safely after TTL expiration.');
$tooOld=hache_sharky_whatsapp_recover_safe_qualification_interactive($expired,['interactive_id'=>'qualify:swims','text'=>'Ya sé nadar'],1788502001);
expect_adapter(!is_array($tooOld['flow']??null),'Ancient qualification buttons must not be revived indefinitely.');
$mutating=hache_sharky_whatsapp_recover_safe_qualification_interactive($expired,['interactive_id'=>'flow:confirm','text'=>'Confirmar'],1788485160);
expect_adapter(!is_array($mutating['flow']??null),'Mutation/confirmation buttons must never be reconstructed after their flow expires.');

$runtimeSource=file_get_contents(__DIR__.'/../config/sharky-runtime.php')?:'';
expect_adapter(str_contains($runtimeSource,'function hache_sharky_usage_record')&&str_contains($runtimeSource,'function hache_sharky_usage_for_contact'),'Real OpenAI token usage must be persistable per hashed conversation.');
expect_adapter(str_contains($v2Source,"'input_tokens'=>")&&str_contains($v2Source,"'response_status'=>$responseStatus"),'Responses API status and real token counters must be surfaced to the internal WhatsApp caller.');

echo "SHARKY_WHATSAPP_ADAPTER_REGRESSION_OK\n";
