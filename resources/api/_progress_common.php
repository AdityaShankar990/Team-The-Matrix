<?php
// _progress_common.php — shared helpers for progress-*.php (Syllabus
// topic / PYQ paper "done" ticks -- see schema.sql's progress_items).
// Not directly requested by the client (no REQUEST_METHOD check / no
// require_auth() of its own) -- included by the endpoints below, same
// pattern as _calendar_events_common.php.

const PROGRESS_TYPES = ['syllabus', 'pyq'];
// Generous ceiling matching the spirit of CALENDAR_EVENT_MAX_PER_USER --
// stops one account from growing this table without bound, well above
// any real syllabus+PYQ item count.
const PROGRESS_MAX_PER_USER = 5000;

function validate_progress_type($type) {
    $type = trim((string)$type);
    if (!in_array($type, PROGRESS_TYPES, true)) fail(msg('invalid_progress_type'));
    return $type;
}

function validate_progress_key($key) {
    $key = trim((string)$key);
    if ($key === '') fail(msg('invalid_progress_key'));
    if (strlen($key) > 191) fail(msg('invalid_progress_key'));
    return $key;
}
