<?php
// POST { id } -> { ok }
// Deletes one personal calendar event. Used by assets/js/calendar.js's
// delEvent(). Deleting an id that doesn't exist (already deleted from
// another device, a duplicate click, etc.) is treated as success rather
// than an error -- the end state the client wants (that id gone) is
// already true either way.
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/_calendar_events_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(msg('post_only'), 405);
$me = require_auth();
$in = json_input();

$id = trim((string)($in['id'] ?? ''));
if (!preg_match(CALENDAR_EVENT_ID_RE, $id)) fail(msg('invalid_event_id'));

$pdo = get_db();
$stmt = $pdo->prepare('DELETE FROM calendar_events WHERE user_id = ? AND id = ?');
$stmt->execute([$me['id'], $id]);

respond(['ok' => true]);
