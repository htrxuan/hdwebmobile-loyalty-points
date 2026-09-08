<?php

namespace htrxuan\hdlp;

if (!defined('ABSPATH')) {
    exit;
}

final class HDLP_MyAccount
{

    const ENDPOINT = 'loyalty-points';

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'add_endpoint'));
        add_filter('query_vars', array($this, 'add_query_var'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_item'));
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array($this, 'render_endpoint_content'));
    }

    public function add_endpoint()
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public function add_query_var($vars)
    {
        $vars[] = self::ENDPOINT;
        return $vars;
    }

    public function add_menu_item($items)
    {
        $new_items = array();
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            if ('orders' === $key) {
                $new_items[self::ENDPOINT] = __('Loyalty Points', 'hdwebmobile-loyalty-points-store-credit');
            }
        }
        if (!isset($new_items[self::ENDPOINT])) {
            $new_items[self::ENDPOINT] = __('Loyalty Points', 'hdwebmobile-loyalty-points-store-credit');
        }
        return $new_items;
    }

    public function render_endpoint_content()
    {
        $user_id = get_current_user_id();
        $balance = HDLP_Ledger::get_balance($user_id);
        $history = HDLP_Ledger::get_history($user_id, 50);

        echo '<h2>' . esc_html__('Loyalty Points', 'hdwebmobile-loyalty-points-store-credit') . '</h2>';
        /* translators: %s: the customer's current points balance, formatted with the strong tags already applied */
        $balance_line = esc_html__('Your current balance: %s points', 'hdwebmobile-loyalty-points-store-credit');
        printf(
            '<p>' . $balance_line . '</p>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $balance_line is already the output of esc_html__() above.
            '<strong>' . esc_html(number_format_i18n($balance)) . '</strong>'
        );

        if (empty($history)) {
            echo '<p>' . esc_html__('No points activity yet.', 'hdwebmobile-loyalty-points-store-credit') . '</p>';
            return;
        }

        echo '<table class="woocommerce-table shop_table hdlp-history-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Date', 'hdwebmobile-loyalty-points-store-credit') . '</th>';
        echo '<th>' . esc_html__('Description', 'hdwebmobile-loyalty-points-store-credit') . '</th>';
        echo '<th>' . esc_html__('Points', 'hdwebmobile-loyalty-points-store-credit') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($history as $row) {
            $points_display = $row->points > 0 ? '+' . number_format_i18n($row->points) : number_format_i18n($row->points);
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html(date_i18n(get_option('date_format'), strtotime($row->created_at))),
                esc_html($row->note),
                esc_html($points_display)
            );
        }

        echo '</tbody></table>';
    }
}
