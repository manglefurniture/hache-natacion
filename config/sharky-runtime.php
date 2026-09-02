<?php

declare(strict_types=1);

function hache_sharky_config_defaults(): array
{
    return [
        'sharky_edad_minima' => ['valor' => '12', 'descripcion' => 'Edad mínima atendida por Hache Natación'],
        'sharky_precio_intensivo' => ['valor' => '1200', 'descripcion' => 'Precio del curso intensivo en MXN'],
        'sharky_precio_regular_3' => ['valor' => '1000', 'descripcion' => 'Mensualidad de 3 clases por semana en MXN'],
        'sharky_precio_regular_5' => ['valor' => '1200', 'descripcion' => 'Mensualidad de 5 clases por semana en MXN'],
        'sharky_inscripcion_monteverde' => ['valor' => '500', 'descripcion' => 'Inscripción de Monteverde en MXN'],
        'sharky_inscripcion_palapas' => ['valor' => '400', 'descripcion' => 'Inscripción de Palapas en MXN'],
        'sharky_kit_gorro_goggles' => ['valor' => '300', 'descripcion' => 'Kit de gorro + goggles en MXN'],
        'sharky_recargo_tarjeta_pct' => ['valor' => '5', 'descripcion' => 'Recargo porcentual por pago con tarjeta'],
        'sharky_whatsapp' => ['valor' => '9902308165', 'descripcion' => 'WhatsApp oficial mostrado desde la web'],
        'sharky_cupo_maximo_intensivo' => ['valor' => '0', 'descripcion' => 'Cupo global por curso intensivo; 0 significa no informar disponibilidad'],
        'sharky_audio_habilitado' => ['valor' => '1', 'descripcion' => '1 permite transcribir notas de voz de WhatsApp; 0 las desactiva'],
        'sharky_audio_max_mb' => ['valor' => '4', 'descripcion' => 'Tamaño máximo de audio aceptado para transcripción, en MB'],
        'sharky_escalado_intentos' => ['valor' => '2', 'descripcion' => 'Respuestas consecutivas sin resolver antes de pasar a atención humana'],
    ];
}

