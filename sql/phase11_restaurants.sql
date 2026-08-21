-- =====================================================================
-- Smart Eats - Phase 11 migration: single restaurant to multi-restaurant
--
-- =====================================================================
--  IMPORTANT - RUN THIS FILE OR smarteats.sql, NEVER BOTH
-- =====================================================================
--
--  Use phase11_restaurants.sql  when you have Phase 1 to 10 data
--                               (real orders, edited menus) to keep.
--
--  Use sql/smarteats.sql        for a clean install with four demo
--                               restaurants and ten seeded accounts.
--
--  Importing this file and then smarteats.sql is not an upgrade path.
--  smarteats.sql drops every table and rebuilds from scratch, so it
--  throws away everything this migration just preserved.
--
--  Running it once is also enough. This file uses ADD COLUMN, so a
--  second run fails with "duplicate column name" - which is harmless
--  but looks alarming.
-- =====================================================================
--
-- Run this ONLY on a database that already holds Phase 1 to 10 data you
-- want to keep.
--
-- What it does, in order:
--   1. creates the restaurants table
--   2. adds restaurant_id to users, categories, menu_items, orders, reviews
--   3. creates one restaurant from the existing `settings` values
--   4. backfills every existing row with that restaurant's id
--   5. tightens the new columns to NOT NULL and adds the foreign keys
--
-- Existing menus, orders, reviews and accounts survive untouched. The
-- old storefront becomes the platform's first restaurant.
--
-- Import through phpMyAdmin (Import tab) or:
--   mysql -u root smarteats < phase11_restaurants.sql
-- =====================================================================

SET NAMES utf8mb4;
USE `smarteats`;

