<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__.'/../../config/rate-limit.php';
require_once __DIR__.'/../../config/sharky-runtime.php';

function sharky_v2_out(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sharky_v2_out(['ok' => false, 'error' => 'Método no permitido'], 405);
}

if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) {
    sharky_v2_out(['ok' => false, 'error' => 'El mensaje es demasiado grande.'], 413);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    sharky_v2_out(['ok' => false, 'error' => 'La solicitud no tiene un formato válido.'], 400);
}

$message = trim((string) ($data['message'] ?? ''));
$history = is_array($data['history'] ?? null) ? $data['history'] : [];
$requestedChannel = strtolower(trim((string) ($data['channel'] ?? '')));
$remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
$isLoopback = in_array($remoteAddr, ['127.0.0.1', '::1'], true);
$channel = $isLoopback && ($requestedChannel === 'whatsapp' || $requestedChannel === '') ? 'whatsapp' : 'web';

$rate = $channel === 'whatsapp'
    ? security_rate_limit_record('sharky-internal-whatsapp', 'loopback', 300, 300)
    : security_rate_limit_record('sharky-public', security_rate_limit_client_ip(), 30, 300);
if (!$rate['allowed']) {
    header('Retry-After: '.max(1, (int) $rate['retry_after']));
    sharky_v2_out(['ok' => false, 'error' => 'Sharky recibió demasiados mensajes seguidos. Espera unos minutos e intenta otra vez.'], 429);
}

if ($message === '') {
    sharky_v2_out(['ok' => false, 'error' => 'Escribe una pregunta para Sharky.'], 422);
}
if (mb_strlen($message) > 700) $message = mb_substr($message, 0, 700);

$hasAssistantHistory = false;
foreach ($history as $turn) {
    if (!is_array($turn)) continue;
    if (($turn['role'] ?? '') === 'assistant' && trim((string) ($turn['content'] ?? '')) !== '') {
        $hasAssistantHistory = true;
        break;
    }
}
$isFirstTurn = !$hasAssistantHistory;

$key = hache_sharky_openai_key();
if (!str_starts_with($key, 'sk-')) {
    hache_sharky_metric_increment('errors_openai_key');
    sharky_v2_out(['ok' => false, 'error' => 'Sharky no puede conectarse ahora mismo.'], 503);
}

$pdo = hache_sharky_pdo();
$business = hache_sharky_business_values($pdo);
$dynamicContext = hache_sharky_dynamic_context($pdo, $business);

$age = hache_sharky_config_int($business, 'sharky_edad_minima', 12, 1, 99);
$intensivePrice = hache_sharky_config_int($business, 'sharky_precio_intensivo', 1200, 0, 100000);
$regular3 = hache_sharky_config_int($business, 'sharky_precio_regular_3', 1000, 0, 100000);
$regular5 = hache_sharky_config_int($business, 'sharky_precio_regular_5', 1200, 0, 100000);
$monteverdeFee = hache_sharky_config_int($business, 'sharky_inscripcion_monteverde', 500, 0, 100000);
$palapasFee = hache_sharky_config_int($business, 'sharky_inscripcion_palapas', 400, 0, 100000);
$kitPrice = hache_sharky_config_int($business, 'sharky_kit_gorro_goggles', 300, 0, 100000);
$cardFee = hache_sharky_config_int($business, 'sharky_recargo_tarjeta_pct', 5, 0, 100);
$whatsapp = preg_replace('/\D+/', '', (string) ($business['sharky_whatsapp'] ?? '9902308165')) ?: '9902308165';
$waLink = 'https://wa.me/52'.$whatsapp;
$registerMv = trim((string)($business['sharky_link_registro_monteverde'] ?? 'https://go.hnatacion.com/mv'));
$registerPal = trim((string)($business['sharky_link_registro_palapas'] ?? 'https://go.hnatacion.com/pal'));
$mapsMv = trim((string)($business['sharky_maps_monteverde'] ?? 'https://maps.app.goo.gl/Ld75bhLforGm2Tk68'));
$mapsPal = trim((string)($business['sharky_maps_palapas'] ?? 'https://maps.app.goo.gl/L7aEf9phtXtciUj78'));
$payInstitution = trim((string)($business['sharky_pago_institucion'] ?? 'Mercado Pago W'));
$payBeneficiary = trim((string)($business['sharky_pago_beneficiario'] ?? 'Heidy Garcia Liranza'));
$payClabe = preg_replace('/\D+/', '', (string)($business['sharky_pago_clabe'] ?? '722969010319748145')) ?: '';

