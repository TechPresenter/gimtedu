-- Two-factor authentication (authenticator app / TOTP) for staff accounts — app/services/two_factor.php.
-- users.two_factor_enabled and users.two_factor_secret already exist (01_core.sql); the secret is stored encrypted.

ALTER TABLE users
  ADD COLUMN two_factor_recovery TEXT NULL COMMENT 'JSON list of password_hash() values of the unused one-time recovery codes' AFTER two_factor_secret,
  ADD COLUMN two_factor_enabled_at DATETIME NULL AFTER two_factor_recovery,
  ADD COLUMN two_factor_last_step BIGINT NULL COMMENT 'last accepted 30-second TOTP step (a code is never accepted twice)' AFTER two_factor_enabled_at;
