<?php

declare(strict_types=1);

$lab = trim((string) getenv('SHARKY_ORCHESTRATOR_LAB_ENABLED')) === '1';
require __DIR__ . ($lab ? '/../public/api/whatsapp-orchestrator-lab.php' : '/../public/api/whatsapp-webhook-v2.php');
