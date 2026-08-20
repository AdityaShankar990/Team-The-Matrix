<?php
// GET ?name=... -> { exists: bool, name: string }
// Lets the "create club" form check whether a name is already taken
// *before* the person hits submit, instead of only finding out after a
// failed club-create.php call. Matching is case-insensitive and ignores
// surrounding whitespace, mirroring the uniqueness check club-create.php
// itself now enforces server-side (see there) — this endpoint is purely
// advisory/UX, the real gate against a race (two people creating the
// same name at the same instant) still lives in club-create.php.
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail(msg('get_only'), 405);
require_auth();

$name = trim($_GET['name'] ?? '');
if ($name === '') {
    respond(['exists' => false, 'name' => '']);
}
if (strlen($name) > 80) $name = substr($name, 0, 80);

$pdo = get_db();
$stmt = $pdo->prepare('SELECT id FROM clubs WHERE LOWER(name) = LOWER(?) LIMIT 1');
$stmt->execute([$name]);
$exists = (bool)$stmt->fetch();

respond(['exists' => $exists, 'name' => $name]);
