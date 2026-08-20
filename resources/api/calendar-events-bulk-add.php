<?php
// POST { events: [ { id, name, date, endDate?, color? }, ... ] } -> { added }
// Same upsert as calendar-events-add.php but for many rows in one
// request -- backs the "add all official holidays to my calendar"
// one-tap flow (assets/js/holidays.js: listHolidaysOnCalendar()),
// which can otherwise be dozens of individual events at once.
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/_calendar_events_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(msg('post_only'), 405);
$me = require_auth();
$in = json_input();

$events = $in['events'] ?? null;
if (!is_array($events) || !count($events)) fail(msg('events_array_required'));
if (count($events) > 500) fail(msg('too_many_calendar_events')); // one request's worth -- well above any real holiday list

$rows = [];
foreach ($events as $i => $ev) {
    if (!is_array($ev)) fail(msg('invalid_data') . " (#$i)");
    $rows[] = validate_calendar_event_input($ev, "#$i");
}

$pdo = get_db();

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM calendar_events WHERE user_id = ?');
$countStmt->execute([$me['id']]);
$existingCount = (int)$countStmt->fetchColumn();

$idsStmt = $pdo->prepare('SELECT id FROM calendar_events WHERE user_id = ?');
$idsStmt->execute([$me['id']]);
$existingIds = array_flip($idsStmt->fetchAll(PDO::FETCH_COLUMN));

$newCount = 0;
foreach ($rows as $row) {
    if (!isset($existingIds[$row['id']])) $newCount++;
}
if ($existingCount + $newCount > CALENDAR_EVENT_MAX_PER_USER) fail(msg('too_many_calendar_events'));

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO calendar_events (id, user_id, name, event_date, end_date, color)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), event_date = VALUES(event_date),
            end_date = VALUES(end_date), color = VALUES(color), updated_at = NOW()'
    );
    foreach ($rows as $row) {
        $stmt->execute([$row['id'], $me['id'], $row['name'], $row['date'], $row['end_date'], $row['color']]);
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

respond(['added' => count($rows)], 201);
