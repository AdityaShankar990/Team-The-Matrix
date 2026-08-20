<?php
// ============================================================
// app-state-toggle.php — set the app's function state.
// POST { status: 'running'|'stopped'|'maintenance', message?, eta? }
//   -> { ok, app_state }
//
// running      — normal operation, loading page boots straight through.
// stopped      — app is deliberately offline; loading page shows a
//                stopped screen, no entry.
// maintenance  — temporary, expects to come back; loading page shows
//                the maintenance panel with optional message/ETA.
// ============================================================
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(msg('post_only'), 405);
$me = require_auth();

error_log("app-state-toggle called by user {$me['id']} ({$me['email']})");

$in = json_input();
$status = (string)($in['status'] ?? '');
$valid = ['running', 'stopped', 'maintenance'];

if (!in_array($status, $valid, true)) {
    fail(sprintf(msg('status_invalid_prefix'), implode(', ', $valid)));
}

$message = trim((string)($in['message'] ?? ''));
$message = substr($message, 0, 300);
$eta = isset($in['eta']) ? trim((string)$in['eta']) : null;
if ($eta === '') $eta = null;
if ($eta !== null) $eta = substr($eta, 0, 50);

$configFile = __DIR__ . '/app-state.json';
$config = [
    'status' => $status,
    'message' => $message,
    'eta' => $eta,
    'updated_at' => date('c'),
    'updated_by' => $me['email']
];

if (file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
    respond([
        'ok' => true,
        'app_state' => [
            'status' => $status,
            'message' => $message,
            'eta' => $eta
        ]
    ]);
} else {
    fail(msg('could_not_write_configuration_file'), 500);
}
?>
