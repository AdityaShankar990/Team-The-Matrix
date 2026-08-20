<?php
// POST { id, name, date, endDate?, color? } -> { event }
// Creates (or, if `id` already exists for this user, updates) one
// personal calendar event. Used by assets/js/calendar.js's addEvent().
// The upsert makes a retried/duplicate push harmless -- see
// _flushStatePush()'s retry-on-failure pattern in storage.js, which
// this follows: the client fires this off optimistically and may send
// it again if the first attempt's response never arrived.
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/_calendar_events_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(msg('post_only'), 405);
$me = require_auth();
$in = json_input();

$row = validate_calendar_event_input($in);

$pdo = get_db();

// Only enforce the cap on a genuinely new id -- an update to an
// existing event must never be blocked by it.
$exists = $pdo->prepare('SELECT 1 FROM calendar_events WHERE user_id = ? AND id = ?');
$exists->execute([$me['id'], $row['id']]);
if (!$exists->fetch()) {
    $count = $pdo->prepare('SELECT COUNT(*) FROM calendar_events WHERE user_id = ?');
    $count->execute([$me['id']]);
    if ((int)$count->fetchColumn() >= CALENDAR_EVENT_MAX_PER_USER) fail(msg('too_many_calendar_events'));
}

$stmt = $pdo->prepare(
    'INSERT INTO calendar_events (id, user_id, name, event_date, end_date, color)
     VALUES (?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE name = VALUES(name), event_date = VALUES(event_date),
        end_date = VALUES(end_date), color = VALUES(color), updated_at = NOW()'
);
$stmt->execute([$row['id'], $me['id'], $row['name'], $row['date'], $row['end_date'], $row['color']]);

respond(['event' => [
    'id' => $row['id'],
    'name' => $row['name'],
    'date' => $row['date'],
    'endDate' => $row['end_date'],
    'color' => $row['color'],
]], 201);
