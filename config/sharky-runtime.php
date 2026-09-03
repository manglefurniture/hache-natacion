<?php

declare(strict_types=1);

require_once __DIR__.'/intensivos-estado.php';
require_once __DIR__.'/sharky-validation.php';

function hache_sharky_config_defaults(): array
{
    return [
        'sharky_edad_minima'=>['valor'=>'12','descripcion'=>'Edad mínima atendida por Hache Natación'],
        'sharky_precio_intensivo'=>['valor'=>'1200','descripcion'=>'Precio general del curso intensivo en MXN; sincronizado con el alta automática del registro público'],
        'sharky_precio_regular_3'=>['valor'=>'1000','descripcion'=>'Mensualidad de 3 clases por semana en MXN'],
        'sharky_precio_regular_5'=>['valor'=>'1200','descripcion'=>'Mensualidad de 5 clases por semana en MXN'],
        'sharky_inscripcion_monteverde'=>['valor'=>'500','descripcion'=>'Inscripción de Monteverde en MXN'],
        'sharky_inscripcion_palapas'=>['valor'=>'400','descripcion'=>'Inscripción de Palapas en MXN'],
        'sharky_kit_gorro_goggles'=>['valor'=>'300','descripcion'=>'Kit opcional de gorro + goggles vendido por Hache, en MXN'],
        'sharky_recargo_tarjeta_pct'=>['valor'=>'5','descripcion'=>'Recargo porcentual por pago con tarjeta, aplicable a todos los conceptos'],
        'sharky_whatsapp'=>['valor'=>'9902308165','descripcion'=>'WhatsApp oficial mostrado desde la web'],
        'sharky_link_registro_monteverde'=>['valor'=>'https://go.hnatacion.com/mv','descripcion'=>'Enlace corto oficial de inscripción a intensivos en Monteverde'],
        'sharky_link_registro_palapas'=>['valor'=>'https://go.hnatacion.com/pal','descripcion'=>'Enlace corto oficial de inscripción a intensivos en Palapas'],
        'sharky_maps_monteverde'=>['valor'=>'https://maps.app.goo.gl/Ld75bhLforGm2Tk68','descripcion'=>'Ubicación oficial de Monteverde en Google Maps'],
        'sharky_maps_palapas'=>['valor'=>'https://maps.app.goo.gl/L7aEf9phtXtciUj78','descripcion'=>'Ubicación oficial de Palapas Protudec en Google Maps'],
        'sharky_pago_institucion'=>['valor'=>'Mercado Pago W','descripcion'=>'Institución de la cuenta pública para pagos por transferencia'],
        'sharky_pago_beneficiario'=>['valor'=>'Heidy Garcia Liranza','descripcion'=>'Beneficiario de la cuenta pública para pagos por transferencia'],
        'sharky_pago_clabe'=>['valor'=>'722969010319748145','descripcion'=>'CLABE pública de 18 dígitos para pagos por transferencia'],
        'sharky_audio_habilitado'=>['valor'=>'1','descripcion'=>'1 permite transcribir notas de voz de WhatsApp; 0 las desactiva'],
        'sharky_audio_max_mb'=>['valor'=>'4','descripcion'=>'Tamaño máximo de audio aceptado para transcripción, en MB'],
        'sharky_escalado_intentos'=>['valor'=>'2','descripcion'=>'Respuestas consecutivas sin resolver antes de pasar a atención humana'],
    ];
}

function hache_sharky_pdo(): ?PDO
{
    static $pdo = false;
    if ($pdo instanceof PDO) return $pdo;
    if ($pdo === null) return null;
    try {
        $config = require __DIR__.'/database.php';
        $pdo = new PDO(
            "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
            $config['user'],
            $config['password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
        );
        return $pdo;
    } catch (Throwable $e) {
        error_log('[sharky-runtime] database unavailable');
        $pdo = null;
        return null;
    }
}

function hache_sharky_business_values(?PDO $pdo = null): array
{
    $defaults = hache_sharky_config_defaults();
    $values = array_map(static fn(array $row): string => (string) $row['valor'], $defaults);
    $pdo ??= hache_sharky_pdo();
    if (!$pdo) return $values;
    try {
        $keys = array_keys($defaults);
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT clave,valor FROM configuracion WHERE clave IN ($marks)");
        $stmt->execute($keys);
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['clave'] ?? '');
            $value = trim((string) ($row['valor'] ?? ''));
            if (!isset($values[$key]) || $value === '') continue;
            if (!hache_sharky_config_value_valid($key, $value)) {
                error_log('[sharky-runtime] invalid stored configuration ignored: '.$key);
                continue;
            }
            $values[$key] = $value;
        }
    } catch (Throwable $e) {
        error_log('[sharky-runtime] configuration read failed');
    }
    return $values;
}

