# OOBJ Abandoned Carts

WooCommerce → **Abandoned carts**: the carts shoppers filled in the last week (or the last 24 hours, 3, 14 or 30 days) that didn't become a completed order.

WooCommerce deletes cart sessions after 2 days (a week for logged-in customers), so the plugin keeps its own record in `{prefix}oobj_ac_carts`. It saves each cart whenever WooCommerce saves it, together with the shopper's name and email once checkout has them and the order checkout creates. On activation it imports the carts still in WooCommerce's sessions.

- **Completed** (not listed): the order is paid, on hold or refunded. Filter: `oobj_ac_completed_statuses`.
- **Listed**, with how far the shopper got: the cart only, reached checkout, order placed but not paid, payment failed, or order cancelled.
- A cart the shopper empties is forgotten. Carts are deleted 30 days after their last activity, daily via Action Scheduler. Filter: `oobj_ac_retention_days`.
- Deleting the plugin drops the table.

## Development

```sh
composer install
composer test   # PHPUnit in the local stack's litespeed container (database oobj_abandoned_carts_tests)
composer lint   # phpcs (WordPress Coding Standards)
```

The working copy is `wp-content/plugins/oobj-abandoned-carts/` in the local site (`~/Sites/oobj`). This repo is a copy of it.
