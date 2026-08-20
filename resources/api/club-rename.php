<?php
// POST { club_id, name } -> { ok: true, name }
// Only a developer account may rename a club — same gate as
// club-create.php/club-delete.php. Renaming doesn't touch membership,
// messages, or the invite code, just the display name everyone sees.
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(msg('post_only'), 405);
$me = require_auth();

if (!$me['is_developer']) {
    fail(msg('only_the_developer_can_rename_a'), 403);
}

$in = json_input();
$clubId = (int)($in['club_id'] ?? 0);
$name = trim($in['name'] ?? '');
if ($clubId <= 0) fail(msg('missing_club_id'));
if ($name === '') fail(msg('give_the_club_a_name'));
if (strlen($name) > 80) $name = substr($name, 0, 80);

$pdo = get_db();

$check = $pdo->prepare('SELECT id FROM clubs WHERE id = ?');
$check->execute([$clubId]);
if (!$check->fetch()) fail(msg('club_not_found'), 404);

// Same case-insensitive duplicate guard as club-create.php, excluding
// this club's own current row so renaming to the exact same name (just
// different casing, or a no-op save) doesn't trip over itself.
$dupe = $pdo->prepare('SELECT id FROM clubs WHERE LOWER(name) = LOWER(?) AND id != ? LIMIT 1');
$dupe->execute([$name, $clubId]);
if ($dupe->fetch()) {
    fail(msg('club_name_already_taken'), 409);
}

$pdo->prepare('UPDATE clubs SET name = ? WHERE id = ?')->execute([$name, $clubId]);

respond(['ok' => true, 'name' => $name]);