function hache_sharky_config_int(array $values, string $key, int $fallback, int $min, int $max): int
{
    if (!is_numeric($values[$key] ?? null)) return $fallback;
    return max($min, min($max, (int) $values[$key]));
}

function hache_sharky_dynamic_context(?PDO $pdo, array $values): string
{
    if (!$pdo) return "DATOS DINÁMICOS\n- No fue posible consultar horarios o cursos en este momento. No inventes datos.";
    $lines = ['DATOS DINÁMICOS DEL SISTEMA','- Estos datos provienen del backend actual y prevalecen sobre horarios históricos.'];
    try {
        $rows = $pdo->query("SELECT s.nombre sede,h.hora_inicio,h.hora_fin,h.regular,h.intensivo FROM horarios h JOIN sedes s ON s.id=h.sede_id WHERE h.activo=1 AND s.activo=1 ORDER BY s.nombre,h.hora_inicio")->fetchAll();
        if (!$rows) {
            $lines[] = '- No hay horarios activos registrados.';
        } else {
            $lines[] = '- Horarios activos registrados:';
            foreach ($rows as $row) {
                $types=[];
                if ((int)($row['regular']??0)===1) $types[]='regular';
                if ((int)($row['intensivo']??0)===1) $types[]='intensivo';
                $lines[] = sprintf('  • %s: %s–%s (%s)',(string)$row['sede'],substr((string)$row['hora_inicio'],0,5),substr((string)$row['hora_fin'],0,5),implode(' / ',$types));
            }
        }
    } catch (Throwable $e) {
        $lines[]='- No se pudieron consultar los horarios activos; no inventes horarios.';
    }
    try {
        $selectableDates = intensivo_lunes_registro(10);
        $lines[]='- Fechas de inicio que el registro público acepta actualmente: '.implode(', ',$selectableDates).'.';
        $marks = implode(',', array_fill(0, count($selectableDates), '?'));
        $stmt = $pdo->prepare("SELECT s.nombre sede,ci.fecha_inicio,ci.fecha_fin,ci.precio FROM cursos_intensivos ci JOIN sedes s ON s.id=ci.sede_id WHERE s.activo=1 AND ci.fecha_inicio IN ($marks) ORDER BY ci.fecha_inicio ASC,s.nombre ASC");
        $stmt->execute($selectableDates);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            $lines[]='- No hay cursos precreados para esas fechas; cuando corresponda usa el precio general del intensivo.';
        } else {
            $lines[]='- Cursos ya creados dentro de esas fechas; su precio registrado prevalece sobre el precio general:';
            foreach ($rows as $row) {
                $price=rtrim(rtrim(number_format((float)$row['precio'],2,'.',''),'0'),'.');
                $lines[]=sprintf('  • %s: %s a %s; precio registrado $%s MXN',(string)$row['sede'],(string)$row['fecha_inicio'],(string)$row['fecha_fin'],$price);
            }
        }
        $lines[]='- Cupos, capacidad de grupo, inscritos y alumnos por carril NO se exponen a Sharky; esas preguntas se derivan a atención humana.';
    } catch (Throwable $e) {
        $lines[]='- No se pudieron consultar cursos o fechas vigentes; no inventes fechas.';
    }
    return implode("\n",$lines);
}

function hache_sharky_openai_key(): string
{
    $env=trim((string)(getenv('OPENAI_API_KEY')?:''));
    if ($env!=='') return $env;
    $path='/etc/hache-openai.env';
    if (!is_readable($path)) return '';
    foreach (file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line) {
        $line=trim((string)$line);
        if ($line===''||str_starts_with($line,'#')) continue;
        if (str_starts_with($line,'sk-')) return $line;
        if (str_starts_with($line,'export ')) $line=trim(substr($line,7));
        if (str_starts_with($line,'OPENAI_API_KEY=')) return trim(trim(substr($line,strlen('OPENAI_API_KEY='))),"\"'");
    }
    return '';
}