-- ---------------------------------------------------------------------
-- 1. The restaurants table
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `restaurants` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`                VARCHAR(120)  NOT NULL,
  `slug`                VARCHAR(120)  NOT NULL,
  `owner_user_id`       INT UNSIGNED  DEFAULT NULL,
  `tagline`             VARCHAR(160)  DEFAULT NULL,
  `description`         TEXT          DEFAULT NULL,
  `cuisine`             VARCHAR(60)   DEFAULT NULL,
  `phone`               VARCHAR(30)   DEFAULT NULL,
  `email`               VARCHAR(150)  DEFAULT NULL,
  `address`             VARCHAR(255)  DEFAULT NULL,
  `city`                VARCHAR(80)   DEFAULT NULL,
  `opening_hours`       VARCHAR(160)  DEFAULT NULL,
  `logo`                VARCHAR(255)  DEFAULT NULL,
  `delivery_fee`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `free_delivery_over`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `min_order_value`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tax_rate`            DECIMAL(5,4)  NOT NULL DEFAULT 0.0000,
  `is_accepting_orders` TINYINT(1)    NOT NULL DEFAULT 1,
  `approval_status`     ENUM('pending','approved','suspended') NOT NULL DEFAULT 'pending',
  `approved_at`         DATETIME      DEFAULT NULL,
  `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_restaurants_slug` (`slug`),
  KEY `idx_restaurants_status` (`approval_status`),
  KEY `idx_restaurants_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. New columns, all nullable to begin with so the backfill can run
-- ---------------------------------------------------------------------
ALTER TABLE `users`
  MODIFY `role` ENUM('customer','staff','vendor','admin') NOT NULL DEFAULT 'customer',
  ADD COLUMN `restaurant_id` INT UNSIGNED DEFAULT NULL AFTER `role`,
  ADD KEY `idx_users_restaurant` (`restaurant_id`);

ALTER TABLE `categories`
  ADD COLUMN `restaurant_id` INT UNSIGNED DEFAULT NULL AFTER `id`,
  ADD KEY `idx_categories_restaurant` (`restaurant_id`);

ALTER TABLE `menu_items`
  ADD COLUMN `restaurant_id` INT UNSIGNED DEFAULT NULL AFTER `id`,
  ADD KEY `idx_items_restaurant` (`restaurant_id`);

ALTER TABLE `orders`
  ADD COLUMN `restaurant_id` INT UNSIGNED DEFAULT NULL AFTER `order_number`,
  ADD KEY `idx_orders_restaurant` (`restaurant_id`, `status`);

ALTER TABLE `reviews`
  ADD COLUMN `restaurant_id` INT UNSIGNED DEFAULT NULL AFTER `menu_item_id`,
  ADD KEY `idx_rev_restaurant` (`restaurant_id`);

-- ---------------------------------------------------------------------
-- 3. Turn the existing storefront into the platform's first restaurant,
--    carrying over the values that used to live in `settings`.
-- ---------------------------------------------------------------------
INSERT INTO `restaurants`
  (`name`, `slug`, `tagline`, `cuisine`, `phone`, `email`, `address`,
   `city`, `opening_hours`, `delivery_fee`, `free_delivery_over`,
   `min_order_value`, `tax_rate`, `is_accepting_orders`,
   `approval_status`, `approved_at`)
SELECT
  COALESCE(MAX(CASE WHEN setting_key = 'restaurant_name'    THEN setting_value END), 'Smart Eats Kitchen'),
  'smart-eats-kitchen',
  MAX(CASE WHEN setting_key = 'restaurant_tagline'   THEN setting_value END),
  'British',
  MAX(CASE WHEN setting_key = 'restaurant_phone'     THEN setting_value END),
  MAX(CASE WHEN setting_key = 'restaurant_email'     THEN setting_value END),
  MAX(CASE WHEN setting_key = 'restaurant_address'   THEN setting_value END),
  'London',
  MAX(CASE WHEN setting_key = 'opening_hours'        THEN setting_value END),
  COALESCE(MAX(CASE WHEN setting_key = 'delivery_fee'       THEN setting_value END), 0),
  COALESCE(MAX(CASE WHEN setting_key = 'free_delivery_over' THEN setting_value END), 0),
  COALESCE(MAX(CASE WHEN setting_key = 'min_order_value'    THEN setting_value END), 0),
  COALESCE(MAX(CASE WHEN setting_key = 'tax_rate'           THEN setting_value END), 0),
  COALESCE(MAX(CASE WHEN setting_key = 'accepting_orders'   THEN setting_value END), 1),
  'approved',
  NOW()
FROM `settings`
ON DUPLICATE KEY UPDATE `slug` = `slug`;

-- ---------------------------------------------------------------------
-- 4. Backfill. Everything that existed before belongs to that restaurant.
-- ---------------------------------------------------------------------
SET @rid = (SELECT id FROM `restaurants` WHERE slug = 'smart-eats-kitchen');

UPDATE `categories` SET `restaurant_id` = @rid WHERE `restaurant_id` IS NULL;
UPDATE `menu_items` SET `restaurant_id` = @rid WHERE `restaurant_id` IS NULL;
UPDATE `orders`     SET `restaurant_id` = @rid WHERE `restaurant_id` IS NULL;

UPDATE `reviews` r
  JOIN `menu_items` m ON m.id = r.menu_item_id
  SET r.`restaurant_id` = m.`restaurant_id`
  WHERE r.`restaurant_id` IS NULL;

-- Existing staff belong to that restaurant. Existing administrators
-- become platform administrators and are deliberately left unscoped.
UPDATE `users` SET `restaurant_id` = @rid WHERE `role` = 'staff';

-- ---------------------------------------------------------------------
-- 5. Tighten the columns and add the foreign keys
-- ---------------------------------------------------------------------
ALTER TABLE `categories`
  MODIFY `restaurant_id` INT UNSIGNED NOT NULL,
  DROP INDEX `uq_categories_slug`,
  ADD UNIQUE KEY `uq_categories_slug` (`restaurant_id`, `slug`),
  ADD CONSTRAINT `fk_categories_restaurant` FOREIGN KEY (`restaurant_id`)
    REFERENCES `restaurants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `menu_items`
  MODIFY `restaurant_id` INT UNSIGNED NOT NULL,
  ADD CONSTRAINT `fk_items_restaurant` FOREIGN KEY (`restaurant_id`)
    REFERENCES `restaurants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `orders`
  MODIFY `restaurant_id` INT UNSIGNED NOT NULL,
  ADD CONSTRAINT `fk_orders_restaurant` FOREIGN KEY (`restaurant_id`)
    REFERENCES `restaurants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `reviews`
  MODIFY `restaurant_id` INT UNSIGNED NOT NULL,
  ADD CONSTRAINT `fk_rev_restaurant` FOREIGN KEY (`restaurant_id`)
    REFERENCES `restaurants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_restaurant` FOREIGN KEY (`restaurant_id`)
    REFERENCES `restaurants` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `restaurants`
  ADD CONSTRAINT `fk_restaurants_owner` FOREIGN KEY (`owner_user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- ---------------------------------------------------------------------
-- 6. Platform settings replace the per-restaurant ones
-- ---------------------------------------------------------------------
INSERT INTO `settings` (`setting_key`,`setting_value`) VALUES
('platform_name','Smart Eats'),
('platform_tagline','Order direct from local restaurants'),
('platform_email','support@smarteats.test'),
('platform_phone','020 7946 0100'),
('platform_open','1')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

DELETE FROM `settings` WHERE `setting_key` IN (
  'restaurant_name','restaurant_tagline','restaurant_phone','restaurant_email',
  'restaurant_address','opening_hours','tax_rate','delivery_fee',
  'free_delivery_over','min_order_value','accepting_orders'
);

-- ---------------------------------------------------------------------
-- 7. An owner account for the migrated restaurant
--
-- The `vendor` role did not exist before Phase 11, so a database
-- migrated from Phase 1 to 10 has administrators and kitchen staff but
-- nobody who owns a restaurant. Without an owner there is no way to try
-- the role that most of this phase is about.
--
-- This creates one, or updates it if the address is already taken:
--
--     owner.kitchen@smarteats.test / Vendor@2026
--
-- The hash below was produced by password_hash() and checked with
-- password_verify(). Change the password from Account once signed in,
-- or reset it from the panel's Staff screen.
-- ---------------------------------------------------------------------
INSERT INTO `users` (`full_name`, `email`, `password_hash`, `phone`, `role`, `restaurant_id`)
VALUES ('Restaurant Owner', 'owner.kitchen@smarteats.test',
        '$2y$10$zqV8mDqEHLUpY.UFfCB9OeoHD6JIGrfmKfx0jKbchfuh9wbN1ouYm',
        '02079461101', 'vendor', @rid)
ON DUPLICATE KEY UPDATE
  `role`          = 'vendor',
  `restaurant_id` = @rid,
  `is_active`     = 1;

UPDATE `restaurants`
   SET `owner_user_id` = (SELECT id FROM users WHERE email = 'owner.kitchen@smarteats.test')
 WHERE id = @rid AND `owner_user_id` IS NULL;

-- ---------------------------------------------------------------------
-- Check the result
-- ---------------------------------------------------------------------
SELECT r.id, r.name, r.slug, r.approval_status,
       (SELECT COUNT(*) FROM menu_items m WHERE m.restaurant_id = r.id) AS dishes,
       (SELECT COUNT(*) FROM orders o     WHERE o.restaurant_id = r.id) AS orders,
       (SELECT u.email FROM users u WHERE u.id = r.owner_user_id)       AS owner
FROM `restaurants` r;
