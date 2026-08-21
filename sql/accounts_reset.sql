-- =====================================================================
-- Smart Eats - staff and administrator accounts
--
-- Creates two new accounts and resets the two original ones, so there
-- are four working logins whichever set you prefer.
--
-- Import through phpMyAdmin, or:
--   mysql -u root smarteats < accounts_reset.sql
--
-- Safe to run more than once: existing rows are updated rather than
-- duplicated.
--
-- ---------------------------------------------------------------------
--  NEW ACCOUNTS
--    manager@smarteats.test   Manager@2026    admin
--    kitchen@smarteats.test   Kitchen@2026    staff
--
--  ORIGINAL ACCOUNTS, password hashes refreshed
--    admin@smarteats.test     admin123        admin
--    staff@smarteats.test     staff123        staff
-- ---------------------------------------------------------------------
--
-- The hashes below were produced with PHP's password_hash() and each one
-- was verified with password_verify() before being written here.
--
-- Change these passwords before any usability testing session.
-- =====================================================================

SET NAMES utf8mb4;
USE `smarteats`;

-- --------------------------------------------------------------------
-- New administrator: manager@smarteats.test / Manager@2026
-- --------------------------------------------------------------------
INSERT INTO `users` (`full_name`, `email`, `password_hash`, `phone`, `role`, `is_active`)
VALUES (
  'Restaurant Manager',
  'manager@smarteats.test',
  '$2y$10$H65/Lcm/M7oWCtgzQlQVUOzFDoxAZRQMncjJMxWgKcoEXK8gnRL/O',
  '02079460003',
  'admin',
  1
)
ON DUPLICATE KEY UPDATE
  `password_hash` = VALUES(`password_hash`),
  `role`          = VALUES(`role`),
  `is_active`     = 1;

-- --------------------------------------------------------------------
-- New kitchen staff: kitchen@smarteats.test / Kitchen@2026
-- --------------------------------------------------------------------
INSERT INTO `users` (`full_name`, `email`, `password_hash`, `phone`, `role`, `is_active`)
VALUES (
  'Kitchen Team',
  'kitchen@smarteats.test',
  '$2y$10$zDLVgpTrwf4IejU6EsFTXu4BJ5qY/G.Wy6hQOvzqfJ2XJcdUpQFoS',
  '02079460004',
  'staff',
  1
)
ON DUPLICATE KEY UPDATE
  `password_hash` = VALUES(`password_hash`),
  `role`          = VALUES(`role`),
  `is_active`     = 1;

-- --------------------------------------------------------------------
-- Refresh the two original accounts, in case their hashes were damaged
-- during an earlier import.
-- --------------------------------------------------------------------
INSERT INTO `users` (`full_name`, `email`, `password_hash`, `phone`, `role`, `is_active`)
VALUES (
  'System Administrator',
  'admin@smarteats.test',
  '$2y$10$dsqUdERy8ujo3Mf7t63XnuplPHEfZaDG6Oth/UlqoQ3iJHnZknZWa',
  '02079460001',
  'admin',
  1
)
ON DUPLICATE KEY UPDATE
  `password_hash` = VALUES(`password_hash`),
  `role`          = VALUES(`role`),
  `is_active`     = 1;

INSERT INTO `users` (`full_name`, `email`, `password_hash`, `phone`, `role`, `is_active`)
VALUES (
  'Kitchen Staff',
  'staff@smarteats.test',
  '$2y$10$9TxtHoBf2okBQvlH7BeUsusnUlshh./wuTf4CPkTfj/3CyD0RiD3i',
  '02079460002',
  'staff',
  1
)
ON DUPLICATE KEY UPDATE
  `password_hash` = VALUES(`password_hash`),
  `role`          = VALUES(`role`),
  `is_active`     = 1;

-- --------------------------------------------------------------------
-- Clear any lockout left over from failed sign-in attempts.
-- --------------------------------------------------------------------
DELETE FROM `login_attempts` WHERE `was_successful` = 0;

-- Confirm the accounts are present and the hashes look intact.
-- A correct bcrypt hash is exactly 60 characters and starts with $2y$.
SELECT `email`, `role`, LENGTH(`password_hash`) AS hash_length,
       LEFT(`password_hash`, 4) AS hash_prefix, `is_active`
FROM `users`
WHERE `role` IN ('admin', 'staff')
ORDER BY `role`, `email`;
