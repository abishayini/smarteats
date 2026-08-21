-- =====================================================================
-- Smart Eats - Phase 11B
--
-- Structured opening hours, restaurant-level reviews, delivery zones,
-- platform commission and the reporting they feed.
--
-- SAFE TO RUN ON A DATABASE THAT ALREADY HAS DATA
--
-- Nothing here drops a table, drops a column or deletes a row. Every
-- change is additive, and every statement checks first whether it has
-- already been applied, so running this file twice is harmless and
-- running it on a database that already contains Phase 11B changes does
-- nothing at all.
--
-- That matters because the alternative pattern - a migration that
-- assumes a known starting state - is exactly what produces a half
-- applied schema when it is run at the wrong moment. Here the file can
-- be run after sql/smarteats.sql, after sql/phase11_restaurants.sql, or
-- twice in a row, and the result is the same either way.
--
-- HOW TO RUN
--   phpMyAdmin, Import tab, select this file.
--   Or:  mysql -u root smarteats < phase11b_features.sql
--
-- NOTHING CHANGES FOR CUSTOMERS UNTIL A RESTAURANT OPTS IN
-- The scheduled opening hours are seeded but left switched off
-- (`uses_schedule` is 0), so no restaurant's ordering behaviour changes
-- the moment this is imported. A restaurant owner turns the schedule on
-- from Opening hours in the panel when they are ready.
-- =====================================================================

SET NAMES utf8mb4;
USE `smarteats`;

-- ---------------------------------------------------------------------
-- A small helper pattern used throughout this file.
--
-- MySQL has no "ADD COLUMN IF NOT EXISTS" that works across the versions
-- shipped with XAMPP, so each column is added through a prepared
-- statement that is built only when information_schema says the column
-- is missing. When it is already there, the statement executed is a
-- harmless DO 0.
-- ---------------------------------------------------------------------

-- === restaurants.commission_rate ====================================
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = 'smarteats'
                   AND TABLE_NAME   = 'restaurants'
                   AND COLUMN_NAME  = 'commission_rate');
SET @ddl := IF(@exists = 0,
  'ALTER TABLE `restaurants`
     ADD COLUMN `commission_rate` DECIMAL(5,4) NOT NULL DEFAULT 0.0000
     AFTER `tax_rate`',
  'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- === restaurants.delivery_postcodes =================================
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = 'smarteats'
                   AND TABLE_NAME   = 'restaurants'
                   AND COLUMN_NAME  = 'delivery_postcodes');
SET @ddl := IF(@exists = 0,
  'ALTER TABLE `restaurants`
     ADD COLUMN `delivery_postcodes` VARCHAR(255) DEFAULT NULL
     AFTER `city`',
  'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- === restaurants.uses_schedule ======================================
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = 'smarteats'
                   AND TABLE_NAME   = 'restaurants'
                   AND COLUMN_NAME  = 'uses_schedule');
