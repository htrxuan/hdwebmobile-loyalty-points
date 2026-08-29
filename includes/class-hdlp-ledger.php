<?php

namespace htrxuan\hdlp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The only class in this plugin that touches the points ledger table, and the only
 * source of truth for a customer's balance.
 *
 * Motivated by CVE-2026-45438: a competing "Smart Coupons for WooCommerce" plugin had
 * a broken access control flaw letting attackers generate high-value coupons through
 * insufficient authorization checks -- forging value that was never actually earned.
 * A points/store-credit balance is exactly the same kind of value and needs the same
 * discipline. This plugin closes that vulnerability class by construction:
 *
 * - There is no "balance" field anywhere that gets directly incremented/decremented.
 *   The table is append-only (every row is a signed point delta with a reason and,
 *   where relevant, an order ID), and a customer's balance is always the live SUM()
 *   of their own rows -- never a cached counter that could drift out of sync with
 *   reality or be overwritten by a bad request.
 * - Every redemption re-checks the *current* live balance at the exact moment of
 *   redemption, never a balance value the browser sent back to the server.
 * - Every earn/redeem is tied to a specific order ID and is idempotent (checked via
 *   order meta before inserting), so a retried request, a duplicate webhook, or a
 *   refresh-after-submit can never double-award or double-redeem the same order.
 * - Every row records who/what caused it (a real order, or an admin's manual
 *   adjustment with their own user ID attached) -- a full audit trail, not just a
 *   number.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
class HDLP_Ledger
{

    const TYPE_EARNED   = 'earned';
    const TYPE_REDEEMED = 'redeemed';
    const TYPE_REVERSED = 'reversed';
    const TYPE_MANUAL   = 'manual';

    public static function get_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'hdlp_ledger';
    }

    public static function get_schema_sql()
    {
        global $wpdb;
        $table           = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            points BIGINT NOT NULL,
            type VARCHAR(20) NOT NULL,
            order_id BIGINT UNSIGNED DEFAULT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY order_id (order_id)
        ) {$charset_collate};";
    }

    /**
     * The one and only way a balance is ever computed -- a live sum, never a cached field.
     */
    public static function get_balance($user_id)
    {
        global $wpdb;
        $sum = $wpdb->get_var($wpdb->prepare(
            'SELECT SUM(points) FROM %i WHERE user_id = %d',
            self::get_table_name(),
            $user_id
        ));
        return null === $sum ? 0 : (int) $sum;
    }

    public static function get_history($user_id, $limit = 20)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d',
            self::get_table_name(),
            $user_id,
            $limit
        ));
    }

    /**
     * Awards points for an order, exactly once. Safe to call multiple times for the
     * same order (e.g. from more than one status-change hook) -- only the first call
     * actually inserts a row.
     */
    public static function award_for_order($order)
    {
        if ($order->get_meta('_hdlp_points_awarded')) {
            return false;
        }

        $user_id = $order->get_customer_id();
        if (!$user_id) {
            return false; // Guest orders have no account to credit points to.
        }

        $options = HDLP_Admin::get_options();
        $points  = (int) floor((float) $order->get_total() * (float) $options['points_per_currency']);
        if ($points <= 0) {
            return false;
        }

        self::insert($user_id, $points, self::TYPE_EARNED, $order->get_id(), sprintf(
            /* translators: %s: order number */
            __('Earned from order #%s', 'hdwebmobile-loyalty-points'),
            $order->get_order_number()
        ));

        $order->update_meta_data('_hdlp_points_awarded', $points);
        $order->save();

        return true;
    }

    /**
     * Redeems points for an order, re-checking the live balance at this exact moment
     * -- never trusting a points figure the checkout form submitted. Returns the
     * actual number of points redeemed (may be less than requested if the live
     * balance or the per-order redemption cap is lower), or 0 if nothing was redeemed.
     */
    public static function redeem_for_order($user_id, $requested_points, $order, $max_allowed_by_cart_total)
    {
        if ($order->get_meta('_hdlp_points_redeemed')) {
            return 0;
        }

        $requested_points = max(0, (int) $requested_points);
        if ($requested_points <= 0) {
            return 0;
        }

        $live_balance = self::get_balance($user_id);
        $to_redeem    = min($requested_points, $live_balance, (int) $max_allowed_by_cart_total);

        if ($to_redeem <= 0) {
            return 0;
        }

        self::insert($user_id, -$to_redeem, self::TYPE_REDEEMED, $order->get_id(), sprintf(
            /* translators: %s: order number */
            __('Redeemed on order #%s', 'hdwebmobile-loyalty-points'),
            $order->get_order_number()
        ));

        $order->update_meta_data('_hdlp_points_redeemed', $to_redeem);
        $order->save();

        return $to_redeem;
    }

    /**
     * Reverses whatever this order previously did to the ledger (award and/or
     * redemption), e.g. when an order is cancelled, refunded, or fails after points
     * already moved. Idempotent -- safe to call more than once, only reverses each
     * side once.
     */
    public static function reverse_for_order($order)
    {
        $user_id = $order->get_customer_id();
        if (!$user_id) {
            return;
        }

        $awarded = (int) $order->get_meta('_hdlp_points_awarded');
        if ($awarded > 0 && !$order->get_meta('_hdlp_award_reversed')) {
            self::insert($user_id, -$awarded, self::TYPE_REVERSED, $order->get_id(), sprintf(
                /* translators: %s: order number */
                __('Reversal of points earned from order #%s', 'hdwebmobile-loyalty-points'),
                $order->get_order_number()
            ));
            $order->update_meta_data('_hdlp_award_reversed', 1);
        }

        $redeemed = (int) $order->get_meta('_hdlp_points_redeemed');
        if ($redeemed > 0 && !$order->get_meta('_hdlp_redemption_reversed')) {
            self::insert($user_id, $redeemed, self::TYPE_REVERSED, $order->get_id(), sprintf(
                /* translators: %s: order number */
                __('Refund of points redeemed on order #%s', 'hdwebmobile-loyalty-points'),
                $order->get_order_number()
            ));
            $order->update_meta_data('_hdlp_redemption_reversed', 1);
        }

        $order->save();
    }

    /**
     * A manual grant/deduction by an admin, e.g. for customer service. Always records
     * which admin made the change.
     */
    public static function manual_adjustment($user_id, $points, $admin_user_id, $note)
    {
        $points = (int) $points;
        if (0 === $points) {
            return false;
        }
        self::insert($user_id, $points, self::TYPE_MANUAL, null, $note, $admin_user_id);
        return true;
    }

    private static function insert($user_id, $points, $type, $order_id = null, $note = '', $created_by = null)
    {
        global $wpdb;
        $wpdb->insert(
            self::get_table_name(),
            array(
                'user_id'    => $user_id,
                'points'     => $points,
                'type'       => $type,
                'order_id'   => $order_id,
                'note'       => $note,
                'created_by' => $created_by,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%d', '%s', '%d', '%s')
        );
    }
}
