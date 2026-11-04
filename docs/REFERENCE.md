# Reference

Hooks, the REST route, the database tables and the front-end markup. For what the plugin does, see the [README](../README.md).

- [Filters](#filters)
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
| `template_redirect` | Restores an emailed cart on the cart page (`?saved-cart=`). |
| `rest_api_init` | Registers the REST route. |
| `wp_enqueue_scripts` | Loads the form's script and styles on the cart and checkout pages only. |
| `admin_menu`, `admin_enqueue_scripts` | The **Saved carts** page and its styles. |
| `oobj_ac_prune` (Action Scheduler, daily) | Deletes carts past the retention period and emailed carts whose links have expired. |

## REST route

### `POST /wp-json/oobj-ac/v1/saved-carts`

Emails the shopper's current cart to them. Public (no authentication): it works on the cart in the request's WooCommerce session cookie.

| Parameter | Type | |
|---|---|---|
| `email` | string, required | Where to send it. |
| `turnstile` | string | Turnstile's answer, when Turnstile is set up. |
| `website` | string | The hidden trap field. Anything in it: the answer is `{"sent": true}` and nothing is sent. |

Answers:

| Status | Code | When |
|---|---|---|
| 200 | | `{"sent": true}`: sent (or the trap field was filled in). |
| 400 | `oobj_ac_email` | Not a valid email address. |
| 400 | `oobj_ac_turnstile` | Turnstile is set up and its answer is missing or failed. |
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
	<form class="oobj-ac-save__form" data-url="/wp-json/oobj-ac/v1/saved-carts" novalidate>
		<label class="oobj-ac-save__label" for="oobj-ac-email">Email</label>
		<div class="oobj-ac-save__row">
			<input class="oobj-ac-save__email" id="oobj-ac-email" name="email" type="email" autocomplete="email" required />
			<button class="oobj-ac-save__button wp-element-button" type="submit">Email me my cart</button>
		</div>
		<input class="oobj-ac-save__trap" name="website" type="text" tabindex="-1" autocomplete="off" aria-hidden="true" />
		<div class="oobj-ac-save__turnstile" data-sitekey="…"></div> <!-- only with Turnstile keys -->
		<p class="oobj-ac-save__status" role="status" aria-live="polite"></p>
	</form>
</section>
```

Under the checkout, the same form inside `<details class="oobj-ac-save oobj-ac-save--collapsed" id="save-cart"><summary>…</summary>…</details>`. The status line gets `is-error` for errors. Link to `#save-cart` to send shoppers to the form.

The styles (`assets/save-cart.css`) use `--wp--preset--color--surface`, `--muted`, `--contrast`, `--base` and `--wp--preset--font-size--large` when the theme defines them, and `--oob-field-border` for the field's border.

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
| `assets/save-cart.js`, `assets/save-cart.css` | The form's script and styles. |
| `uninstall.php` | Drops the tables when the plugin is deleted. |
