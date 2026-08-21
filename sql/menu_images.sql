-- =====================================================================
-- Smart Eats - attach photos to the seeded menu items
--
-- OPTIONAL. There are two ways to attach images, and the other one is
-- easier: open attach_images.php in the browser, which lists the files
-- actually present in your uploads folders and matches them to dishes
-- for you. Use this file if you would rather do it in SQL, or if you
-- want the same set of images restored after every database rebuild.
--
-- HOW TO USE
--   1. Put your image files in  smarteats/uploads/menu/
--   2. Name them as listed below, or edit the filenames here to match
--      whatever you already have.
--   3. Import through phpMyAdmin, or run:
--        mysql -u root smarteats < menu_images.sql
--
-- Any dish without a matching file keeps the placeholder graphic, so a
-- partly finished set of photos still displays correctly.
--
-- WHY EACH UPDATE NAMES A RESTAURANT
-- Dish names are only unique within a restaurant. Two restaurants on the
-- platform may both sell a Margherita, and an UPDATE matching on the
-- name alone would hand them the same photograph. Every statement below
-- is therefore scoped to one restaurant.
--
-- IMAGE GUIDANCE
--   Shape      4:3 landscape (the menu cards crop to this)
--   Size       around 800 x 600 pixels is plenty
--   Format     .jpg or .webp
--   Weight     under 300 KB each, so the menu stays fast (NFR-01)
--   Filenames  lowercase, no spaces, no accented characters
-- =====================================================================

SET NAMES utf8mb4;
USE `smarteats`;

-- ---------------------------------------------------------------------
-- Smart Eats Kitchen
-- These are the eighteen dishes carried over from Phase 1 to 10, so
-- photographs from the single-restaurant version of the system already
-- match these filenames and will reattach unchanged.
-- ---------------------------------------------------------------------
SET @r := (SELECT id FROM restaurants WHERE slug = 'smart-eats-kitchen');

UPDATE `menu_items` SET `image` = 'spring-rolls.jpg'         WHERE restaurant_id = @r AND `name` = 'Vegetable spring rolls';
UPDATE `menu_items` SET `image` = 'chicken-wings.jpg'        WHERE restaurant_id = @r AND `name` = 'Chicken wings';
UPDATE `menu_items` SET `image` = 'garlic-bread.jpg'         WHERE restaurant_id = @r AND `name` = 'Garlic bread';
UPDATE `menu_items` SET `image` = 'chicken-burger.jpg'       WHERE restaurant_id = @r AND `name` = 'Grilled chicken burger';
UPDATE `menu_items` SET `image` = 'beef-lasagne.jpg'         WHERE restaurant_id = @r AND `name` = 'Beef lasagne';
UPDATE `menu_items` SET `image` = 'paneer-butter-masala.jpg' WHERE restaurant_id = @r AND `name` = 'Paneer butter masala';
UPDATE `menu_items` SET `image` = 'fish-and-chips.jpg'       WHERE restaurant_id = @r AND `name` = 'Fish and chips';
UPDATE `menu_items` SET `image` = 'chicken-fried-rice.jpg'   WHERE restaurant_id = @r AND `name` = 'Chicken fried rice';
UPDATE `menu_items` SET `image` = 'chow-mein.jpg'            WHERE restaurant_id = @r AND `name` = 'Vegetable chow mein';
UPDATE `menu_items` SET `image` = 'prawn-noodles.jpg'        WHERE restaurant_id = @r AND `name` = 'Egg noodles with prawns';
UPDATE `menu_items` SET `image` = 'fries.jpg'                WHERE restaurant_id = @r AND `name` = 'Skin-on fries';
UPDATE `menu_items` SET `image` = 'coleslaw.jpg'             WHERE restaurant_id = @r AND `name` = 'Coleslaw';
UPDATE `menu_items` SET `image` = 'side-salad.jpg'           WHERE restaurant_id = @r AND `name` = 'Side salad';
UPDATE `menu_items` SET `image` = 'chocolate-brownie.jpg'    WHERE restaurant_id = @r AND `name` = 'Chocolate brownie';
UPDATE `menu_items` SET `image` = 'mango-sorbet.jpg'         WHERE restaurant_id = @r AND `name` = 'Mango sorbet';
UPDATE `menu_items` SET `image` = 'still-water.jpg'          WHERE restaurant_id = @r AND `name` = 'Still water 500ml';
UPDATE `menu_items` SET `image` = 'orange-juice.jpg'         WHERE restaurant_id = @r AND `name` = 'Fresh orange juice';
UPDATE `menu_items` SET `image` = 'masala-chai.jpg'          WHERE restaurant_id = @r AND `name` = 'Masala chai';

