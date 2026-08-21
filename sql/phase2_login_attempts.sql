-- =====================================================================
-- Smart Eats - Phase 2 migration
-- Adds login attempt logging, used to lock an account out temporarily
-- after repeated failed sign-in attempts.
--
-- Run this only if smarteats.sql was already imported during Phase 1.
-- A fresh import of smarteats.sql already contains this table.
--
--   mysql -u root smarteats < phase2_login_attempts.sql
-- =====================================================================

USE `smarteats`;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`        VARCHAR(150) NOT NULL,
  `ip_address`   VARCHAR(45)  NOT NULL,
  `was_successful` TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_lookup` (`email`, `attempted_at`),
  KEY `idx_attempts_ip` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
