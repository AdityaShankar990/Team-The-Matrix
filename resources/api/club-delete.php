<?php
// POST { club_id } -> { ok: true }
// Only a developer account may delete a club outright — a much heavier
// action than leaving (club-leave.php), so it's gated the same way
// club-create.php is. clubs' FK columns are all ON DELETE CASCADE
// (club_members, club_messages, club_message_reads all reference
// clubs.id — see schema.sql), so one DELETE here is enough to clean up
// every row that pointed at this club; nothing orphaned is left behind.
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(msg('post_only'), 405);
$me = require_auth();

if (!$me['is_developer']) {
    fail(msg('only_the_developer_can_delete_a'), 403);
}

$in = json_input();
$clubId = (int)($in['club_id'] ?? 0);
if ($clubId <= 0) fail(msg('missing_club_id'));

$pdo = get_db();

$check = $pdo->prepare('SELECT id FROM clubs WHERE id = ?');
$check->execute([$clubId]);
if (!$check->fetch()) fail(msg('club_not_found'), 404);

$pdo->prepare('DELETE FROM clubs WHERE id = ?')->execute([$clubId]);

respond(['ok' => true]);
