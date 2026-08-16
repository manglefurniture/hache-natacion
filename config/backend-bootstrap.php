<?php

declare(strict_types=1);

$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$baseName = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));

// No alterar login, endpoints API ni respuestas no visuales.
if ($baseName === 'index.php' || str_starts_with($scriptName, '/api/')) {
    return;
}

require_once __DIR__ . '/auth.php';
$u = auth_user();
if (!$u) {
    header('Location: /');
    exit;
}

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: $scriptName;
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

ob_start(static function (string $html): string {
    if (stripos($html, '<html') === false || stripos($html, '<body') === false) {
        return $html;
    }

    $css = '<link rel="stylesheet" href="/assets/backend-menu.css">';
    $js = '<script src="/assets/backend-menu.js" defer></script>';

    if (stripos($html, '/assets/backend-menu.css') === false) {
        if (stripos($html, '</head>') !== false) {
            $html = preg_replace('/<\/head>/i', $css . "\n</head>", $html, 1) ?? $html;
        } else {
            $html = $css . $html;
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
