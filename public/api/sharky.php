<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
$message = trim((string)($data['message'] ?? ''));

if ($message === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Escribe una pregunta para Sharky.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (mb_strlen($message) > 700) {
    $message = mb_substr($message, 0, 700);
}

$keyFile = '/etc/hache-openai.env';
$key = is_readable($keyFile) ? trim((string)file_get_contents($keyFile)) : '';
if (str_starts_with($key, 'OPENAI_API_KEY=')) {
    $key = trim(substr($key, strlen('OPENAI_API_KEY=')));
}

if (!str_starts_with($key, 'sk-')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Sharky no puede conectarse ahora mismo.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$instructions = <<<'TXT'
Eres Sharky, el asistente virtual de Hache Natación en Cancún, México. Eres un delfín simpático, claro y breve. Hablas español natural y cercano, sin exagerar emojis. Tu trabajo es orientar a personas interesadas en aprender o mejorar natación.

Información confirmada:
- Hache Natación trabaja en Cancún.
- Sedes: Monteverde y Palapas.
- Curso intensivo: pensado principalmente para quien no sabe nadar o tiene bases muy elementales; busca seguridad, adaptación al agua, fundamentos y técnica.
- Clases regulares: para personas que ya tienen base y quieren continuar con técnica, resistencia y estilos.
- Si alguien no sabe qué programa elegir, pregunta brevemente qué sabe hacer actualmente en el agua.

Reglas:
- No inventes precios, horarios, cupos, promociones, políticas, teléfonos ni enlaces que no estén en esta información.
- Si preguntan algo que requiere disponibilidad o datos administrativos actuales, di que debe confirmarlo con Hache Natación.
- No afirmes que una inscripción o reserva quedó hecha.
- No des consejos médicos ni de seguridad acuática que sustituyan supervisión profesional.
- Responde normalmente en 2 a 5 frases y ve al punto.
- Preséntate como Sharky solo cuando tenga sentido; no repitas tu nombre en cada respuesta.
TXT;

$payload = json_encode([
    'model' => 'gpt-5.4-nano',
    'instructions' => $instructions,
    'input' => $message,
    'max_output_tokens' => 220,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ],
    CURLOPT_POSTFIELDS => $payload,
]);

$response = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $curlError !== '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Sharky perdió la conexión por un momento. Intenta otra vez.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = json_decode($response, true);
if ($status < 200 || $status >= 300 || !is_array($result)) {
    error_log('Sharky OpenAI HTTP ' . $status);
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Sharky no pudo responder ahora mismo. Intenta de nuevo.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$answer = '';
foreach (($result['output'] ?? []) as $item) {
    foreach (($item['content'] ?? []) as $content) {
        if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
            $answer .= (string)$content['text'];
        }
    }
}
$answer = trim($answer);

if ($answer === '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Sharky se quedó pensando demasiado. Prueba otra pregunta.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'answer' => $answer], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
