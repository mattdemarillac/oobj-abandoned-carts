# Reference

Hooks, the REST route, the database tables and the front-end markup. For what the plugin does, see the [README](../README.md).

- [Filters](#filters)
- [Bot checks](#bot-checks)
- [Browser events](#browser-events)
- [WordPress and WooCommerce hooks the plugin uses](#wordpress-and-woocommerce-hooks-the-plugin-uses)
- [REST route](#rest-route)
- [The restore link](#the-restore-link)
- [Tables](#tables)
- [Markup](#markup)
- [Code layout](#code-layout)

## Filters

### `oobj_ac_retention_days`

How many days carts are kept after their last activity, and how long "Email me my cart" links work. At least 1.

```php
add_filter( 'oobj_ac_retention_days', fn() => 60 );
```

Default: `30`.

### `oobj_ac_completed_statuses`

Order statuses (without `wc-`) that mean the shopper finished checking out. Carts whose order has one of these aren't listed.

```php
add_filter( 'oobj_ac_completed_statuses', function ( array $statuses ) {
	$statuses[] = 'awaiting-shipment'; // A custom status.
	return $statuses;
} );
```

Default: WooCommerce's paid statuses (`wc_get_is_paid_statuses()`: processing and completed), plus `on-hold` and `refunded`.

### `oobj_ac_bot_check`

The bot check on "Email me my cart": an `OOBJ_AC_Bot_Check`, or `null` for none. It receives the built-in Cloudflare Turnstile when that has its keys (else `null`). A check whose `ready()` is false isn't used.

```php
add_filter( 'oobj_ac_bot_check', fn() => new My_Recaptcha_Check() ); // Your own.
add_filter( 'oobj_ac_bot_check', '__return_null' );                 // None.
```

### `oobj_ac_ga4`

Whether the Google Analytics 4 adapter's script (`assets/adapters/ga4.js`) loads with the form. Default `true`. The script sends `save_cart` only on pages that have `gtag()`.

```php
add_filter( 'oobj_ac_ga4', '__return_false' );
```

## Bot checks

A bot check is an adapter: a PHP class for the server and a script for the browser.

**PHP:** implement `OOBJ_AC_Bot_Check` (`inc/interface-oobj-ac-bot-check.php`):

| Method | |
|---|---|
| `ready(): bool` | Whether it's set up (has its keys). One that isn't ready isn't used. |
| `markup(): string` | What goes in the form, escaped: an element with a `data-oobj-ac-bot-check` attribute, where the check shows. |
| `enqueue(): void` | Enqueues its script, on the cart and checkout pages. |
| `verify( string $answer, string $ip ): bool` | Whether the answer the browser sent passes. An empty answer should fail. |

**Browser:** the script defines `window.oobjAcBotCheck`:

| Method | |
|---|---|
| `start( element )` | Shows the check in the element from `markup()`. Called when the shopper starts on the form (focus on the email field); load the provider's script here, not before. |
| `token()` | The answer to send, or `''`, or a promise of it (for a check that may still be working when the shopper sends). Sent as the REST parameter `bot_check`. |
| `reset()` | After each try, so the next one gets a fresh answer. |

The built-in **Cloudflare Turnstile** (`inc/adapters/class-oobj-ac-turnstile.php`, `assets/adapters/turnstile.js`) is an example. It reads Simple Cloudflare Turnstile's options: it's ready when `cfturnstile_key` and `cfturnstile_secret` are both set and the shopper isn't exempt (`cfturnstile_whitelisted()`, or `cfturnstile_failover` with failsafe type `allow` while `cfturnstile_is_cloudflare_down()`); its markup carries `data-appearance`, `data-theme`, `data-size`, `data-language` and `data-refresh-timeout` from `cfturnstile_appearance`, `_theme`, `_size`, `_language` and `_refresh_timeout`; its `token()` waits up to 5 seconds for an answer; and it verifies with `https://challenges.cloudflare.com/turnstile/v0/siteverify`.

## Browser events

| Event | Fired on | When |
|---|---|---|
| `oobj-ac:saved` | The form (bubbles) | A cart was sent: listen on `document` for analytics or anything else. |

```js
document.addEventListener( 'oobj-ac:saved', () => { /* … */ } );
```

## WordPress and WooCommerce hooks the plugin uses

| Hook | What the plugin does |
|---|---|
| `woocommerce_cart_updated` | Saves the cart's record (at most every 10 minutes while nothing changes). |
| `woocommerce_cart_emptied` | Forgets the cart, unless it has an order. |
| `woocommerce_store_api_cart_update_customer_from_request` | Records the name and email from the block checkout. |
| `woocommerce_store_api_checkout_update_order_meta` | Links the block checkout's order to the cart. |
| `woocommerce_checkout_order_processed` | Links the classic checkout's order to the cart. |
| `render_block_woocommerce/cart` | Adds the "Email me my cart" form after the Cart block. |
| `render_block_woocommerce/checkout` | Adds the collapsed form after the Checkout block. |
| `render_block_woocommerce/mini-cart` | Loads the mini cart link's script and style, and adds its address and text after the Mini-Cart block. |
| `template_redirect` | Restores an emailed cart on the cart page (`?saved-cart=`). |
| `rest_api_init` | Registers the REST route. |
| `wp_enqueue_scripts` | Loads the form's script and styles, the bot check's script and the GA4 adapter's, on the cart and checkout pages only. |
| `admin_menu`, `admin_enqueue_scripts` | The **Saved carts** page and its styles. |
| `oobj_ac_prune` (Action Scheduler, daily) | Deletes carts past the retention period and emailed carts whose links have expired. |

## REST route

### `POST /wp-json/oobj-ac/v1/saved-carts`

Emails the shopper's current cart to them. Public (no authentication needed): it works on the cart in the request's WooCommerce session. Send WordPress's REST nonce in `X-WP-Nonce` (the form has it in `data-nonce`): without it WordPress treats a signed-in shopper as signed out, and their cart isn't found.

| Parameter | Type | |
|---|---|---|
| `email` | string, required | Where to send it. |
| `bot_check` | string | The bot check's answer (`window.oobjAcBotCheck.token()`), when there's a bot check. |
| `website` | string | The hidden trap field. Anything in it: the answer is `{"sent": true}` and nothing is sent. |

Answers:

| Status | Code | When |
|---|---|---|
| 200 | | `{"sent": true}`: sent (or the trap field was filled in). |
| 400 | `oobj_ac_email` | Not a valid email address. |
| 400 | `oobj_ac_bot_check` | There's a bot check and the answer is missing or doesn't pass. |
| 400 | `oobj_ac_empty` | The session has no cart, or it's empty. |
| 429 | `oobj_ac_limit` | 3 emails to the address in the last day, or 5 from the IP address in the last hour. |

Errors are WordPress's REST error shape: `{"code": "...", "message": "...", "data": {"status": 400}}`. The messages are written for the shopper; the form shows them.

## The restore link

`/cart/?saved-cart=TOKEN`, where `TOKEN` is 32 hexadecimal characters.

On the cart page (the page set as WooCommerce's cart page, matched by its ID), before it renders:

1. The saved cart is found by the token's SHA-256 hash, unless its link has expired.
2. Unless it was already restored in this session, each item is added with `WC()->cart->add_to_cart()` (product, quantity, variation and its attributes) if the product still exists, can be bought and is in stock. The others are listed in a notice.
3. The row records when the link was used and how many times.
4. A redirect to the cart, without the token.

## Tables

### `{prefix}oobj_ac_carts`

| Column | Type | |
|---|---|---|
| `id` | bigint unsigned, primary key | |
| `cart_key` | varchar(64) | WooCommerce's session customer ID (indexed). |
| `user_id` | bigint unsigned | The account, when signed in. |
| `email`, `name` | varchar(200) | Once checkout has them, or from "Email me my cart". |
| `items` | longtext (JSON) | `product_id`, `variation_id`, `name`, `quantity`, `line_total` (with tax) per item. |
| `item_count` | int unsigned | |
| `total` | decimal(19,4) | With tax. |
| `currency` | char(3) | |
| `order_id` | bigint unsigned | The order checkout created (indexed). |
| `created_at`, `updated_at` | datetime (UTC) | `updated_at` is indexed. |
| `checkout_at` | datetime (UTC), null | When it reached checkout. |

### `{prefix}oobj_ac_saved`

| Column | Type | |
|---|---|---|
| `id` | bigint unsigned, primary key | |
| `cart_id` | bigint unsigned | The cart's row in `oobj_ac_carts`, or 0 (indexed). |
| `token_hash` | char(64), unique | SHA-256 of the link's token. The token itself isn't stored. |
| `email` | varchar(200) | Where it was sent (indexed). |
| `items` | longtext (JSON) | `product_id`, `variation_id`, `variation` (attributes), `quantity`, `name` per item. |
| `created_at` | datetime (UTC) | |
| `expires_at` | datetime (UTC) | When the link stops working (indexed). |
| `restored_at` | datetime (UTC), null | When the link was last used. |
| `restores` | int unsigned | How many times it was used (once per session). |

The schema version is in the option `oobj_ac_db_version` (currently `2`). `dbDelta()` brings the tables up to date when it changes.

## Markup

The cart page's form (after the Cart block):

```html
<section class="oobj-ac-save" id="save-cart" aria-labelledby="oobj-ac-save-title">
	<h2 class="oobj-ac-save__title" id="oobj-ac-save-title">Not ready to check out?</h2>
	<p class="oobj-ac-save__text">Email yourself this cart and pick it up on any device. …</p>
	<form class="oobj-ac-save__form" data-url="/wp-json/oobj-ac/v1/saved-carts" data-nonce="…" novalidate>
		<label class="oobj-ac-save__label" for="oobj-ac-email">Email</label>
		<div class="oobj-ac-save__row">
			<input class="oobj-ac-save__email" id="oobj-ac-email" name="email" type="email" autocomplete="email" required />
			<button class="oobj-ac-save__button wp-element-button" type="submit">Email me my cart</button>
		</div>
		<input class="oobj-ac-save__trap" name="website" type="text" tabindex="-1" autocomplete="off" aria-hidden="true" />
		<div class="oobj-ac-save__bot-check" data-oobj-ac-bot-check="turnstile" data-sitekey="…"></div> <!-- the bot check's markup(), when there is one -->
		<p class="oobj-ac-save__status" role="status" aria-live="polite"></p>
	</form>
</section>
```

The mini cart's link: after the Mini-Cart block, `<span class="oobj-ac-mini-cart-link" hidden data-url="…/cart/#save-cart" data-text="Email me this cart"></span>`; `assets/mini-cart.js` adds `<a class="oobj-ac-mini-cart" href="…">Email me this cart</a>` to the drawer's `.wc-block-mini-cart__footer`. On the cart page, `#save-cart` puts the cursor in the email field.

Under the checkout, the same form inside `<details class="oobj-ac-save oobj-ac-save--collapsed" id="save-cart"><summary>…</summary>…</details>`. The status line gets `is-error` for errors. Link to `#save-cart` to send shoppers to the form.

The styles (`assets/save-cart.css`) use `--wp--preset--color--surface`, `--muted`, `--contrast`, `--base` and `--wp--preset--font-size--large` when the theme defines them, and `--oobj-ac-field-border` (default `#8a8a8a`) for the email field's border.

## Code layout

| Path | |
|---|---|
| `oobj-abandoned-carts.php` | Plugin header, autoloader, activation and deactivation. |
| `inc/class-oobj-ac-plugin.php` | Creates the modules and registers their hooks. |
| `inc/class-oobj-ac-store.php` | The two tables: schema, reads and writes. |
| `inc/class-oobj-ac-tracker.php` | Records carts as WooCommerce saves them. |
| `inc/class-oobj-ac-backfill.php` | Imports WooCommerce's sessions on activation. |
| `inc/class-oobj-ac-cleanup.php` | The daily clean-up and the retention period. |
| `inc/class-oobj-ac-stage.php` | How far a cart got, and which order statuses count as completed. |
| `inc/class-oobj-ac-admin-page.php`, `inc/class-oobj-ac-list-table.php` | The **Saved carts** page. |
| `inc/class-oobj-ac-saved-carts.php` | "Email me my cart": the form, the REST route, the email and the restore link. |
| `inc/interface-oobj-ac-bot-check.php` | The bot check interface. |
| `inc/adapters/` | Optional integrations: `class-oobj-ac-turnstile.php` (Cloudflare Turnstile as the bot check) and `class-oobj-ac-ga4.php` (Google Analytics 4). |
| `inc/class-oobj-ac-updates.php`, `inc/class-oobj-ac-github-releases.php` | Updates from the GitHub releases. |
| `inc/class-oobj-ac-mini-cart.php`, `assets/mini-cart.js`, `assets/mini-cart.css` | The mini cart's **Email me this cart** link. |
| `assets/save-cart.js`, `assets/save-cart.css` | The form's script and styles. |
| `assets/adapters/` | The adapters' scripts: `turnstile.js` (`window.oobjAcBotCheck`) and `ga4.js` (`save_cart` on `oobj-ac:saved`). |
| `uninstall.php` | Drops the tables when the plugin is deleted. |
