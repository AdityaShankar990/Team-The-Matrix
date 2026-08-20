<?php
// POST { name } -> { club }
// Only an account with users.is_developer = 1 may create a club group.
// This is checked here, server-side, regardless of what the frontend
// shows or hides — the real gate lives in this file.
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(msg('post_only'), 405);
$me = require_auth();

if (!$me['is_developer']) {
    fail(msg('only_the_developer_can_create_a'), 403);
}

$in = json_input();
$name = trim($in['name'] ?? '');
if ($name === '') fail(msg('give_the_club_a_name'));
if (strlen($name) > 80) $name = substr($name, 0, 80);

// message_mode: 'multi' (default) lets every joined member post; 'single'
// restricts posting to the owner only (see club-send.php/
// club-send-image.php for the actual server-side enforcement — this is
// just where the club's own setting is chosen and stored).
$mode = $in['mode'] ?? 'multi';
if ($mode !== 'single' && $mode !== 'multi') fail(msg('invalid_club_message_mode'));

// auto_join: when true, anyone who hasn't got a club_members row yet is
// silently enrolled the moment club-list.php loads for them -- no "Join"
// tap required. See club-list.php.
$autoJoin = !empty($in['auto_join']) ? 1 : 0;

$pdo = get_db();

// A club name isn't declared UNIQUE at the schema level (clubs.name is
// just VARCHAR), so without an explicit check here two clubs could
// silently end up with the exact same name -- confusing in the club
// list/search, and in @mentions elsewhere. Case-insensitive so "GATE CS"
// and "gate cs" are still treated as the same name.
$dupe = $pdo->prepare('SELECT id FROM clubs WHERE LOWER(name) = LOWER(?) LIMIT 1');
$dupe->execute([$name]);
if ($dupe->fetch()) {
    fail(msg('club_name_already_taken'), 409);
}

// invite_code is unique per club (schema requires it); not surfaced in the
// UI in this version, just generated so the column is always populated.
$inviteCode = random_invite_code();
for ($i = 0; $i < 5; $i++) {
    $check = $pdo->prepare('SELECT id FROM clubs WHERE invite_code = ?');
    $check->execute([$inviteCode]);
    if (!$check->fetch()) break;
    $inviteCode = random_invite_code();
}

$stmt = $pdo->prepare('INSERT INTO clubs (name, invite_code, created_by, message_mode, auto_join) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([$name, $inviteCode, $me['id'], $mode, $autoJoin]);
$clubId = $pdo->lastInsertId();

$pdo->prepare('INSERT INTO club_members (club_id, user_id, role) VALUES (?, ?, ?)')
    ->execute([$clubId, $me['id'], 'owner']);

respond([
    'club' => [
        'id' => (int)$clubId,
        'name' => $name,
        'message_mode' => $mode,
        'icon_url' => null,
        'auto_join' => (bool)$autoJoin,
        'is_member' => true,
        'is_owner' => true,
        'created_at' => gmdate('Y-m-d H:i:s'),
    ],
], 201);
