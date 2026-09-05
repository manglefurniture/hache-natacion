<?php

declare(strict_types=1);

function hache_rum_deployed_build_id(string $root): ?string
{
    $root = rtrim($root, '/');
    $marker = $root . '/.hache-deployed-sha';

    // El deploy root publica este marcador como la frontera autoritativa y
    // web-readable. No dependemos de permisos internos de .git para servir RUM.
    if (file_exists($marker) || is_link($marker)) {
        if (is_link($marker) || !is_file($marker) || !is_readable($marker)) {
            return null;
        }
        $markerSha = @file_get_contents($marker);
        if (!is_string($markerSha)) {
            return null;
        }
        $markerSha = trim($markerSha);
        if (!preg_match('/^[a-f0-9]{40}$/', $markerSha)) {
            return null;
        }
        return 'git-' . substr($markerSha, 0, 12);
    }

    // Compatibilidad de bootstrap para instalaciones anteriores al marcador.
    $gitDir = $root . '/.git';
    if (!is_dir($gitDir)) {
        return null;
    }

    $head = @file_get_contents($gitDir . '/HEAD');
    if (!is_string($head)) {
        return null;
    }
    $head = trim($head);

    $sha = null;
    if (preg_match('/^[a-f0-9]{40}$/', $head)) {
        $sha = $head;
    } elseif (preg_match('/^ref: (refs\/[A-Za-z0-9._\/-]+)$/', $head, $match)) {
        $ref = $match[1];
        if (str_contains($ref, '..') || str_starts_with($ref, '/')) {
            return null;
        }

        $loose = @file_get_contents($gitDir . '/' . $ref);
        if (is_string($loose) && preg_match('/^[a-f0-9]{40}$/', trim($loose))) {
            $sha = trim($loose);
        } else {
            $packed = @file($gitDir . '/packed-refs', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($packed)) {
                foreach ($packed as $line) {
                    if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                        continue;
                    }
                    if (preg_match('/^([a-f0-9]{40})\s+(.+)$/', $line, $packedMatch) && $packedMatch[2] === $ref) {
                        $sha = $packedMatch[1];
                        break;
                    }
                }
            }
        }
    }

    if (!is_string($sha) || !preg_match('/^[a-f0-9]{40}$/', $sha)) {
        return null;
    }

    return 'git-' . substr($sha, 0, 12);
}
