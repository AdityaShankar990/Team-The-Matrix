<?php
// POST multipart/form-data: file (image) -> { user }
// Stores the image under /uploads/avatars/ and sets users.avatar_url.
require_once __DIR__ . '/common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(msg('post_only'), 405);
$me = require_auth();

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errMap = [1=>'File too large',2=>'File too large',3=>'Partial upload',4=>'No file',6=>'No temp dir',7=>'Write failed'];
    $code = $_FILES['file']['error'] ?? 4;
    fail($errMap[$code] ?? msg('upload_failed'));
}

$maxBytes = 15 * 1024 * 1024; // 15 MB (raw upload before compression)
if ($_FILES['file']['size'] > $maxBytes) fail(msg('image_too_large_max_15_mb'));

$allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
$origName = basename($_FILES['file']['name']);
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if (!isset($allowed[$ext])) fail(msg('only_jpg_png_webp_or_gif'));

$pdo = get_db();

$uploadDir = __DIR__ . '/../uploads/avatars/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// Photos are already compressed/resized client-side before upload, but we
// re-encode server-side too (belt-and-braces) so a max dimension + quality
// cap is enforced no matter what sent the request.
$storedName = 'u' . $me['id'] . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
$dest = $uploadDir . $storedName;

if (!compress_and_save_image($_FILES['file']['tmp_name'], $ext, $dest)) {
    fail(msg('could_not_process_image'));
}

// Remove the user's previous avatar file, if any, to avoid piling up storage.
$stmt = $pdo->prepare('SELECT avatar_url FROM users WHERE id = ?');
$stmt->execute([$me['id']]);
$old = $stmt->fetchColumn();
if ($old && strpos($old, 'uploads/avatars/') === 0) {
    $oldPath = __DIR__ . '/../' . $old;
    if (is_file($oldPath)) @unlink($oldPath);
}

$relPath = 'uploads/avatars/' . $storedName;
$pdo->prepare('UPDATE users SET avatar_url = ? WHERE id = ?')->execute([$relPath, $me['id']]);

$stmt = $pdo->prepare('SELECT ' . user_select_columns($pdo) . ' FROM users u WHERE u.id = ?');
$stmt->execute([$me['id']]);
$user = normalize_user_row($stmt->fetch());
$user['id'] = (int)$user['id'];

respond(['user' => $user]);
