<?php

declare(strict_types=1);

require_once __DIR__.'/../config/request-context.php';

function request_context_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "REQUEST_CONTEXT_REGRESSION_FAIL: {$message}\n");
        exit(1);
    }
}

$propagated = hache_request_context_from_server([
    'HTTP_X_REQUEST_ID' => 'request-42.alpha',
    'HTTP_X_CORRELATION_ID' => 'trace:42',
]);
request_context_expect($propagated['request_id'] === 'request-42.alpha', 'Debe conservar un request ID externo válido.');
request_context_expect($propagated['correlation_id'] === 'trace:42', 'Debe conservar un correlation ID externo válido.');

$generated = hache_request_context_from_server([
    'HTTP_X_REQUEST_ID' => "invalid\r\nheader",
    'HTTP_X_CORRELATION_ID' => '',
]);
request_context_expect((bool)preg_match('/^[a-f0-9]{32}$/', $generated['request_id']), 'Debe reemplazar un request ID externo inválido.');
request_context_expect($generated['correlation_id'] === $generated['request_id'], 'Correlation ID ausente debe usar el request ID local.');

$redacted = hache_log_context_redact([
    'password' => 'no-debe-aparecer',
    'nested' => ['access-token' => 'tampoco', 'safe' => 'ok'],
]);
request_context_expect($redacted['password'] === '[REDACTED]', 'Debe redactar contraseñas.');
request_context_expect($redacted['nested']['access-token'] === '[REDACTED]', 'Debe redactar secretos anidados.');
request_context_expect($redacted['nested']['safe'] === 'ok', 'No debe alterar contexto no sensible.');

$lines = [];
hache_log_event('ERROR', 'api.response.internal_error', ['api_key' => 'no-debe-aparecer', 'route' => '/api/example.php'], $propagated, static function(string $line) use (&$lines): bool {
    $lines[] = $line;
    return true;
});
$record = json_decode($lines[0] ?? '', true);
request_context_expect(is_array($record), 'El logger debe emitir JSON válido.');
request_context_expect(($record['request_id'] ?? null) === 'request-42.alpha', 'El log debe incluir request_id.');
request_context_expect(($record['correlation_id'] ?? null) === 'trace:42', 'El log debe incluir correlation_id.');
request_context_expect(($record['context']['api_key'] ?? null) === '[REDACTED]', 'El log no debe exponer api_key.');

fwrite(STDOUT, "REQUEST_CONTEXT_REGRESSION_OK\n");
