<?php

declare(strict_types=1);

function hache_sharky_valid_https_url(string $value, array $allowedHosts): bool
{
    if (filter_var($value, FILTER_VALIDATE_URL) === false) return false;
    $parts = parse_url($value);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') return false;
    $host = strtolower((string)($parts['host'] ?? ''));
    return in_array($host, $allowedHosts, true);
}

function hache_sharky_config_value_valid(string $key, string $value): bool
{
    if (mb_strlen($value) > 220) return false;
    if ($key === 'sharky_whatsapp') return preg_match('/^\d{7,15}$/', preg_replace('/\D+/', '', $value) ?: '') === 1;
    if (in_array($key, ['sharky_link_registro_monteverde','sharky_link_registro_palapas'], true)) {
        return hache_sharky_valid_https_url($value, ['go.hnatacion.com']);
    }
    if (in_array($key, ['sharky_maps_monteverde','sharky_maps_palapas'], true)) {
        return hache_sharky_valid_https_url($value, ['maps.app.goo.gl','maps.google.com','google.com','www.google.com']);
    }
    if ($key === 'sharky_pago_clabe') return preg_match('/^\d{18}$/', $value) === 1;
    if (in_array($key, ['sharky_pago_institucion','sharky_pago_beneficiario'], true)) return $value !== '' && mb_strlen($value) <= 100;
    if ($key === 'sharky_audio_habilitado') return in_array($value, ['0', '1'], true);
    if ($key === 'sharky_edad_minima') return ctype_digit($value) && (int)$value >= 1 && (int)$value <= 99;
    if ($key === 'sharky_recargo_tarjeta_pct') return is_numeric($value) && (float)$value >= 0 && (float)$value <= 100;
    if ($key === 'sharky_audio_max_mb') return ctype_digit($value) && (int)$value >= 1 && (int)$value <= 20;
    if ($key === 'sharky_escalado_intentos') return ctype_digit($value) && (int)$value >= 1 && (int)$value <= 5;
    return is_numeric($value) && (float)$value >= 0 && (float)$value <= 1000000;
}
