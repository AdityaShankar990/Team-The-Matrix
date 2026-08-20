<?php
// GET -> { events: [ { id, name, date, endDate?, color } ] }
// Full list of the logged-in user's personal calendar events, read
// straight from the calendar_events table (see schema.sql). Called on
// login and on every background sync tick (assets/js/storage.js) so an
// event added on another device shows up here without a manual refresh.
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/_calendar_events_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(msg('get_only'), 405);
$me = require_auth();

$pdo = get_db();
$stmt = $pdo->prepare(
    'SELECT id, name, event_date, end_date, color
     FROM calendar_events
     WHERE user_id = ?
     ORDER BY event_date ASC'
);
$stmt->execute([$me['id']]);
$rows = $stmt->fetchAll();

respond(['events' => array_map('calendar_event_row_to_json', $rows)]);
