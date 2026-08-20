<?php
// ============================================================
// changelogs-update.php — update changelog entries
// POST { changelogs } -> { ok, changelogs }
// ============================================================
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(msg('post_only'), 405);
$me = require_auth();

error_log("changelogs-update called by user {$me['id']} ({$me['email']})");

$in = json_input();
$changelogs = $in['changelogs'] ?? [];

// Validate input
if (!is_array($changelogs)) {
    fail(msg('changelogs_must_be_an_array'));
}

// Sanitize and limit
$changelogs = array_filter(array_map(function($item) {
    $item = trim((string)$item);
    return $item ? substr($item, 0, 200) : null;
}, $changelogs));

$changelogs = array_values($changelogs); // Re-index
$changelogs = array_slice($changelogs, 0, 50); // Max 50 items

$configFile = __DIR__ . '/changelogs.json';
$config = [
    'changelogs' => $changelogs,
    'updated_at' => date('c'),
    'updated_by' => $me['email']
];

if (file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
    respond([
        'ok' => true,
        'changelogs' => $changelogs
    ]);
} else {
    fail(msg('could_not_write_configuration_file'), 500);
}
?>
