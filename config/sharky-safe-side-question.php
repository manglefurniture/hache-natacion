<?php

declare(strict_types=1);

function hache_sharky_safe_side_normalize(string $text): string
{
    if(function_exists('hache_sharky_orchestrator_normalize'))return hache_sharky_orchestrator_normalize($text);
    $text=mb_strtolower(trim($text),'UTF-8');
    return strtr($text,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
}

function hache_sharky_safe_side_business_values(PDO $pdo): array
{
    if(!function_exists('hache_sharky_business_values'))require_once __DIR__.'/sharky-runtime.php';
    return function_exists('hache_sharky_business_values')?hache_sharky_business_values($pdo):[];
}

function hache_sharky_safe_side_sensitive_request(string $text): bool
{
    $t=hache_sharky_safe_side_normalize($text);
    return preg_match('/\b(?:inscribir|inscribeme|inscribirme|registrar|registrame|registrarme|cobra(?:me|r)?|paga(?:me|r)?|cancelame|cancelar|repone(?:r|me)?|cambia(?:r|me)?\s+(?:mi\s+)?(?:dato|datos|producto|sede|horario|turno))\b/u',$t)===1;
}

function hache_sharky_safe_side_program(array $state,string $text=''): string
{
    $t=hache_sharky_safe_side_normalize($text);
    $intensive=preg_match('/\b(?:intensivo|curso\s+intensivo|aprende\s+a\s+nadar)\b/u',$t)===1;
    $regular=preg_match('/\b(?:regular|clases\s+regulares)\b/u',$t)===1;
    if($intensive&&!$regular)return 'intensive';
    if($regular&&!$intensive)return 'regular';
    $program=(string)($state['commercial_context']['program']??'');
    return in_array($program,['intensive','regular'],true)?$program:'';
}

function hache_sharky_safe_side_venue(array $state,string $text): string
{
    $t=hache_sharky_safe_side_normalize($text);
    $monteverde=preg_match('/\bmonteverde\b/u',$t)===1;
    $palapas=preg_match('/\bpalapas(?:\s+protudec)?\b/u',$t)===1;
    if($monteverde&&!$palapas)return 'MONTEVERDE';
    if($palapas&&!$monteverde)return 'PALAPAS';
    $sede=(string)($state['commercial_context']['sede_clave']??'');
    return in_array($sede,['MONTEVERDE','PALAPAS'],true)?$sede:'';
}

function hache_sharky_safe_side_schedules(PDO $pdo,string $program,string $sede): array
{
    if(!in_array($program,['intensive','regular'],true)||!in_array($sede,['MONTEVERDE','PALAPAS'],true))return [];
    $column=$program==='regular'?'regular':'intensivo';
    try{
        $st=$pdo->prepare("SELECT h.hora_inicio,h.hora_fin FROM horarios h JOIN sedes s ON s.id=h.sede_id WHERE s.clave=:s AND s.activo=1 AND h.activo=1 AND h.$column=1 ORDER BY h.hora_inicio,h.id");
        $st->execute([':s'=>$sede]);$out=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            $start=substr((string)($row['hora_inicio']??''),0,5);$end=substr((string)($row['hora_fin']??''),0,5);
            if($start!==''&&$end!=='')$out[]=$start.'–'.$end;
        }
        return array_values(array_unique($out));
    }catch(Throwable $e){error_log('[sharky-safe-side] schedule lookup failed');return [];}
}

function hache_sharky_safe_side_regular_prices(PDO $pdo,string $sede): array
{
    if(!in_array($sede,['MONTEVERDE','PALAPAS'],true))return [];
    try{
        $st=$pdo->prepare('SELECT p.sesiones_semana,p.precio FROM planes p JOIN sedes s ON s.id=p.sede_id WHERE s.clave=:s AND s.activo=1 AND p.activo=1 ORDER BY p.sesiones_semana,p.precio,p.id');
        $st->execute([':s'=>$sede]);$out=[];
        foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row){
            if(!is_numeric($row['precio']??null))continue;
            $sessions=(int)($row['sesiones_semana']??0);$price=(float)$row['precio'];
            $out[]=$sessions.' veces por semana: $'.number_format($price,0,'.',',').' MXN al mes';
        }
        return array_values(array_unique($out));
    }catch(Throwable $e){error_log('[sharky-safe-side] plan lookup failed');return [];}
}

