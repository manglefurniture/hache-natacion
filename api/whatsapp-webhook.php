<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/sharky-orchestrator-store.php';

$lab = hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED') === '1';
require __DIR__ . ($lab ? '/../public/api/whatsapp-orchestrator-lab.php' : '/../public/api/whatsapp-webhook-v2.php');
