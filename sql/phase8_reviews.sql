-- =====================================================================
-- Smart Eats - Phase 8 migration
--
-- Adds a unique constraint so a customer can review each dish once per
-- order. Enforcing this in the database rather than only in PHP means a
-- double-submitted form cannot create two reviews.
--
-- Run this only if the database was imported before Phase 8.
-- A fresh import of smarteats.sql already contains the constraint.
--
--   Import through phpMyAdmin, or:
--   mysql -u root smarteats < phase8_reviews.sql
-- =====================================================================

SET NAMES utf8mb4;
USE `smarteats`;

-- Clear any duplicates that already exist, keeping the earliest review.
DELETE r1 FROM `reviews` r1
INNER JOIN `reviews` r2
  ON r1.menu_item_id = r2.menu_item_id
 AND r1.order_id = r2.order_id
 AND r1.order_id IS NOT NULL
 AND r1.id > r2.id;

ALTER TABLE `reviews`
  ADD CONSTRAINT `chk_reviews_rating` CHECK (`rating` BETWEEN 1 AND 5);

ALTER TABLE `reviews`
  ADD UNIQUE KEY `uq_reviews_order_item` (`order_id`, `menu_item_id`);
