-- =====================================================================
-- Smart Eats - Web-Based Food Ordering Platform for Small Restaurants
-- Database schema and seed data (Phase 11: multi-restaurant)
--
-- Target: MySQL / MariaDB (XAMPP)
--
-- Import via phpMyAdmin (Import tab), or from a command line:
--     mysql -u root < smarteats.sql
--
-- Running this again resets the database to its seeded state. It drops
-- the individual tables rather than the whole database, because XAMPP's
-- phpMyAdmin disables DROP DATABASE by default and would refuse the
-- import at the first statement.
--
-- WHAT CHANGED IN PHASE 11
-- Smart Eats is now a platform that several independent restaurants
-- share, rather than the storefront of one restaurant. A `restaurants`
-- table was added, and `categories`, `menu_items` and `orders` each
-- carry a `restaurant_id`. Money settings that used to live in the
-- `settings` table (delivery fee, minimum order, VAT) are now columns on
-- `restaurants`, because each restaurant sets its own. The `settings`
-- table survives for genuinely platform-wide values only.
-- =====================================================================

-- Force the client connection to UTF-8. Without this, an import run from a
-- command line defaulting to latin1 stores the pound sign double-encoded.
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `smarteats`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `smarteats`;

-- ---------------------------------------------------------------------
-- Clearing out an existing installation
--
-- Two things have to happen before the old tables can be dropped.
--
-- 1. Foreign key checks are suspended. This alone is usually enough.
--
-- 2. The constraint linking `users` back to `restaurants` is removed
--    first. The two tables reference each other - a restaurant has an
--    owner, and an owner belongs to a restaurant - so while that pair of
--    constraints exists there is NO order in which the tables can be
--    dropped if the server is enforcing them. phpMyAdmin's Import tab
--    re-enables foreign key checks by default, which overrides the line
--    below and makes `DROP TABLE restaurants` fail with error 1451,
--    leaving the database half dropped.
--
--    The block below removes that one constraint if it is there and does
--    nothing if it is not, so this file imports cleanly on a fresh
--    server, over a previous install, and over a database that has
--    already been through phase11_restaurants.sql.
-- ---------------------------------------------------------------------
SET FOREIGN_KEY_CHECKS = 0;

SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = 'smarteats'
     AND TABLE_NAME        = 'users'
     AND CONSTRAINT_NAME   = 'fk_users_restaurant'
);
SET @drop_fk := IF(@fk_exists > 0,
  'ALTER TABLE `users` DROP FOREIGN KEY `fk_users_restaurant`',
  'DO 0');
PREPARE drop_fk_stmt FROM @drop_fk;
EXECUTE drop_fk_stmt;
DEALLOCATE PREPARE drop_fk_stmt;

DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `restaurant_reviews`;
DROP TABLE IF EXISTS `restaurant_hours`;
DROP TABLE IF EXISTS `reviews`;
DROP TABLE IF EXISTS `order_status_history`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `menu_items`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `restaurants`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `settings`;

