<?php

declare(strict_types=1);

$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$baseName = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: $scriptName;

// No alterar login, endpoints API ni formularios/páginas públicas.
$publicPages = [
    '/',
    '/home.php',
    '/registro.php',
    '/curso-intensivo.php',
    '/clases-regulares.php',
    '/monteverde-regular.php',
    '/monteverde-intensivo.php',
    '/palapas-regular.php',
    '/palapas-intensivo.php',
    '/inscripcion-intensivo.php',
];

if (
    $baseName === 'index.php' ||
    str_starts_with($scriptName, '/api/') ||
    in_array($currentPath, $publicPages, true)
) {
    return;
}

require_once __DIR__ . '/auth.php';
$u = auth_user();
if (!$u) {
    header('Location: /');
    exit;
}

if (!empty($u['debe_cambiar_password']) && $currentPath !== '/cambiar-password.php') {
    header('Location: /cambiar-password.php');
    exit;
}
if ($u['rol'] === 'ALUMNO' && !in_array($currentPath, ['/mi-cuenta.php','/cambiar-password.php'], true)) {
    header('Location: /mi-cuenta.php');
    exit;
}
if ($u['rol'] === 'VERIFICADOR') {
    $adminOnly = ['/agregar-alumno.php','/editar-alumno.php','/usuarios.php'];
    if (in_array($currentPath, $adminOnly, true)) {
        header('Location: /dashboard.php');
        exit;
    }
}

// Mantener el estado administrativo alineado con las reglas reales de acceso.
// Regular: inscripción cubierta + mensualidad vigente. Monteverde exenta inscripción
// cuando el alumno viene de continuidad de intensivo; Palapas no.
if (in_array($u['rol'], ['ADMIN','VERIFICADOR'], true)) {
    try {
        require_once __DIR__ . '/reglas-acceso.php';
        $cfg = require __DIR__ . '/database.php';
        $pdoReglas = new PDO(
            "mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset={$cfg['charset']}",
            $cfg['user'],
            $cfg['password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
        );
        $sedeClaveReglas = auth_resolve_sede_clave(null);
        $stReglas = $pdoReglas->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");
        $stReglas->execute([':c'=>$sedeClaveReglas]);
        if ($sedeIdReglas = $stReglas->fetchColumn()) regla_reconciliar_sede($pdoReglas,(string)$sedeIdReglas);
    } catch (Throwable $e) {
        // La reconciliación no debe romper la navegación; los endpoints de pago vuelven a validar.
    }
}

ob_start(static function (string $html): string {
    if (stripos($html, '<html') === false || stripos($html, '<body') === false) {
        return $html;
    }

    $css = '<link rel="stylesheet" href="/assets/backend-menu.css">';
    $diag = '<script src="/assets/diagnostico.js?v=20260817-1"></script>';
    $js = '<script src="/assets/backend-menu.js" defer></script>';

    if (stripos($html, '/assets/backend-menu.css') === false) {
        if (stripos($html, '</head>') !== false) {
            $html = preg_replace('/<\/head>/i', $css . "\n</head>", $html, 1) ?? $html;
        } else {
            $html = $css . $html;
        }
    }

    if (stripos($html, '/assets/diagnostico.js') === false) {
        if (stripos($html, '</head>') !== false) {
            $html = preg_replace('/<\/head>/i', $diag . "\n</head>", $html, 1) ?? $html;
        } else {
            $html = $diag . $html;
        }
    }

    if (stripos($html, '/assets/backend-menu.js') === false) {
        if (stripos($html, '</body>') !== false) {
            $html = preg_replace('/<\/body>/i', $js . "\n</body>", $html, 1) ?? $html;
        } else {
            $html .= $js;
        }
    }

    return $html;
});