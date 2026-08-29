=== HDWebmobile Loyalty Points & Store Credit ===
Contributors: htrxuan
Donate link: https://paypal.me/htrxuan/20
Tags: woocommerce, loyalty points, rewards, store credit, discount
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Customers earn points on completed orders and redeem them for a discount at checkout -- balance always computed from an audit-logged ledger.

== Description ==

HDWebmobile Loyalty Points & Store Credit rewards customers with points for every completed order, which they can redeem for a discount on a future purchase. Customers see their balance and full history on a new "Loyalty Points" tab in their account.

= Why this plugin exists =
A competing "Smart Coupons for WooCommerce" plugin had a broken access control vulnerability (CVE-2026-45438) letting attackers generate high-value coupons through insufficient authorization checks -- forging value that was never actually earned. A points balance is exactly the same kind of value, and needs the same discipline. This plugin closes that vulnerability class by construction:

* There is no "balance" field anywhere that gets directly written. Every award, redemption, and admin adjustment is a signed row in an append-only ledger table, and a customer's balance is always the live sum of their own rows -- never a cached number that could drift or be overwritten by a bad request.
* Every redemption re-checks the customer's *current* live balance at the exact moment of redemption, both when the discount is shown in the cart and again when the order is actually created -- never a points figure the checkout form sent back to the server.
* Every earn and redemption is tied to a specific order and only ever happens once for that order, so a page refresh, retried request, or duplicate status-change event can never double-award or double-redeem.
* If an order is later cancelled, refunded, or fails, any points it awarded or redeemed are automatically reversed -- so a cancelled order can never leave a customer with free points, or a lost balance, on the books.
* Manual adjustments (for customer service) always record which admin made the change.

= Key Features =
* Configurable points-earning rate (points per currency unit spent) and the order status that triggers earning
* Configurable redemption rate (points required per currency unit of discount), a minimum-points-to-redeem threshold, and a maximum percentage of any single order that can be paid with points
* A simple redemption box on the cart page showing the customer's live balance, with an amount they can apply as a discount
* "Loyalty Points" tab in My Account showing current balance and full transaction history
* Automatic point reversal on cancelled, refunded, or failed orders
* Admin manual adjustment tool (grant or deduct points for a specific customer by email) plus a recent-activity ledger view, both under WooCommerce > HDWebmobile

= Limitations (please read before installing) =
* Points can only be earned by logged-in customers with an account -- guest checkouts don't accrue points, since there's no account to credit them to
* No expiry date on points in this version
* The cart redemption box uses a plain form submission (not AJAX) -- applying or removing a redemption reloads the cart page

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/hdwebmobile-loyalty-points` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress. WooCommerce must already be installed and active.
3. Adjust the earning/redemption rates under WooCommerce > HDWebmobile > Loyalty Points if the defaults don't suit your store.

== How to Use ==

= 1. Customers earn points automatically =
As soon as an order reaches the configured "earning" status (Completed, by default), the customer's account is credited with points based on the order total. Nothing else to configure.

= 2. Customers redeem points on the cart page =
Once a customer has enough points (the configured minimum), a box appears on the cart page showing their balance and an amount to redeem. Applying it adds a discount, capped at the configured maximum percentage of the order.

= 3. Customers track their balance =
"Loyalty Points" appears in the My Account menu, showing the current balance and a full history of every point earned, redeemed, or adjusted.

= 4. Customer service adjustments =
Under WooCommerce > HDWebmobile > Loyalty Points, an admin can grant or deduct points for any customer by email, with a note -- useful for compensating a delayed order or correcting an issue.

== Screenshots ==

1. The points redemption box on the cart page.
2. The "Loyalty Points" tab in My Account, showing balance and history.
3. The admin settings, manual adjustment, and recent-activity ledger under WooCommerce > HDWebmobile.

== Changelog ==

= 1.0.0 =
* Initial release: append-only points ledger, automatic earning on order completion, cart-page redemption with live balance re-verification, automatic reversal on cancelled/refunded/failed orders, My Account balance and history, admin manual adjustment and ledger view.
