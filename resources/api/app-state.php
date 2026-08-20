<?php
// ============================================================
// app-state.php — public read of the app's function state.
// Three states: running | stopped | maintenance
//
// GET -> { status, message, eta, updated_at }
// ============================================================
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(msg('get_only'), 405);

respond(getAppState());

// ============================================================
function getAppState() {
    $valid = ['running', 'stopped', 'maintenance'];
    $configFile = __DIR__ . '/app-state.json';

    $state = [
        'status' => 'running',
        'message' => '',
        'eta' => null,
        'updated_at' => null
    ];

    if (file_exists($configFile)) {
        try {
            $config = json_decode(file_get_contents($configFile), true);
            if (is_array($config)) {
                if (in_array($config['status'] ?? '', $valid, true)) {
                    $state['status'] = $config['status'];
                }
                $state['message'] = $config['message'] ?? '';
                $state['eta'] = $config['eta'] ?? null;
                $state['updated_at'] = $config['updated_at'] ?? null;
            }
        } catch (\Throwable $e) {
            // fall through to default 'running' state below
        }
    }

    return $state;
}
?>