function hache_sharky_safe_side_answer(PDO $pdo,array $state,string $text): ?string
{
    $text=trim($text);if($text===''||hache_sharky_safe_side_sensitive_request($text))return null;
    $t=hache_sharky_safe_side_normalize($text);$program=hache_sharky_safe_side_program($state,$text);$sede=hache_sharky_safe_side_venue($state,$text);

    if(preg_match('/\b(?:precio|precios|costo|costos|cuesta|cuestan|cuanto\s+(?:cuesta|sale|vale)|mensual|mensualidad|al mes)\b/u',$t)===1){
        if($program==='intensive'){
            $business=hache_sharky_safe_side_business_values($pdo);$price=$business['sharky_precio_intensivo']??null;
            if(!is_numeric($price))return '💬 No tengo un precio confirmado disponible en este momento; prefiero no inventarlo.';
            return '💰 El curso intensivo cuesta $'.number_format((float)$price,0,'.',',').' MXN por las 3 semanas completas, de lunes a viernes. No es mensualidad y no lleva inscripción.';
        }
        if($program==='regular'&&$sede!==''){
            $prices=hache_sharky_safe_side_regular_prices($pdo,$sede);
            if($prices)return '💰 Mensualidades vigentes en '.($sede==='MONTEVERDE'?'Colegio Monteverde':'Palapas Protudec').":\n• ".implode("\n• ",$prices);
        }
        return '💬 El precio depende del producto'.($program==='regular'?' y de la sede':'').' que elijas. Primero conserva esa selección y te mostraré únicamente el precio confirmado.';
    }

    if(preg_match('/\b(?:duracion|cuanto dura|cuantas semanas|cuantos dias)\b/u',$t)===1){
        if($program==='regular')return '📅 Las clases regulares se manejan por mensualidad; la frecuencia depende del plan confirmado.';
        if($program==='intensive')return '📅 El curso intensivo dura 3 semanas, con clases de lunes a viernes.';
        return '📅 El curso intensivo dura 3 semanas, de lunes a viernes. Las clases regulares se manejan por mensualidad; la duración depende del producto que elijas.';
    }

    if(preg_match('/\b(?:ubicacion|ubicaciones|direccion|direcciones|donde|maps|mapa|como llego|como llegar)\b/u',$t)===1){
        if($sede==='')return '📍 Tenemos Colegio Monteverde y Palapas Protudec en Cancún. Elige la sede y te mostraré su ubicación confirmada sin cambiar ninguna otra selección.';
        $business=hache_sharky_safe_side_business_values($pdo);$key=$sede==='MONTEVERDE'?'sharky_maps_monteverde':'sharky_maps_palapas';$url=trim((string)($business[$key]??''));
        if($url===''||filter_var($url,FILTER_VALIDATE_URL)===false)return '💬 No tengo una ubicación confirmada disponible para esa sede en este momento; prefiero no inventarla.';
        return '📍 '.($sede==='MONTEVERDE'?'Colegio Monteverde':'Palapas Protudec').":\n".$url;
    }

    if(preg_match('/\b(?:horario|horarios|hora|horas|turno|turnos|matutino|vespertino|manana|tarde|noche)\b/u',$t)===1){
        if($program===''||$sede==='')return '🕒 Los horarios dependen del producto y de la sede. Cuando ambas selecciones estén confirmadas te mostraré solo los horarios vigentes.';
        $hours=hache_sharky_safe_side_schedules($pdo,$program,$sede);
        if(!$hours)return '💬 No encuentro horarios activos confirmados para esa combinación en este momento; prefiero no inventarlos.';
        return '🕒 Horarios vigentes de '.($program==='regular'?'clases regulares':'curso intensivo').' en '.($sede==='MONTEVERDE'?'Colegio Monteverde':'Palapas Protudec').":\n• ".implode("\n• ",$hours);
    }

    if(preg_match('/\b(?:requisito|requisitos|necesito|llevar|incluye|incluyen|gorro|gorra|goggles|lentes|traje|equipo|equipamiento|saber nadar)\b/u',$t)===1){
        if(preg_match('/\b(?:saber nadar|se nadar|no se nadar)\b/u',$t)===1)return '🏊 No necesitas saber nadar para el curso intensivo; está pensado para aprender desde cero o reforzar la base.';
        return '🎒 Para entrar al agua necesitas traje de baño que no sea de algodón ni mezclilla, gorro de natación y goggles.';
    }

    if(preg_match('/\b(?:diferencia|diferencias|intensivo.*regular|regular.*intensivo)\b/u',$t)===1){
        return '🏊 El intensivo es para aprender desde cero o reforzar la base y dura 3 semanas, de lunes a viernes. 📅 Las clases regulares son para personas con formación previa y se manejan por mensualidad.';
    }

    return '💬 No tengo información confirmada suficiente para responder esa duda sin adivinar. Tu selección pendiente se conserva.';
}