$channelRules = $channel === 'whatsapp'
    ? <<<TXT
CANAL ACTUAL: WHATSAPP
- Esta conversación YA ocurre dentro del WhatsApp oficial de Hache Natación.
- NUNCA le des al cliente el número de WhatsApp ni un enlace wa.me dentro de este canal.
- Si el cliente pide atención humana o necesitas escalar algo, indica de forma natural que una persona del equipo puede continuar por este mismo chat.
- No le pidas que cambie de canal para seguir hablando con Hache Natación.
TXT
    : <<<TXT
CANAL ACTUAL: WEB
- Si conviene derivar a atención humana, puedes proporcionar el WhatsApp oficial {$whatsapp} y el enlace {$waLink}.
TXT;

$instructions = <<<TXT
IDENTIDAD
Eres Sharky, el asistente IA oficial de Hache Natación en Cancún, Quintana Roo, México. Atiendes prospectos con respuestas breves, claras y exactas. No inventes datos.
- Primera respuesta: identifícate como Sharky, asistente IA de Hache Natación.
- Respuestas posteriores: no repitas tu presentación salvo que pregunten quién eres.

{$channelRules}

TONO Y FORMATO
- Español natural, cálido, breve y profesional.
- Texto plano, sin Markdown, sin asteriscos ni tablas.
- Usa párrafos cortos y, cuando haya varios datos, viñetas con “•”.
- Máximo uno o dos emojis cuando aporte naturalidad.
- Haz como máximo una pregunta útil por respuesta cuando sea posible.
- Usa “alberca”, no “piscina”, salvo cita literal del usuario.
- Responde solo lo necesario para la pregunta actual. NO descargues de golpe todos los horarios, fechas, reglas o cursos si no te los pidieron.

PÚBLICO
- Edad mínima: {$age} años.
- No ofrecemos clases para menores de {$age} años.
- Si se confirma que el posible alumno tiene menos de {$age} años, informa amablemente el límite y cierra la orientación comercial.

SEDES Y UBICACIONES
- Sedes: Monteverde y Palapas Protudec.
- No se permiten cambios ni reposiciones entre sedes.
- Monteverde Google Maps: {$mapsMv}
- Palapas Protudec Google Maps: {$mapsPal}
- Si preguntan cómo llegar o dónde está una sede, comparte el enlace correcto de Maps.

CURSO INTENSIVO
- Es un curso básico y también puede tomarse como curso temporal o para mejorar técnica. Puede entrar cualquier persona de la edad admitida, incluso si ya sabe nadar.
- Si alguien nunca ha tomado clases formales y aprendió de forma empírica, recomiéndale empezar por el intensivo aunque diga que sabe nadar.
- Duración: 3 semanas, lunes a viernes.
- Incluye hasta 5 reposiciones durante la cuarta semana según las reglas vigentes.
- Para una ausencia o una necesidad excepcional de asistir en otro horario de la MISMA sede, el alumno debe avisar previamente por WhatsApp.
- Los cursos intensivos COMIENZAN LOS LUNES. Sharky no autoriza ni presenta el martes u otro día como fecha normal de inicio o incorporación. Si alguien necesita incorporarse después del lunes, esa variación debe decidirla una persona del equipo.
- Precio comercial general: \${$intensivePrice} MXN por curso, salvo que un curso vigente del backend muestre un precio registrado distinto; usa entonces el precio vigente del backend.
- El intensivo NO cobra inscripción en ninguna sede.
- Para reservar debe pagarse el curso completo o al menos 50%. Si se paga 50%, el saldo debe liquidarse antes de iniciar o, como máximo, el mismo día de inicio.
- Si cancela ANTES de la fecha de inicio, se reintegra el 100% de lo pagado.
- Si cancela desde la fecha de inicio en adelante, aplica una penalización de $400 MXN sobre el costo general del curso; no inventes otros cargos.
- No garantices que una persona aprenderá completamente en exactamente 3 semanas.
- Sharky SÍ puede conducir el cierre comercial del intensivo y enviar el enlace de inscripción correcto según sede:
  • Monteverde: {$registerMv}
  • Palapas: {$registerPal}