-- ---------------------------------------------------------------------
-- users : customers, restaurant owners, restaurant staff, platform admins
--
-- A single table with a role column keeps authentication logic in one
-- place, which is simpler to maintain than four separate account tables.
--
-- restaurant_id scopes an account to one restaurant. A vendor (owner) and
-- their staff carry it; customers and platform administrators do not.
-- The foreign key is added after `restaurants` exists, because the two
-- tables reference each other.
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`     VARCHAR(120)  NOT NULL,
  `email`         VARCHAR(150)  NOT NULL,
  `password_hash` VARCHAR(255)  NOT NULL,
  `phone`         VARCHAR(30)   DEFAULT NULL,
  `address`       VARCHAR(255)  DEFAULT NULL,
  `role`          ENUM('customer','staff','vendor','admin') NOT NULL DEFAULT 'customer',
  `restaurant_id` INT UNSIGNED  DEFAULT NULL,
  `is_active`     TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_restaurant` (`restaurant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- restaurants : the sellers on the platform (FR-20 to FR-24)
--
-- approval_status gates visibility. A restaurant that has just
-- registered is 'pending' and cannot be found or ordered from until a
-- platform administrator approves it, which stops anyone from putting a
-- live menu on the site by filling in a public form.
--
-- The money columns are per restaurant because a takeaway charging a
-- 2.50 delivery fee and a pizzeria charging 1.50 are the ordinary case
-- on a platform of independent businesses.
-- ---------------------------------------------------------------------
CREATE TABLE `restaurants` (
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
  `delivery_postcodes`  VARCHAR(255)  DEFAULT NULL,
  `opening_hours`       VARCHAR(160)  DEFAULT NULL,
  `logo`                VARCHAR(255)  DEFAULT NULL,
  `delivery_fee`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `free_delivery_over`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `min_order_value`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tax_rate`            DECIMAL(5,4)  NOT NULL DEFAULT 0.0000,
  `commission_rate`     DECIMAL(5,4)  NOT NULL DEFAULT 0.0000,
  `is_accepting_orders` TINYINT(1)    NOT NULL DEFAULT 1,
  `uses_schedule`       TINYINT(1)    NOT NULL DEFAULT 0,
  `approval_status`     ENUM('pending','approved','suspended') NOT NULL DEFAULT 'pending',
  `approved_at`         DATETIME      DEFAULT NULL,
  `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_restaurants_slug` (`slug`),
  KEY `idx_restaurants_status` (`approval_status`),
  KEY `idx_restaurants_owner` (`owner_user_id`),
  CONSTRAINT `fk_restaurants_owner` FOREIGN KEY (`owner_user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_restaurant` FOREIGN KEY (`restaurant_id`)
    REFERENCES `restaurants` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- ---------------------------------------------------------------------
-- categories : menu groupings, one set per restaurant (FR-05)
--
-- Categories belong to a restaurant rather than the platform, so a
-- pizzeria is not forced to file its food under a takeaway's headings.
-- The slug is unique within a restaurant, not across the whole site.
-- ---------------------------------------------------------------------
CREATE TABLE `categories` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `restaurant_id` INT UNSIGNED NOT NULL,
  `name`          VARCHAR(80)  NOT NULL,
  `slug`          VARCHAR(80)  NOT NULL,
  `sort_order`    INT          NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_slug` (`restaurant_id`, `slug`),
  KEY `idx_categories_restaurant` (`restaurant_id`),
  CONSTRAINT `fk_categories_restaurant` FOREIGN KEY (`restaurant_id`)
    REFERENCES `restaurants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- menu_items : the digital menu (FR-01, FR-14, FR-15)
-- is_available = temporarily sold out, is_active = removed from menu.
-- Two flags rather than one so an item can be hidden without losing its
-- history in past orders.
--
-- restaurant_id is stored directly as well as being reachable through
-- the category, because almost every query filters on it and the join
-- would be pure overhead.
-- ---------------------------------------------------------------------
CREATE TABLE `menu_items` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `restaurant_id` INT UNSIGNED NOT NULL,
  `category_id`   INT UNSIGNED NOT NULL,
  `name`          VARCHAR(150)  NOT NULL,
  `description`   TEXT          DEFAULT NULL,
  `price`         DECIMAL(10,2) NOT NULL,
  `image`         VARCHAR(255)  DEFAULT NULL,
  `prep_minutes`  INT           NOT NULL DEFAULT 15,
  `is_available`  TINYINT(1)    NOT NULL DEFAULT 1,
  `is_active`     TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_items_restaurant` (`restaurant_id`),
  KEY `idx_items_category` (`category_id`),
  KEY `idx_items_available` (`is_active`, `is_available`),
  CONSTRAINT `fk_items_restaurant` FOREIGN KEY (`restaurant_id`)
    REFERENCES `restaurants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_items_category` FOREIGN KEY (`category_id`)
    REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- orders : one row per submitted order (FR-03)
--
-- restaurant_id is NOT NULL: one order belongs to exactly one seller.
-- A basket holding dishes from two restaurants is refused before it can
-- reach this table, which keeps payment, the kitchen ticket and the
-- tracking page single-restaurant and therefore simple.
--
-- user_id is nullable because guest checkout is supported. Customer
-- contact details are copied onto the order so a guest order is
-- self-contained and a later profile edit cannot rewrite order history.
-- ---------------------------------------------------------------------
CREATE TABLE `orders` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_number`      VARCHAR(20)  NOT NULL,
  `restaurant_id`     INT UNSIGNED NOT NULL,
  `user_id`           INT UNSIGNED DEFAULT NULL,
  `customer_name`     VARCHAR(120) NOT NULL,
  `customer_email`    VARCHAR(150) DEFAULT NULL,
  `customer_phone`    VARCHAR(30)  NOT NULL,
  `order_type`        ENUM('delivery','pickup') NOT NULL DEFAULT 'delivery',
  `delivery_address`  VARCHAR(255) DEFAULT NULL,
  `subtotal`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tax`               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `delivery_fee`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total`             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status`            ENUM('pending','confirmed','preparing','ready',
                           'out_for_delivery','completed','cancelled')
                      NOT NULL DEFAULT 'pending',
  `payment_status`    ENUM('unpaid','paid','failed','refunded')
                      NOT NULL DEFAULT 'unpaid',
  `payment_method`    ENUM('card','cash') NOT NULL DEFAULT 'card',
  `payment_intent_id` VARCHAR(120) DEFAULT NULL,
  `notes`             TEXT DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_number` (`order_number`),
  KEY `idx_orders_restaurant` (`restaurant_id`, `status`),
  KEY `idx_orders_user` (`user_id`),
  KEY `idx_orders_status` (`status`),
  KEY `idx_orders_created` (`created_at`),
  CONSTRAINT `fk_orders_restaurant` FOREIGN KEY (`restaurant_id`)
    REFERENCES `restaurants` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- order_items : line items
-- item_name and unit_price are snapshots taken at the time of ordering,
-- so changing a menu price later does not alter historical orders.
-- ---------------------------------------------------------------------
CREATE TABLE `order_items` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     INT UNSIGNED NOT NULL,
  `menu_item_id` INT UNSIGNED DEFAULT NULL,
  `item_name`    VARCHAR(150)  NOT NULL,
  `unit_price`   DECIMAL(10,2) NOT NULL,
  `quantity`     INT UNSIGNED  NOT NULL DEFAULT 1,
  `line_total`   DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_oi_order` (`order_id`),
  KEY `idx_oi_item` (`menu_item_id`),
  CONSTRAINT `fk_oi_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_oi_item` FOREIGN KEY (`menu_item_id`)
    REFERENCES `menu_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- payments : Stripe transaction log (FR-04)
-- ---------------------------------------------------------------------
CREATE TABLE `payments` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`          INT UNSIGNED NOT NULL,
  `stripe_intent_id`  VARCHAR(120) DEFAULT NULL,
  `amount`            DECIMAL(10,2) NOT NULL,
  `currency`          VARCHAR(10)   NOT NULL DEFAULT 'GBP',
  `method`            VARCHAR(40)   NOT NULL DEFAULT 'card',
  `status`            VARCHAR(40)   NOT NULL DEFAULT 'created',
  `failure_message`   VARCHAR(255)  DEFAULT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pay_order` (`order_id`),
  KEY `idx_pay_intent` (`stripe_intent_id`),
  CONSTRAINT `fk_pay_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- order_status_history : audit trail of every status change (FR-10)
-- This is the evidence source for RQ1 - the timestamps here allow order
-- processing time to be measured rather than estimated.
-- ---------------------------------------------------------------------
CREATE TABLE `order_status_history` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED NOT NULL,
  `status`     ENUM('pending','confirmed','preparing','ready',
                    'out_for_delivery','completed','cancelled') NOT NULL,
  `changed_by` INT UNSIGNED DEFAULT NULL,
  `note`       VARCHAR(255) DEFAULT NULL,
  `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_osh_order` (`order_id`),
  CONSTRAINT `fk_osh_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_osh_user` FOREIGN KEY (`changed_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- reviews : ratings and comments (FR-07)
-- restaurant_id is denormalised onto the row so a restaurant's average
-- rating is one indexed query rather than a three-table join.
-- ---------------------------------------------------------------------
CREATE TABLE `reviews` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `menu_item_id`  INT UNSIGNED NOT NULL,
  `restaurant_id` INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED DEFAULT NULL,
  `order_id`      INT UNSIGNED DEFAULT NULL,
  `rating`        TINYINT UNSIGNED NOT NULL,
  `comment`       TEXT DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reviews_order_item` (`order_id`, `menu_item_id`),
  KEY `idx_rev_item` (`menu_item_id`),
  KEY `idx_rev_restaurant` (`restaurant_id`),
  CONSTRAINT `chk_reviews_rating` CHECK (`rating` BETWEEN 1 AND 5),
  CONSTRAINT `fk_rev_item` FOREIGN KEY (`menu_item_id`)
    REFERENCES `menu_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rev_restaurant` FOREIGN KEY (`restaurant_id`)
    REFERENCES `restaurants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rev_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_rev_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- restaurant_hours : a weekly trading schedule (FR-25)
--
-- `opening_hours` on the restaurants table stays as it is: it is a
-- sentence written for a customer to read, and it is useless for
-- deciding whether the kitchen is open right now. This table holds the
-- same information in a form the system can act on.
--
-- day_of_week follows MySQL's WEEKDAY(): 0 is Monday, 6 is Sunday. A
-- closing time at or before the opening time means the shift runs past
-- midnight, which is ordinary for a restaurant rather than an error.
-- ---------------------------------------------------------------------
CREATE TABLE `restaurant_hours` (
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
-- Kept apart from `reviews` because the two are different questions. The
-- lasagne can be excellent while the delivery was an hour late, and a
-- customer should be able to say both. One review per order.
-- ---------------------------------------------------------------------
CREATE TABLE `restaurant_reviews` (
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
-- login_attempts : sign-in log used for temporary account lockout
-- Repeated failures on the same email address are throttled, which
-- addresses the brute-force risk in the security requirements.
-- ---------------------------------------------------------------------
CREATE TABLE `login_attempts` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`          VARCHAR(150) NOT NULL,
  `ip_address`     VARCHAR(45)  NOT NULL,
  `was_successful` TINYINT(1)   NOT NULL DEFAULT 0,
  `attempted_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_lookup` (`email`, `attempted_at`),
  KEY `idx_attempts_ip` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- settings : platform configuration, editable from the admin panel
--
-- Only genuinely platform-wide values live here now. Anything that
-- differs between restaurants is a column on `restaurants` instead.
-- ---------------------------------------------------------------------
CREATE TABLE `settings` (
  `setting_key`   VARCHAR(60)  NOT NULL,
  `setting_value` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- SEED DATA
-- =====================================================================

-- ---------------------------------------------------------------------
-- Accounts. Passwords are bcrypt hashes produced by password_hash() and
-- checked with password_verify() before being written here. Change them
-- before any user testing session.
--
--   PLATFORM ADMINISTRATORS (no restaurant)
--   manager@smarteats.test        / Manager@2026
--   admin@smarteats.test          / admin123
--
--   RESTAURANT OWNERS (vendor)
--   owner.kitchen@smarteats.test  / Vendor@2026   Smart Eats Kitchen
--   owner.spice@smarteats.test    / Vendor@2026   Spice Route
--   owner.bella@smarteats.test    / Vendor@2026   Bella Napoli
--   owner.green@smarteats.test    / Vendor@2026   Green Bowl (pending)
--
--   KITCHEN STAFF
--   kitchen@smarteats.test        / Kitchen@2026  Smart Eats Kitchen
--   staff@smarteats.test          / staff123      Smart Eats Kitchen
--   kitchen.spice@smarteats.test  / Kitchen@2026  Spice Route
--   kitchen.bella@smarteats.test  / Kitchen@2026  Bella Napoli
--
-- If a password is ever refused, open setup_accounts.php, which
-- regenerates every hash on your own machine instead of copying them.
-- ---------------------------------------------------------------------
INSERT INTO `users` (`full_name`, `email`, `password_hash`, `phone`, `role`) VALUES
('Platform Manager', 'manager@smarteats.test',
 '$2y$10$H65/Lcm/M7oWCtgzQlQVUOzFDoxAZRQMncjJMxWgKcoEXK8gnRL/O',
 '02079460003', 'admin'),
('System Administrator', 'admin@smarteats.test',
 '$2y$10$dsqUdERy8ujo3Mf7t63XnuplPHEfZaDG6Oth/UlqoQ3iJHnZknZWa',
 '02079460001', 'admin');

INSERT INTO `users` (`full_name`, `email`, `password_hash`, `phone`, `role`) VALUES
('Nadia Karim',   'owner.kitchen@smarteats.test',
 '$2y$10$zqV8mDqEHLUpY.UFfCB9OeoHD6JIGrfmKfx0jKbchfuh9wbN1ouYm',
 '02079461101', 'vendor'),
('Ravi Sharma',   'owner.spice@smarteats.test',
 '$2y$10$olAj4lBdapIwDj8xpdGTCeHKFhRKluAM8TLzdjyp60hZOv9/h.doS',
 '02079462201', 'vendor'),
('Giulia Rossi',  'owner.bella@smarteats.test',
 '$2y$10$j68B2e/gPk6RsJrr0NXoQuNWzr5bBrlrPtivLU/L7ZPyx/vrgkb.e',
 '02079463301', 'vendor'),
('Tom Whitfield', 'owner.green@smarteats.test',
 '$2y$10$oB1V18BuWV3wM8piAmOPE.SpCvAZv1s7SzQvYwpdk1hRNrUBqrXEO',
 '02079464401', 'vendor');

-- ---------------------------------------------------------------------
-- Restaurants. Three approved and one still pending, so the approval
-- gate can be demonstrated without editing the database by hand.
-- ---------------------------------------------------------------------
INSERT INTO `restaurants`
  (`name`, `slug`, `owner_user_id`, `tagline`, `description`, `cuisine`,
   `phone`, `email`, `address`, `city`, `opening_hours`,
   `delivery_fee`, `free_delivery_over`, `min_order_value`, `tax_rate`,
   `is_accepting_orders`, `approval_status`, `approved_at`)
VALUES
('Smart Eats Kitchen', 'smart-eats-kitchen',
 (SELECT id FROM (SELECT id FROM users WHERE email = 'owner.kitchen@smarteats.test') AS t1),
 'Order straight from the kitchen',
 'A neighbourhood kitchen cooking British and Asian favourites to order. Everything is made when you order it, so nothing sits under a lamp.',
 'British', '020 7946 0000', 'hello@smarteats.test',
 '128 High Street, London, E1 6AN', 'London', 'Mon to Sun, 11:00 to 22:30',
 2.50, 25.00, 8.00, 0.0000, 1, 'approved', NOW()),

('Spice Route', 'spice-route',
 (SELECT id FROM (SELECT id FROM users WHERE email = 'owner.spice@smarteats.test') AS t2),
 'Home-style North Indian cooking',
 'A family-run kitchen serving North Indian curries, breads and biryani. Spice levels are cooked to order, so say the word in the notes box.',
 'Indian', '020 7946 2200', 'hello@spiceroute.test',
 '9 Market Row, London, E2 8HD', 'London', 'Tue to Sun, 12:00 to 23:00',
 1.95, 30.00, 10.00, 0.0000, 1, 'approved', NOW()),

('Bella Napoli', 'bella-napoli',
 (SELECT id FROM (SELECT id FROM users WHERE email = 'owner.bella@smarteats.test') AS t3),
 'Wood-fired pizza, nothing else',
 'A two-oven pizzeria working from a Neapolitan dough proved for 48 hours. Small menu, done properly.',
 'Italian', '020 7946 3300', 'ciao@bellanapoli.test',
 '44 Church Lane, London, N1 7RT', 'London', 'Wed to Sun, 16:00 to 23:00',
 3.00, 35.00, 12.00, 0.0000, 1, 'approved', NOW()),

('Green Bowl', 'green-bowl',
 (SELECT id FROM (SELECT id FROM users WHERE email = 'owner.green@smarteats.test') AS t4),
 'Salads, grain bowls and cold-pressed juice',
 'A lunch counter built around seasonal vegetables. This restaurant is awaiting approval and is not yet visible to customers.',
 'Healthy', '020 7946 4400', 'hello@greenbowl.test',
 '3 Station Approach, London, SE1 9QR', 'London', 'Mon to Fri, 08:00 to 16:00',
 2.00, 20.00, 7.00, 0.0000, 1, 'pending', NULL);

-- Attach each owner to their restaurant.
UPDATE `users` u
  JOIN `restaurants` r ON r.owner_user_id = u.id
  SET u.restaurant_id = r.id;

-- ---------------------------------------------------------------------
-- Kitchen staff, each scoped to one restaurant.
-- ---------------------------------------------------------------------
INSERT INTO `users` (`full_name`, `email`, `password_hash`, `phone`, `role`, `restaurant_id`) VALUES
('Kitchen Team', 'kitchen@smarteats.test',
 '$2y$10$zDLVgpTrwf4IejU6EsFTXu4BJ5qY/G.Wy6hQOvzqfJ2XJcdUpQFoS',
 '02079460004', 'staff',
 (SELECT id FROM restaurants WHERE slug = 'smart-eats-kitchen')),
('Kitchen Staff', 'staff@smarteats.test',
 '$2y$10$9TxtHoBf2okBQvlH7BeUsusnUlshh./wuTf4CPkTfj/3CyD0RiD3i',
 '02079460002', 'staff',
 (SELECT id FROM restaurants WHERE slug = 'smart-eats-kitchen')),
('Spice Route Kitchen', 'kitchen.spice@smarteats.test',
 '$2y$10$HvVgClI9u/YmYrVEpvbSPeBOoxNZrMtQ8cgq2CVcnMjNm2GFxrRCa',
 '02079462202', 'staff',
 (SELECT id FROM restaurants WHERE slug = 'spice-route')),
('Bella Napoli Kitchen', 'kitchen.bella@smarteats.test',
 '$2y$10$WPozSw7yafRmgTJXmob5juCt8OGezyUAttmfoALwYh/0bXQx0Tk.G',
 '02079463302', 'staff',
 (SELECT id FROM restaurants WHERE slug = 'bella-napoli'));

-- ---------------------------------------------------------------------
-- Categories, one set per restaurant.
-- ---------------------------------------------------------------------
INSERT INTO `categories` (`restaurant_id`, `name`, `slug`, `sort_order`)
SELECT r.id, v.name, v.slug, v.sort_order
FROM `restaurants` r
JOIN (
  SELECT 'Starters' AS name, 'starters' AS slug, 1 AS sort_order UNION ALL
  SELECT 'Main dishes','main-dishes',2 UNION ALL
  SELECT 'Rice & noodles','rice-noodles',3 UNION ALL
  SELECT 'Sides','sides',4 UNION ALL
  SELECT 'Desserts','desserts',5 UNION ALL
  SELECT 'Drinks','drinks',6
) v
WHERE r.slug = 'smart-eats-kitchen';

INSERT INTO `categories` (`restaurant_id`, `name`, `slug`, `sort_order`)
SELECT r.id, v.name, v.slug, v.sort_order
FROM `restaurants` r
JOIN (
  SELECT 'Street snacks' AS name, 'street-snacks' AS slug, 1 AS sort_order UNION ALL
  SELECT 'Curries','curries',2 UNION ALL
  SELECT 'Biryani','biryani',3 UNION ALL
  SELECT 'Breads','breads',4 UNION ALL
  SELECT 'Sweets','sweets',5 UNION ALL
  SELECT 'Drinks','drinks',6
) v
WHERE r.slug = 'spice-route';

INSERT INTO `categories` (`restaurant_id`, `name`, `slug`, `sort_order`)
SELECT r.id, v.name, v.slug, v.sort_order
FROM `restaurants` r
JOIN (
  SELECT 'Antipasti' AS name, 'antipasti' AS slug, 1 AS sort_order UNION ALL
  SELECT 'Pizza','pizza',2 UNION ALL
  SELECT 'Sides','sides',3 UNION ALL
  SELECT 'Dolci','dolci',4 UNION ALL
  SELECT 'Drinks','drinks',5
) v
WHERE r.slug = 'bella-napoli';

INSERT INTO `categories` (`restaurant_id`, `name`, `slug`, `sort_order`)
SELECT r.id, v.name, v.slug, v.sort_order
FROM `restaurants` r
JOIN (
  SELECT 'Bowls' AS name, 'bowls' AS slug, 1 AS sort_order UNION ALL
  SELECT 'Salads','salads',2 UNION ALL
  SELECT 'Juices','juices',3
) v
WHERE r.slug = 'green-bowl';

-- ---------------------------------------------------------------------
-- Menu items. Each block resolves its own restaurant and category ids,
-- so the file stays correct whatever auto-increment values MySQL hands
-- out on a given machine.
-- ---------------------------------------------------------------------

-- Smart Eats Kitchen
INSERT INTO `menu_items` (`restaurant_id`, `category_id`, `name`, `description`, `price`, `prep_minutes`)
SELECT c.restaurant_id, c.id, v.name, v.description, v.price, v.prep
FROM `categories` c
JOIN `restaurants` r ON r.id = c.restaurant_id AND r.slug = 'smart-eats-kitchen'
JOIN (
  SELECT 'starters' AS cat, 'Vegetable spring rolls' AS name, 'Four crisp rolls with a sweet chilli dip.' AS description, 4.50 AS price, 10 AS prep UNION ALL
  SELECT 'starters','Chicken wings','Six wings tossed in a house spice rub.',6.25,15 UNION ALL
  SELECT 'starters','Garlic bread','Toasted sourdough with garlic butter and herbs.',3.75,8 UNION ALL
  SELECT 'main-dishes','Grilled chicken burger','Chargrilled breast, lettuce, tomato, brioche bun.',9.95,18 UNION ALL
  SELECT 'main-dishes','Beef lasagne','Layered pasta with slow-cooked beef ragu.',11.50,20 UNION ALL
  SELECT 'main-dishes','Paneer butter masala','Paneer in a mild tomato and cashew sauce.',10.25,20 UNION ALL
  SELECT 'main-dishes','Fish and chips','Beer-battered cod with thick-cut chips.',12.00,20 UNION ALL
  SELECT 'rice-noodles','Chicken fried rice','Wok-fried rice with egg, peas and spring onion.',8.50,15 UNION ALL
  SELECT 'rice-noodles','Vegetable chow mein','Stir-fried noodles with seasonal vegetables.',7.95,15 UNION ALL
  SELECT 'rice-noodles','Egg noodles with prawns','Soft noodles, king prawns, garlic and chilli.',10.75,18 UNION ALL
  SELECT 'sides','Skin-on fries','Lightly salted, cooked to order.',3.25,10 UNION ALL
  SELECT 'sides','Coleslaw','Crunchy cabbage and carrot in a light dressing.',2.50,5 UNION ALL
  SELECT 'sides','Side salad','Mixed leaves, cucumber and cherry tomato.',3.00,5 UNION ALL
  SELECT 'desserts','Chocolate brownie','Warm brownie with vanilla ice cream.',4.95,8 UNION ALL
  SELECT 'desserts','Mango sorbet','Two scoops, dairy free.',3.95,5 UNION ALL
  SELECT 'drinks','Still water 500ml','Chilled.',1.20,1 UNION ALL
  SELECT 'drinks','Fresh orange juice','Freshly squeezed, 300ml.',2.80,3 UNION ALL
  SELECT 'drinks','Masala chai','Spiced tea with milk.',2.20,5
) v ON v.cat = c.slug;

-- Spice Route
INSERT INTO `menu_items` (`restaurant_id`, `category_id`, `name`, `description`, `price`, `prep_minutes`)
SELECT c.restaurant_id, c.id, v.name, v.description, v.price, v.prep
FROM `categories` c
JOIN `restaurants` r ON r.id = c.restaurant_id AND r.slug = 'spice-route'
JOIN (
  SELECT 'street-snacks' AS cat, 'Onion bhaji' AS name, 'Four bhajis with mint yoghurt.' AS description, 4.25 AS price, 12 AS prep UNION ALL
  SELECT 'street-snacks','Samosa chaat','Crushed samosa, chickpeas, tamarind and yoghurt.',5.50,12 UNION ALL
  SELECT 'street-snacks','Chicken tikka','Six pieces from the tandoor, lemon and red onion.',7.25,18 UNION ALL
  SELECT 'curries','Butter chicken','Tandoori chicken in a mild tomato and cream sauce.',11.50,22 UNION ALL
  SELECT 'curries','Lamb rogan josh','Slow-cooked lamb shoulder with Kashmiri chilli.',13.25,25 UNION ALL
  SELECT 'curries','Chana masala','Chickpeas with ginger, cumin and fresh coriander.',9.25,18 UNION ALL
  SELECT 'curries','Saag paneer','Spinach and paneer with garlic and green chilli.',10.50,20 UNION ALL
  SELECT 'biryani','Chicken dum biryani','Sealed and baked with saffron rice and raita.',12.95,28 UNION ALL
  SELECT 'biryani','Vegetable biryani','Seasonal vegetables, whole spices, crisp onion.',10.95,25 UNION ALL
  SELECT 'breads','Plain naan','Fresh from the tandoor.',2.75,6 UNION ALL
  SELECT 'breads','Garlic and coriander naan','Brushed with garlic butter.',3.25,6 UNION ALL
  SELECT 'breads','Tandoori roti','Wholemeal, no butter.',2.50,6 UNION ALL
  SELECT 'sweets','Gulab jamun','Two, in warm cardamom syrup.',3.95,5 UNION ALL
  SELECT 'sweets','Kheer','Slow-cooked rice pudding with pistachio.',4.25,5 UNION ALL
  SELECT 'drinks','Mango lassi','Thick, sweet, served cold.',3.50,4 UNION ALL
  SELECT 'drinks','Salted lime soda','Fresh lime, sparkling water.',2.75,3
) v ON v.cat = c.slug;

-- Bella Napoli
INSERT INTO `menu_items` (`restaurant_id`, `category_id`, `name`, `description`, `price`, `prep_minutes`)
SELECT c.restaurant_id, c.id, v.name, v.description, v.price, v.prep
FROM `categories` c
JOIN `restaurants` r ON r.id = c.restaurant_id AND r.slug = 'bella-napoli'
JOIN (
  SELECT 'antipasti' AS cat, 'Burrata and tomato' AS name, 'Whole burrata, datterini tomatoes, basil oil.' AS description, 8.50 AS price, 8 AS prep UNION ALL
  SELECT 'antipasti','Arancini','Three saffron risotto balls with mozzarella.',6.50,12 UNION ALL
  SELECT 'pizza','Margherita','San Marzano tomato, fior di latte, basil.',9.50,14 UNION ALL
  SELECT 'pizza','Diavola','Spicy salami, tomato, mozzarella, chilli honey.',12.50,14 UNION ALL
  SELECT 'pizza','Quattro formaggi','Mozzarella, gorgonzola, pecorino, parmesan.',13.00,14 UNION ALL
  SELECT 'pizza','Marinara','Tomato, garlic, oregano, olive oil. No cheese.',8.50,12 UNION ALL
  SELECT 'pizza','Prosciutto e rucola','Parma ham, rocket, shaved parmesan.',14.00,15 UNION ALL
  SELECT 'sides','Rosemary focaccia','From the same dough, sea salt and rosemary.',4.50,10 UNION ALL
  SELECT 'sides','Rocket and parmesan salad','Lemon dressing.',5.00,5 UNION ALL
  SELECT 'dolci','Tiramisu','Made in house each morning.',5.95,5 UNION ALL
  SELECT 'dolci','Lemon sorbet','Two scoops.',4.25,5 UNION ALL
  SELECT 'drinks','San Pellegrino 500ml','Sparkling.',2.20,1 UNION ALL
  SELECT 'drinks','Chinotto','Bittersweet Italian soda.',2.60,1
) v ON v.cat = c.slug;

-- Green Bowl (pending approval, so these are not visible to customers)
INSERT INTO `menu_items` (`restaurant_id`, `category_id`, `name`, `description`, `price`, `prep_minutes`)
SELECT c.restaurant_id, c.id, v.name, v.description, v.price, v.prep
FROM `categories` c
JOIN `restaurants` r ON r.id = c.restaurant_id AND r.slug = 'green-bowl'
JOIN (
  SELECT 'bowls' AS cat, 'Falafel grain bowl' AS name, 'Bulgur, falafel, pickled cabbage, tahini.' AS description, 8.95 AS price, 10 AS prep UNION ALL
  SELECT 'bowls','Chicken and quinoa bowl','Grilled chicken, quinoa, avocado, lime.',9.95,10 UNION ALL
  SELECT 'salads','Caesar salad','Cos, sourdough croutons, parmesan.',7.50,8 UNION ALL
  SELECT 'juices','Green juice','Apple, cucumber, celery, ginger.',4.50,4
) v ON v.cat = c.slug;

-- ---------------------------------------------------------------------
-- Platform settings. Everything restaurant-specific now lives on the
-- restaurants table instead.
-- ---------------------------------------------------------------------
-- ---------------------------------------------------------------------
-- Weekly schedules for the demo restaurants, matching the sentence shown
-- on each restaurant's page so the two cannot contradict each other.
--
-- `uses_schedule` is left at 0 on every restaurant. Seeding hours and
-- enforcing them are two different decisions, and a demonstration that
-- opens with a closed restaurant because of the time of day is a poor
-- demonstration. Turn it on from Opening hours in the panel.
-- ---------------------------------------------------------------------
INSERT INTO `restaurant_hours` (`restaurant_id`, `day_of_week`, `opens_at`, `closes_at`, `is_closed`)
SELECT r.id, d.day_of_week, '11:00:00', '22:30:00', 0
FROM `restaurants` r
JOIN (
  SELECT 0 AS day_of_week UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL
  SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
) d;

-- Spice Route: Tuesday to Sunday, 12:00 to 23:00.
UPDATE `restaurant_hours` h
  JOIN `restaurants` r ON r.id = h.restaurant_id AND r.slug = 'spice-route'
  SET h.opens_at = '12:00:00', h.closes_at = '23:00:00',
      h.is_closed = IF(h.day_of_week = 0, 1, 0);

-- Bella Napoli: Wednesday to Sunday, 16:00 to 23:00.
UPDATE `restaurant_hours` h
  JOIN `restaurants` r ON r.id = h.restaurant_id AND r.slug = 'bella-napoli'
  SET h.opens_at = '16:00:00', h.closes_at = '23:00:00',
      h.is_closed = IF(h.day_of_week IN (0, 1), 1, 0);

-- Green Bowl: Monday to Friday, 08:00 to 16:00.
UPDATE `restaurant_hours` h
  JOIN `restaurants` r ON r.id = h.restaurant_id AND r.slug = 'green-bowl'
  SET h.opens_at = '08:00:00', h.closes_at = '16:00:00',
      h.is_closed = IF(h.day_of_week IN (5, 6), 1, 0);

INSERT INTO `settings` (`setting_key`,`setting_value`) VALUES
('platform_name','Smart Eats'),
('platform_tagline','Order direct from local restaurants'),
('platform_email','support@smarteats.test'),
('platform_phone','020 7946 0100'),
('currency_code','GBP'),
('currency_symbol','£'),
('platform_open','1'),
('default_commission_rate','0.0000'),
('payout_reference_prefix','PAY');
