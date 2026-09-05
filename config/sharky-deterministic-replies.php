<?php

declare(strict_types=1);

require_once __DIR__.'/sharky-runtime.php';

function hache_sharky_deterministic_normalize(string $text): string
{
    if (function_exists('hache_sharky_orchestrator_normalize')) return hache_sharky_orchestrator_normalize($text);
    $text=mb_strtolower(trim($text),'UTF-8');
    return strtr($text,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
}

function hache_sharky_deterministic_commercial(array $state): ?array
{
    $commercial=is_array($state['commercial_context']??null)?$state['commercial_context']:[];
    $program=(string)($commercial['program']??'');
    $sede=(string)($commercial['sede_clave']??'');
    if(!in_array($program,['intensive','regular'],true)||!in_array($sede,['MONTEVERDE','PALAPAS'],true))return null;
    return ['program'=>$program,'sede'=>$sede];
}

function hache_sharky_deterministic_sede_label(string $sede): string
{
    return $sede==='MONTEVERDE'?'Colegio Monteverde':'Palapas Protudec';
}

function hache_sharky_deterministic_detect_explicit_sede(string $text): ?string
{
    $t=hache_sharky_deterministic_normalize($text);
    $mv=preg_match('/\bmonteverde\b/u',$t)===1;
    $pal=preg_match('/\bpalapas(?:\s+protudec)?\b/u',$t)===1;
    if($mv&&!$pal)return 'MONTEVERDE';
    if($pal&&!$mv)return 'PALAPAS';
    return null;
}

function hache_sharky_deterministic_schedule_request(string $text): bool
{
    $t=hache_sharky_deterministic_normalize($text);
    return preg_match('/\b(horario|horarios|hora|horas)\b/u',$t)===1
        && preg_match('/\b(?:de|a|desde)\s*\d{1,2}(?::\d{2})?\s*(?:a|-)\s*\d{1,2}(?::\d{2})?\b/u',$t)!==1;
}

function hache_sharky_deterministic_price_request(string $text): bool
{
    $t=hache_sharky_deterministic_normalize($text);
    return preg_match('/\b(precio|precios|costo|costos|cuesta|cuestan|mensualidad|cuanto\s+sale|cuanto\s+es)\b/u',$t)===1;
}

function hache_sharky_deterministic_location_request(string $text): bool
{
    $t=hache_sharky_deterministic_normalize($text);
    return preg_match('/\b(ubicacion|direccion|maps|mapa|donde\s+queda|donde\s+esta|como\s+llego|como\s+llegar|mandame\s+la\s+ubicacion|enviame\s+la\s+ubicacion)\b/u',$t)===1;
}

function hache_sharky_deterministic_location_followup_request(string $text,array $state): bool
{
    if(hache_sharky_deterministic_detect_explicit_sede($text)===null)return false;
    $t=hache_sharky_deterministic_normalize($text);
    if(preg_match('/^[¿?¡!\s]*(?:y\s+)?(?:la\s+)?(?:de\s+)?(?:monteverde|palapas(?:\s+protudec)?)[?!.¿¡ ]*$/u',$t)!==1)return false;
    $previous=trim((string)($state['previous_user_text']??''));
    return $previous!==''&&hache_sharky_deterministic_location_request($previous);
}

function hache_sharky_deterministic_route_followup(string $text): bool
{
    $t=hache_sharky_deterministic_normalize($text);
    return preg_match('/^(?:en\s+)?(?:coche|carro|auto|automovil|caminando|a\s+pie)[.! ]*$/u',$t)===1;
}

function hache_sharky_deterministic_time_range(string $text): ?array
{
    $t=hache_sharky_deterministic_normalize($text);
    if(preg_match('/\b(?:de|desde)?\s*(\d{1,2})(?::(\d{2}))?\s*(?:a|-)\s*(\d{1,2})(?::(\d{2}))?\b/u',$t,$m)!==1)return null;
    $h1=(int)$m[1];$m1=(isset($m[2])&&$m[2]!=='')?(int)$m[2]:0;
    $h2=(int)$m[3];$m2=(isset($m[4])&&$m[4]!=='')?(int)$m[4]:0;
    if($h1>23||$h2>23||$m1>59||$m2>59)return null;
    return [sprintf('%02d:%02d',$h1,$m1),sprintf('%02d:%02d',$h2,$m2)];
}

function hache_sharky_deterministic_active_schedules(PDO $pdo,string $program,string $sede): array
{
    $flag=$program==='regular'?'regular':'intensivo';
    $sql="SELECT h.hora_inicio,h.hora_fin FROM horarios h JOIN sedes s ON s.id=h.sede_id WHERE s.clave=:c AND s.activo=1 AND h.activo=1 AND h.$flag=1 ORDER BY h.hora_inicio";
    $st=$pdo->prepare($sql);$st->execute([':c'=>$sede]);
    $out=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
        $start=substr((string)($row['hora_inicio']??''),0,5);$end=substr((string)($row['hora_fin']??''),0,5);
        if($start===''||$end==='')continue;
        $out[]=$start.'–'.$end;
    }
    return array_values(array_unique($out));
}

