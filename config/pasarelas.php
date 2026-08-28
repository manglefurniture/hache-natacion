<?php
declare(strict_types=1);

/**
 * Helpers reutilizables para credenciales de pasarelas de pago.
 *
 * Los secretos se cifran en base de datos con una llave que vive únicamente
 * en el entorno del servidor (HACHE_PAYMENT_CONFIG_KEY). La llave de cifrado
 * nunca debe guardarse en la base de datos ni en el repositorio.
 */

function pasarela_clave_maestra(): string
{
    $raw = trim((string)getenv('HACHE_PAYMENT_CONFIG_KEY'));
    if (strlen($raw) < 32) {
        throw new RuntimeException('HACHE_PAYMENT_CONFIG_KEY debe contener al menos 32 caracteres.');
    }
    return hash('sha256', $raw, true);
}

function pasarela_cifrar(string $plain): string
{
    if ($plain === '') return '';

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt(
        $plain,
        'aes-256-gcm',
        pasarela_clave_maestra(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );
    if ($cipher === false || strlen($tag) !== 16) {
        throw new RuntimeException('No se pudo cifrar la credencial de pago.');
    }

    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function pasarela_descifrar(?string $payload): string
{
    $payload = trim((string)$payload);
    if ($payload === '') return '';
    if (!str_starts_with($payload, 'v1:')) {
        throw new RuntimeException('Formato de credencial cifrada no reconocido.');
    }

    $decoded = base64_decode(substr($payload, 3), true);
    if ($decoded === false || strlen($decoded) < 29) {
        throw new RuntimeException('Credencial cifrada inválida.');
    }

    $iv = substr($decoded, 0, 12);
    $tag = substr($decoded, 12, 16);
    $cipher = substr($decoded, 28);
    $plain = openssl_decrypt(
        $cipher,
        'aes-256-gcm',
        pasarela_clave_maestra(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    if ($plain === false) {
        throw new RuntimeException('No se pudo descifrar la credencial de pago.');
    }

    return $plain;
}

function pasarela_mercadopago_fila(PDO $pdo, bool $forUpdate = false): ?array
{
    $sql = "SELECT proveedor,activo,entorno,public_key,access_token_enc,webhook_secret_enc,updated_at
            FROM pasarelas_pago_config
            WHERE proveedor='mercadopago'" . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->query($sql);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * Configuración de uso exclusivo del servidor para checkout/webhooks.
 * Nunca serializar este resultado hacia el navegador.
 */
function pasarela_mercadopago_credenciales(PDO $pdo): array
{
    $row = pasarela_mercadopago_fila($pdo);
    if (!$row) {
        return [
            'activo' => false,
            'entorno' => 'TEST',
            'public_key' => '',
            'access_token' => '',
            'webhook_secret' => '',
        ];
    }

    return [
        'activo' => (bool)$row['activo'],
        'entorno' => strtoupper((string)$row['entorno']) === 'PRODUCTION' ? 'PRODUCTION' : 'TEST',
        'public_key' => trim((string)$row['public_key']),
        'access_token' => pasarela_descifrar($row['access_token_enc'] ?? ''),
        'webhook_secret' => pasarela_descifrar($row['webhook_secret_enc'] ?? ''),
    ];
}

/**
 * Parte segura que sí puede entregarse al frontend del checkout.
 */
function pasarela_mercadopago_publica(PDO $pdo): array
{
    $row = pasarela_mercadopago_fila($pdo);
    return [
        'activo' => $row ? (bool)$row['activo'] : false,
        'entorno' => $row && strtoupper((string)$row['entorno']) === 'PRODUCTION' ? 'PRODUCTION' : 'TEST',
        'public_key' => $row ? trim((string)$row['public_key']) : '',
    ];
}
