<?php

declare(strict_types=1);

require_once __DIR__.'/../config/sharky-runtime.php';
require_once __DIR__.'/../config/sharky-outbox.php';
require_once __DIR__.'/../config/sharky-inbox.php';
require_once __DIR__.'/../config/sharky-contact-book.php';
require_once __DIR__.'/../config/sharky-followup.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

if (!in_array('--dry-run', $argv, true)) {
    fwrite(STDERR, "This command is read-only and requires --dry-run\n");
    exit(2);
}

$now = time();
$minAge = 48 * 3600;
$maxAge = 72 * 3600;
$cutover = (new DateTimeImmutable('2026-09-10 13:52:26', new DateTimeZone('America/Cancun')))->getTimestamp();

$stats = [
    'mode' => 'dry-run',
    'window_hours' => ['min' => 48, 'max' => 72],
    'feature_cutover_local' => '2026-09-10 13:52:26 America/Cancun',
    'stage2_sent_contacts' => 0,
    'eligible' => 0,
    'excluded' => [
        'decrypt_failed' => 0,
        'invalid_contact' => 0,
        'outside_window' => 0,
        'covered_by_current_rule' => 0,
        'not_current_prospect' => 0,
        'registered' => 0,
        'inbound_history_unavailable' => 0,
        'replied_after_original_turn' => 0,
    ],
    'privacy' => 'aggregate_counts_only',
    'generated_at_utc' => gmdate('c', $now),
];

try {
    if (hache_sharky_orchestrator_secret('SHARKY_ORCHESTRATOR_LAB_ENABLED') !== '1') {
        throw new RuntimeException('Sharky orchestrator is not enabled');
    }
    if (strlen(hache_sharky_orchestrator_secret('SHARKY_CONTACT_HASH_KEY')) < 32) {
        throw new RuntimeException('SHARKY_CONTACT_HASH_KEY missing');
    }

    $pdo = hache_sharky_pdo();
    if (!$pdo instanceof PDO) throw new RuntimeException('Database unavailable');
    if (!hache_sharky_orchestrator_store_ready($pdo)) throw new RuntimeException('Sharky store unavailable');

    // Stage 2 is the strongest proof that this contact was already commercially
    // qualified by the existing follow-up flow. Only SENT rows are considered.
    $rows = $pdo->query(
        "SELECT contact_hash,payload_ciphertext,payload_iv,payload_tag,status,created_at,sent_at\n"
        ."FROM sharky_outbox\n"
        ."WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 DAY)\n"
        ."ORDER BY created_at ASC,id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $stage2ByContact = [];
    $hasStage3 = [];

    foreach ($rows as $row) {
        $payload = hache_sharky_outbox_decrypt($row);
        if (!is_array($payload)) {
            $stats['excluded']['decrypt_failed']++;
            continue;
        }
        $meta = $payload['_sharky_followup'] ?? null;
        if (!is_array($meta)) continue;
        $stage = (int)($meta['stage'] ?? 0);
        $contactHash = strtolower(trim((string)($row['contact_hash'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $contactHash)) continue;

        if ($stage === 3) {
            $hasStage3[$contactHash] = true;
            continue;
        }
        if ($stage !== 2 || (string)($row['status'] ?? '') !== 'SENT') continue;

        $userTurnAt = (int)($meta['user_turn_at'] ?? 0);
        $contact = preg_replace('/\D+/', '', (string)($payload['to'] ?? '')) ?: '';
        if ($userTurnAt <= 0 || $contact === '') continue;
        if (!hash_equals($contactHash, hache_sharky_orchestrator_contact_hash($contact))) continue;

        $current = $stage2ByContact[$contactHash] ?? null;
        if (!is_array($current) || $userTurnAt > (int)$current['user_turn_at']) {
            $stage2ByContact[$contactHash] = [
                'user_turn_at' => $userTurnAt,
                'contact' => $contact,
            ];
        }
    }

    $stats['stage2_sent_contacts'] = count($stage2ByContact);

    $latestInbound = $pdo->prepare(
        'SELECT MAX(UNIX_TIMESTAMP(received_at)) FROM sharky_message_receipts WHERE contact_hash=:c'
    );

    foreach ($stage2ByContact as $contactHash => $candidate) {
        $userTurnAt = (int)$candidate['user_turn_at'];
        $contact = (string)$candidate['contact'];
        $age = $now - $userTurnAt;

        if ($age < $minAge || $age > $maxAge || $userTurnAt >= $cutover) {
            $stats['excluded']['outside_window']++;
            continue;
        }

        if (($hasStage3[$contactHash] ?? false) === true) {
            $stats['excluded']['covered_by_current_rule']++;
            continue;
        }

        $normalized = hache_sharky_contact_book_normalize_phone($contact);
        if ($normalized === null) {
            $stats['excluded']['invalid_contact']++;
            continue;
        }

        $identity = hache_sharky_contact_book_identity($pdo, $normalized['e164']);
        if (($identity['role'] ?? '') !== 'PROSPECT') {
            $stats['excluded']['not_current_prospect']++;
            continue;
        }

        $registered = hache_sharky_followup_registered_contact($pdo, $contact);
        if ($registered !== false) {
            $stats['excluded']['registered']++;
            continue;
        }

        $latestInbound->execute([':c' => $contactHash]);
        $lastInboundAt = (int)($latestInbound->fetchColumn() ?: 0);
        if ($lastInboundAt <= 0) {
            $stats['excluded']['inbound_history_unavailable']++;
            continue;
        }

        // The original inbound receipt can be a few seconds later than the state
        // timestamp, so allow a small clock/processing tolerance. Any meaningful
        // later inbound means the person already replied and must be excluded.
        if ($lastInboundAt > $userTurnAt + 30) {
            $stats['excluded']['replied_after_original_turn']++;
            continue;
        }

        $stats['eligible']++;
    }

    fwrite(STDOUT, json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Sharky retroactive re-engagement dry-run: '.$e->getMessage().PHP_EOL);
    exit(1);
}
