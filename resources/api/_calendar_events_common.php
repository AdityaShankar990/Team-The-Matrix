<?php
// _calendar_events_common.php — shared helpers for calendar-events-*.php.
// Not directly requested by the client (no REQUEST_METHOD check / no
// require_auth() of its own) -- it's included by the four endpoints
// below, the same way every other endpoint pulls in common.php.

const CALENDAR_EVENT_ID_RE = '/^[a-z0-9_-]{1,20}$/i';
const CALENDAR_EVENT_DATE_RE = '/^\d{4}-\d{2}-\d{2}$/';
// Matches assets/js/calendar.js's eventColorFromName() output (a hex
// color from its fixed palette) while still tolerating a hand-picked
// hex the client might send from elsewhere -- validated as a shape
// (short hex string), not against the exact palette, so the palette
// can change client-side without this needing to be kept in sync.
const CALENDAR_EVENT_COLOR_RE = '/^#[0-9a-f]{3,8}$/i';

// A generous ceiling, not a real-world expectation -- just stops a
// single account from growing this table without bound (matches the
// spirit of state-w.php's 8MB cap on the old whole-blob approach).
const CALENDAR_EVENT_MAX_PER_USER = 2000;

// Validates one { id, name, date, endDate?, color? } payload. Returns
// a clean row array on success, or calls fail() (ending the request)
// on the first problem -- $label lets the bulk endpoint's error
// mention which item in the array was bad.
function validate_calendar_event_input($in, $label = null) {
    $suffix = $label !== null ? " ($label)" : '';

    $id = trim((string)($in['id'] ?? ''));
    if (!preg_match(CALENDAR_EVENT_ID_RE, $id)) fail(msg('invalid_event_id') . $suffix);

    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') fail(msg('event_name_required') . $suffix);
    if (strlen($name) > 200) $name = substr($name, 0, 200);

    $date = trim((string)($in['date'] ?? ''));
    if (!preg_match(CALENDAR_EVENT_DATE_RE, $date)) fail(msg('event_date_required') . $suffix);

    $endDate = trim((string)($in['endDate'] ?? ''));
    if ($endDate !== '' && !preg_match(CALENDAR_EVENT_DATE_RE, $endDate)) fail(msg('invalid_date') . $suffix);
    if ($endDate !== '' && $endDate < $date) $endDate = ''; // ignore a bogus end-before-start range rather than failing the whole request

    $color = trim((string)($in['color'] ?? ''));
    if ($color !== '' && !preg_match(CALENDAR_EVENT_COLOR_RE, $color)) $color = ''; // silently drop an odd color rather than failing the request over cosmetics

    return [
        'id' => $id,
        'name' => $name,
        'date' => $date,
        'end_date' => $endDate !== '' ? $endDate : null,
        'color' => $color !== '' ? $color : null,
    ];
}

// Shape returned to the client mirrors S.events' own shape (assets/js/
// calendar.js) so the front-end can drop a response straight into
// S.events without any renaming.
function calendar_event_row_to_json($row) {
    $out = [
        'id' => $row['id'],
        'name' => $row['name'],
        'date' => $row['event_date'],
        'color' => $row['color'],
    ];
    if (!empty($row['end_date'])) $out['endDate'] = $row['end_date'];
    return $out;
}
