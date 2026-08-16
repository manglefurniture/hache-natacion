<?php

declare(strict_types=1);

$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$baseName = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));

// No alterar login, endpoints API ni respuestas no visuales.
if ($baseName === 'index.php' || str_starts_with($scriptName, '/api/')) {
    return;
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
