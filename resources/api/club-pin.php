<?php
// POST { club_id, pinned: true|false } -> { ok: true, pinned: bool }
//
// Pins a club to the top of the Clubs list — this used to be
// localStorage-only (per-device), which meant pinning a club on your
// phone didn't carry over to the same account on a laptop. Moved
// server-side onto club_members.pinned, same table/pattern as
// `following` (see club-follow.php's header comment for why the insert
// here can't just reuse club-messages.php's plain INSERT IGNORE).
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(msg('post_only'), 405);
$me = require_auth();

$in = json_input();
$clubId = (int)($in['club_id'] ?? 0);
if ($clubId <= 0 || !array_key_exists('pinned', $in)) fail(msg('invalid_action'));
$pinned = (bool)$in['pinned'];

$pdo = get_db();

$check = $pdo->prepare('SELECT id FROM clubs WHERE id = ?');
$check->execute([$clubId]);
if (!$check->fetch()) fail(msg('club_not_found'), 404);

$pdo->prepare(
    'INSERT INTO club_members (club_id, user_id, role, pinned)
     VALUES (?, ?, \'member\', ?)
     ON DUPLICATE KEY UPDATE pinned = VALUES(pinned)'
)->execute([$clubId, $me['id'], $pinned ? 1 : 0]);

respond(['ok' => true, 'pinned' => $pinned]);
