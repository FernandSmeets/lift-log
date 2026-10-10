-- Lift Log database schema (MariaDB 11.x)
-- Applied automatically by migrate.php after each deploy (all statements are idempotent).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email           VARCHAR(254) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at   DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login sessions: the app keeps a random token, the database only stores its SHA-256 hash.
CREATE TABLE IF NOT EXISTS sessions (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  token_hash      CHAR(64)     NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at      DATETIME     NOT NULL,
  user_agent      VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sessions_token (token_hash),
  KEY ix_sessions_user (user_id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- All training data of one user (exercises, sets, weights, settings) as one JSON document,
-- the same shape the app keeps in localStorage ('liftlog_data_v2').
-- revision goes up by 1 on every save, so two devices can't silently overwrite each other.
CREATE TABLE IF NOT EXISTS user_data (
  user_id         INT UNSIGNED NOT NULL,
  data            LONGTEXT     NOT NULL,
  revision        INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_user_data_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT chk_user_data_json CHECK (JSON_VALID(data))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Forgot password" links (token hashed, valid for a short time, single use).
CREATE TABLE IF NOT EXISTS password_resets (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  token_hash      CHAR(64)     NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at      DATETIME     NOT NULL,
  used_at         DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_resets_token (token_hash),
  CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed login attempts, used to slow down password guessing.
CREATE TABLE IF NOT EXISTS login_attempts (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip              VARCHAR(45)  NOT NULL,
  email           VARCHAR(254) NULL,
  attempted_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_attempts_ip_time (ip, attempted_at),
  KEY ix_attempts_email_time (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each action (login, forgot, register, reset, delete) has its own counter.
ALTER TABLE login_attempts ADD COLUMN IF NOT EXISTS action VARCHAR(20) NOT NULL DEFAULT 'login' AFTER email;
CREATE INDEX IF NOT EXISTS ix_attempts_action_ip_time ON login_attempts (action, ip, attempted_at);
CREATE INDEX IF NOT EXISTS ix_attempts_action_email_time ON login_attempts (action, email, attempted_at);

-- Reserved for push notifications later.
CREATE TABLE IF NOT EXISTS push_subscriptions (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  endpoint        VARCHAR(1000) NOT NULL,
  p256dh          VARCHAR(255) NOT NULL,
  auth            VARCHAR(255) NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_endpoint (endpoint(500)),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