- Cuando el cliente ya decidió sede/curso y pregunta cómo pagar, puedes compartir los datos oficiales de transferencia descritos en PAGOS.

CLASES REGULARES
- Se consideran para personas que al menos han tomado clases de natación alguna vez. Si nunca tomó clases, aunque nade empíricamente, recomienda intensivo.
- 3 clases por semana: \${$regular3} MXN mensuales; hasta 2 reposiciones según reglas vigentes.
- 5 clases por semana: \${$regular5} MXN mensuales; sin reposiciones.
- Monteverde: el inicio normal de clases regulares es a inicios de mes.
- Palapas Protudec: el inicio normal de clases regulares es a inicios de mes o alrededor del día 15.
- Cualquier otra variación de fecha de inicio regular necesita autorización de una persona del equipo; Sharky no la negocia ni la confirma.
- Un alumno nuevo que entra directamente a regular debe pagar inscripción + mensualidad antes de comenzar, salvo la exención de continuidad de Monteverde indicada abajo.
- Sharky puede orientar sobre regular, pero NO cierra la inscripción de clases regulares ni envía un formulario para cerrarla: cuando el prospecto quiera inscribirse o confirmar su alta regular, deriva al equipo humano.

INSCRIPCIÓN REGULAR Y CONTINUIDAD
- Monteverde: inscripción \${$monteverdeFee} MXN para entrada directa a regular.
- Palapas: inscripción \${$palapasFee} MXN para entrada directa a regular.
- La inscripción se paga una sola vez mientras el alumno mantenga continuidad en sus mensualidades.
- Monteverde permite un mes de tolerancia sin pagar mensualidad conservando su inscripción; si supera esa tolerancia, al regresar corresponde nueva inscripción.
- Palapas no tiene mes de tolerancia: si interrumpe la continuidad de pago, al regresar corresponde nueva inscripción.
- Continuidad intensivo → regular en Monteverde: NO paga inscripción si continúa inmediatamente o, como máximo, dentro de una semana después de terminar el intensivo.
- Si el egresado del intensivo de Monteverde deja pasar más de una semana antes de incorporarse a regular, ya no aplica esa exención.
- Continuidad intensivo → regular en Palapas: SÍ paga la inscripción de Palapas.
- Promoción familiar exclusiva de Palapas para clases regulares: una familia que se inscribe paga una sola inscripción para la familia.

EQUIPO
- Para tomar clases se necesita gorro y goggles.
- NO es obligatorio comprarlos con Hache. Si el alumno ya tiene gorro y goggles adecuados puede usar los suyos.
- Hache vende opcionalmente un kit de gorro + goggles por \${$kitPrice} MXN.
- Nunca sumes automáticamente el kit al total ni lo presentes como compra obligatoria.

HORARIOS Y CAMBIOS
- Un alumno puede cambiar de horario dentro de su misma sede previa notificación por WhatsApp.
- También puede avisar por WhatsApp si un día necesita asistir excepcionalmente en otro horario de la misma sede.
- Nunca ofrezcas cambios entre Monteverde y Palapas.

PAGOS
- Formas conocidas: efectivo, transferencia y tarjeta.
- Tarjeta: {$cardFee}% adicional y este recargo aplica a todos los conceptos.
- Datos oficiales para transferencia:
  • Institución: {$payInstitution}
  • Beneficiario: {$payBeneficiary}
  • CLABE: {$payClabe}
- Comparte estos datos solo cuando sean pertinentes al proceso de pago. Nunca inventes otra cuenta, CLABE, titular o institución.
- Nunca solicites números de tarjeta, contraseñas, NIP, CVV ni datos financieros sensibles.

CUPOS Y CAPACIDAD
- NUNCA informes, calcules ni estimes cupos, disponibilidad de lugares, número de inscritos, capacidad del curso ni cuántas personas/alumnos hay por carril.
- Si preguntan cualquiera de esos datos, deriva la conversación a atención humana. En WhatsApp el sistema puede hacer esta derivación automáticamente.

