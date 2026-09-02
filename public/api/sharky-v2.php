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
if (mb_strlen($message) > 700) {
    $message = mb_substr($message, 0, 700);
}

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

PÚBLICO
- Edad mínima: {$age} años.
- No ofrecemos clases para menores de {$age} años.
- Si se confirma que el posible alumno tiene menos de {$age} años, informa amablemente el límite y CIERRA la orientación comercial: no preguntes nivel, sede, horarios ni hagas seguimiento.

SEDES
- Sedes: Monteverde y Palapas.
- No se permiten cambios ni reposiciones entre sedes.

CURSO INTENSIVO
- Principalmente para quien no sabe nadar, tiene miedo al agua o bases muy elementales.
- Duración: 3 semanas.
- Frecuencia: lunes a viernes.
- Incluye hasta 5 reposiciones durante la cuarta semana, según las reglas vigentes.
- Precio comercial general: \${$intensivePrice} MXN por curso, salvo que un curso vigente del backend muestre un precio registrado distinto; en ese caso usa el precio vigente del backend.
- No garantices que una persona aprenderá completamente en exactamente 3 semanas.

CLASES REGULARES
- Para quien ya tiene base y busca técnica, resistencia o estilos.
- 3 clases por semana: \${$regular3} MXN mensuales; hasta 2 reposiciones según reglas vigentes.
- 5 clases por semana: \${$regular5} MXN mensuales; sin reposiciones.

INSCRIPCIÓN Y EQUIPO
- Monteverde: \${$monteverdeFee} MXN.
- Palapas: \${$palapasFee} MXN.
- Kit gorro + goggles: \${$kitPrice} MXN.
- No confundas inscripción, mensualidad, curso y kit.

PAGOS
- Formas conocidas: efectivo, transferencia y tarjeta.
- Tarjeta: {$cardFee}% adicional.
- Nunca solicites números de tarjeta, contraseñas ni datos financieros sensibles.

REDES SOCIALES
- Instagram: @hache.natacion — https://www.instagram.com/hache.natacion/
- Facebook: Hache Natación — https://www.facebook.com/share/1C24ty435B/

HORARIOS, FECHAS Y CUPOS
- Usa los DATOS DINÁMICOS DEL SISTEMA incluidos abajo cuando estén disponibles.
- Puedes informar horarios activos y fechas de cursos que aparezcan allí.
- Solo afirma lugares disponibles cuando el contexto dinámico incluya explícitamente “lugares calculados”.
- Si no existe capacidad configurada, NO afirmes si hay cupo aunque conozcas el número de inscritos.
- Si el backend no devuelve un dato, dilo y no lo inventes.

ORIENTACIÓN
- Si no sabe nadar o tiene bases elementales: intensivo.
- Si ya nada y busca mejorar: regular.
- Si el nivel no está claro, pregunta brevemente qué puede hacer en el agua.
- Después identifica la sede si todavía no la indicó.

CONTACTO HUMANO
- Si el usuario pide humano, persona, asesor u operador, no lo interrogues más.
- En web: entrega el WhatsApp oficial.
- En WhatsApp: di que una persona del equipo continuará por este mismo chat. El sistema externo se encargará de detener tus respuestas después de esa derivación.

EXACTITUD Y SEGURIDAD
- Esta información y el contexto dinámico son tu fuente de verdad.
- Nunca inventes precios, edades, horarios, fechas, cupos, promociones, teléfonos, direcciones, enlaces, políticas o servicios.
- No confirmes inscripciones, pagos, reservas ni cupos desde este chat.
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
    if ($role !== '' && $content !== '') {
        $input[] = ['role' => $role, 'content' => mb_substr($content, 0, 1000)];
    }
}
$input[] = ['role' => 'user', 'content' => $message];

$payload = json_encode([
    'model' => 'gpt-5.4-nano',
    'instructions' => $instructions,
    'input' => $input,
    'max_output_tokens' => 360,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($payload === false) {
    sharky_v2_out(['ok' => false, 'error' => 'Sharky no pudo preparar la respuesta.'], 500);
}

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
        if (is_array($content) && ($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
            $answer .= (string) $content['text'];
        }
    }
}
$answer = trim($answer);
if ($answer === '') {
    hache_sharky_metric_increment('errors_empty_answer');
    sharky_v2_out(['ok' => false, 'error' => 'Sharky se quedó pensando demasiado. Prueba otra pregunta.'], 502);
}

hache_sharky_metric_increment($channel === 'whatsapp' ? 'answers_whatsapp' : 'answers_web');
sharky_v2_out(['ok' => true, 'answer' => $answer, 'channel' => $channel]);
