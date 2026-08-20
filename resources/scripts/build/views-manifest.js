// scripts/build/views-manifest.js
//
// The filename <-> const-name table for assets/js/views/*.js, used by
// reformat-views.js. Kept as an explicit table (not derived from the
// filename) because a couple of the const names don't follow the
// obvious pattern -- e.g. view-comp-exam.js declares
// VIEW_COMPEXAM_HTML, not VIEW_COMP_EXAM_HTML -- so guessing would
// silently break those.

module.exports = [
    { jsFile: 'shell.js', constName: 'SHELL_HTML' },
    { jsFile: 'view-about.js', constName: 'VIEW_ABOUT_HTML' },
    { jsFile: 'view-auth.js', constName: 'VIEW_AUTH_HTML' },
    { jsFile: 'view-board.js', constName: 'VIEW_BOARD_HTML' },
    { jsFile: 'view-club.js', constName: 'VIEW_CLUB_HTML' },
    { jsFile: 'view-comp-exam.js', constName: 'VIEW_COMPEXAM_HTML' },
    { jsFile: 'view-holidays.js', constName: 'VIEW_HOLIDAYS_HTML' },
    { jsFile: 'view-home.js', constName: 'VIEW_HOME_HTML' },
    { jsFile: 'view-notices.js', constName: 'VIEW_NOTICES_HTML' },
    { jsFile: 'view-privacy.js', constName: 'VIEW_PRIVACY_HTML' },
    { jsFile: 'view-pyq.js', constName: 'VIEW_PYQ_HTML' },
    { jsFile: 'view-results.js', constName: 'VIEW_RESULTS_HTML' },
    { jsFile: 'view-syllabus.js', constName: 'VIEW_SYLLABUS_HTML' },
    { jsFile: 'view-terms.js', constName: 'VIEW_TERMS_HTML' },
    { jsFile: 'view-tracker.js', constName: 'VIEW_TRACKER_HTML' },
];
