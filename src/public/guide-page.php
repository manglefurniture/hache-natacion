<?php

declare(strict_types=1);

$guides = require dirname(__DIR__, 2) . '/config/seo-guides.php';
if (!isset($guideSlug, $guides[$guideSlug])) {
    http_response_code(404);
    echo 'Guía no encontrada';
    exit;
}

$guide = $guides[$guideSlug];
$baseUrl = 'https://hnatacion.com';
$canonical = $baseUrl . $guide['url'];
$h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$schema = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Article',
            '@id' => $canonical . '#article',
            'headline' => $guide['h1'],
            'description' => $guide['description'],
            'mainEntityOfPage' => $canonical,
            'inLanguage' => 'es-MX',
            'author' => [
                '@type' => 'Organization',
                '@id' => $baseUrl . '/#organization',
                'name' => 'Hache Natación',
                'url' => $baseUrl . '/',
            ],
            'publisher' => [
                '@type' => 'Organization',
                '@id' => $baseUrl . '/#organization',
                'name' => 'Hache Natación',
                'url' => $baseUrl . '/',
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => $baseUrl . '/assets/icons/hache-icon.svg',
                ],
            ],
            'image' => $baseUrl . '/assets/seo/clases-natacion-adultos-cancun.jpg',
            'datePublished' => '2026-08-27',
            'dateModified' => '2026-08-27',
        ],
        [
            '@type' => 'BreadcrumbList',
            '@id' => $canonical . '#breadcrumb',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Inicio', 'item' => $baseUrl . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Guías', 'item' => $baseUrl . '/guias/'],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $guide['h1'], 'item' => $canonical],
            ],
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#062a45">
  <title><?= $h($guide['title']) ?></title>
  <meta name="description" content="<?= $h($guide['description']) ?>">
  <meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">
  <link rel="canonical" href="<?= $h($canonical) ?>">

  <meta property="og:locale" content="es_MX">
  <meta property="og:type" content="article">
  <meta property="og:site_name" content="Hache Natación">
  <meta property="og:title" content="<?= $h($guide['h1']) ?>">
  <meta property="og:description" content="<?= $h($guide['description']) ?>">
  <meta property="og:url" content="<?= $h($canonical) ?>">
  <meta property="og:image" content="https://hnatacion.com/assets/seo/clases-natacion-adultos-cancun.jpg">
  <meta property="og:image:width" content="1672">
  <meta property="og:image:height" content="941">
  <meta property="og:image:alt" content="Clase de natación para adultos de Hache Natación en Cancún">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= $h($guide['h1']) ?>">
  <meta name="twitter:description" content="<?= $h($guide['description']) ?>">
  <meta name="twitter:image" content="https://hnatacion.com/assets/seo/clases-natacion-adultos-cancun.jpg">

  <link rel="icon" href="/assets/icons/hache-icon.svg" type="image/svg+xml">
  <link rel="preload" as="image" href="/assets/seo/clases-natacion-adultos-cancun.webp" type="image/webp" fetchpriority="high">
  <link rel="stylesheet" href="/assets/programas-publicos.css?v=20260827-guides1">
  <link rel="stylesheet" href="/assets/guias.css?v=20260827-guides1">
  <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
</head>
<body>
<nav class="top" aria-label="Navegación">
  <a class="brand" href="/"><span class="mark">H</span><span>H NATACIÓN</span></a>
  <a class="back" href="/guias/">← Guías</a>
</nav>

<header class="hero guide-hero">
  <img class="hero-media" src="/assets/seo/clases-natacion-adultos-cancun.webp" alt="Clase de natación para adultos de Hache Natación en Cancún" width="1672" height="941" fetchpriority="high" decoding="async">
  <div class="hero-inner">
    <div class="eyebrow"><?= $h($guide['eyebrow']) ?></div>
    <h1><?= $h($guide['h1']) ?></h1>
    <p><?= $h($guide['lead']) ?></p>
  </div>
</header>

<main>
  <article class="guide-article">
    <div class="guide-shell">
      <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="/">Inicio</a><span>›</span><a href="/guias/">Guías</a><span>›</span><span aria-current="page"><?= $h($guide['h1']) ?></span>
      </nav>

      <div class="facts guide-facts">
        <?php foreach ($guide['facts'] as $fact): ?>
          <div class="fact"><small><?= $h($fact['label']) ?></small><strong><?= $h($fact['value']) ?></strong></div>
        <?php endforeach; ?>
      </div>

      <div class="guide-body">
        <?php foreach ($guide['sections'] as $section): ?>
          <section class="guide-section">
            <h2><?= $h($section['heading']) ?></h2>
            <?php foreach ($section['paragraphs'] ?? [] as $paragraph): ?>
              <p><?= $h($paragraph) ?></p>
            <?php endforeach; ?>
            <?php if (!empty($section['bullets'])): ?>
              <ul>
                <?php foreach ($section['bullets'] as $item): ?><li><?= $h($item) ?></li><?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <?php if (!empty($section['note'])): ?>
              <aside class="guide-note"><strong><?= $h($section['note_label'] ?? 'Nota') ?></strong><p><?= $h($section['note']) ?></p></aside>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
      </div>
    </div>

    <section class="cta guide-cta">
      <div class="wrap">
        <h2><?= $h($guide['cta']['title']) ?></h2>
        <p><?= $h($guide['cta']['text']) ?></p>
        <div class="hero-actions">
          <a class="btn ghost" href="<?= $h($guide['cta']['primary_href']) ?>"><?= $h($guide['cta']['primary_label']) ?></a>
          <a class="btn ghost" href="<?= $h($guide['cta']['secondary_href']) ?>"><?= $h($guide['cta']['secondary_label']) ?></a>
        </div>
      </div>
    </section>

    <section class="wrap related-guides">
      <div class="eyebrow">SIGUE APRENDIENDO</div>
      <h2 class="section-title">Guías relacionadas.</h2>
      <div class="guide-grid compact">
        <?php foreach ($guide['related'] as $relatedSlug): $related = $guides[$relatedSlug]; ?>
          <a class="guide-card" href="<?= $h($related['url']) ?>">
            <small><?= $h($related['eyebrow']) ?></small>
            <h3><?= $h($related['h1']) ?></h3>
            <p><?= $h($related['description']) ?></p>
            <strong>Leer guía →</strong>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  </article>
</main>

<footer class="footer">
  <div><strong>H NATACIÓN</strong><br><small>Cancún · Quintana Roo</small></div>
  <nav aria-label="Más información">
    <a href="/">Inicio</a>
    <a href="/guias/">Guías</a>
    <a href="/curso-intensivo.php">Curso intensivo</a>
    <a href="/clases-regulares.php">Clases regulares</a>
    <a href="/metodologia.php">Cómo trabajamos</a>
    <a href="/privacidad/">Privacidad</a>
  </nav>
</footer>
</body>
</html>