function hache_sharky_deterministic_schedule_message(array $state): ?string
{
    $commercial=hache_sharky_deterministic_commercial($state);if($commercial===null)return null;
    $pdo=hache_sharky_pdo();if(!$pdo instanceof PDO)return 'No pude consultar los horarios activos en este momento. Prefiero no inventarte datos; intenta de nuevo en unos minutos.';
    try{$hours=hache_sharky_deterministic_active_schedules($pdo,$commercial['program'],$commercial['sede']);}
    catch(Throwable $e){return 'No pude consultar los horarios activos en este momento. Prefiero no inventarte datos; intenta de nuevo en unos minutos.';}
    $label=hache_sharky_deterministic_sede_label($commercial['sede']);
    $programLabel=$commercial['program']==='regular'?'clases regulares':'curso intensivo';
    if(!$hours)return 'Ahora mismo no encuentro horarios activos de '.$programLabel.' en '.$label.'. Si quieres, puedo dejarte con el equipo para revisarlo.';
    return '🕐 Horarios vigentes de '.$programLabel.' en '.$label.':'."\n\n".implode("\n",array_map(static fn(string $h):string=>'• '.$h,$hours));
}

function hache_sharky_deterministic_price_message(array $state): ?string
{
    $commercial=hache_sharky_deterministic_commercial($state);if($commercial===null)return null;
    $pdo=hache_sharky_pdo();$business=hache_sharky_business_values($pdo instanceof PDO?$pdo:null);
    if($commercial['program']==='intensive'){
        $selected=$state['selected_course_price']??null;
        $price=is_numeric($selected)?(float)$selected:(float)hache_sharky_config_int($business,'sharky_precio_intensivo',1200,0,100000);
        $priceText=rtrim(rtrim(number_format($price,2,'.',','),'0'),'.');
        $label=is_numeric($selected)?'Precio del curso seleccionado':'Precio general';
        return '💰 Curso intensivo'."\n\n".'• '.$label.': $'.$priceText.' MXN'."\n".'• Duración: 3 semanas, lunes a viernes'."\n".'• No cobra inscripción.';
    }
    $p3=hache_sharky_config_int($business,'sharky_precio_regular_3',1000,0,100000);
    $p5=hache_sharky_config_int($business,'sharky_precio_regular_5',1200,0,100000);
    $feeKey=$commercial['sede']==='MONTEVERDE'?'sharky_inscripcion_monteverde':'sharky_inscripcion_palapas';
    $fee=hache_sharky_config_int($business,$feeKey,$commercial['sede']==='MONTEVERDE'?500:400,0,100000);
    $label=hache_sharky_deterministic_sede_label($commercial['sede']);
    return '💰 Clases regulares en '.$label.':'."\n\n".'• 3 clases por semana: $'.number_format($p3,0,'.',',').' MXN mensuales'."\n".'• 5 clases por semana: $'.number_format($p5,0,'.',',').' MXN mensuales'."\n".'• Inscripción para entrada directa: $'.number_format($fee,0,'.',',').' MXN.';
}