function hache_sharky_pdo(): ?PDO
{
    static $pdo = false;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if ($pdo === null) {
        return null;
    }

    try {
        $config = require __DIR__.'/database.php';
        $pdo = new PDO(
            "mysql:host={$config['host']};dbname={$config['dbname']};charset={$config['charset']}",
            $config['user'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
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
    $values = [];
    foreach ($defaults as $key => $row) {
        $values[$key] = (string) $row['valor'];
    }

    $pdo ??= hache_sharky_pdo();
    if (!$pdo) {
        return $values;
    }

    try {
        $keys = array_keys($defaults);
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $pdo->prepare("SELECT clave,valor FROM configuracion WHERE clave IN ($marks)");
        $stmt->execute($keys);
        foreach ($stmt->fetchAll() as $row) {
            $key = (string) ($row['clave'] ?? '');
            if (isset($values[$key]) && $row['valor'] !== null && trim((string) $row['valor']) !== '') {
                $values[$key] = trim((string) $row['valor']);
            }
        }
    } catch (Throwable $e) {
        error_log('[sharky-runtime] configuration read failed');
    }

    return $values;
}

function hache_sharky_config_int(array $values, string $key, int $fallback, int $min, int $max): int
{
    $raw = $values[$key] ?? null;
    if (!is_numeric($raw)) {
        return $fallback;
    }
    $value = (int) $raw;
    return max($min, min($max, $value));
}

function hache_sharky_dynamic_context(?PDO $pdo, array $values): string
{
    if (!$pdo) {
        return "DATOS DINÁMICOS\n- No fue posible consultar horarios o cursos en este momento. No inventes disponibilidad.";
    }

    $lines = ["DATOS DINÁMICOS DEL SISTEMA", '- Estos datos provienen del backend actual y prevalecen sobre horarios históricos.'];

    try {
        $stmt = $pdo->query("SELECT s.nombre sede,h.hora_inicio,h.hora_fin,h.regular,h.intensivo FROM horarios h JOIN sedes s ON s.id=h.sede_id WHERE h.activo=1 AND s.activo=1 ORDER BY s.nombre,h.hora_inicio");
        $rows = $stmt->fetchAll();
        if ($rows) {
            $lines[] = '- Horarios activos registrados:';
            foreach ($rows as $row) {
                $types = [];
                if ((int) ($row['regular'] ?? 0) === 1) $types[] = 'regular';
                if ((int) ($row['intensivo'] ?? 0) === 1) $types[] = 'intensivo';
                $start = substr((string) ($row['hora_inicio'] ?? ''), 0, 5);
                $end = substr((string) ($row['hora_fin'] ?? ''), 0, 5);
                $lines[] = sprintf('  • %s: %s–%s (%s)', (string) $row['sede'], $start, $end, implode(' / ', $types));
            }
        } else {
            $lines[] = '- No hay horarios activos registrados.';
        }
    } catch (Throwable $e) {
        $lines[] = '- No se pudieron consultar los horarios activos; no inventes horarios.';
    }

    try {
        $stmt = $pdo->query("SELECT s.nombre sede,ci.fecha_inicio,ci.fecha_fin,ci.precio,COUNT(cia.id) total_alumnos FROM cursos_intensivos ci JOIN sedes s ON s.id=ci.sede_id LEFT JOIN curso_intensivo_alumnos cia ON cia.curso_intensivo_id=ci.id WHERE s.activo=1 AND ci.fecha_fin>=CURDATE() GROUP BY ci.id,s.nombre,ci.fecha_inicio,ci.fecha_fin,ci.precio ORDER BY ci.fecha_inicio ASC LIMIT 8");
        $rows = $stmt->fetchAll();
        if ($rows) {
            $capacity = hache_sharky_config_int($values, 'sharky_cupo_maximo_intensivo', 0, 0, 500);
            $lines[] = '- Cursos intensivos vigentes o próximos:';
            foreach ($rows as $row) {
                $availability = '';
                if ($capacity > 0) {
                    $enrolled = (int) ($row['total_alumnos'] ?? 0);
                    $left = max(0, $capacity - $enrolled);
                    $availability = sprintf('; cupo global configurado %d, inscritos %d, lugares calculados %d', $capacity, $enrolled, $left);
                }
                $lines[] = sprintf(
                    '  • %s: %s a %s; precio registrado $%s MXN%s',
                    (string) $row['sede'],
                    (string) $row['fecha_inicio'],
                    (string) $row['fecha_fin'],
                    rtrim(rtrim(number_format((float) $row['precio'], 2, '.', ''), '0'), '.'),
                    $availability
                );
            }
            if ($capacity === 0) {
                $lines[] = '- La capacidad máxima no está configurada: NO afirmes si hay cupo; solo informa fechas y horarios registrados.';
            }
        } else {
            $lines[] = '- No hay cursos intensivos vigentes o próximos registrados.';
        }
    } catch (Throwable $e) {
        $lines[] = '- No se pudieron consultar cursos vigentes; no inventes fechas ni cupos.';
    }

    return implode("\n", $lines);
}

function hache_sharky_openai_key(): string
{
    $fromEnvironment = trim((string) (getenv('OPENAI_API_KEY') ?: ''));
    if ($fromEnvironment !== '') {
        return $fromEnvironment;
    }

    $path = '/etc/hache-openai.env';
    if (!is_readable($path)) {
        return '';
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_starts_with($line, 'sk-')) return $line;
        if (str_starts_with($line, 'export ')) $line = trim(substr($line, 7));
        if (!str_starts_with($line, 'OPENAI_API_KEY=')) continue;
        return trim(trim(substr($line, strlen('OPENAI_API_KEY='))), "\"'");
    }
    return '';
}

function hache_sharky_normalize_text(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    return strtr($text, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
}

function hache_sharky_human_request(string $text): bool
{
    $text = hache_sharky_normalize_text($text);
    $patterns = [
        '/\b(quiero|necesito|puedo|podria|quisiera|dejame|pasame|comunica(?:me|rme)?|ponme)\b.{0,45}\b(hablar|contactar|atencion|persona|humano|asesor|operador|alguien|equipo)\b/u',
        '/\b(hablar|contactar|comunicarme)\b.{0,35}\b(con|a)\b.{0,20}\b(una persona|alguien|un humano|asesor|operador|equipo)\b/u',
        '/\b(asesor humano|atencion humana|operador humano|persona real)\b/u',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text) === 1) return true;
    }
    return false;
}

function hache_sharky_frustration(string $text): bool
{
    $text = hache_sharky_normalize_text($text);
    $patterns = [
        '/\b(no me entiendes|no estas entendiendo|ya te dije|te lo repito|no respondes lo que|no me estas ayudando)\b/u',
        '/\b(esto no sirve|no sirves|que mal servicio|estoy molesto|estoy enojado)\b/u',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text) === 1) return true;
    }
    return false;
}

