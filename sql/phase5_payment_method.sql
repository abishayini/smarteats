-- =====================================================================
-- Smart Eats - Phase 5 migration
-- Records how each order is being paid for: by card through Stripe, or
-- in cash on delivery or collection.
--
-- Run this only if the database was imported before Phase 5.
-- A fresh import of smarteats.sql already contains the column.
--
--   Import through phpMyAdmin, or:
--   mysql -u root smarteats < phase5_payment_method.sql
-- =====================================================================

SET NAMES utf8mb4;
USE `smarteats`;

ALTER TABLE `orders`
  ADD COLUMN `payment_method` ENUM('card','cash') NOT NULL DEFAULT 'card'
  AFTER `payment_status`;
