<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$guides = require $root . '/config/seo-guides.php';
$seenTitles = [];
$seenCanonicals = [];

$fail = static function (string $message): never {
    fwrite(STDERR, "SEO guides: {$message}\n");
    exit(1);
};
$assert = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) $fail($message);
};

foreach ($guides as $slug => $guide) {
    $routeFile = $root . '/public' . $guide['url'] . 'index.php';
    $assert(is_file($routeFile), "falta la ruta pública {$guide['url']}");

    ob_start();
    $guideSlug = $slug;
    require $root . '/src/public/guide-page.php';
    $html = (string)ob_get_clean();

    $canonical = 'https://hnatacion.com' . $guide['url'];
    $assert(str_contains($html, '<title>' . htmlspecialchars($guide['title'], ENT_QUOTES, 'UTF-8') . '</title>'), "title incorrecto en {$slug}");
    $assert(str_contains($html, '<link rel="canonical" href="' . $canonical . '">'), "canonical incorrecta en {$slug}");
    $assert(str_contains($html, '<meta name="robots" content="index,follow'), "robots indexable ausente en {$slug}");
    $assert(str_contains($html, '<h1>' . htmlspecialchars($guide['h1'], ENT_QUOTES, 'UTF-8') . '</h1>'), "H1 incorrecto en {$slug}");
    $assert(str_contains($html, 'href="/guias/"'), "{$slug} debe enlazar al hub de guías");
    $assert(str_contains($html, 'href="/curso-intensivo.php"') || str_contains($html, 'href="/clases-regulares.php"') || str_contains($html, 'href="/metodologia.php"'), "{$slug} debe enlazar a una página comercial o de metodología");

    preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);
    $assert(!empty($matches[1]), "JSON-LD ausente en {$slug}");
    foreach ($matches[1] as $json) {
        json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    preg_match('/<title>([^<]+)<\/title>/', $html, $titleMatch);
    preg_match('/<link rel="canonical" href="([^"]+)">/', $html, $canonicalMatch);
    $title = trim((string)($titleMatch[1] ?? ''));
    $pageCanonical = trim((string)($canonicalMatch[1] ?? ''));
    $assert($title !== '' && !isset($seenTitles[$title]), "title duplicado: {$title}");
    $assert($pageCanonical !== '' && !isset($seenCanonicals[$pageCanonical]), "canonical duplicada: {$pageCanonical}");
    $seenTitles[$title] = true;
    $seenCanonicals[$pageCanonical] = true;
}

ob_start();
require $root . '/public/guias/index.php';
$hub = (string)ob_get_clean();
$assert(str_contains($hub, '<link rel="canonical" href="https://hnatacion.com/guias/">'), 'canonical del hub incorrecta');
$assert(str_contains($hub, '<meta name="robots" content="index,follow'), 'hub debe ser indexable');
foreach ($guides as $guide) {
    $assert(str_contains($hub, 'href="' . $guide['url'] . '"'), "hub no enlaza {$guide['url']}");
}

echo "✓ guías SEO renderizadas y verificadas\n";