function hache_sharky_answer_needs_human(string $answer): bool
{
    $text = hache_sharky_normalize_text($answer);
    return preg_match('/\b(prefiero no invent|hay que confirmar|debe confirmarse|necesita confirmacion|equipo de hache puede confirmar|una persona del equipo)\b/u', $text) === 1;
}

function hache_sharky_state_dirs(string $kind): array
{
    $suffix = match ($kind) {
        'takeover' => 'hache-whatsapp-human',
        'metrics' => 'hache-sharky-metrics',
        default => 'hache-sharky-'.$kind,
    };
    return ['/var/tmp/'.$suffix, rtrim(sys_get_temp_dir(), '/').'/'.$suffix];
}

function hache_sharky_writable_dir(string $kind): string
{
    foreach (hache_sharky_state_dirs($kind) as $dir) {
        if ((is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir)) {
            return $dir;
        }
    }
    return '';
}

function hache_sharky_contact_hash(string $contact): string
{
    $contact = preg_replace('/\D+/', '', $contact) ?: '';
    $secret = '';
    foreach ([dirname(__DIR__).'/.env', dirname(__DIR__, 2).'/.env'] as $envFile) {
        if (!is_readable($envFile)) continue;
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim((string) $line);
            if (str_starts_with($line, 'export ')) $line = trim(substr($line, 7));
            if (str_starts_with($line, 'META_APP_SECRET=')) {
                $secret = trim(trim(substr($line, strlen('META_APP_SECRET='))), "\"'");
                break 2;
            }
        }
    }
    $envSecret = trim((string) (getenv('META_APP_SECRET') ?: ''));
    if ($envSecret !== '') $secret = $envSecret;
    return hash_hmac('sha256', $contact, $secret !== '' ? $secret : 'hache-whatsapp-history');
}

function hache_sharky_takeover_path_for_hash(string $hash): string
{
    $dir = hache_sharky_writable_dir('takeover');
    return $dir === '' ? '' : $dir.'/'.$hash;
}

function hache_sharky_takeover_active(string $contact): bool
{
    $hash = hache_sharky_contact_hash($contact);
    foreach (hache_sharky_state_dirs('takeover') as $dir) {
        if (is_file($dir.'/'.$hash)) return true;
    }
    return false;
}

function hache_sharky_takeover_mark(string $contact, string $reason, string $summary = ''): bool
{
    $contact = preg_replace('/\D+/', '', $contact) ?: '';
    if ($contact === '') return false;
    $hash = hache_sharky_contact_hash($contact);
    $path = hache_sharky_takeover_path_for_hash($hash);
    if ($path === '') return false;

    $activatedAt = gmdate('c');
    if (is_file($path)) {
        $existing = json_decode((string) @file_get_contents($path), true);
        if (is_array($existing) && is_string($existing['activated_at'] ?? null)) {
            $activatedAt = $existing['activated_at'];
        }
    }

    $data = [
        'contact_hash' => $hash,
        'phone_last4' => substr($contact, -4),
        'reason' => mb_substr(trim($reason), 0, 60),
        'summary' => mb_substr(trim($summary), 0, 1200),
        'activated_at' => $activatedAt,
        'updated_at' => gmdate('c'),
    ];
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) return false;
    return @file_put_contents($path, $encoded, LOCK_EX) !== false;
}

