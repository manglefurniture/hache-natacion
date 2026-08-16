<?php

declare(strict_types=1);

$frontend = dirname(__DIR__) . '/frontend/index.html';

if (!is_file($frontend)) {
    http_response_code(500);
    exit('Frontend no disponible.');
}

$html = file_get_contents($frontend);
$html = str_replace('./styles.css', '/assets/styles.css', $html);
$html = str_replace('./app.js', '/assets/app.js', $html);

echo $html;
