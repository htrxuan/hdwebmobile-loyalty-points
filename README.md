# HDWebmobile Loyalty Points & Store Credit

Customers earn points on completed orders and redeem them for a discount at checkout -- balance always computed from an audit-logged ledger.

- **WordPress.org:** https://wordpress.org/plugins/hdwebmobile-loyalty-points/
- **Requires:** WordPress 6.9+, WooCommerce, PHP 7.4+
- **License:** GPLv2 or later

## Description

HDWebmobile Loyalty Points & Store Credit rewards customers with points for every completed order, which they can redeem for a discount on a future purchase. Customers see their balance and full history on a new "Loyalty Points" tab in their account.

## Why this plugin exists

A competing "Smart Coupons for WooCommerce" plugin had a broken access control vulnerability (CVE-2026-45438) letting attackers generate high-value coupons through insufficient authorization checks -- forging value that was never actually earned. A points balance is exactly the same kind of value, and needs the same discipline. This plugin closes that vulnerability class by construction:

* There is no "balance" field anywhere that gets directly written. Every award, redemption, and admin adjustment is a signed row in an append-only ledger table, and a customer's balance is always the live sum of their own rows -- never a cached number that could drift or be overwritten by a bad request.
* Every redemption re-checks the customer's *current* live balance at the exact moment of redemption, both when the discount is shown in the cart and again when the order is actually created -- never a points figure the checkout form sent back to the server.
* Every earn and redemption is tied to a specific order and only ever happens once for that order, so a page refresh, retried request, or duplicate status-change event can never double-award or double-redeem.
* If an order is later cancelled, refunded, or fails, any points it awarded or redeemed are automatically reversed -- so a cancelled order can never leave a customer with free points, or a lost balance, on the books.
* Manual adjustments (for customer service) always record which admin made the change.

## Features

* Configurable points-earning rate (points per currency unit spent) and the order status that triggers earning
* Configurable redemption rate (points required per currency unit of discount), a minimum-points-to-redeem threshold, and a maximum percentage of any single order that can be paid with points
* A simple redemption box on the cart page showing the customer's live balance, with an amount they can apply as a discount
* "Loyalty Points" tab in My Account showing current balance and full transaction history
* Automatic point reversal on cancelled, refunded, or failed orders
* Admin manual adjustment tool (grant or deduct points for a specific customer by email) plus a recent-activity ledger view, both under WooCommerce > HDWebmobile

## Development

Standard WordPress plugin structure:

```
hdwebmobile-loyalty-points.php    Bootstrap
includes/class-hdlp-activator.php
includes/class-hdlp-admin.php
includes/class-hdlp-core.php
includes/class-hdlp-earning.php
includes/class-hdlp-hub.php
includes/class-hdlp-ledger.php
includes/class-hdlp-myaccount.php
includes/class-hdlp-redemption.php
```

Part of the [HDWebmobile](https://hdwebmobile.com/plugins/) suite of focused, single-purpose WooCommerce plugins.

## License

GPLv2 or later. See [LICENSE](LICENSE).

