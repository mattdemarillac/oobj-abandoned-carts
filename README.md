# Saved Carts for WooCommerce

[![Build and Release](https://github.com/mattdemarillac/oobj-abandoned-carts/actions/workflows/release.yml/badge.svg)](https://github.com/mattdemarillac/oobj-abandoned-carts/actions/workflows/release.yml)

Two things for a WooCommerce store:

1. **A list of unfinished carts** under **WooCommerce → Saved carts**: the carts shoppers filled that didn't become a completed order, with who they were (once checkout knows), what was in the cart, how far they got, and whether they emailed the cart to themselves.
2. **"Email me my cart"** for shoppers: under the cart (and, collapsed, under the checkout) a shopper enters their email address and gets their cart by email, with a link that puts it back on any device.

WooCommerce deletes cart sessions after 2 days for guests and 7 days for signed-in customers, so the plugin keeps its own record of every cart as it changes.

> **Status: pre-release (0.3.2).** It runs on a development site, not yet in production. See [Status](#status) for what's left before 1.0.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [The saved carts list](#the-saved-carts-list)
- [Email me my cart](#email-me-my-cart)
- [Configuration](#configuration)
- [Data and privacy](#data-and-privacy)
- [Uninstalling](#uninstalling)
- [Licence](#licence)
- [Status](#status)
- More: [reference](docs/REFERENCE.md) (hooks, REST route, tables, markup) · [analytics](docs/ANALYTICS.md) (GA4) · [development](docs/DEVELOPMENT.md) (tests, coverage, lint)

## Requirements

| | |
|---|---|
| WordPress | 6.6 or later |
| PHP | 8.1 or later |
| WooCommerce | Active (the plugin declares `Requires Plugins: woocommerce`). Works with the block cart and checkout, and records orders from the classic checkout too. |
| Optional | A bot check for "Email me my cart": Cloudflare Turnstile with the keys from [Simple Cloudflare Turnstile](https://wordpress.org/plugins/simple-cloudflare-turnstile/), or your own ([Configuration](#configuration)). Nothing else is required. |

## Installation

1. Upload the plugin folder to `wp-content/plugins/` (or install the zip from **Plugins → Add New → Upload Plugin**).
2. Activate it.

New versions then arrive through **Dashboard → Updates** from the plugin's GitHub releases, with nothing to set up ([development](docs/DEVELOPMENT.md#ci-and-releases)).

On activation the plugin creates its two tables (see [Data and privacy](#data-and-privacy)) and imports the carts WooCommerce still has in its sessions (the last 2 to 7 days), so the list isn't empty on day one. The daily clean-up (Action Scheduler, part of WooCommerce) is scheduled on the next admin page load.

After an update, the tables are upgraded on the next page load when their version changes. There's no separate migration to run.

## The saved carts list

**WooCommerce → Saved carts** (shop managers and administrators: the `manage_woocommerce` capability).

- **Period:** the last 24 hours, 3, 7 (default), 14 or 30 days, by last activity. Totals for the period are at the top.
- **Listed:** carts that didn't become a completed order. "Completed" means the order is paid, on hold or refunded ([filter](docs/REFERENCE.md#filters)).
- **Columns:**

| Column | Shows |
|---|---|
| Last activity | When the cart last changed (hover for the date and time). |
| Customer | Name and email once checkout has them; a link to the customer's account if they were signed in. |
| Items | What was in the cart, with quantities. |
| Total | The cart's total, in its currency. |
| Got as far as | **Cart only**, **Reached checkout**, **Order placed, not paid**, **Payment failed** or **Order cancelled (unpaid)**, with a link to the order when there is one. |
| Emailed | Whether the shopper emailed the cart to themselves ("Yes, 2 days ago") and whether they came back from the email ("Came back 1 hour ago"). |
| Started | When the cart was first filled. |

How carts are recorded:

- A cart is saved whenever WooCommerce saves it (at most every 10 minutes while nothing changes), with the shopper's name and email once checkout has them, and the order checkout creates.
- A cart the shopper empties is forgotten, unless it already has an order (checkout empties the cart once the order is placed).
- Signing out keeps the account's cart.
- Carts are deleted 30 days after their last activity ([filter](docs/REFERENCE.md#filters)).

## Email me my cart

What the shopper sees:

- **Cart page:** under the cart, "Not ready to check out?" with an email field and an **Email me my cart** button. It shows only when the cart has something in it.
- **Checkout:** the same form under the checkout, collapsed behind "Not ready to buy? Email yourself this cart", so it isn't in the way of paying.
- **The email** ("Your saved cart at *store name*", in WooCommerce's email template): each item with its photo, name, quantity and price, and a **Back to my cart** button.
- **The link** works for 30 days (the retention period), on any device. It adds every item that's still for sale to the cart the shopper has open, then shows the cart with "Your saved cart is back." Items that are out of stock or gone are skipped with "No longer available: *names*." Opening the link twice in one session doesn't add the items twice. An expired or unknown link says "That saved cart link has expired."

How it works:

1. The form posts to the REST route `POST /wp-json/oobj-ac/v1/saved-carts` ([reference](docs/REFERENCE.md#rest-route)).
2. The plugin saves a copy of the cart's items with a random token, and stores only a SHA-256 hash of the token: a copy of the table can't be used to open anyone's cart.
3. The link is `/cart/?saved-cart=TOKEN`. On the cart page, before it renders, the plugin finds the saved cart by the token's hash, adds the items with WooCommerce's own `add_to_cart()`, and redirects to `/cart/` without the token. No JavaScript is involved. The cart page must not be served from a page cache (WooCommerce's cart never should be).
4. The cart's record in the list gets the email address if it had none, and the **Emailed** column shows it.

Against misuse (the form sends email from the store's domain):

- the shopper's session must have a cart;
- a hidden field that people don't see and bots fill in: a filled-in field gets a "sent" answer and nothing is sent;
- a **bot check** when one is set up, such as Cloudflare Turnstile ([Configuration](#configuration));
- at most **3 emails to an address a day** and **5 emails from one IP address an hour**.

The address is used only to send that cart. It isn't added to any mailing list, and the form says so.

## Configuration

There's no settings page. Behaviour is changed with filters ([reference](docs/REFERENCE.md#filters)):

| Filter | Default | Changes |
|---|---|---|
| `oobj_ac_retention_days` | `30` | How long carts are kept, and how long "Email me my cart" links work. |
| `oobj_ac_completed_statuses` | Paid statuses, `on-hold`, `refunded` | Which order statuses count as a completed order (not listed). |
| `oobj_ac_bot_check` | Turnstile, when it has keys; otherwise none | The bot check on "Email me my cart": another one, or `null` for none. |
| `oobj_ac_ga4` | `true` | Whether the GA4 adapter's script loads. |

**Bot check (optional).** "Email me my cart" always has the trap field and the limits. A bot check is an extra, plugged in through an adapter ([reference](docs/REFERENCE.md#bot-checks)):

- **Cloudflare Turnstile** is built in. It's used when the keys from [Simple Cloudflare Turnstile](https://wordpress.org/plugins/simple-cloudflare-turnstile/) are saved (both the site key and the secret key). Its script loads only once the shopper starts on the form, and the server checks each answer with Cloudflare.
- **Another check** (reCAPTCHA, hCaptcha, your own): implement `OOBJ_AC_Bot_Check` and return it from the `oobj_ac_bot_check` filter.
- **None:** without Turnstile keys there's none, or return `null` from the filter to turn Turnstile off.

**Look:** the form uses the theme's colours and type through WordPress's preset variables (`--wp--preset--color--*`), with plain fallbacks; set `--oobj-ac-field-border` for the email field's border. Its markup and classes are in the [reference](docs/REFERENCE.md#markup).

**Analytics (optional).** When a cart is sent, the form fires the DOM event `oobj-ac:saved`. The built-in **Google Analytics 4** adapter turns it into the GA4 event `save_cart`, only on pages that already load GA (`gtag()`); the plugin never loads GA itself. Return `false` from the `oobj_ac_ga4` filter to turn the adapter off, or listen for the event to send it to another tool ([analytics](docs/ANALYTICS.md)).

## Data and privacy

The plugin stores personal data. Mention it in the store's privacy policy.

| Table | What's in it | Kept |
|---|---|---|
| `{prefix}oobj_ac_carts` | One row per cart: WooCommerce's session key, the user ID when signed in, name and email (once checkout has them, or from "Email me my cart"), the items, count, total and currency, the order ID, and when it started, changed and reached checkout. | 30 days after the last activity |
| `{prefix}oobj_ac_saved` | One row per emailed cart: the cart it came from, the email address, the items, a SHA-256 hash of the link's token, and when it was sent, when the link expires and when it was last used. | Until the link expires (30 days) |

Also:

- **IP addresses aren't stored.** The per-IP limit is a transient keyed by a hash of the IP that expires after an hour.
- **Turnstile** (when it's the bot check) sends the shopper's IP address and the widget's answer to Cloudflare to check them.
- The plugin's option is `oobj_ac_db_version`, its session keys are `oobj_ac` and `oobj_ac_restored`, and its Action Scheduler hook is `oobj_ac_prune` (group `oobj-abandoned-carts`).

## Uninstalling

**Deactivating** stops recording and unschedules the clean-up; the data stays. **Deleting** the plugin from **Plugins** drops both tables and deletes its option.

## Licence

Use and modification are permitted; redistribution isn't. See [LICENSE](LICENSE). WordPress, WooCommerce and the Composer packages keep their own licences.

## Status

Done:

- The saved carts list, with the **Emailed** column.
- "Email me my cart" on the cart and checkout pages, the email, and the link back.
- Optional integrations as adapters, in `inc/adapters/` and `assets/adapters/`: Cloudflare Turnstile (bot check) and Google Analytics 4.
- PHPUnit tests (74) with 100% line coverage, run against WordPress and WooCommerce.
- Updates from GitHub releases, through **Dashboard → Updates** ([development](docs/DEVELOPMENT.md#ci-and-releases)).
- A CI build: lint, the tests with 100% coverage, then the release zip.
- This documentation.

Before 1.0:

- **An accessibility check** of the form (axe, WCAG 2.2 AA) on the cart and checkout, on phone and desktop.
- **The Turnstile widget's space:** with Cloudflare's test keys it leaves an empty gap in the form; check it with real keys.
- **A link from the mini cart** to the form.
- **GA4:** `restore_cart`, and tagging the email's link so purchases from it can be attributed ([analytics](docs/ANALYTICS.md)).
- **Generic test scripts** ([development](docs/DEVELOPMENT.md#the-current-scripts-and-their-assumptions)) and **a neutral name and prefix**.
