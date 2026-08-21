# Smart Eats

A web-based food ordering platform for small, independent restaurants.
Built for LD6053 Computing and Digital Technologies, Northumbria University.

Several restaurants share one site. Each keeps its own menu, its own prices,
its own delivery rules and its own kitchen screen. A customer chooses a
restaurant, orders from it, pays online and follows the order from
confirmation to the door.

---

## Contents

- [What Phase 11 changed](#what-phase-11-changed)
- [Requirements](#requirements)
- [Installing](#installing)
- [Signing in](#signing-in)
- [If a password is refused](#if-a-password-is-refused)
- [How the roles work](#how-the-roles-work)
- [Project structure](#project-structure)
- [Configuration](#configuration)
- [Helper reference](#helper-reference)
- [Design decisions worth citing](#design-decisions-worth-citing)
- [Test scenarios](#test-scenarios)

---

## What Phase 11 changed

Phases 1 to 10 built the system as a single restaurant's storefront. The
project proposal, however, describes a system for small-scale restaurants in
the plural, and the problem statement is about the ordering practices of an
industry rather than of one business. Phase 11 closes that gap. **The research
question, aim and objectives are unchanged**; only the system has been extended
to match the proposal that was already written.

Concretely:

| Before | After |
|---|---|
| One menu | One menu per restaurant, browsed restaurant by restaurant |
| No sellers to choose between | A restaurant directory with search and cuisine filters |
| Staff accounts saw every order | Each account is scoped to one restaurant |
| Fees and minimums in the `settings` table | Columns on each restaurant's own row |
| No way to join the platform | A public registration page and an approval queue |
| Tracking said the food was ready | Tracking says which restaurant has the order |

The database gained a `restaurants` table, and `users`, `categories`,
`menu_items`, `orders` and `reviews` each gained a `restaurant_id`.

### The single-restaurant basket

**A basket holds dishes from exactly one restaurant.** Adding a dish from
another restaurant offers to start a fresh basket rather than merging.

This is the same rule Uber Eats, Deliveroo and Just Eat apply, and it is the
single most important design decision in Phase 11. Allowing one basket to span
two kitchens would mean two payments, two tickets, two preparation times and
two delivery windows behind one order reference, which multiplies the
complexity of checkout, payment, the order board and the tracker. Keeping one
order to one seller leaves all of those exactly as Phases 1 to 10 built them.

The rule is enforced in `api/cart_action.php`, not in the browser. A
cross-restaurant add is answered with HTTP 409 and `needs_switch`, naming both
restaurants; the basket is only emptied after the customer says yes.

## What Phase 11B added

Phase 11A made the platform work with several restaurants. Phase 11B fills in
the parts a restaurant actually runs on day to day.

**Scheduled opening hours.** `opening_hours` was a sentence written for a
customer to read, which is useless for deciding whether a kitchen is open right
now. A new `restaurant_hours` table holds a weekly schedule the system can act
on, and ordering opens and closes with it. Shifts running past midnight are
handled properly, so 17:00 to 01:00 is a normal evening service rather than a
data error.

Following the schedule is **opt in**, and the seeded restaurants have it turned
off. A restaurant already trading on the manual switch is not changed by the
upgrade, and a demonstration never opens with a restaurant unexpectedly closed
because of the time of day. Turn it on from **Opening hours** in the panel.

**Restaurant reviews, separate from dish reviews.** The lasagne can be
excellent while the delivery was an hour late. Customers now rate the
restaurant itself, optionally scoring food and speed separately, and that
feeds the rating shown on the directory alongside the dish ratings.

**Reports, including order processing time.** This is the screen that answers
the research question. Every status change has been timestamped in
`order_status_history` since Phase 3, so the time an order spends waiting to be
accepted, being cooked and waiting to go out is measured rather than estimated.
A restaurant working from paper tickets cannot produce these numbers at all,
which is the comparison the dissertation is making.

**Delivery zones.** A restaurant can list the outward postcodes it covers, and
checkout refuses a delivery outside them while still offering collection. An
address with no recognisable postcode is allowed through, because refusing a
real customer is the worse mistake.

**Settlement and commission.** Each restaurant carries a commission rate,
defaulting to zero, and the platform administrator gets a settlement report of
gross, commission and net payable per restaurant for any date range. Cash
orders are excluded, because that money never passed through the platform.

**Card payment diagnostics.** A screen that checks each Stripe key separately
and says which one is wrong, rather than leaving that to be deduced from a
blank payment form.

---

## Requirements

- XAMPP with Apache and MySQL or MariaDB
- PHP 8.0 or newer
- No Composer, no frameworks, no build step

---

## Installing

1. Copy the `smarteats` folder into `C:\xampp\htdocs`, so the project sits at
   `C:\xampp\htdocs\smarteats`.

2. Start **Apache** and **MySQL** from the XAMPP control panel.

3. Open <http://localhost/phpmyadmin>, choose the **Import** tab and import
   `sql/smarteats.sql`. This creates the database and seeds four demo
   restaurants with full menus.

4. Optionally import `sql/phase11b_features.sql` as well. A fresh
   `smarteats.sql` already contains everything in it, so this is only needed
   when upgrading a database you already have data in. It is additive and
   safe to run twice.

   > **Import one SQL file, not both.** `sql/smarteats.sql` is a clean
   > install: it drops every table and rebuilds from scratch.
   > `sql/phase11_restaurants.sql` is an upgrade for a database you already
   > have data in. Running the migration and then the clean install throws
   > away everything the migration just preserved.

   From a command line instead:

   ```
   mysql -u root < sql/smarteats.sql
   ```

5. Open <http://localhost/smarteats>.

Re-importing `sql/smarteats.sql` resets everything to the seeded state. It drops
the individual tables rather than the whole database, because phpMyAdmin
disables `DROP DATABASE` by default and would refuse the import at the first
statement.

### Upgrading an existing Phase 1 to 10 database

If you already have a database with orders you want to keep, do **not** import
`smarteats.sql`. Import `sql/phase11_restaurants.sql` instead. It creates the
`restaurants` table, builds one restaurant from your existing `settings`
values, backfills every existing menu item, order and review with its id, then
adds the constraints. Your old storefront becomes the platform's first
restaurant and nothing is lost.

Run it once only; it uses `ADD COLUMN`, so a second run fails with a duplicate
column error.

Because the `vendor` role did not exist before Phase 11, the migration also
creates one owner account for the migrated restaurant, so the new role can
actually be tried:

    owner.kitchen@smarteats.test / Vendor@2026

The other seeded owners and the demo restaurants exist only in
`sql/smarteats.sql`. Your original administrator and staff logins are
untouched.

### Adding images

Photographs are optional. A dish with no photo shows a placeholder graphic and
a restaurant with no logo shows its initials, both by design, so the site looks
finished either way.

There are two folders, and they are not interchangeable:

| Folder | Holds | Shape |
|---|---|---|
| `uploads/menu/` | dish photographs | 4:3 landscape, around 800 x 600 |
| `uploads/logos/` | restaurant logos | square, around 400 x 400 |

**One at a time, through the panel.** This is the normal route and the one to
demonstrate. Sign in as a restaurant owner, then:

- a dish photo: **Menu items → Edit → Photo → upload**
- a logo: **Settings → Logo → upload**

The file is checked by its real contents, renamed by the application and stored
in the right folder automatically. Replacing a photo deletes the old file.

**A folder of images all at once.** After a database rebuild the files in
`uploads/` survive but the rows pointing at them do not, so every dish reverts
to the placeholder. Rather than re-uploading them one by one, open:

    http://localhost/smarteats/attach_images.php

It lists the files actually present in both folders, shows a thumbnail of what
each dish and restaurant currently has, and offers a dropdown per row.
**Suggest matches by filename** fills in the obvious ones by reducing both
sides to a comparison key, so `chicken-wings.jpg` is offered to *Chicken wings*
regardless of case, spaces, dashes or extension. Suggestions are not saved
until you press the button, so a wrong guess costs nothing.

Nothing is moved, renamed or deleted; only the `image` and `logo` columns are
written. Delete `attach_images.php` once the images are attached.

**In SQL.** `sql/menu_images.sql` sets a filename for every seeded dish across
all four restaurants. Useful if you want the same set restored automatically
after each rebuild. Every statement is scoped to one restaurant, because dish
names are only unique within a restaurant and two of them could both sell a
Margherita.

### Recovering from a half-finished import

If an import stopped partway, the site may report a missing table such as
`smarteats.login_attempts doesn't exist`. Some tables were dropped and never
rebuilt. Nothing is wrong with the application; the database is simply
incomplete.

To recover, in phpMyAdmin select the `smarteats` database, choose
**Operations → Drop the database (DROP)**, then import `sql/smarteats.sql`
again. It recreates everything from scratch.

### Why an import can stop at `DROP TABLE restaurants`

`users` and `restaurants` reference each other: a restaurant has an owner, and
an owner belongs to a restaurant. While both constraints exist there is no
order in which the tables can be dropped if the server is enforcing foreign
keys, and the import fails with **#1451 Cannot delete or update a parent row**.

`sql/smarteats.sql` sets `FOREIGN_KEY_CHECKS = 0`, but phpMyAdmin's Import tab
has an **Enable foreign key checks** box that is ticked by default and can
override it. The file therefore removes that one constraint itself before
dropping anything, using a block that does nothing if the constraint is not
there. Imports now succeed whether foreign key checks are on or off, on a fresh
server, over a previous install, or over a database that has already been
through the migration.

### Enabling card payment (Stripe sandbox)

Card payment stays switched off until test keys are added, and the system says
so on the checkout page rather than failing. Cash on delivery and cash on
collection work without any of this, so the system is fully demonstrable
before you start.

Everything below happens in **test mode**. No real money moves, no bank
account is needed, and no real card is ever used.

**1. Create a Stripe account.** Sign up at stripe.com. You can skip the
business details it asks for; test mode does not need them.

**2. Switch test mode on.** There is a **Test mode** toggle at the top right of
the Stripe dashboard. Turn it on before copying anything. Every key you copy
afterwards begins `pk_test_` or `sk_test_`; a key beginning `pk_live_` or
`sk_live_` means the toggle is off.

**3. Copy the two API keys.** Go to **Developers → API keys**. Copy the
**Publishable key**, then reveal and copy the **Secret key**.

**4. Paste them into `config/config.php`**, replacing the placeholders:

```php
define('STRIPE_PUBLISHABLE_KEY', 'pk_test_51AbC...');
define('STRIPE_SECRET_KEY',      'sk_test_51AbC...');
define('STRIPE_WEBHOOK_SECRET',  'whsec_replace_me');   // optional, see below
```

Watch for a space at the start or end when pasting. It is the single most
common cause of a key that looks right and is rejected.

**5. Check it.** Sign in as a platform administrator and open
**Card payments** in the panel. Each key is checked separately and told apart:
missing, wrong prefix, live key by mistake, or stray whitespace. Press **Test
the connection to Stripe** to have the server create and discard a real test
PaymentIntent, which is the only way to prove the secret key is genuinely
accepted.

**6. Place a test order.** At checkout choose *Pay by card now*, then use:

| Field | Value |
|---|---|
| Card number | `4242 4242 4242 4242` |
| Expiry | any future date, e.g. `12/30` |
| CVC | any three digits |
| Postcode | any, e.g. `E1 6AN` |

Other useful test cards: `4000 0000 0000 0002` is declined, and
`4000 0025 0000 3155` requires 3D Secure authentication, which is worth
demonstrating because it exercises the redirect path.

**The webhook secret is optional.** Without it an order is marked paid when the
customer returns to the confirmation page, which covers every normal case and
is enough for a demonstration. With it, a payment still confirms if the
customer closes the browser mid-redirect. To set it up you need Stripe's CLI
forwarding to `http://localhost/smarteats/api/stripe_webhook.php`, because
Stripe cannot reach `localhost` from the internet.

**Never commit live keys.** `config/config.php` holds credentials, and the
`.htaccess` in `config/` denies web access to that folder so a
misconfiguration cannot serve it as plain text. Keys are deliberately not
editable from the admin panel: a key stored in the database would end up inside
every database export.

---

## Signing in

Ten accounts are seeded. Change these before any user testing session.

**Platform administrators** — approve restaurants, see everything

| Email | Password |
|---|---|
| `manager@smarteats.test` | `Manager@2026` |
| `admin@smarteats.test` | `admin123` |

**Restaurant owners** — manage one restaurant

| Email | Password | Restaurant |
|---|---|---|
| `owner.kitchen@smarteats.test` | `Vendor@2026` | Smart Eats Kitchen |
| `owner.spice@smarteats.test` | `Vendor@2026` | Spice Route |
| `owner.bella@smarteats.test` | `Vendor@2026` | Bella Napoli |
| `owner.green@smarteats.test` | `Vendor@2026` | Green Bowl *(pending approval)* |

**Kitchen staff** — one restaurant's order board only

| Email | Password | Restaurant |
|---|---|---|
| `kitchen@smarteats.test` | `Kitchen@2026` | Smart Eats Kitchen |
| `staff@smarteats.test` | `staff123` | Smart Eats Kitchen |
| `kitchen.spice@smarteats.test` | `Kitchen@2026` | Spice Route |
| `kitchen.bella@smarteats.test` | `Kitchen@2026` | Bella Napoli |

**Green Bowl is seeded as pending on purpose.** It is invisible to customers
until a platform administrator approves it, which lets you demonstrate the
approval gate without editing the database by hand.

---

## If a password is refused

A bcrypt hash is a 60 character string full of `$`, `/` and `.` characters. It
survives being copied through a SQL file most of the time, and when it does not
the symptom is confusing: the account exists, the password is right, and the
sign-in is still refused.

Open **<http://localhost/smarteats/setup_accounts.php>** and press the button.
It regenerates every hash with PHP on your own machine, verifies each one with
`password_verify()` before reporting success, reattaches every owner and staff
account to the right restaurant, and clears any lockout.

**Delete `setup_accounts.php` once an administrator can sign in.** Anyone who
can reach it can reset these passwords. From then on, accounts are managed
properly from the panel's Staff screen, which also hashes locally.

### If one specific account is refused

Sign in as another administrator or as the restaurant's owner and open
**Staff**. Each account shows its lockout state:

- **Locked out** means five failed attempts inside the last fifteen minutes.
  The account then refuses even the correct password until the window passes.
  Press **Unlock** to clear it immediately.
- Typing a new password beside the account and pressing **Reset** hashes it
  locally and clears the lockout at the same time.

---

## How the roles work

| Role | Sees |
|---|---|
| `customer` | The directory, every approved restaurant's menu, their own orders |
| `staff` | The live order board for **their own** restaurant |
| `vendor` | Their own restaurant: menu, categories, staff, orders, settings |
| `admin` | The platform: approvals, every restaurant, platform settings |

Vendors and administrators share the same panel screens rather than there being
a duplicated `/vendor` folder. Every screen scopes itself through
`panel_restaurant()`: a vendor is fixed to the restaurant on their account,
while an administrator picks one from the switcher in the sidebar, or leaves it
on **All restaurants** to see the platform as a whole.

That single function is the security boundary of the whole platform. Every
panel query filters on what it returns, and every screen that acts on a record
fetched by id calls `require_restaurant_access()` before touching it, because an
id in a URL or a form post is not a permission.

### A restaurant's three states

- **pending** — registered, invisible to customers, owner can build the menu
- **approved** — listed, searchable, able to take orders
- **suspended** — removed from the directory, orders and history retained

Restaurants are suspended rather than deleted. A restaurant with order history
cannot be deleted without taking that history with it, and that history is the
evidence base for the processing-time analysis.

---

## Project structure

```
smarteats/
├── index.php                  platform home
├── restaurants.php            the restaurant directory
├── restaurant.php             one restaurant's menu
├── menu.php                   search every dish, grouped by restaurant
├── item.php                   one dish
├── cart.php  checkout.php  payment.php  order_success.php
├── track.php                  tracking, names the restaurant
├── my_orders.php  review.php  account.php
├── login.php  logout.php  register.php
├── restaurant_register.php    list your restaurant
├── setup_accounts.php         account repair tool, delete after setup
├── 404.php  500.php
├── admin/
│   ├── dashboard.php          overview, vendor or platform
│   ├── menu.php  item_form.php  categories.php
│   ├── orders.php  users.php  settings.php
│   ├── restaurants.php        approval queue (platform admin)
│   └── platform.php           platform settings (platform admin)
├── staff/
│   ├── dashboard.php          live order board
│   └── order_view.php         order detail and kitchen ticket
├── api/
│   ├── cart_action.php        basket, enforces one restaurant per basket
│   ├── order_status.php  staff_orders.php  stripe_webhook.php
├── includes/
│   ├── auth.php               sessions, roles, lockout
│   ├── restaurants.php        lookup, state, scoping, permissions
│   ├── orders.php             creation, ownership, status transitions
│   ├── functions.php          helpers and the session basket
│   ├── header.php  footer.php  panel_header.php  panel_footer.php
│   ├── upload.php  stripe.php  errors.php
├── config/
│   ├── config.php             all settings live here
│   └── db.php                 PDO connection and query helpers
├── assets/css  assets/js  assets/img
├── uploads/menu  uploads/logos
└── sql/
    ├── smarteats.sql              full schema and seed data
    ├── phase11_restaurants.sql    migration from Phase 1 to 10
    └── phase2/5/8 …               earlier incremental migrations
```

---

## Configuration

Everything adjustable lives in three places, and the split matters:

- **`config/config.php`** — base URL, database credentials, session timeout,
  Stripe keys, upload limits, and the two platform rules
  (`SINGLE_RESTAURANT_BASKET`, `REQUIRE_RESTAURANT_APPROVAL`).
- **The `restaurants` table** — everything a restaurant sets for itself:
  delivery fee, free-delivery threshold, minimum order, VAT rate, address,
  opening hours, contact details, logo. Edited from **Settings** in the panel.
- **The `settings` table** — platform-wide values only: platform name, tagline,
  support contact, currency, and the maintenance switch. Edited from
  **Platform settings**.

Keeping the last two apart is what stops one business's change from altering
another's prices.

---

## Helper reference

| Function | Purpose |
|---|---|
| `e($value)` | Escape output. Use on every echoed variable. |
| `url('menu.php')` | Build a full URL from the project root. |
| `redirect('cart.php')` | Redirect and stop. |
| `db_one()` `db_all()` `db_value()` `db_insert()` | Prepared-statement queries. |
| `csrf_field()` / `verify_csrf()` | CSRF token for every form. |
| `flash($msg, $type)` | Queue a message for the next page. |
| `setting('platform_name')` | Read a platform setting. |
| `money(9.5)` | Format as £9.50. |
| `require_login()` / `require_role('admin','vendor')` | Access guards. |
| `restaurant_by_id()` `restaurant_by_slug()` | Restaurant lookup. |
| `public_restaurants($filters)` | Approved restaurants for the directory. |
| `restaurant_is_open()` / `restaurant_closed_reason()` | Ordering state. |
| `panel_restaurant()` / `panel_restaurant_id()` | The current panel scope. |
| `require_restaurant_access($id)` | Refuse another restaurant's record. |
| `cart()` `cart_count()` `cart_items()` `cart_totals()` | Session basket. |
| `cart_restaurant()` | Which restaurant the basket belongs to. |
| `active_orders($restaurantId)` | The live board, scoped. |
| `can_view_order()` / `can_manage_order()` | Order-level permission. |

### Using the shared layout

Customer-facing page:

```php
<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Menu';
include __DIR__ . '/includes/header.php';
?>
<!-- page content -->
<?php include __DIR__ . '/includes/footer.php'; ?>
```

Panel page:

```php
<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restaurants.php';
require_role('admin', 'vendor');
require_active_restaurant();
handle_restaurant_switch();
$restaurant = require_panel_restaurant();
$page_title = 'Menu items';
include __DIR__ . '/../includes/panel_header.php';
?>
<!-- page content -->
<?php include __DIR__ . '/../includes/panel_footer.php'; ?>
```

Always use `__DIR__` for includes, otherwise paths break inside `/staff` and
`/admin`.

---

## Design decisions worth citing

**One basket, one restaurant.** Discussed above. The alternative, splitting a
basket into several orders at checkout, was considered and rejected as a scope
boundary rather than an oversight.

**Categories belong to a restaurant, not the platform.** A pizzeria has
Antipasti and Pizza; a takeaway has Curries and Breads. A shared taxonomy would
have been simpler to build and wrong for the users. The category slug is unique
within a restaurant, which is why two restaurants can both have Sides.

**Registration is public, visibility is not.** Anyone may apply to join;
nobody appears on the site until a person has looked at the application. The
owner can sign in and build their menu while they wait, which is the sensible
use of the waiting period.

**The restaurant is stored on the order, not derived from its items.** An order
row carries `restaurant_id` directly. Deriving it from the line items would
break the moment a dish was withdrawn, and it is the column every board,
report and permission check filters on.

**Money settings moved from the `settings` table to the `restaurants` table.**
Two independent businesses cannot share one delivery fee. This is the change
that turns the old admin Settings screen from a platform screen into a
per-restaurant one.

**Order references stay unique platform-wide.** A customer with orders from two
kitchens can never hold two slips carrying the same number.

**Reviews carry `restaurant_id` as well as `menu_item_id`.** Denormalised on
purpose, so a restaurant's average rating on the directory is one indexed query
rather than a three-table join on every card.

**Session basket, not a database table.** Guest checkout works without creating
throwaway account rows, and the scale of the platform does not justify
persisting abandoned baskets.

**Price snapshots on order lines.** `order_items` stores the item name and unit
price as they were when the order was placed, so a later menu edit cannot
rewrite history.

**Every status change is timestamped.** `order_status_history` is what makes
order processing time measurable rather than estimated, and is the evidence
source for the research question.

---

## Test scenarios

The Phase 11 additions. The isolation cases are the strongest evidence that the
platform genuinely separates its tenants.

| ID | Scenario | Expected result |
|---|---|---|
| VEN-01 | Register a restaurant through the public form | Owner account and restaurant created together, status pending |
| VEN-02 | Register with an email that already exists | Refused, neither the account nor the restaurant is created |
| VEN-03 | Open a pending restaurant as a customer | 404, it does not appear in the directory or in search |
| VEN-04 | Open a pending restaurant as its own owner | Visible, marked "Preview only" |
| VEN-05 | Approve the restaurant as a platform admin | Appears in the directory immediately |
| VEN-06 | Suspend an approved restaurant | Disappears from the directory, its orders remain |
| BRW-01 | Browse the directory | Every approved restaurant, with fees and minimum order |
| BRW-02 | Filter by cuisine | Only restaurants of that cuisine |
| BRW-03 | Search for a dish name across the platform | Results grouped under the restaurant that sells each one |
| BRW-04 | Open a dish from the cross-restaurant search | The restaurant is named in the breadcrumb and beside the price |
| BSK-01 | Add a dish, then a dish from another restaurant | Prompted to start a new basket, naming both restaurants |
| BSK-02 | Decline the prompt | Original basket unchanged |
| BSK-03 | Accept the prompt | Basket replaced with the new restaurant's dish |
| BSK-04 | Add a dish from a restaurant that has paused ordering | Refused with the restaurant's own message |
| BSK-05 | Basket below that restaurant's minimum order | Checkout blocked, shortfall stated in the restaurant's own figures |
| BSK-06 | Two restaurants with different delivery fees | Each basket is priced by its own restaurant |
| ORD-01 | Place an order | `orders.restaurant_id` set, order appears only on that restaurant's board |
| ORD-02 | Track the order by reference | Restaurant name, address and phone shown above the timeline |
| ORD-03 | Reorder from a different restaurant than the current basket | Warned, then the basket is replaced |
| ISO-01 | Vendor A opens the live board | Only restaurant A's orders |
| ISO-02 | Vendor A opens restaurant B's order by editing the id in the URL | Refused with 403 |
| ISO-03 | Vendor A posts a status change for restaurant B's order | Refused, the order is unchanged |
| ISO-04 | Vendor A opens restaurant B's dish in the edit form | Refused |
| ISO-05 | Vendor A assigns a dish to restaurant B's category | Refused by validation |
| ISO-06 | Vendor A's Staff screen | Only restaurant A's staff, and only the staff role can be created |
| ISO-07 | Vendor A changes their delivery fee | Restaurant B's prices are unaffected |
| ISO-08 | Staff account polls the board endpoint | Counts and alerts cover its own restaurant only |
| ADM-01 | Admin selects a restaurant in the switcher | Every management screen scopes to it |
| ADM-02 | Admin leaves the switcher on All restaurants | Order history shows a restaurant column |
| ADM-03 | Admin pauses the platform | No restaurant can take an order, browsing still works |

### Phase 11B

| ID | Scenario | Expected result |
|---|---|---|
| HRS-01 | Set a weekly schedule, leave it switched off | Hours shown to customers, ordering unaffected |
| HRS-02 | Switch the schedule on, inside opening hours | Ordering works normally |
| HRS-03 | Switch the schedule on, outside opening hours | Menu browsable, ordering refused, next opening time stated |
| HRS-04 | Mark a day closed and save | Times retained, day shown as closed, ordering refused that day |
| HRS-05 | Set a shift of 17:00 to 01:00, test at 00:30 next day | Treated as open, not closed |
| HRS-06 | Pause ordering manually while inside opening hours | Closed; the manual switch overrides the schedule |
| HRS-07 | Consecutive days with identical times | Grouped as "Monday to Friday" on the restaurant page |
| ZON-01 | No postcodes set, order to any address | Accepted |
| ZON-02 | Set E1, E2; order to an E1 address | Accepted |
| ZON-03 | Set E1, E2; order to an N1 address | Refused with the covered zones named, collection still offered |
| ZON-04 | Set E1, E2; address with no recognisable postcode | Accepted, not blocked |
| REV-01 | Complete an order, open the review page | Restaurant rating offered above the dish ratings |
| REV-02 | Rate the restaurant only, no dishes | Saved |
| REV-03 | Return to the review page for the same order | Restaurant section no longer offered, dishes still are |
| REV-04 | Submit the same review form twice | One review stored, no error shown |
| REV-05 | Restaurant rating left | Counts towards the average on the directory |
| REP-01 | Open Reports as a vendor | Own restaurant only, no other restaurant's figures |
| REP-02 | Complete an order through every status | Each stage timing appears in the processing table |
| REP-03 | Report over a period with no completed orders | Empty state, not a division error |
| REP-04 | Open Reports as admin with no restaurant selected | Platform-wide figures plus the restaurant column |
| REP-05 | Settlement with commission at 0 | Gross equals payable |
| REP-06 | Settlement with commission at 10 per cent | Commission and net calculated per restaurant |
| REP-07 | Cash order in the period | Counted in orders, excluded from settlement |
| PAY-01 | Open Card payments with no keys set | Both keys reported as not set, checkout offers cash only |
| PAY-02 | Paste a key with a trailing space | Reported as a whitespace problem, not as a wrong key |
| PAY-03 | Paste a live key | Reported as a live key, refused |
| PAY-04 | Valid test keys, press Test the connection | PaymentIntent created and reported |
| PAY-05 | Pay with 4242 4242 4242 4242 | Order marked paid, appears on the kitchen board |
| PAY-06 | Pay with 4000 0000 0000 0002 | Declined, order stays unpaid, nothing sent to the kitchen |
| MIG-01 | Import phase11b_features.sql twice | Second run completes with no error and no change |
| MIG-02 | Import it on a database already carrying orders | Orders, menus and accounts untouched |
