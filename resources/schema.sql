-- ============================================================
-- USERS
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(60) NOT NULL,
  handle VARCHAR(32) NOT NULL UNIQUE,
  avatar_url VARCHAR(255) DEFAULT NULL,
  registration_number VARCHAR(20) DEFAULT NULL,
  is_developer TINYINT(1) NOT NULL DEFAULT 0,
  batch_start_year SMALLINT DEFAULT NULL,
  batch_end_year SMALLINT DEFAULT NULL,
  -- Which AI assistant "Ask AI" buttons (PYQ/Syllabus/Comp Exam screens)
  -- should route to for this person -- 'chatgpt' (default, opens
  -- chatgpt.com with the question prefilled), 'gemini' (opens
  -- gemini.google.com and copies the question to the clipboard, since
  -- Gemini has no prefill URL param), or 'gemma_offline' (answered
  -- in-app by the local Gemma model bundled with the Windows desktop
  -- app -- see scripts/beuclub-win_app/src-tauri -- not available from
  -- a plain browser tab). See api/profile-update.php for validation.
  ai_model_preference VARCHAR(20) NOT NULL DEFAULT 'chatgpt',
  -- Moderation state (see api/_moderation_common.php). blocked_until set
  -- means the account can't send club/DM messages or friend requests
  -- until that timestamp -- club-send.php/dm-send.php/friend-request-
  -- send.php all check it. Set automatically by the system for message-
  -- rate spam (see _check_spam_rate_limit()), or by a developer via
  -- report-resolve.php after a second abusive/inappropriate-content
  -- report. warning_count tracks how many prior warnings this account
  -- has received for abusive/inappropriate content specifically -- the
  -- first such report a developer actions sends a warning notification
  -- and increments this; the second blocks instead.
  blocked_until DATETIME DEFAULT NULL,
  warning_count INT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- NOTICE_READS — per-user "I've opened this notice" marker (see
-- api/notice-mark-read.php / api/notice-reads-list.php). Once a notice
-- is marked read here, the Notices screen's NEW badge (only ever shown
-- for a notice published TODAY to begin with — see assets/js/notices.js)
-- stops showing it for that user, on any device, since this lives on
-- the server rather than in that device's localStorage.
-- notice_key is date + '|' + a lowercased/whitespace-collapsed title
-- (see common.php's notice_key()) -- BEU's notice board has no stable
-- numeric ID of its own, so title+date is the closest thing to one.
CREATE TABLE IF NOT EXISTS notice_reads (
  user_id INT NOT NULL,
  notice_key VARCHAR(210) NOT NULL,
  seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, notice_key),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- NOTICE_MAIL_LOG — one row per notice that has already triggered the
-- automatic "email everyone in the matching batch" send (see
-- api/notices.php's dispatch_new_notice_mail()), so a notice already
-- mailed once never gets re-sent on a later cache refresh/cron tick.
CREATE TABLE IF NOT EXISTS notice_mail_log (
  notice_key VARCHAR(210) NOT NULL PRIMARY KEY,
  sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  recipients INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Same "claim this key before doing the slow work, so an overlapping
-- request can't send duplicates" role as notice_mail_log above, for
-- api/results.php's own dispatch_new_result_mail() (see that file's
-- header comment for why results can't attach a PDF the way notices
-- do).
CREATE TABLE IF NOT EXISTS result_mail_log (
  result_key VARCHAR(210) NOT NULL PRIMARY KEY,
  sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  recipients INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PENDING SIGNUPS — holds an unverified signup (email + hashed password +
-- name + optional registration number + hashed OTP) until the person
-- enters the code we emailed them. Turned into a real row in `users` by
-- signup-verify.php. registration_number is optional.
CREATE TABLE IF NOT EXISTS pending_signups (
  email VARCHAR(190) PRIMARY KEY,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(60) NOT NULL,
  registration_number VARCHAR(20) DEFAULT NULL,
  otp_hash VARCHAR(255) NOT NULL,
  otp_expires_at DATETIME NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PASSWORD RESETS — holds a hashed OTP for "forgot password" requests,
-- keyed by email. A row here does NOT mean the account is compromised;
-- it's deleted as soon as the reset completes, expires, or is replaced by
-- a fresh request (same upsert pattern as pending_signups above).
CREATE TABLE IF NOT EXISTS password_resets (
  email VARCHAR(190) PRIMARY KEY,
  otp_hash VARCHAR(255) NOT NULL,
  otp_expires_at DATETIME NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AUTH TOKENS
CREATE TABLE IF NOT EXISTS auth_tokens (
  token VARCHAR(64) PRIMARY KEY,
  user_id INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CLUBS
CREATE TABLE IF NOT EXISTS clubs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  invite_code VARCHAR(12) NOT NULL UNIQUE,
  created_by INT NULL,
  -- 'multi' (default): every joined member can post. 'single': only the
  -- owner (club_members.role = 'owner') can post -- everyone else who
  -- joins can read and stays a member (unseen badges, following, etc.
  -- all still work) but the compose row is swapped for a read-only
  -- notice client-side, and club-send.php/club-send-image.php enforce
  -- the same rule server-side regardless of what the client shows.
  message_mode ENUM('single','multi') NOT NULL DEFAULT 'multi',
  -- Custom club icon (uploaded via club-icon-upload.php). NULL means "no
  -- icon set yet" -- the frontend falls back to rendering the club's
  -- initials as a text badge (see initialsOf() in club.js) until an
  -- owner/developer sets one.
  icon_url VARCHAR(255) DEFAULT NULL,
  -- When 1, any user is auto-enrolled as a member (club_members role
  -- 'member') the moment club-list.php sees they don't have a row yet --
  -- no explicit "Join" tap required. Set at creation, changeable by the
  -- owner/a developer only (see club-rename.php-style gating).
  auto_join TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CLUB MEMBERS
CREATE TABLE IF NOT EXISTS club_members (
  club_id INT NOT NULL,
  user_id INT NOT NULL,
  role ENUM('owner','member') NOT NULL DEFAULT 'member',
  joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (club_id, user_id),
  FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STUDY SESSIONS table removed — it only ever existed to back a group
-- leaderboard, which isn't part of this Club implementation.

-- CLUB CHAT
CREATE TABLE IF NOT EXISTS club_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  club_id INT NOT NULL,
  user_id INT NOT NULL,
  body VARCHAR(2000) NOT NULL,
  msg_type ENUM('text','file') NOT NULL DEFAULT 'text',
  file_name VARCHAR(255) DEFAULT NULL,
  file_size INT DEFAULT NULL,
  reply_to_id INT DEFAULT NULL,
  reply_body VARCHAR(200) DEFAULT NULL,
  reply_display_name VARCHAR(60) DEFAULT NULL,
  edited_at DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MESSAGE REPORTS — a "report" button on any club/DM chat bubble
-- (message-report.php). message_type + message_id together identify the
-- reported row (club_messages.id or dm_messages.id) -- no FK on purpose,
-- since it points at one of two different tables depending on
-- message_type, and a reported message may later be soft-deleted while
-- the report itself should still stick around for review.
CREATE TABLE IF NOT EXISTS message_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_type ENUM('club','dm') NOT NULL,
  message_id INT NOT NULL,
  reporter_id INT NOT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  -- Moderation state (reports-list.php / report-resolve.php, both
  -- developer-only). Starts 'open' on insert. A developer resolving a
  -- report sets every row for that (message_type, message_id) to the
  -- same status at once -- see report-resolve.php's comment for why
  -- it's per-item, not per-row. 'actioned' additionally soft-deletes the
  -- message itself, but ONLY for message_type='club' -- dm_messages
  -- never gets force-deleted by a developer, see dm-message-delete.php.
  status ENUM('open','reviewed','dismissed','actioned') NOT NULL DEFAULT 'open',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY idx_message (message_type, message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- FRIEND REQUESTS — a row here with status='accepted' (in either
-- direction) means the two users are friends and may DM each other (see
-- dm-send.php / dm-messages.php, which check this before allowing a
-- thread). status='pending' is an outstanding request; 'declined' means
-- the recipient said no (the sender can send a fresh request later,
-- which flips this same row back to 'pending' rather than inserting a
-- duplicate — see friend-request-send.php).
-- USER REPORTS — reporting a PERSON (from the profile view screen),
-- distinct from message_reports above which flags one specific chat
-- message. Purely a moderation log; nothing here hides/blocks anyone
-- automatically.
CREATE TABLE IF NOT EXISTS user_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reported_user_id INT NOT NULL,
  reporter_id INT NOT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  -- Same moderation-state column as message_reports.status above, same
  -- developer-only endpoints. 'actioned' here is descriptive only --
  -- there's no suspend/ban column on `users` yet, so it just records
  -- that a developer looked at it, not that anything automatic happened.
  status ENUM('open','reviewed','dismissed','actioned') NOT NULL DEFAULT 'open',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY idx_reported_user (reported_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- @MENTIONS — across club chat, DMs, and home comments. One row per
-- (message-or-comment, mentioned user). See
-- extract_mentions_and_notify() in api/common.php.
CREATE TABLE IF NOT EXISTS mentions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mentioned_user_id INT NOT NULL,
  mentioned_by_user_id INT NOT NULL,
  source_type ENUM('club', 'dm', 'comment', 'system') NOT NULL,
  source_id INT DEFAULT NULL,
  message_id INT NOT NULL,
  body_snippet VARCHAR(160) DEFAULT NULL,
  seen_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (mentioned_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (mentioned_by_user_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY idx_mentioned_unseen (mentioned_user_id, seen_at),
  KEY idx_mentioned_created (mentioned_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS friend_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  from_user_id INT NOT NULL,
  to_user_id INT NOT NULL,
  status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  responded_at DATETIME DEFAULT NULL,
  -- Set once the recipient has opened the notification bell with this
  -- request showing (see friend-requests-mark-seen.php /
  -- unseen-counts.php) -- clears the red dot the same way mentions'
  -- own seen_at does, without affecting whether the request itself is
  -- still pending/actionable on the People tab.
  seen_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_pair (from_user_id, to_user_id),
  FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- BLOCKED USERS — one row per (blocker, blocked) pair. Blocking someone
-- (from their profile view or the Friends list) immediately unfriends
-- them / cancels any pending friend request either way (see
-- user-block.php), and friend-request-send.php refuses a new request
-- while a block exists in either direction.
CREATE TABLE IF NOT EXISTS blocked_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  blocker_id INT NOT NULL,
  blocked_id INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_block_pair (blocker_id, blocked_id),
  FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY idx_blocked (blocked_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- DM THREADS
CREATE TABLE IF NOT EXISTS dm_threads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user1_id INT NOT NULL,
  user2_id INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_thread (user1_id, user2_id),
  FOREIGN KEY (user1_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (user2_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- DM MESSAGES
CREATE TABLE IF NOT EXISTS dm_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  thread_id INT NOT NULL,
  user_id INT NOT NULL,
  body VARCHAR(4000) NOT NULL,
  msg_type ENUM('text','file') NOT NULL DEFAULT 'text',
  file_name VARCHAR(255) DEFAULT NULL,
  file_size INT DEFAULT NULL,
  reply_to_id INT DEFAULT NULL,
  reply_body VARCHAR(200) DEFAULT NULL,
  reply_display_name VARCHAR(60) DEFAULT NULL,
  edited_at DATETIME DEFAULT NULL,
  deleted_at DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (thread_id) REFERENCES dm_threads(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- UNSEEN MESSAGE TRACKING — one row per (club, user) / (dm thread, user)
-- recording the highest message id that person has actually opened that
-- chat and seen. Unseen count for a club/DM is just
-- "messages with id > last_read_message_id" (0 rows = never opened it,
-- read from the start). See api/unseen-counts.php (badge counts) and
-- api/mark-read.php (bumps this whenever a chat is opened).
CREATE TABLE IF NOT EXISTS club_message_reads (
  club_id INT NOT NULL,
  user_id INT NOT NULL,
  last_read_message_id INT NOT NULL DEFAULT 0,
  -- Tracks the highest message id we've actually SUCCEEDED in emailing
  -- this member about while offline, separately from last_read_message_id
  -- above. Decoupling the two means a transient Brevo/network failure on
  -- one message no longer permanently suppresses every later offline
  -- email in the same club until the member happens to open the chat --
  -- see notify_offline_club_members() in api/common.php.
  last_notified_message_id INT NOT NULL DEFAULT 0,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (club_id, user_id),
  FOREIGN KEY (club_id) REFERENCES clubs(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dm_thread_reads (
  thread_id INT NOT NULL,
  user_id INT NOT NULL,
  last_read_message_id INT NOT NULL DEFAULT 0,
  -- Same purpose as club_message_reads.last_notified_message_id above,
  -- for DM threads -- see notify_offline_dm_recipient() in api/common.php.
  last_notified_message_id INT NOT NULL DEFAULT 0,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (thread_id, user_id),
  FOREIGN KEY (thread_id) REFERENCES dm_threads(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- APP DATA — one row per user, holds the full Daily Tracker state
-- (tasks/sites/days/events/holidays/log) as JSON. This is the single
-- source of truth; the client no longer relies on manual export/import
-- or third-party drive backups.
CREATE TABLE IF NOT EXISTS user_app_data (
  user_id INT PRIMARY KEY,
  data_json LONGTEXT NOT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CALENDAR_EVENTS — a user's personal calendar entries (assets/js/
-- calendar.js: addEvent()/delEvent(), plus the "add all official
-- holidays to my calendar" one-tap flow in holidays.js). These used to
-- live only as an `events` array inside user_app_data's single JSON
-- blob, riding along with the ~800ms-debounced whole-state push on
-- every unrelated edit (a task tick, a site added, etc.) and only ever
-- landing on another device on that device's next 30s background poll
-- or full state reload. Their own table with their own tiny endpoints
-- (api/calendar-events-*.php) means an event add/delete is a single
-- small request independent of the rest of the app's state, syncing
-- immediately instead of waiting on/being bundled with everything else.
-- id is the short client-generated string from assets/js/helpers.js's
-- uid() (Math.random().toString(36) slice) -- kept as the primary key
-- (scoped per-user) rather than an AUTO_INCREMENT int so the client can
-- assign it immediately at creation time (optimistic UI, no round trip
-- needed before the event is addressable for an edit/delete that
-- follows right after) and so INSERT ... ON DUPLICATE KEY UPDATE makes
-- a retried/duplicate push idempotent instead of creating a second row.
CREATE TABLE IF NOT EXISTS calendar_events (
  id VARCHAR(20) NOT NULL,
  user_id INT NOT NULL,
  name VARCHAR(200) NOT NULL,
  event_date DATE NOT NULL,
  end_date DATE DEFAULT NULL,
  color VARCHAR(20) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_date (user_id, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PROGRESS_ITEMS — tick marks for "done" study items: Syllabus topics
-- (assets/js/syllabus.js) and PYQ papers (assets/js/pyq.js). Both screens
-- are pure client-side drill-down pickers with no user data of their own
-- until now, so this is a small, generic table rather than two near-
-- identical ones: item_type tells the two kinds of tick apart, item_key
-- is whatever stable string identifies one item within that type (a
-- PYQ paper's own paper_id; a syllabus topic has no such id in the
-- scraped data, so the client builds one from its position --
-- branch/sem/subject/section/index, see syllabusTopicKey() in
-- syllabus.js). Only a completed item ever has a row here -- unchecking
-- one deletes its row rather than storing a completed=0, so the table
-- only ever grows with the (bounded) set of things actually finished.
CREATE TABLE IF NOT EXISTS progress_items (
  user_id INT NOT NULL,
  item_type ENUM('syllabus','pyq') NOT NULL,
  item_key VARCHAR(191) NOT NULL,
  completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, item_type, item_key),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- HOME COMMENTS — the public comment feed at the bottom of the Home tab.
CREATE TABLE IF NOT EXISTS home_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  body VARCHAR(500) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- HOME COMMENT LIKES — one row per (comment, user) so a like can be
-- toggled on/off cleanly and counted with a simple COUNT(*).
CREATE TABLE IF NOT EXISTS home_comment_likes (
  comment_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (comment_id, user_id),
  FOREIGN KEY (comment_id) REFERENCES home_comments(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- COLUMN UPGRADES — safe to run any number of times, on a fresh
-- database or an existing one. Uses the same information_schema check
-- as the indexes below, so a column that already exists (e.g. because
-- it was added by a previous partial run) is silently skipped instead
-- of throwing "#1060 Duplicate column name". This replaces the old
-- migration-run-this.sql file — running this whole script now does
-- everything that file used to do.
-- ============================================================
