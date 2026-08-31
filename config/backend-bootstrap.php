<?php

declare(strict_types=1);

$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$baseName = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: $scriptName;
$requestHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
$requestHost = explode(':', $requestHost, 2)[0];

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cache-Control: private, no-store, max-age=0');
}

if ($requestHost === 'www.hnatacion.com') {
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: https://hnatacion.com' . ($requestUri !== '' ? $requestUri : '/'), true, 301);
    exit;
}

$publicPages = [
    '/', '/home.php', '/registro.php', '/curso-intensivo.php', '/clases-regulares.php',
    '/monteverde.php', '/palapas-protudec.php', '/metodologia.php',
    '/monteverde-regular.php', '/monteverde-intensivo.php', '/palapas-regular.php',
    '/palapas-intensivo.php', '/inscripcion-intensivo.php',
];
$isPublicStory = str_starts_with($currentPath, '/historias/');

if ($baseName === 'index.php' || str_starts_with($scriptName, '/api/') || $isPublicStory || in_array($currentPath, $publicPages, true)) return;

if (!headers_sent()) header('X-Robots-Tag: noindex, nofollow, noarchive');

require_once __DIR__ . '/auth.php';
$u = auth_revalidate_user();
if (!$u) { header('Location: /'); exit; }
if (!empty($u['debe_cambiar_password']) && $currentPath !== '/cambiar-password.php') { header('Location: /cambiar-password.php'); exit; }
if ($u['rol'] === 'ALUMNO' && !in_array($currentPath, ['/mi-cuenta.php','/cambiar-password.php'], true)) { header('Location: /mi-cuenta.php'); exit; }
if ($u['rol'] === 'VERIFICADOR') {
    $adminOnly = ['/agregar-alumno.php','/editar-alumno.php','/usuarios.php'];
    if (in_array($currentPath, $adminOnly, true)) { header('Location: /dashboard.php'); exit; }
}

if ($u['rol'] === 'ADMIN') {
    try {
        require_once __DIR__ . '/intensivos-estado.php';
        $sedeClaveIntensivos = auth_resolve_sede_clave(null);
        $hoyIntensivos = intensivo_hoy_operativo()->format('Y-m-d');
        $intensivosReconciliados = $_SESSION['hache_intensivos_reconciliados'][$sedeClaveIntensivos] ?? null;
        if ($intensivosReconciliados !== $hoyIntensivos) {
            $cfgIntensivos = require __DIR__ . '/database.php';
            $pdoIntensivos = new PDO(
                "mysql:host={$cfgIntensivos['host']};dbname={$cfgIntensivos['dbname']};charset={$cfgIntensivos['charset']}",
                $cfgIntensivos['user'],
                $cfgIntensivos['password'],
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
            );
            $stIntensivos = $pdoIntensivos->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");
            $stIntensivos->execute([':c'=>$sedeClaveIntensivos]);
            if ($sedeIdIntensivos = $stIntensivos->fetchColumn()) {
                intensivos_reconciliar_estados_sede($pdoIntensivos,(string)$sedeIdIntensivos);
                $_SESSION['hache_intensivos_reconciliados'][$sedeClaveIntensivos] = $hoyIntensivos;
            }
        }
    } catch (Throwable $e) { error_log('Hache estados intensivos: '.$e->getMessage()); }
}

if ($u['rol'] === 'ADMIN') {
    try {
        require_once __DIR__ . '/reglas-acceso.php';
        $sedeClaveReglas = auth_resolve_sede_clave(null);
        $hoyReglas = date('Y-m-d');
        $reconciliada = $_SESSION['hache_reconciliada'][$sedeClaveReglas] ?? null;
        if ($reconciliada !== $hoyReglas) {
            $cfg = require __DIR__ . '/database.php';
            $pdoReglas = new PDO("mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset={$cfg['charset']}",$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
            $stReglas = $pdoReglas->prepare("SELECT id FROM sedes WHERE clave=:c AND activo=1 LIMIT 1");
            $stReglas->execute([':c'=>$sedeClaveReglas]);
            if ($sedeIdReglas = $stReglas->fetchColumn()) {
                regla_reconciliar_sede_una_vez($pdoReglas,(string)$sedeIdReglas,$sedeClaveReglas);
            }
        }
    } catch (Throwable $e) { error_log('Hache reconciliación diaria: '.$e->getMessage()); }
}

ob_start(static function (string $html): string {
    if (stripos($html, '<html') === false || stripos($html, '<body') === false) return $html;
    $themeJs = '<script src="/assets/backend-theme.js?v=20260831-1"></script>';
    $css = '<link rel="stylesheet" href="/assets/backend-menu.css">';
    $relief = '<link rel="stylesheet" href="/assets/backend-relief.css?v=20260830-1">';
    $themeCss = '<link rel="stylesheet" href="/assets/backend-theme.css?v=20260831-1">';
    $themeReviewFixes = '<link rel="stylesheet" href="/assets/backend-theme-review-fixes.css?v=20260831-1">';
    $diag = '<script src="/assets/diagnostico.js?v=20260817-1"></script>';
    $js = '<script src="/assets/backend-menu.js?v=20260828-historias1" defer></script>';
    $oblig = '<script src="/assets/obligaciones-alumnos.js?v=20260821-1" defer></script>';
    $phone = '<script src="/assets/telefono-internacional.js?v=20260821-1" defer></script>';
    foreach ([['/assets/backend-theme.js',$themeJs,'</head>'],['/assets/backend-menu.css',$css,'</head>'],['/assets/backend-relief.css',$relief,'</head>'],['/assets/backend-theme.css',$themeCss,'</head>'],['/assets/backend-theme-review-fixes.css',$themeReviewFixes,'</head>'],['/assets/diagnostico.js',$diag,'</head>'],['/assets/backend-menu.js',$js,'</body>'],['/assets/obligaciones-alumnos.js',$oblig,'</body>'],['/assets/telefono-internacional.js',$phone,'</body>']] as [$needle,$tag,$close]) {
        if (stripos($html,$needle)!==false) continue;
        if (stripos($html,$close)!==false) $html=preg_replace('/'.preg_quote($close,'/').'/i',$tag."\n".$close,$html,1)??$html; else $html.=$tag;
    }
    return $html;
});
