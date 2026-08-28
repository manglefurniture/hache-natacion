<?php

declare(strict_types=1);

$guides = require dirname(__DIR__, 2) . '/config/seo-guides.php';
$baseUrl = 'https://hnatacion.com';
$canonical = $baseUrl . '/guias/';
$h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$schema = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'CollectionPage',
            '@id' => $canonical . '#webpage',
            'url' => $canonical,
            'name' => 'Guías de natación para adultos | Hache Natación',
            'description' => 'Guías prácticas de Hache Natación sobre aprendizaje adulto, respiración, flotación, confianza y elección de programa.',
            'inLanguage' => 'es-MX',
            'about' => ['@id' => $baseUrl . '/#organization'],
        ],
        [
            '@type' => 'ItemList',
            '@id' => $canonical . '#guides',
            'itemListElement' => array_values(array_map(
                static fn(array $guide, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $guide['h1'],
                    'url' => $baseUrl . $guide['url'],
                ],
                $guides,
                array_keys(array_keys($guides))
            )),
        ],
        [
            '@type' => 'BreadcrumbList',
            '@id' => $canonical . '#breadcrumb',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Inicio', 'item' => $baseUrl . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Guías', 'item' => $canonical],
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
  <title>Guías de natación para adultos | Hache Natación Cancún</title>
  <meta name="description" content="Guías prácticas para adultos que quieren aprender a nadar: confianza en el agua, respiración, flotación, nivel y elección de programa.">
  <meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1">
  <link rel="canonical" href="https://hnatacion.com/guias/">

  <meta property="og:locale" content="es_MX">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Hache Natación">
  <meta property="og:title" content="Guías de natación para adultos | Hache Natación">
  <meta property="og:description" content="Respuestas claras para empezar, ganar confianza y elegir el programa adecuado.">
  <meta property="og:url" content="https://hnatacion.com/guias/">
  <meta property="og:image" content="https://hnatacion.com/assets/seo/clases-natacion-adultos-cancun.jpg">
  <meta property="og:image:width" content="1672">
  <meta property="og:image:height" content="941">
  <meta property="og:image:alt" content="Clase de natación para adultos de Hache Natación en Cancún">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="Guías de natación para adultos | Hache Natación">
  <meta name="twitter:description" content="Aprendizaje adulto, respiración, flotación, confianza y elección de programa.">
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
  <a class="back" href="/">← Inicio</a>
</nav>

<header class="hero guide-hero">
  <img class="hero-media" src="/assets/seo/clases-natacion-adultos-cancun.webp" alt="Clase de natación para adultos de Hache Natación en Cancún" width="1672" height="941" fetchpriority="high" decoding="async">
  <div class="hero-inner">
    <div class="eyebrow">GUÍAS HACHE NATACIÓN</div>
    <h1>Entender el agua también es parte de aprender a nadar.</h1>
    <p>Respuestas prácticas para adultos que empiezan, quieren ganar confianza o necesitan entender qué trabajar antes de elegir un programa.</p>
  </div>
</header>

<main>
  <section class="wrap">
    <div class="eyebrow">APRENDER CON CONTEXTO</div>
    <h2 class="section-title">Una pregunta real, una guía útil.</h2>
    <p class="guide-intro">Estas páginas no sustituyen una clase dentro de la alberca. Su función es ayudarte a entender conceptos, reconocer tu punto de partida y llegar mejor preparado a un proceso de aprendizaje presencial.</p>

    <div class="facts guide-principles">
      <div class="fact"><small>Contenido</small><strong>Una intención por guía</strong></div>
      <div class="fact"><small>Enfoque</small><strong>Adultos y adolescentes +12</strong></div>
      <div class="fact"><small>Objetivo</small><strong>Comprender antes de elegir</strong></div>
    </div>

    <div class="guide-grid">
      <?php foreach ($guides as $guide): ?>
        <a class="guide-card" href="<?= $h($guide['url']) ?>">
          <small><?= $h($guide['eyebrow']) ?></small>
          <h3><?= $h($guide['h1']) ?></h3>
          <p><?= $h($guide['description']) ?></p>
          <strong>Leer guía →</strong>
        </a>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="dark-section">
    <div class="wrap">
      <div class="eyebrow">DEL CONTENIDO A LA ALBERCA</div>
      <h2 class="section-title">Cuando ya sabes qué necesitas, elige cómo trabajarlo.</h2>
      <div class="steps">
        <article class="step"><span>01</span><h3>Empieza o refuerza bases</h3><p>Revisa el curso intensivo si necesitas una frecuencia alta para comenzar, ganar seguridad o consolidar fundamentos.</p><p><a href="/curso-intensivo.php"><strong>Ver curso intensivo →</strong></a></p></article>
        <article class="step"><span>02</span><h3>Da continuidad</h3><p>Revisa las clases regulares si ya tienes una base y quieres desarrollar técnica, respiración, resistencia y estilos.</p><p><a href="/clases-regulares.php"><strong>Ver clases regulares →</strong></a></p></article>
        <article class="step"><span>03</span><h3>Conoce el método</h3><p>Si todavía no sabes dónde encajas, conoce cómo Hache Natación parte del nivel real de cada alumno.</p><p><a href="/metodologia.php"><strong>Ver metodología →</strong></a></p></article>
      </div>
    </div>
  </section>

  <section class="cta">
    <div class="wrap">
      <h2>La guía aclara. La práctica construye la habilidad.</h2>
      <p>Cuando estés listo para empezar, revisa programas, sedes y disponibilidad en Cancún.</p>
      <div class="hero-actions">
        <a class="btn ghost" href="/curso-intensivo.php">Curso intensivo</a>
        <a class="btn ghost" href="/clases-regulares.php">Clases regulares</a>
      </div>
    </div>
  </section>
</main>

<footer class="footer">
  <div><strong>H NATACIÓN</strong><br><small>Cancún · Quintana Roo</small></div>
  <nav aria-label="Más información">
    <a href="/">Inicio</a>
    <a href="/curso-intensivo.php">Curso intensivo</a>
    <a href="/clases-regulares.php">Clases regulares</a>
    <a href="/metodologia.php">Cómo trabajamos</a>
    <a href="/monteverde.php">Monteverde</a>
    <a href="/palapas-protudec.php">Palapas</a>
    <a href="/privacidad/">Privacidad</a>
  </nav>
</footer>
</body>
</html>