-- ---------------------------------------------------------------------
-- Spice Route
-- ---------------------------------------------------------------------
SET @r := (SELECT id FROM restaurants WHERE slug = 'spice-route');

UPDATE `menu_items` SET `image` = 'onion-bhaji.jpg'          WHERE restaurant_id = @r AND `name` = 'Onion bhaji';
UPDATE `menu_items` SET `image` = 'samosa-chaat.jpg'         WHERE restaurant_id = @r AND `name` = 'Samosa chaat';
UPDATE `menu_items` SET `image` = 'chicken-tikka.jpg'        WHERE restaurant_id = @r AND `name` = 'Chicken tikka';
UPDATE `menu_items` SET `image` = 'butter-chicken.jpg'       WHERE restaurant_id = @r AND `name` = 'Butter chicken';
UPDATE `menu_items` SET `image` = 'rogan-josh.jpg'           WHERE restaurant_id = @r AND `name` = 'Lamb rogan josh';
UPDATE `menu_items` SET `image` = 'chana-masala.jpg'         WHERE restaurant_id = @r AND `name` = 'Chana masala';
UPDATE `menu_items` SET `image` = 'saag-paneer.jpg'          WHERE restaurant_id = @r AND `name` = 'Saag paneer';
UPDATE `menu_items` SET `image` = 'chicken-biryani.jpg'      WHERE restaurant_id = @r AND `name` = 'Chicken dum biryani';
UPDATE `menu_items` SET `image` = 'vegetable-biryani.jpg'    WHERE restaurant_id = @r AND `name` = 'Vegetable biryani';
UPDATE `menu_items` SET `image` = 'plain-naan.jpg'           WHERE restaurant_id = @r AND `name` = 'Plain naan';
UPDATE `menu_items` SET `image` = 'garlic-naan.jpg'          WHERE restaurant_id = @r AND `name` = 'Garlic and coriander naan';
UPDATE `menu_items` SET `image` = 'tandoori-roti.jpg'        WHERE restaurant_id = @r AND `name` = 'Tandoori roti';
UPDATE `menu_items` SET `image` = 'gulab-jamun.jpg'          WHERE restaurant_id = @r AND `name` = 'Gulab jamun';
UPDATE `menu_items` SET `image` = 'kheer.jpg'                WHERE restaurant_id = @r AND `name` = 'Kheer';
UPDATE `menu_items` SET `image` = 'mango-lassi.jpg'          WHERE restaurant_id = @r AND `name` = 'Mango lassi';
UPDATE `menu_items` SET `image` = 'lime-soda.jpg'            WHERE restaurant_id = @r AND `name` = 'Salted lime soda';

-- ---------------------------------------------------------------------
-- Bella Napoli
-- ---------------------------------------------------------------------
SET @r := (SELECT id FROM restaurants WHERE slug = 'bella-napoli');

