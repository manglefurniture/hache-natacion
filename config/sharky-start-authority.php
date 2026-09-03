<?php

declare(strict_types=1);

/**
 * Autoridad comercial de Sharky sobre fechas de inicio.
 *
 * No intenta comprender todo el español. Protege las excepciones de negocio
 * que Sharky nunca puede autorizar y deja el lenguaje abierto al modelo.
 */

function hache_sharky_start_authority_normalize(string $text): string
{
    if (function_exists('hache_sharky_normalize_text')) return hache_sharky_normalize_text($text);
    return strtr(mb_strtolower(trim($text), 'UTF-8'), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
}

function hache_sharky_start_authority_reference(?DateTimeImmutable $reference = null): DateTimeImmutable
{
    if ($reference instanceof DateTimeImmutable) return $reference->setTimezone(new DateTimeZone('America/Cancun'));
    return new DateTimeImmutable('today', new DateTimeZone('America/Cancun'));
}

function hache_sharky_start_authority_parse_date(string $normalized, ?DateTimeImmutable $reference = null): ?DateTimeImmutable
{
    $reference = hache_sharky_start_authority_reference($reference);

    if (preg_match('/\bpasado\s+manana\b/u', $normalized) === 1) return $reference->modify('+2 days');
    $morningContext = preg_match('/\b(?:por|en|de)\s+la\s+manana\b|\bhorario\s+(?:de\s+)?manana\b/u', $normalized) === 1;
    if (!$morningContext && preg_match('/\bmanana\b/u', $normalized) === 1) return $reference->modify('+1 day');
    if (preg_match('/\bhoy\b/u', $normalized) === 1) return $reference;

    if (preg_match('/\b(\d{1,2})[\/-](\d{1,2})(?:[\/-](\d{2,4}))?\b/u', $normalized, $m) === 1) {
        $day=(int)$m[1];$month=(int)$m[2];$year=isset($m[3])&&$m[3]!==''?(int)$m[3]:(int)$reference->format('Y');
        if ($year < 100) $year += 2000;
        if (checkdate($month,$day,$year)) return (new DateTimeImmutable('now', new DateTimeZone('America/Cancun')))->setDate($year,$month,$day)->setTime(0,0);
    }

    $months=[
        'enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,
        'julio'=>7,'agosto'=>8,'septiembre'=>9,'setiembre'=>9,'octubre'=>10,'noviembre'=>11,'diciembre'=>12,
    ];
    if (preg_match('/\b(\d{1,2})\s+de\s+(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)(?:\s+de\s+(\d{4}))?\b/u', $normalized, $m) === 1) {
        $day=(int)$m[1];$month=$months[$m[2]];$year=isset($m[3])&&$m[3]!==''?(int)$m[3]:(int)$reference->format('Y');
        if (checkdate($month,$day,$year)) return (new DateTimeImmutable('now', new DateTimeZone('America/Cancun')))->setDate($year,$month,$day)->setTime(0,0);
    }

    return null;
}

function hache_sharky_start_authority_start_intent(string $normalized): bool
{
    return preg_match('/\b(empezar|empiezo|iniciar|inicio|comenzar|comienzo|entrar|incorporarme|incorporarse|incorporar|integrarme|sumarme)\b/u', $normalized) === 1;
}

/**
 * Devuelve null cuando Sharky conserva autoridad; devuelve una decisión de
 * handoff cuando la fecha solicitada requiere aprobación humana.
 *
 * @return array{program:string,reason:string,message:string}|null
 */
function hache_sharky_start_authority_handoff(string $text, ?DateTimeImmutable $reference = null): ?array
{
    $t=hache_sharky_start_authority_normalize($text);
    if ($t==='' || !hache_sharky_start_authority_start_intent($t)) return null;
    $reference=hache_sharky_start_authority_reference($reference);
    $explicitDate=hache_sharky_start_authority_parse_date($t,$reference);

    $isIntensive=preg_match('/\b(intensivo|intensivos|curso intensivo|cursos intensivos)\b/u',$t)===1;
    if ($isIntensive) {
        $nonMonday=preg_match('/\b(martes|miercoles|jueves|viernes|sabado|sabados|domingo|domingos)\b/u',$t)===1;
        $latePhrase=preg_match('/\b(despues del lunes|a mitad de semana|mitad de semana)\b/u',$t)===1;
        $invalidExplicit=$explicitDate instanceof DateTimeImmutable
            && ((int)$explicitDate->format('N')!==1 || $explicitDate<$reference->setTime(0,0));
        if ($nonMonday || $latePhrase || $invalidExplicit) {
            return [
                'program'=>'intensive',
                'reason'=>'start_date_exception',
                'message'=>'Los cursos intensivos comienzan los lunes. Si necesitas incorporarte en otra fecha, esa excepción debe confirmarla una persona del equipo.',
            ];
        }
        return null;
    }

    $isRegular=preg_match('/\b(clases? regulares?|regular|regulares|mensualidad)\b/u',$t)===1;
    if (!$isRegular) return null;

    $isPalapas=preg_match('/\bpalapas?(?: protudec)?\b/u',$t)===1;
    $isMonteverde=preg_match('/\bmonteverde\b/u',$t)===1;
    $beginning=preg_match('/\b(inicio|inicios|principio|principios|comienzo|comienzos)\s+(?:del?\s+)?mes\b/u',$t)===1;
    if ($beginning) return null;

    $midMonth=preg_match('/\b(alrededor|cerca|sobre)\s+(?:del?\s+)?15\b/u',$t)===1
        || preg_match('/\b(quincena|mitad de mes)\b/u',$t)===1;
    if ($midMonth) {
        if ($isPalapas) return null;
        if ($isMonteverde) {
            return [
                'program'=>'regular',
                'reason'=>'start_date_exception',
                'message'=>'En Monteverde las clases regulares comienzan a inicios de mes. Un inicio alrededor del día 15 u otra fecha necesita autorización del equipo.',
            ];
        }
        return null; // Falta sede: Sharky debe preguntar cuál, no escalar todavía.
    }

    if (preg_match('/\b(fin|final|finales)\s+(?:del?\s+)?mes\b|\bultima\s+semana\b/u',$t)===1) {
        return [
            'program'=>'regular',
            'reason'=>'start_date_exception',
            'message'=>'Las clases regulares comienzan a inicios de mes; en Palapas también puede haber inicio alrededor del día 15. Cualquier otra fecha debe confirmarla una persona del equipo.',
        ];
    }

    $day=null;
    if ($explicitDate instanceof DateTimeImmutable) $day=(int)$explicitDate->format('j');
    elseif (preg_match('/\b(?:dia|el)\s+(\d{1,2})\b/u',$t,$m)===1) $day=(int)$m[1];

    if ($day!==null) {
        if ($day===1) return null;
        if ($day===15 && $isPalapas) return null;
        if ($day===15 && !$isMonteverde) return null; // sin sede, preguntar antes de decidir
        return [
            'program'=>'regular',
            'reason'=>'start_date_exception',
            'message'=>'Las clases regulares comienzan a inicios de mes; en Palapas también puede haber inicio alrededor del día 15. Para otra fecha exacta debe confirmarlo una persona del equipo.',
        ];
    }

    return null;
}

function hache_sharky_start_authority_intensive_date_allowed(string $date, ?string $today = null): bool
{
    $tz=new DateTimeZone('America/Cancun');
    $start=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$tz);
    if (!$start || $start->format('Y-m-d')!==$date || (int)$start->format('N')!==1) return false;
    $reference=DateTimeImmutable::createFromFormat('!Y-m-d',$today ?: (new DateTimeImmutable('today',$tz))->format('Y-m-d'),$tz);
    if (!$reference) return false;
    return $start >= $reference;
}

function hache_sharky_start_authority_policy(): string
{
    return implode("\n", [
        'AUTORIDAD SOBRE FECHAS DE INICIO:',
        '- Los cursos intensivos COMIENZAN LOS LUNES. No presentes martes ni otro día como fecha de inicio o incorporación autorizada por Sharky.',
        '- Aunque una herramienta técnica pueda permitir una incorporación tardía, esa variación es exclusivamente autoridad humana y Sharky debe derivarla.',
        '- Clases regulares en Monteverde: inicio normal a inicios de mes.',
        '- Clases regulares en Palapas Protudec: inicio normal a inicios de mes o alrededor del día 15.',
        '- Cualquier otra variación de fecha de inicio debe confirmarla una persona del equipo; Sharky no la negocia ni la autoriza.',
    ]);
}