function hache_sharky_deterministic_location_message(string $text,array $state): ?string
{
    $sede=hache_sharky_deterministic_detect_explicit_sede($text);
    if($sede===null){$commercial=hache_sharky_deterministic_commercial($state);$sede=$commercial['sede']??null;}
    if(!in_array($sede,['MONTEVERDE','PALAPAS'],true))return 'Claro. ¿Necesitas la ubicación de Colegio Monteverde o la de Palapas Protudec?';
    $pdo=hache_sharky_pdo();$business=hache_sharky_business_values($pdo instanceof PDO?$pdo:null);
    $key=$sede==='MONTEVERDE'?'sharky_maps_monteverde':'sharky_maps_palapas';
    $url=trim((string)($business[$key]??''));
    if($url===''||filter_var($url,FILTER_VALIDATE_URL)===false)return 'No tengo una ubicación válida configurada para esa sede en este momento. Te dejo con el equipo para confirmarla.';
    return '📍 '.hache_sharky_deterministic_sede_label($sede).' (Hache Natación)'."\n\n".$url."\n\n".'Abre el enlace para ver indicaciones desde tu ubicación.';
}

function hache_sharky_deterministic_schedule_selection_message(string $text,array $state): ?string
{
    $range=hache_sharky_deterministic_time_range($text);if($range===null)return null;
    $commercial=hache_sharky_deterministic_commercial($state);if($commercial===null)return null;
    $pdo=hache_sharky_pdo();if(!$pdo instanceof PDO)return null;
    try{$hours=hache_sharky_deterministic_active_schedules($pdo,$commercial['program'],$commercial['sede']);}catch(Throwable $e){return null;}
    $needle=$range[0].'–'.$range[1];
    if(!in_array($needle,$hours,true))return null;
    $label=hache_sharky_deterministic_sede_label($commercial['sede']);
    $programLabel=$commercial['program']==='regular'?'clases regulares':'curso intensivo';
    return 'Sí, el horario '.$needle.' está activo para '.$programLabel.' en '.$label.'. Si quieres, te digo el precio o continuamos con el siguiente paso.';
}

function hache_sharky_deterministic_reply(string $text,array $state,array $context=[]): ?string
{
    if(hache_sharky_deterministic_location_request($text)||hache_sharky_deterministic_location_followup_request($text,$state))return hache_sharky_deterministic_location_message($text,$state);
    if(hache_sharky_deterministic_schedule_request($text))return hache_sharky_deterministic_schedule_message($state);
    if(hache_sharky_deterministic_price_request($text))return hache_sharky_deterministic_price_message($state);
    $scheduleSelection=hache_sharky_deterministic_schedule_selection_message($text,$state);if($scheduleSelection!==null)return $scheduleSelection;
    if(hache_sharky_deterministic_route_followup($text)){
        $commercial=hache_sharky_deterministic_commercial($state);
        if($commercial!==null)return 'Para la ruta en coche o caminando, abre Google Maps desde la ubicación de la sede; Sharky no calcula rutas en tiempo real.';
    }
    return null;
}

function hache_sharky_reply_looks_incomplete(string $answer): bool
{
    $answer=trim($answer);if($answer==='')return true;
    $plain=preg_replace('/[^\p{L}\p{N}:]+/u',' ',hache_sharky_deterministic_normalize($answer))??'';
    $plain=trim(preg_replace('/\s+/u',' ',$plain)??'');
    if($plain==='')return true;
    if(str_ends_with(rtrim($answer),':'))return true;
    $words=preg_split('/\s+/u',preg_replace('/[^\p{L}\p{N} ]+/u',' ',$plain)??'',-1,PREG_SPLIT_NO_EMPTY)?:[];
    if(count($words)<=4&&preg_match('/\b(hola|claro|perfecto|listo|entendido|ok|oki|vale)\b/u',$plain)===1)return true;
    foreach(preg_split('/\R/u',$answer)?:[] as $line){if(trim($line)==='•')return true;}
    return false;
}