function hache_sharky_takeover_list(): array
{
    $items = [];
    foreach (hache_sharky_state_dirs('takeover') as $dir) {
        if (!is_dir($dir)) continue;
        foreach (glob($dir.'/*') ?: [] as $path) {
            if (!is_file($path)) continue;
            $hash = basename($path);
            if (!preg_match('/^[a-f0-9]{64}$/', $hash)) continue;
            if (isset($items[$hash])) continue;
            $raw = trim((string) @file_get_contents($path));
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $timestamp = ctype_digit($raw) ? (int) $raw : ((int) @filemtime($path));
                $data = [
                    'contact_hash' => $hash,
                    'phone_last4' => '----',
                    'reason' => 'manual_legacy',
                    'summary' => '',
                    'activated_at' => $timestamp > 0 ? gmdate('c', $timestamp) : null,
                    'updated_at' => $timestamp > 0 ? gmdate('c', $timestamp) : null,
                ];
            }
            $items[$hash] = $data;
        }
    }
    $items = array_values($items);
    usort($items, static fn(array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
    return $items;
}

function hache_sharky_takeover_resume_hash(string $hash): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) return false;
    $removed = false;
    foreach (hache_sharky_state_dirs('takeover') as $dir) {
        $path = $dir.'/'.$hash;
        if (is_file($path) && @unlink($path)) $removed = true;
    }
    if ($removed) hache_sharky_metric_increment('reactivations');
    return $removed;
}

function hache_sharky_history_summary(array $turns, string $pendingMessage = ''): string
{
    $pieces = [];
    foreach (array_slice($turns, -8) as $turn) {
        if (!is_array($turn)) continue;
        $role = ($turn['role'] ?? '') === 'assistant' ? 'Sharky' : (($turn['role'] ?? '') === 'user' ? 'Cliente' : '');
        $content = preg_replace('/\s+/u', ' ', trim((string) ($turn['content'] ?? ''))) ?: '';
        if ($role !== '' && $content !== '') {
            $pieces[] = $role.': '.mb_substr($content, 0, 180);
        }
    }
    if (trim($pendingMessage) !== '') {
        $pieces[] = 'Cliente: '.mb_substr(preg_replace('/\s+/u', ' ', trim($pendingMessage)) ?: '', 0, 180);
    }
    return mb_substr(implode(' | ', $pieces), 0, 1100);
}

function hache_sharky_metric_increment(string $key, int $amount = 1): void
{
    if (!preg_match('/^[a-z][a-z0-9_]{1,50}$/', $key)) return;
    $dir = hache_sharky_writable_dir('metrics');
    if ($dir === '') return;
    $path = $dir.'/'.gmdate('Y-m-d').'.json';
    $handle = @fopen($path, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        return;
    }
    rewind($handle);
    $raw = stream_get_contents($handle);
    $data = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($data)) $data = [];
    $data[$key] = max(0, (int) ($data[$key] ?? 0) + $amount);
    $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded !== false) {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $encoded);
    }
    flock($handle, LOCK_UN);
    fclose($handle);
}

function hache_sharky_metrics(int $days = 7): array
{
    $days = max(1, min(31, $days));
    $rows = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = gmdate('Y-m-d', time() - ($i * 86400));
        $data = [];
        foreach (hache_sharky_state_dirs('metrics') as $dir) {
            $path = $dir.'/'.$date.'.json';
            if (!is_file($path)) continue;
            $decoded = json_decode((string) @file_get_contents($path), true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    $data[$key] = (int) ($data[$key] ?? 0) + (int) $value;
                }
            }
        }
        $rows[] = ['date' => $date, 'counters' => $data];
    }
    return $rows;
}