UPDATE `menu_items` SET `image` = 'burrata.jpg'              WHERE restaurant_id = @r AND `name` = 'Burrata and tomato';
UPDATE `menu_items` SET `image` = 'arancini.jpg'             WHERE restaurant_id = @r AND `name` = 'Arancini';
UPDATE `menu_items` SET `image` = 'margherita.jpg'           WHERE restaurant_id = @r AND `name` = 'Margherita';
UPDATE `menu_items` SET `image` = 'diavola.jpg'              WHERE restaurant_id = @r AND `name` = 'Diavola';
UPDATE `menu_items` SET `image` = 'quattro-formaggi.jpg'     WHERE restaurant_id = @r AND `name` = 'Quattro formaggi';
UPDATE `menu_items` SET `image` = 'marinara.jpg'             WHERE restaurant_id = @r AND `name` = 'Marinara';
UPDATE `menu_items` SET `image` = 'prosciutto-rucola.jpg'    WHERE restaurant_id = @r AND `name` = 'Prosciutto e rucola';
UPDATE `menu_items` SET `image` = 'focaccia.jpg'             WHERE restaurant_id = @r AND `name` = 'Rosemary focaccia';
UPDATE `menu_items` SET `image` = 'rocket-salad.jpg'         WHERE restaurant_id = @r AND `name` = 'Rocket and parmesan salad';
UPDATE `menu_items` SET `image` = 'tiramisu.jpg'             WHERE restaurant_id = @r AND `name` = 'Tiramisu';
UPDATE `menu_items` SET `image` = 'lemon-sorbet.jpg'         WHERE restaurant_id = @r AND `name` = 'Lemon sorbet';
UPDATE `menu_items` SET `image` = 'san-pellegrino.jpg'       WHERE restaurant_id = @r AND `name` = 'San Pellegrino 500ml';
UPDATE `menu_items` SET `image` = 'chinotto.jpg'             WHERE restaurant_id = @r AND `name` = 'Chinotto';

-- ---------------------------------------------------------------------
-- Green Bowl
-- ---------------------------------------------------------------------
SET @r := (SELECT id FROM restaurants WHERE slug = 'green-bowl');

UPDATE `menu_items` SET `image` = 'falafel-bowl.jpg'         WHERE restaurant_id = @r AND `name` = 'Falafel grain bowl';
UPDATE `menu_items` SET `image` = 'chicken-quinoa-bowl.jpg'  WHERE restaurant_id = @r AND `name` = 'Chicken and quinoa bowl';
UPDATE `menu_items` SET `image` = 'caesar-salad.jpg'         WHERE restaurant_id = @r AND `name` = 'Caesar salad';
UPDATE `menu_items` SET `image` = 'green-juice.jpg'          WHERE restaurant_id = @r AND `name` = 'Green juice';

-- ---------------------------------------------------------------------
-- Restaurant logos
--
-- These live in smarteats/uploads/logos/, a different folder from the
-- dish photographs, because they are a different shape and are served in
-- different places. Square images of around 400 by 400 pixels work best.
-- A restaurant with no logo shows its initials, which reads as a design
-- choice rather than a missing file.
-- ---------------------------------------------------------------------
UPDATE `restaurants` SET `logo` = 'smart-eats-kitchen.png' WHERE slug = 'smart-eats-kitchen';
UPDATE `restaurants` SET `logo` = 'spice-route.png'        WHERE slug = 'spice-route';
UPDATE `restaurants` SET `logo` = 'bella-napoli.png'       WHERE slug = 'bella-napoli';
UPDATE `restaurants` SET `logo` = 'green-bowl.png'         WHERE slug = 'green-bowl';

-- ---------------------------------------------------------------------
-- Which dishes still have no photograph:
--
--   SELECT r.name AS restaurant, m.name AS dish
--     FROM menu_items m
--     JOIN restaurants r ON r.id = m.restaurant_id
--    WHERE m.image IS NULL OR m.image = ''
--    ORDER BY r.name, m.name;
--
-- A row here is not a fault. The placeholder graphic is deliberate and
-- the menu displays correctly without a photograph.
-- ---------------------------------------------------------------------