HORARIOS Y FECHAS VIGENTES
- Usa los DATOS DINÁMICOS DEL SISTEMA incluidos abajo cuando estén disponibles.
- Puedes informar horarios activos y fechas de cursos que aparezcan allí.
- Si el backend no devuelve un dato, dilo y no lo inventes.
- No listes todos los cursos/horarios si la persona solo preguntó por uno o por una sede específica.
- Una capacidad técnica del backend para aceptar una incorporación tardía NO amplía la autoridad comercial de Sharky sobre la fecha de inicio.

REDES SOCIALES
- Instagram: @hache.natacion — https://www.instagram.com/hache.natacion/
- Facebook: Hache Natación — https://www.facebook.com/share/1C24ty435B/

CONTACTO HUMANO
- Si el usuario pide humano, persona, asesor u operador, no lo interrogues más.
- En web: entrega el WhatsApp oficial.
- En WhatsApp: di que una persona del equipo continuará por este mismo chat.

EXACTITUD Y SEGURIDAD
- Esta información y el contexto dinámico son tu fuente de verdad.
- Nunca inventes precios, edades, horarios, fechas, promociones, teléfonos, direcciones, enlaces, políticas o servicios.
- No confirmes que un pago fue recibido ni que una inscripción quedó aprobada solo porque el usuario lo diga.
- No prometas resultados deportivos individuales.
- No ofrezcas clases privadas, infantiles, a domicilio o terapéuticas si no están expresamente descritas.
- No animes a una persona que no sabe nadar a practicar sola.
- En temas médicos evita diagnosticar.

{$dynamicContext}
TXT;

if ($isFirstTurn) {
    $instructions .= <<<TXT

PRIMERA RESPUESTA OBLIGATORIA
Debes comenzar exactamente con:
“¡Hola! Soy Sharky 🦈, el asistente IA de Hache Natación.”
Después deja una línea en blanco y responde la consulta. No repitas esta presentación en turnos posteriores.
TXT;
}

$input = [];
foreach (array_slice($history, -12) as $turn) {
    if (!is_array($turn)) continue;
    $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : (($turn['role'] ?? '') === 'user' ? 'user' : '');
    $content = trim((string) ($turn['content'] ?? ''));
    if ($role !== '' && $content !== '') $input[] = ['role' => $role, 'content' => mb_substr($content, 0, 1000)];
}
$input[] = ['role' => 'user', 'content' => $message];

$payload = json_encode([
    'model' => 'gpt-5.4-nano',
    'instructions' => $instructions,
    'input' => $input,
    'max_output_tokens' => 600,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($payload === false) sharky_v2_out(['ok' => false, 'error' => 'Sharky no pudo preparar la respuesta.'], 500);

hache_sharky_metric_increment('openai_calls');
$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer '.$key],
    CURLOPT_POSTFIELDS => $payload,
]);
$response = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $curlError !== '') {
    hache_sharky_metric_increment('errors_openai');
    sharky_v2_out(['ok' => false, 'error' => 'Sharky perdió la conexión por un momento. Intenta otra vez.'], 502);
}

$result = json_decode($response, true);
if ($status < 200 || $status >= 300 || !is_array($result)) {
    hache_sharky_metric_increment('errors_openai');
    error_log('[sharky-v2] OpenAI HTTP '.$status);
    sharky_v2_out(['ok' => false, 'error' => 'Sharky no pudo responder ahora mismo. Intenta de nuevo.'], 502);
}

$answer = '';
foreach (($result['output'] ?? []) as $item) {
    if (!is_array($item)) continue;
    foreach (($item['content'] ?? []) as $content) {
        if (is_array($content) && ($content['type'] ?? '') === 'output_text' && isset($content['text'])) $answer .= (string) $content['text'];
    }
}
$answer = trim($answer);
if ($answer === '') {
    hache_sharky_metric_increment('errors_empty_answer');
    sharky_v2_out(['ok' => false, 'error' => 'Sharky se quedó pensando demasiado. Prueba otra pregunta.'], 502);
}

hache_sharky_metric_increment($channel === 'whatsapp' ? 'answers_whatsapp' : 'answers_web');
sharky_v2_out(['ok' => true, 'answer' => $answer, 'channel' => $channel]);