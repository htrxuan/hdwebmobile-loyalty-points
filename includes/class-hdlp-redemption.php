<?php

namespace htrxuan\hdlp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles redeeming points for a cart discount. The number of points a customer
 * *wants* to redeem is just a request stored in their own WC session -- it is
 * re-validated against their live ledger balance and the configured caps on every
 * single cart calculation and again at order-creation time. Nothing here ever trusts
 * a discount amount computed anywhere but this class, and this class never trusts a
 * balance from anywhere but HDLP_Ledger::get_balance().
 */
final class HDLP_Redemption
{

    private static $instance = null;

    const SESSION_KEY  = 'hdlp_redeem_points';
    const NONCE_ACTION = 'hdlp_redeem';

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('template_redirect', array($this, 'handle_form_submission'));
        add_filter('the_content', array($this, 'inject_redemption_box_for_block_cart'));
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_discount'));
        add_action('woocommerce_checkout_order_processed', array($this, 'redeem_on_order'), 10, 3);
        add_action('woocommerce_store_api_checkout_order_processed', array($this, 'redeem_on_order_from_block_checkout'));
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_assets'));
    }

    public function maybe_enqueue_assets()
    {
        if (is_cart() || is_account_page()) {
            wp_enqueue_style('hdlp-frontend', HDLP_PLUGIN_URL . 'assets/css/hdlp-frontend.css', array(), HDLP_VERSION);
        }
    }

    public function handle_form_submission()
    {
        if (!is_cart() || !isset($_POST['hdlp_action'])) {
            return;
        }

        if (!isset($_POST['hdlp_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hdlp_nonce'])), self::NONCE_ACTION)) {
            return;
        }

        if (!is_user_logged_in()) {
            return;
        }

        $action = sanitize_text_field(wp_unslash($_POST['hdlp_action']));

        if ('remove' === $action) {
            WC()->session->set(self::SESSION_KEY, 0);
        } elseif ('apply' === $action) {
            $requested = isset($_POST['hdlp_points']) ? absint(wp_unslash($_POST['hdlp_points'])) : 0;
            WC()->session->set(self::SESSION_KEY, $requested);
        }

        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    /**
     * The cart page's own content (whether it's the classic [woocommerce_cart]
     * shortcode or the WooCommerce Cart block) always passes through the_content,
     * so this is the one place guaranteed to run regardless of which cart template
     * the store uses -- woocommerce_after_cart_table, by contrast, only fires for
     * the classic template and is silently skipped by the block-based cart.
     */
    public function inject_redemption_box_for_block_cart($content)
    {
        if (!is_cart() || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        ob_start();
        $this->render_redemption_box();
        $box = ob_get_clean();

        return $content . $box;
    }

    public function render_redemption_box()
    {
        if (!is_user_logged_in()) {
            return;
        }

        $options = HDLP_Admin::get_options();
        if (empty($options['enabled'])) {
            return;
        }

        $user_id  = get_current_user_id();
        $balance  = HDLP_Ledger::get_balance($user_id);
        $current  = (int) WC()->session->get(self::SESSION_KEY, 0);

        echo '<div class="hdlp-redemption-box">';
        /* translators: %s: the customer's current points balance, formatted with the strong tags already applied */
        $balance_line = esc_html__('You have %s loyalty points available.', 'hdwebmobile-loyalty-points');
        printf(
            '<p>' . $balance_line . '</p>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $balance_line is already the output of esc_html__() above.
            '<strong>' . esc_html(number_format_i18n($balance)) . '</strong>'
        );

        if ($balance < (int) $options['min_points_to_redeem']) {
            /* translators: %s: the minimum number of points required to redeem, already formatted and escaped */
            $min_points_line = esc_html__('You need at least %s points to redeem.', 'hdwebmobile-loyalty-points');
            printf(
                '<p class="hdlp-note">' . $min_points_line . '</p>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $min_points_line is already the output of esc_html__() above.
                esc_html(number_format_i18n((int) $options['min_points_to_redeem']))
            );
            echo '</div>';
            return;
        }

        echo '<form method="post" class="hdlp-redemption-form">';
        wp_nonce_field(self::NONCE_ACTION, 'hdlp_nonce');
        printf(
            '<label for="hdlp_points">%s</label>',
            esc_html__('Points to redeem:', 'hdwebmobile-loyalty-points')
        );
        printf(
            '<input type="number" id="hdlp_points" name="hdlp_points" min="%d" max="%d" step="1" value="%d" />',
            (int) $options['min_points_to_redeem'],
            (int) $balance,
            $current > 0 ? (int) $current : (int) $options['min_points_to_redeem']
        );
        echo '<button type="submit" name="hdlp_action" value="apply" class="button">' . esc_html__('Apply', 'hdwebmobile-loyalty-points') . '</button>';
        if ($current > 0) {
            echo ' <button type="submit" name="hdlp_action" value="remove" class="button">' . esc_html__('Remove', 'hdwebmobile-loyalty-points') . '</button>';
        }
        echo '</form>';
        echo '</div>';
    }

    public function apply_discount($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        if (!is_user_logged_in()) {
            return;
        }

        $requested = (int) WC()->session->get(self::SESSION_KEY, 0);
        if ($requested <= 0) {
            return;
        }

        $options = HDLP_Admin::get_options();
        if (empty($options['enabled'])) {
            return;
        }

        $discount = $this->calculate_discount($requested, get_current_user_id(), $cart->get_subtotal(), $options);
        if ($discount <= 0) {
            return;
        }

        $cart->add_fee(__('Loyalty Points Discount', 'hdwebmobile-loyalty-points'), -$discount, false);
    }

    /**
     * Shared by the live cart discount and the final order-time redemption, so the
     * amount a customer sees in their cart is exactly what gets deducted -- computed
     * fresh both times from the live ledger balance, never a stored figure.
     */
    private function calculate_discount($requested_points, $user_id, $subtotal, $options)
    {
        $live_balance = HDLP_Ledger::get_balance($user_id);
        $points       = min($requested_points, $live_balance);
        if ($points < (int) $options['min_points_to_redeem']) {
            return 0.0;
        }

        $rate           = max(1, (int) $options['points_redemption_rate']); // points per 1 currency unit.
        $discount       = $points / $rate;
        $max_by_percent = $subtotal * ((int) $options['max_redeem_percent'] / 100);

        return round(min($discount, $max_by_percent), 2);
    }

    /**
     * Fired by the classic shortcode-based checkout.
     */
    public function redeem_on_order($order_id, $posted_data, $order)
    {
        $this->do_redeem($order);
    }

    /**
     * Fired by the WooCommerce Checkout block/Store API, which builds and processes
     * orders through an entirely separate code path from the classic checkout and
     * never fires woocommerce_checkout_order_processed above -- without this, a
     * block-checkout order would keep the cart's fee discount but never actually
     * debit the ledger for it.
     */
    public function redeem_on_order_from_block_checkout($order)
    {
        $this->do_redeem($order);
    }

    private function do_redeem($order)
    {
        if (!is_user_logged_in()) {
            return;
        }

        $requested = (int) WC()->session->get(self::SESSION_KEY, 0);
        if ($requested <= 0) {
            return;
        }

        $options   = HDLP_Admin::get_options();
        $user_id   = get_current_user_id();
        $rate      = max(1, (int) $options['points_redemption_rate']);
        $max_by_percent_points = (int) floor(($order->get_subtotal() * ((int) $options['max_redeem_percent'] / 100)) * $rate);

        $redeemed = HDLP_Ledger::redeem_for_order($user_id, $requested, $order, $max_by_percent_points);

        if ($redeemed > 0) {
            WC()->session->set(self::SESSION_KEY, 0);
        }
    }
}
