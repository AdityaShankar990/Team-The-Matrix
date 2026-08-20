<?php
// _moderation_common.php — shared helpers for the report/block system.
// Included by club-send.php / dm-send.php (spam rate-limit) and
// report-resolve.php (warning/block escalation on an actioned
// abusive/inappropriate-content report). Not directly requested by the
// client, same pattern as _progress_common.php / _calendar_events_common.php.

// ---------- System-detected send-rate spam ----------
// More than this many messages (club + DM combined) within this many
// seconds is treated as spam and auto-blocks the sender for 6 hours,
// immediately -- this one does NOT wait on developer approval (see the
// big comment in report-resolve.php for why the abusive/inappropriate
// path does) since a flood has to be stopped in real time, not after a
// human gets around to reviewing it. It still leaves a paper trail: a
// system-generated, already-'actioned' row in message_reports, so it
// shows up in reports-list.php as something a developer can look back
// on (and reverse, by clearing blocked_until directly in the DB, if it
// was a false positive) rather than happening invisibly.
define('SPAM_RATE_LIMIT_COUNT', 8);
define('SPAM_RATE_LIMIT_WINDOW_SECONDS', 30);
define('SPAM_BLOCK_HOURS', 6);

// Call AFTER inserting the just-sent message (so it's counted) but
// BEFORE responding to the client. $messageId/$messageType identify
// that just-sent message, purely so the auto-generated report below
// points at something concrete. Throws via fail() (same as the rest of
// this app) if the sender just tipped over the threshold -- the insert
// already happened, but require_not_blocked() on their *next* send
// stops anything further.
function check_spam_rate_limit($pdo, $me, $messageType, $messageId) {
    $windowStart = gmdate('Y-m-d H:i:s', time() - SPAM_RATE_LIMIT_WINDOW_SECONDS);

    $clubCount = $pdo->prepare('SELECT COUNT(*) FROM club_messages WHERE user_id = ? AND created_at >= ?');
    $clubCount->execute([$me['id'], $windowStart]);
    $dmCount = $pdo->prepare('SELECT COUNT(*) FROM dm_messages WHERE user_id = ? AND created_at >= ?');
    $dmCount->execute([$me['id'], $windowStart]);

    $total = (int)$clubCount->fetchColumn() + (int)$dmCount->fetchColumn();
    if ($total < SPAM_RATE_LIMIT_COUNT) return;

    $blockedUntil = gmdate('Y-m-d H:i:s', time() + SPAM_BLOCK_HOURS * 3600);
    $pdo->prepare('UPDATE users SET blocked_until = ? WHERE id = ?')->execute([$blockedUntil, $me['id']]);

    // Audit trail -- system as reporter_id 0 would violate the FK, so
    // this is filed under the sender's own account with a reason that
    // makes the automated origin obvious in reports-list.php.
    $pdo->prepare(
        "INSERT INTO message_reports (message_type, message_id, reporter_id, reason, status)
         VALUES (?, ?, ?, 'Spam (auto-detected: sent " . SPAM_RATE_LIMIT_COUNT . "+ messages in " . SPAM_RATE_LIMIT_WINDOW_SECONDS . "s)', 'actioned')"
    )->execute([$messageType, $messageId, $me['id']]);

    fail(msg('you_have_been_blocked_from_beuclub') . ' (' . SPAM_BLOCK_HOURS . 'h — sending too many messages too quickly)', 429);
}

// ---------- Warning -> block escalation for actioned reports ----------
// Called from report-resolve.php when a developer sets an 'actioned'
// status on a message report whose reason is 'Abusive or harassing' or
// 'Inappropriate content'. First offense of either kind sends a warning
// notification (via the mentions/notifications inbox -- see
// mentions-list.php, which already surfaces source_type='system'
// entries alongside real @mentions); a second escalates straight to a
// 6-hour block, same duration as the spam path above, instead of a
// second warning.
define('WARNING_BLOCK_HOURS', 6);

function apply_warning_or_block($pdo, $reportedUserId, $reasonLabel, $resolvedByDeveloperId) {
    $stmt = $pdo->prepare('SELECT warning_count FROM users WHERE id = ?');
    $stmt->execute([$reportedUserId]);
    $row = $stmt->fetch();
    if (!$row) return;
    $warnings = (int)$row['warning_count'];

    if ($warnings < 1) {
        $pdo->prepare('UPDATE users SET warning_count = warning_count + 1 WHERE id = ?')->execute([$reportedUserId]);
        $text = "You have received a warning for {$reasonLabel}. Further violations will result in a block from beuclub.";
        _send_system_notification($pdo, $reportedUserId, $resolvedByDeveloperId, $text);
    } else {
        $blockedUntil = gmdate('Y-m-d H:i:s', time() + WARNING_BLOCK_HOURS * 3600);
        $pdo->prepare('UPDATE users SET blocked_until = ? WHERE id = ?')->execute([$blockedUntil, $reportedUserId]);
        $text = "You have been blocked from beuclub for " . WARNING_BLOCK_HOURS . " hours for repeated {$reasonLabel}.";
        _send_system_notification($pdo, $reportedUserId, $resolvedByDeveloperId, $text);
    }
}

// Drops a row into `mentions` with source_type='system' so it surfaces
// in the recipient's notification bell (mentions-list.php already
// selects every row for the user regardless of source_type, and
// unseen-counts.php's mentions count is just "WHERE seen_at IS NULL" --
// neither needs a code change to pick this up). mentioned_by_user_id is
// NOT NULL / FK'd to users(id) with no real "system" account to point
// at, so it's set to whichever developer approved the report --
// meaningful in its own right ("system, approved by developer X") and
// mentions.js already renders mentioned_by's name, just not for
// source_type='system' rows (see the special-case added there).
// message_id is also NOT NULL with no matching row to reference here,
// so it's set to 0 rather than left dangling against a real message.
function _send_system_notification($pdo, $userId, $resolvedByDeveloperId, $text) {
    $pdo->prepare(
        "INSERT INTO mentions (mentioned_user_id, mentioned_by_user_id, source_type, source_id, message_id, body_snippet, created_at)
         VALUES (?, ?, 'system', NULL, 0, ?, ?)"
    )->execute([$userId, $resolvedByDeveloperId, mb_substr($text, 0, 160), gmdate('Y-m-d H:i:s')]);
}
