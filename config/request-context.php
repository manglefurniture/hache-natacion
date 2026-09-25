<?php

declare(strict_types=1);

/**
 * Contexto mínimo y sin estado externo para correlacionar requests HTTP y logs.
 * Los IDs recibidos solo se reutilizan cuando cumplen el contrato de caracteres.
 */
function hache_request_normalize_external_id(mixed $value): ?string
{
    if (!is_string($value)) return null;
    $value = trim($value);
    if ($value === '' || strlen($value) > 128) return null;
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value) === 1 ? $value : null;
}

/** @return array{request_id:string,correlation_id:string} */
function hache_request_context_from_server(array $server): array
{
    $requestId = hache_request_normalize_external_id($server['HTTP_X_REQUEST_ID'] ?? null)
        ?? bin2hex(random_bytes(16));
    $correlationId = hache_request_normalize_external_id($server['HTTP_X_CORRELATION_ID'] ?? null)
        ?? $requestId;

    return ['request_id' => $requestId, 'correlation_id' => $correlationId];
}

/** @return array{request_id:string,correlation_id:string} */
function hache_request_context(): array
{
    static $context = null;
    if ($context === null) $context = hache_request_context_from_server($_SERVER);
    return $context;
}

function hache_request_apply_response_headers(): void
{
    if (headers_sent()) return;
    $context = hache_request_context();
    header('X-Request-Id: '.$context['request_id']);
    header('X-Correlation-Id: '.$context['correlation_id']);
}

function hache_log_context_redact(mixed $value, ?string $key = null): mixed
{
    if ($key !== null) {
        $normalized = strtolower(str_replace(['-', '.'], '_', $key));
        foreach (['password','passwd','token','secret','authorization','cookie','api_key','apikey','private_key','cvv','card_number'] as $fragment) {
            if (str_contains($normalized, $fragment)) return '[REDACTED]';
        }
    }
    if (is_array($value)) {
        $redacted = [];
        foreach ($value as $childKey => $childValue) {
            $redacted[$childKey] = hache_log_context_redact($childValue, is_string($childKey) ? $childKey : null);
        }
        return $redacted;
    }
    if (is_object($value)) return '[OBJECT_REDACTED]';
    if (is_resource($value)) return '[RESOURCE_REDACTED]';
    if (is_string($value) && strlen($value) > 4096) return substr($value, 0, 4096).'…[TRUNCATED]';
    return $value;
}

/**
 * @param array{request_id:string,correlation_id:string}|null $context
 * @param callable(string):mixed|null $writer
 */
function hache_log_event(string $severity, string $event, array $data = [], ?array $context = null, ?callable $writer = null): void
{
    $severity = strtoupper(trim($severity));
    if (!in_array($severity, ['DEBUG','INFO','WARN','ERROR','CRITICAL'], true)) {
        throw new InvalidArgumentException('Severidad de log no soportada.');
    }
    $event = trim($event);
    if ($event === '') throw new InvalidArgumentException('El evento de log es obligatorio.');
    $context ??= hache_request_context();
    $record = [
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'severity' => $severity,
        'service' => 'hache-natacion',
        'environment' => getenv('APP_ENV') ?: 'unknown',
        'event' => $event,
        'request_id' => $context['request_id'],
        'correlation_id' => $context['correlation_id'],
        'context' => hache_log_context_redact($data),
    ];
    $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    ($writer ?? static fn(string $entry): bool => error_log($entry))($line ?: '{"event":"log_encoding_failed"}');
}