function hache_sharky_normalize_text(string $text): string
{
    return strtr(mb_strtolower(trim($text),'UTF-8'),['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
}

function hache_sharky_capacity_request(string $text): bool
{
    $text=hache_sharky_normalize_text($text);
    $capacityContext='(?:intensivos?|cursos?|grupos?|clases?|carril|carriles)';
    $implicitCapacityVerb='(?:caben|entran|admite|admiten|acepta|aceptan|permite|permiten)';
    $implicitCountForward='/\b(cuantos?|cuantas?)\s+'.$implicitCapacityVerb.'\b.{0,18}\b(?:en\s+)?(?:(?:el|la|los|las|cada|un|una)\s+)?'.$capacityContext.'\b/u';
    $implicitCountReverse='/\b(?:(?:el|la|los|las|cada|un|una)\s+)?'.$capacityContext.'\b.{0,24}\b(cuantos?|cuantas?)\s+'.$implicitCapacityVerb.'\b/u';
    $placeNoun='(?:lugar|lugares|espacio|espacios)';
    $placeDirect='/^¿?(?:hay|queda|quedan)\s+'.$placeNoun.'(?:\s+(?:disponible|disponibles|libre|libres))?\??$/u';
    $placeVerbToContext='/\b(?:hay|queda|quedan|tiene|tienen)\b.{0,18}\b'.$placeNoun.'\b.{0,28}\b'.$capacityContext.'\b/u';
    $placeContextToVerb='/\b'.$capacityContext.'\b.{0,28}\b(?:hay|queda|quedan|tiene|tienen)\b.{0,18}\b'.$placeNoun.'\b/u';
    $placeAvailableContext='/\b'.$placeNoun.'\b.{0,12}\b(?:disponible|disponibles|libre|libres)\b.{0,28}\b'.$capacityContext.'\b/u';
    $placeContextAvailable='/\b'.$capacityContext.'\b.{0,28}\b'.$placeNoun.'\b.{0,12}\b(?:disponible|disponibles|libre|libres)\b/u';
    $placeCountForward='/\b(cuantos?|cuantas?|numero de|cantidad de)\s+'.$placeNoun.'\b.{0,36}\b'.$capacityContext.'\b/u';
    $placeCountReverse='/\b'.$capacityContext.'\b.{0,36}\b(cuantos?|cuantas?|numero de|cantidad de)\s+'.$placeNoun.'\b/u';
    $placeCountRemaining='/\b(cuantos?|cuantas?|numero de|cantidad de)\s+'.$placeNoun.'\b.{0,18}\b(?:queda|quedan|disponible|disponibles|libre|libres)\b/u';
    if (
        preg_match('/\b(cuantos?|cuantas?|numero de|cantidad de)\b.{0,28}\bgente\b/u',$text)===1
        || preg_match('/\bgente\b.{0,28}\b(cuantos?|cuantas?|numero de|cantidad de)\b/u',$text)===1
        || preg_match('/\baforo\b/u',$text)===1
    ) return true;
    $hasExplicitCapacity = preg_match('/\b(cupo|cupos|vacante|vacantes|lleno|llena|llenos|llenas)\b/u',$text)===1
        || str_contains($text,'lista de espera')
        || preg_match('/\b(cuantos?|cuantas?|numero de)\b.{0,28}\b(alumnos|personas|nadadores)\b/u',$text)===1
        || preg_match('/\b(alumnos|personas|nadadores)\b.{0,28}\b(cuantos?|cuantas?|numero de)\b/u',$text)===1
        || preg_match('/\bpor carril\b/u',$text)===1
        || preg_match($placeDirect,$text)===1
        || preg_match($placeVerbToContext,$text)===1
        || preg_match($placeContextToVerb,$text)===1
        || preg_match($placeAvailableContext,$text)===1
        || preg_match($placeContextAvailable,$text)===1
        || preg_match($placeCountForward,$text)===1
        || preg_match($placeCountReverse,$text)===1
        || preg_match($placeCountRemaining,$text)===1
        || preg_match($implicitCountForward,$text)===1
        || preg_match($implicitCountReverse,$text)===1;
    if (!$hasExplicitCapacity && preg_match('/\b(disponibilidad|disponible|disponibles)\b/u',$text)===1) {
        $spokenHour='(?:una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|once|doce|trece|catorce|quince|dieciseis|diecisiete|dieciocho|diecinueve|veinte|veintiuna|veintidos|veintitres)';
        $weekday='(?:lunes|martes|miercoles|jueves|viernes|sabados?|domingos?)';
        $month='(?:enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)';
        $hasScheduleQualifier = preg_match('/\b(horario|horarios|hora|horas|fecha|fechas|dia|dias|turno|turnos|manana|tarde|noche|hoy|semana|semanas)\b/u',$text)===1
            || preg_match('/\b'.$weekday.'\b/u',$text)===1
            || preg_match('/\b(?:en|para|durante)\s+'.$month.'\b/u',$text)===1
            || preg_match('/\b\d{1,2}\s+de\s+'.$month.'(?:\s+de\s+\d{4})?\b/u',$text)===1
            || preg_match('/\b\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b/u',$text)===1
            || preg_match('/\blas?\s+(?:\d{1,2}(?::\d{2})?|'.$spokenHour.')(?:\s+y\s+(?:cuarto|media))?\b/u',$text)===1
            || preg_match('/\b\d{1,2}(?::\d{2})?\s*(?:am|pm)\b/u',$text)===1
            || preg_match('/\b(?:de|entre)\s+(?:\d{1,2}(?::\d{2})?|'.$spokenHour.')\s+(?:a|y)\s+(?:\d{1,2}(?::\d{2})?|'.$spokenHour.')\b/u',$text)===1;
        if ($hasScheduleQualifier) return false;
    }
    foreach ([
        '/\b(cupo|cupos|vacante|vacantes)\b/u',
        $placeDirect,
        $placeVerbToContext,
        $placeContextToVerb,
        $placeAvailableContext,
        $placeContextAvailable,
        $placeCountForward,
        $placeCountReverse,
        $placeCountRemaining,
        '/\b(disponibilidad)\b.{0,35}\b'.$capacityContext.'\b/u',
        '/\b'.$capacityContext.'\b.{0,35}\b(disponibilidad)\b/u',
        '/\b(cuantos?|cuantas?|numero de)\b.{0,25}\b(alumnos|personas|nadadores)\b.{0,25}\b'.$capacityContext.'\b/u',
        '/\b'.$capacityContext.'\b.{0,40}\b(cuantos?|cuantas?|numero de)\b.{0,20}\b(alumnos|personas|nadadores)\b/u',
        '/\b(cuantos?|cuantas?|numero de)\b.{0,25}\b(alumnos|personas|nadadores)\b.{0,15}\b(hay|tiene|tienen|caben|entran|admite|admiten|acepta|aceptan|permite|permiten)\b/u',
        '/\b(cuantos?|cuantas?|numero de)\b.{0,20}\bpor (?:carril|grupo|curso|clase)\b/u',
        $implicitCountForward,
        $implicitCountReverse,
        '/\bcapacidad(?:\s+maxima)?\s+(?:del|de la|de los|de las)\s+(grupo|grupos|curso|cursos|clase|clases|carril|carriles|intensivo|intensivos)\b/u',
        '/\b(grupo|grupos|curso|cursos|clase|clases|carril|carriles|intensivo|intensivos)\b.{0,20}\bcapacidad(?:\s+maxima)?\b/u',
        '/\bcapacidad\b.{0,16}\b(tiene|tienen|admite|admiten|permite|permiten|acepta|aceptan)\b.{0,14}\b(el\s+|la\s+|los\s+|las\s+)?(grupo|grupos|curso|cursos|clase|clases|carril|carriles|intensivo|intensivos)\b/u',
        '/\b(esta|estan)\b.{0,15}\b(lleno|llena|llenos|llenas)\b/u',
        '/\b(grupo|grupos|curso|cursos|clase|clases|carril|carriles)\b.{0,25}\b(se\s+(lleno|llena|llenaron|llenan))\b/u',
        '/\b(se\s+(lleno|llena|llenaron|llenan))\b.{0,25}\b(grupo|grupos|curso|cursos|clase|clases|carril|carriles)\b/u',
        '/\b(esta|estan|hay)\b.{0,12}\b(disponible|disponibles)\b.{0,20}\b'.$capacityContext.'\b/u',
        '/\blista de espera\b/u',
    ] as $pattern) if (preg_match($pattern,$text)===1) return true;
    return false;
}

function hache_sharky_regular_enrollment_request(string $text): bool
{
    $candidate=hache_sharky_normalize_text($text);
    $negativeMarker='(?:no|todavia no|aun no|por ahora no|tampoco|ni)';
    $negativeIntent='(?:(?:me|te|se|nos|yo)\s+)?(?:(?:quiero|quiere|queremos|quieren|necesito|necesita|necesitamos|necesitan|quisiera|deseo|desea|deseamos|desean|pienso|piensa|pensamos|piensan|planeo|planea|planeamos|planean|voy a|va a|vamos a|van a)\s+)?';
    $negativeAction='(?:inscribirme|registrarme|anotarme|apuntarme|inscribirse|registrarse|anotarse|apuntarse|inscribir|registrar|anotar|apuntar|inscribirlo|inscribirla|registrarlo|registrarla|anotarlo|anotarla|apuntarlo|apuntarla|darme de alta|darte de alta|darse de alta|darnos de alta|dar de alta|empezar|comenzar|entrar)';
    foreach ([
        '/\b'.$negativeMarker.'\s+'.$negativeIntent.$negativeAction.'\b/u',
        '/\b'.$negativeMarker.'\s+'.$negativeIntent.'(?:(?:hacer|completar|confirmar|tramitar|realizar|iniciar|finalizar)\s+)?(?:(?:mi|tu|su|nuestra|nuestro|la|el|una|un)\s+)?(?:inscripcion|alta|registro)\b/u',
        '/\bno\s+(?:tengo|tiene|tenemos|tienen)?\s*(?:intencion|planes?)\s+de\s+'.$negativeAction.'\b/u',
    ] as $negativePattern) {
        $candidate=preg_replace($negativePattern,' ',$candidate)??$candidate;
    }

    $regular='(?:clases regulares|regular|regulares|mensualidad)';
    $patterns=[
        '/\b(quiero|quisiera|necesito|me quiero|ya quiero)\b.{0,24}\b(inscribirme|registrarme|anotarme|apuntarme|darme de alta|empezar|comenzar|entrar)\b.{0,32}\b'.$regular.'\b/u',
        '/\b(inscribirme|registrarme|anotarme|apuntarme|darme de alta)\b.{0,32}\b'.$regular.'\b/u',
        '/\b(como|donde)\b.{0,18}\b(me inscribo|me registro|puedo inscribirme|puedo registrarme|hago la inscripcion)\b.{0,32}\b'.$regular.'\b/u',
        '/\b(inscribir|registrar|anotar|apuntar|dar de alta|inscribirlo|inscribirla|registrarlo|registrarla|anotarlo|anotarla|apuntarlo|apuntarla)\b.{0,55}\b'.$regular.'\b/u',
        '/\b'.$regular.'\b.{0,55}\b(inscribir|registrar|anotar|apuntar|dar de alta|inscribirlo|inscribirla|registrarlo|registrarla|anotarlo|anotarla|apuntarlo|apuntarla)\b/u',
        '/\b(inscribo|inscribimos|registro|registramos|anoto|anotamos|apunto|apuntamos)\b.{0,55}\b'.$regular.'\b/u',
        '/\b(inscribirse|registrarse|anotarse|apuntarse|darse de alta)\b.{0,45}\b'.$regular.'\b/u',
        '/\b'.$regular.'\b.{0,45}\b(inscribirse|registrarse|anotarse|apuntarse|darse de alta)\b/u',
        '/\b(quiero|necesito|quisiera|deseo)\s+(?:(?:hacer|completar|confirmar|tramitar|realizar|iniciar|finalizar)\s+)?(?:(?:mi|la|el|una|un)\s+)?(inscripcion|alta|registro)\b.{0,40}\b'.$regular.'\b/u',
        '/\b'.$regular.'\b.{0,45}\b(quiero|necesito|quisiera|deseo)\s+(?:(?:hacer|completar|confirmar|tramitar|realizar|iniciar|finalizar)\s+)?(?:(?:mi|la|el|una|un)\s+)?(inscripcion|alta|registro)\b/u',
    ];
    foreach ($patterns as $pattern) if (preg_match($pattern,$candidate)===1) return true;
    return false;
}
function hache_sharky_human_request(string $text): bool
{
    if (hache_sharky_capacity_request($text)) return true;
    $text=hache_sharky_normalize_text($text);

    $explicitHumanPatterns=[
        '/\b(quiero|necesito|quisiera|puedo|podria)\b.{0,25}\b(hablar|contactar|comunicarme)\b.{0,35}\b(persona|humano|asesor|operador|alguien|equipo)\b/u',
        '/\b(pasame|ponme|comunicame|dejame hablar)\b.{0,35}\b(persona|humano|asesor|operador|alguien|equipo)\b/u',
        '/\b(me puedes|puedes|podrias|podria)\b.{0,20}\b(pasar|poner|comunicar)\b.{0,35}\b(persona|humano|asesor|operador|alguien|equipo)\b/u',
        '/\b(quiero|necesito|quisiera)\b.{0,18}\b(una persona|un humano|humano|un asesor|asesor|operador|atencion humana)\b/u',
        '/\b(asesor humano|atencion humana|operador humano|persona real)\b/u',
    ];
    foreach ($explicitHumanPatterns as $pattern) if (preg_match($pattern,$text)===1) return true;

    return hache_sharky_regular_enrollment_request($text);
}

function hache_sharky_frustration(string $text): bool
{
    $text=hache_sharky_normalize_text($text);
    foreach ([
        '/\b(no me entiendes|no estas entendiendo|ya te dije|no respondes lo que|no me estas ayudando)\b/u',
        '/\b(esto no sirve|no sirves|que mal servicio|estoy molesto|estoy enojado)\b/u',
    ] as $pattern) if (preg_match($pattern,$text)===1) return true;
    return false;
}

function hache_sharky_answer_needs_human(string $answer): bool
{
    return preg_match('/\b(prefiero no invent|hay que confirmar|debe confirmarse|necesita confirmacion|equipo de hache puede confirmar|una persona del equipo)\b/u',hache_sharky_normalize_text($answer))===1;
}

function hache_sharky_state_dirs(string $kind): array
{
    $suffix=match($kind){'takeover'=>'hache-whatsapp-human','metrics'=>'hache-sharky-metrics',default=>'hache-sharky-'.$kind};
    return ['/var/tmp/'.$suffix,rtrim(sys_get_temp_dir(),'/').'/'.$suffix];
}

function hache_sharky_writable_dir(string $kind): string
{
    foreach (hache_sharky_state_dirs($kind) as $dir) {
        if ((is_dir($dir)||@mkdir($dir,0700,true))&&is_writable($dir)) return $dir;
    }
    return '';
}

function hache_sharky_meta_secret(): string
{
    $env=trim((string)(getenv('META_APP_SECRET')?:''));
    if ($env!=='') return $env;
    $root=dirname(__DIR__);
    $envFile=$root.'/.env';
    if (!is_readable($envFile)) return '';
    foreach (file($envFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line) {
        $line=trim((string)$line);
        if (str_starts_with($line,'export ')) $line=trim(substr($line,7));
        if (str_starts_with($line,'META_APP_SECRET=')) return trim(trim(substr($line,strlen('META_APP_SECRET='))),"\"'");
    }
    return '';
}

function hache_sharky_contact_hash(string $contact): string
{
    $contact=preg_replace('/\D+/','',$contact)?:'';
    $secret=hache_sharky_meta_secret();
    return hash_hmac('sha256',$contact,$secret!==''?$secret:'hache-whatsapp-history');
}

function hache_sharky_takeover_path_for_hash(string $hash): string
{
    $dir=hache_sharky_writable_dir('takeover');
    return $dir===''?'':$dir.'/'.$hash;
}

function hache_sharky_takeover_active(string $contact): bool
{
    $hash=hache_sharky_contact_hash($contact);
    foreach (hache_sharky_state_dirs('takeover') as $dir) if (is_file($dir.'/'.$hash)) return true;
    return false;
}

function hache_sharky_takeover_mark(string $contact,string $reason,string $summary=''): bool
{
    $contact=preg_replace('/\D+/','',$contact)?:'';
    if ($contact==='') return false;
    $hash=hache_sharky_contact_hash($contact);
    $path=hache_sharky_takeover_path_for_hash($hash);
    if ($path==='') return false;
    $activatedAt=gmdate('c');
    if (is_file($path)) {
        $existing=json_decode((string)@file_get_contents($path),true);
        if (is_array($existing)&&is_string($existing['activated_at']??null)) $activatedAt=$existing['activated_at'];
    }
    $data=['contact_hash'=>$hash,'phone_last4'=>substr($contact,-4),'reason'=>mb_substr(trim($reason),0,60),'summary'=>mb_substr(trim($summary),0,1200),'activated_at'=>$activatedAt,'updated_at'=>gmdate('c')];
    $encoded=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    return $encoded!==false&&@file_put_contents($path,$encoded,LOCK_EX)!==false;
}

function hache_sharky_takeover_list(): array
{
    $items=[];
    foreach (hache_sharky_state_dirs('takeover') as $dir) {
        if (!is_dir($dir)) continue;
        foreach (glob($dir.'/*')?:[] as $path) {
            if (!is_file($path)) continue;
            $hash=basename($path);
            if (!preg_match('/^[a-f0-9]{64}$/',$hash)||isset($items[$hash])) continue;
            $raw=trim((string)@file_get_contents($path));
            $data=json_decode($raw,true);
            if (!is_array($data)) {
                $ts=ctype_digit($raw)?(int)$raw:(int)@filemtime($path);
                $data=['contact_hash'=>$hash,'phone_last4'=>'----','reason'=>'manual_legacy','summary'=>'','activated_at'=>$ts>0?gmdate('c',$ts):null,'updated_at'=>$ts>0?gmdate('c',$ts):null];
            }
            $items[$hash]=$data;
        }
    }
    $rows=array_values($items);
    usort($rows,static fn(array $a,array $b):int=>strcmp((string)($b['updated_at']??''),(string)($a['updated_at']??'')));
    return $rows;
}
function hache_sharky_takeover_resume_hash(string $hash): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/',$hash)) return false;
    $removed=false;
    foreach (hache_sharky_state_dirs('takeover') as $dir) {
        $path=$dir.'/'.$hash;
        if (is_file($path)&&@unlink($path)) $removed=true;
    }
    if ($removed) hache_sharky_metric_increment('reactivations');
    return $removed;
}

function hache_sharky_history_summary(array $turns,string $pendingMessage=''): string
{
    $pieces=[];
    foreach (array_slice($turns,-8) as $turn) {
        if (!is_array($turn)) continue;
        $role=($turn['role']??'')==='assistant'?'Sharky':(($turn['role']??'')==='user'?'Cliente':'');
        $content=preg_replace('/\s+/u',' ',trim((string)($turn['content']??'')))?:'';
        if ($role!==''&&$content!=='') $pieces[]=$role.': '.mb_substr($content,0,180);
    }
    if (trim($pendingMessage)!=='') $pieces[]='Cliente: '.mb_substr(preg_replace('/\s+/u',' ',trim($pendingMessage))?:'',0,180);
    return mb_substr(implode(' | ',$pieces),0,1100);
}

function hache_sharky_local_date(int $offsetDays=0): string
{
    $tz=new DateTimeZone('America/Cancun');
    $now=new DateTimeImmutable('now',$tz);
    if ($offsetDays!==0) $now=$now->modify(($offsetDays>0?'+':'').$offsetDays.' days');
    return $now->format('Y-m-d');
}

function hache_sharky_metric_increment(string $key,int $amount=1): void
{
    if (!preg_match('/^[a-z][a-z0-9_]{1,50}$/',$key)) return;
    $dir=hache_sharky_writable_dir('metrics');
    if ($dir==='') return;
    $path=$dir.'/'.hache_sharky_local_date().'.json';
    $handle=@fopen($path,'c+');
    if (!$handle||!flock($handle,LOCK_EX)) {if(is_resource($handle))fclose($handle);return;}
    rewind($handle);$raw=stream_get_contents($handle);$data=json_decode(is_string($raw)?$raw:'',true);if(!is_array($data))$data=[];
    $data[$key]=max(0,(int)($data[$key]??0)+$amount);
    $encoded=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($encoded!==false){ftruncate($handle,0);rewind($handle);fwrite($handle,$encoded);}
    flock($handle,LOCK_UN);fclose($handle);
}

function hache_sharky_metrics(int $days=7): array
{
    $days=max(1,min(31,$days));$rows=[];
    for($i=$days-1;$i>=0;$i--){
        $date=hache_sharky_local_date(-$i);$data=[];
        foreach(hache_sharky_state_dirs('metrics') as $dir){$path=$dir.'/'.$date.'.json';if(!is_file($path))continue;$decoded=json_decode((string)@file_get_contents($path),true);if(!is_array($decoded))continue;foreach($decoded as $key=>$value)$data[$key]=(int)($data[$key]??0)+(int)$value;}
        $rows[]=['date'=>$date,'counters'=>$data];
    }
    return $rows;
}