SET @ddl := IF(@exists = 0,
  'ALTER TABLE `restaurants`
     ADD COLUMN `uses_schedule` TINYINT(1) NOT NULL DEFAULT 0
     AFTER `is_accepting_orders`',
  'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------
-- restaurant_hours : a weekly trading schedule (FR-25)
--
-- `opening_hours` on the restaurants table stays as it is. It is a
-- sentence written for a customer to read - "Tue to Sun, 12:00 to 23:00"
-- - and it is useless for deciding whether the kitchen is open right
-- now. This table holds the same information in a form the system can
-- act on, one row per day of the week.
--
-- day_of_week follows MySQL's WEEKDAY(): 0 is Monday and 6 is Sunday.
-- PHP's date('N') is 1 to 7 from Monday, so the application subtracts
-- one rather than inventing a third convention.
--
-- A closing time earlier than or equal to the opening time means the
-- shift runs past midnight, which is ordinary for a restaurant: 17:00 to
-- 01:00 is a normal evening service, not a data error.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `restaurant_hours` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `restaurant_id` INT UNSIGNED NOT NULL,
  `day_of_week`   TINYINT UNSIGNED NOT NULL,
  `opens_at`      TIME       NOT NULL DEFAULT '09:00:00',
  `closes_at`     TIME       NOT NULL DEFAULT '22:00:00',
  `is_closed`     TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hours_day` (`restaurant_id`, `day_of_week`),
  CONSTRAINT `chk_hours_day` CHECK (`day_of_week` BETWEEN 0 AND 6),
  CONSTRAINT `fk_hours_restaurant` FOREIGN KEY (`restaurant_id`)
    REFERENCES `restaurants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- restaurant_reviews : rating the restaurant, not the food (FR-26)
--
-- A separate table rather than a nullable menu_item_id on `reviews`,
-- for two reasons. The existing table is NOT NULL on that column and
-- carries a unique key across it, so relaxing both on a live database
-- risks the data already in there. And the two things are genuinely
-- different questions: the lasagne can be excellent while the delivery
-- was an hour late, and a customer should be able to say so.
--
-- One review per order, enforced by a unique key as well as by the form,
-- so a resubmitted page cannot produce two.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `restaurant_reviews` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `restaurant_id` INT UNSIGNED NOT NULL,
  `order_id`      INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED DEFAULT NULL,
  `rating`        TINYINT UNSIGNED NOT NULL,
  `food_rating`   TINYINT UNSIGNED DEFAULT NULL,
  `speed_rating`  TINYINT UNSIGNED DEFAULT NULL,
  `comment`       TEXT DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_restaurant_review_order` (`order_id`),
  KEY `idx_rr_restaurant` (`restaurant_id`),
  CONSTRAINT `chk_rr_rating` CHECK (`rating` BETWEEN 1 AND 5),
  CONSTRAINT `fk_rr_restaurant` FOREIGN KEY (`restaurant_id`)
    REFERENCES `restaurants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rr_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rr_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Seed a default weekly schedule for any restaurant that has none.
--
-- INSERT IGNORE against the unique key means a restaurant that already
-- has hours keeps them untouched, so this is safe to re-run. The default
-- is a seven day 11:00 to 22:30 week, which is only a starting point for
-- the owner to edit.
--
-- `uses_schedule` is deliberately left at 0. Seeding hours and enforcing
-- them are two different decisions, and a migration should not quietly
-- close somebody's restaurant.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `restaurant_hours` (`restaurant_id`, `day_of_week`, `opens_at`, `closes_at`, `is_closed`)
SELECT r.id, d.day_of_week, '11:00:00', '22:30:00', 0
FROM `restaurants` r
JOIN (
  SELECT 0 AS day_of_week UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL
  SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
) d;

-- Bring the demo restaurants' schedules into line with the sentence
-- already shown on their pages, so the two do not contradict each other.
-- Each block only touches a restaurant that exists.

-- Spice Route: Tuesday to Sunday, 12:00 to 23:00. Closed Monday.
UPDATE `restaurant_hours` h
  JOIN `restaurants` r ON r.id = h.restaurant_id AND r.slug = 'spice-route'
  SET h.opens_at = '12:00:00', h.closes_at = '23:00:00',
      h.is_closed = IF(h.day_of_week = 0, 1, 0);

-- Bella Napoli: Wednesday to Sunday, 16:00 to 23:00. Closed Mon and Tue.
UPDATE `restaurant_hours` h
  JOIN `restaurants` r ON r.id = h.restaurant_id AND r.slug = 'bella-napoli'
  SET h.opens_at = '16:00:00', h.closes_at = '23:00:00',
      h.is_closed = IF(h.day_of_week IN (0, 1), 1, 0);

-- Green Bowl: Monday to Friday, 08:00 to 16:00. Closed at the weekend.
UPDATE `restaurant_hours` h
  JOIN `restaurants` r ON r.id = h.restaurant_id AND r.slug = 'green-bowl'
  SET h.opens_at = '08:00:00', h.closes_at = '16:00:00',
      h.is_closed = IF(h.day_of_week IN (5, 6), 1, 0);

-- ---------------------------------------------------------------------
-- Platform settings added by this phase.
--
-- ON DUPLICATE KEY UPDATE of the key against itself means an existing
-- value the administrator has already changed is preserved. A migration
-- that resets settings somebody has deliberately edited is a migration
-- that gets run once and then distrusted.
-- ---------------------------------------------------------------------
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('default_commission_rate', '0.0000'),
('payout_reference_prefix', 'PAY')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- ---------------------------------------------------------------------
-- Check the result
-- ---------------------------------------------------------------------
SELECT r.name,
       r.uses_schedule,
       r.commission_rate,
       (SELECT COUNT(*) FROM restaurant_hours h WHERE h.restaurant_id = r.id) AS scheduled_days,
       (SELECT COUNT(*) FROM restaurant_reviews rr WHERE rr.restaurant_id = r.id) AS restaurant_reviews
FROM `restaurants` r
ORDER BY r.name;
