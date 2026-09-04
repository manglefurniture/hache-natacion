<?php

declare(strict_types=1);

require_once __DIR__.'/../../config/rate-limit.php';
require_once __DIR__.'/../../config/sharky-runtime.php';
require_once __DIR__.'/../../config/sharky-deterministic-replies.php';

function hache_sharky_dispatcher_is_loopback_whatsapp(array $data): bool
{
    $remote=trim((string)($_SERVER['REMOTE_ADDR']??''));
    if(!in_array($remote,['127.0.0.1','::1'],true))return false;
    return strtolower(trim((string)($data['channel']??'')))==='whatsapp';
}

function hache_sharky_dispatcher_state_from_history(array $data): array
{
    $state=[
        'identity'=>['kind'=>'prospect'],
        'commercial_context'=>['program'=>null,'sede_clave'=>null,'age'=>null],
        'assistant_presentation_queued'=>false,
        'previous_user_text'=>'',
        'selected_course_price'=>null,
    ];
    foreach(array_slice(is_array($data['history']??null)?$data['history']:[],-12) as $turn){
        if(!is_array($turn))continue;
        $role=(string)($turn['role']??'');
        $content=trim((string)($turn['content']??''));
        if($role==='assistant'&&$content!=='')$state['assistant_presentation_queued']=true;
        if($role==='user'&&$content!==''){$state['previous_user_text']=$content;continue;}
        if($role!=='system'||$content==='')continue;
        if(preg_match('/Precio del curso intensivo seleccionado en backend:\s*\$([0-9]+(?:\.[0-9]+)?)/u',$content,$pm)===1)$state['selected_course_price']=(float)$pm[1];
        $t=hache_sharky_deterministic_normalize($content);
        if(str_contains($t,'programa: curso intensivo'))$state['commercial_context']['program']='intensive';
        elseif(str_contains($t,'programa: clases regulares'))$state['commercial_context']['program']='regular';
        if(str_contains($t,'sede: palapas protudec'))$state['commercial_context']['sede_clave']='PALAPAS';
        elseif(str_contains($t,'sede: monteverde'))$state['commercial_context']['sede_clave']='MONTEVERDE';
        if(preg_match('/\bedad:\s*(\d{1,3})\s+anos\b/u',$t,$m)===1){$age=(int)$m[1];if($age>=1&&$age<=120)$state['commercial_context']['age']=$age;}
    }
    return $state;
}

function hache_sharky_dispatcher_conversation_underway(array $state): bool
{
    if(($state['assistant_presentation_queued']??false)===true)return true;
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    return in_array(($commercial['program']??null),['intensive','regular'],true)
        || in_array(($commercial['sede_clave']??null),['MONTEVERDE','PALAPAS'],true);
}

function hache_sharky_dispatcher_clean_model_answer(string $answer,bool $conversationUnderway): string
{
    $answer=str_replace(["\r\n","\r"],"\n",trim($answer));
    $answer=preg_replace('/^\s*[•·]\s*$/mu','',$answer)??$answer;
    $answer=preg_replace('/\n{3,}/u',"\n\n",$answer)??$answer;
    if($conversationUnderway){
        $answer=preg_replace('/^\s*[¡!¿?]*\s*(?:hola|holi|buenas)\s*[!¡.,🙂😊👋🦈]*\s*(?:\n+|\s{2,})?/iu','',trim($answer),1)??$answer;
        $answer=trim($answer);
    }
    return $answer;
}

function hache_sharky_dispatcher_out(array $body,int $status=200): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($status);
    echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$raw=(string)file_get_contents('php://input');
$data=json_decode($raw,true);
if(!is_array($data)||!hache_sharky_dispatcher_is_loopback_whatsapp($data)){
    require __DIR__.DIRECTORY_SEPARATOR.'sharky-v2.php';
}

$state=hache_sharky_dispatcher_state_from_history($data);
$message=trim((string)($data['message']??''));
$deterministic=$message!==''?hache_sharky_deterministic_reply($message,$state):null;
if(is_string($deterministic)&&trim($deterministic)!==''){
    $rate=security_rate_limit_record('sharky-internal-whatsapp','loopback',300,300);
    if(!$rate['allowed']){
        header('Retry-After: '.max(1,(int)$rate['retry_after']));
        hache_sharky_dispatcher_out(['ok'=>false,'error'=>'Sharky recibió demasiados mensajes seguidos. Espera unos minutos e intenta otra vez.'],429);
    }
    hache_sharky_metric_increment('answers_whatsapp');
    hache_sharky_metric_increment('deterministic_replies');
    hache_sharky_dispatcher_out(['ok'=>true,'answer'=>trim($deterministic),'channel'=>'whatsapp','source'=>'deterministic']);
}

$underway=hache_sharky_dispatcher_conversation_underway($state);
ob_start(static function(string $buffer) use ($underway): string {
    $body=json_decode($buffer,true);
    if(!is_array($body)||($body['ok']??false)!==true||!isset($body['answer']))return $buffer;
    $answer=hache_sharky_dispatcher_clean_model_answer((string)$body['answer'],$underway);
    $responseIncomplete=(string)($body['response_status']??'completed')==='incomplete';
    if($responseIncomplete||hache_sharky_reply_looks_incomplete($answer)){
        $answer='No quiero dejarte una respuesta a medias. Dime de nuevo qué dato necesitas y te respondo completo.';
        hache_sharky_metric_increment('guarded_incomplete_answers');
    }
    $body['answer']=$answer;
    $body['guarded']=true;
    return json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:$buffer;
});

require __DIR__.DIRECTORY_SEPARATOR.'sharky-v2.php';
