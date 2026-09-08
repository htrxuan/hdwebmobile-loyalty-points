<?php

namespace htrxuan\hdlp;

if (!defined('ABSPATH')) {
    exit;
}

class HDLP_Admin
{

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
        require_once HDLP_PLUGIN_DIR . 'includes/class-hdlp-hub.php';
        add_filter('hdwebmobile_hub_tabs', array($this, 'register_hub_tabs'));
        add_action('admin_init', array($this, 'page_init'));
        add_action('admin_post_hdlp_manual_adjustment', array($this, 'handle_manual_adjustment'));
        add_action('admin_notices', array($this, 'render_admin_notices'));
    }

    public function register_hub_tabs($tabs)
    {
        $tabs['loyalty-points'] = array(
            'label'  => __('Loyalty Points', 'hdwebmobile-loyalty-points-store-credit'),
            'order'  => 65,
            'render' => array($this, 'render_settings_page'),
        );
        return $tabs;
    }

    public function render_settings_page()
    {
        $options = self::get_options();
        ?>
        <p><?php esc_html_e('Customers earn points on completed orders and redeem them for a discount at checkout. Balances are always computed from the ledger below -- never a value that can be set directly.', 'hdwebmobile-loyalty-points-store-credit'); ?></p>

        <form method="post" action="options.php">
            <?php
            settings_fields('hdlp_option_group');
            do_settings_sections('hdlp-settings');
            submit_button();
            ?>
        </form>

        <hr />

        <h2><?php esc_html_e('Manual Adjustment', 'hdwebmobile-loyalty-points-store-credit'); ?></h2>
        <p><?php esc_html_e('Grant or deduct points for a specific customer, e.g. for customer service. This is logged in the ledger with your admin account attached.', 'hdwebmobile-loyalty-points-store-credit'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('hdlp_manual_adjustment', 'hdlp_manual_nonce'); ?>
            <input type="hidden" name="action" value="hdlp_manual_adjustment" />
            <table class="form-table">
                <tr>
                    <th><label for="hdlp_user_email"><?php esc_html_e('Customer email', 'hdwebmobile-loyalty-points-store-credit'); ?></label></th>
                    <td><input type="email" id="hdlp_user_email" name="hdlp_user_email" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th><label for="hdlp_adjust_points"><?php esc_html_e('Points (use a negative number to deduct)', 'hdwebmobile-loyalty-points-store-credit'); ?></label></th>
                    <td><input type="number" id="hdlp_adjust_points" name="hdlp_adjust_points" step="1" required /></td>
                </tr>
                <tr>
                    <th><label for="hdlp_adjust_note"><?php esc_html_e('Note', 'hdwebmobile-loyalty-points-store-credit'); ?></label></th>
                    <td><input type="text" id="hdlp_adjust_note" name="hdlp_adjust_note" class="regular-text" placeholder="<?php esc_attr_e('e.g. Compensation for delayed order', 'hdwebmobile-loyalty-points-store-credit'); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(__('Apply Adjustment', 'hdwebmobile-loyalty-points-store-credit')); ?>
        </form>

        <hr />

        <h2><?php esc_html_e('Recent Ledger Activity', 'hdwebmobile-loyalty-points-store-credit'); ?></h2>
        <?php $this->render_recent_ledger(); ?>
        <?php
    }

    private function render_recent_ledger()
    {
        global $wpdb;
        $table = HDLP_Ledger::get_table_name();
        $rows  = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, 50)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only admin audit view of this plugin's own ledger table.

        if (empty($rows)) {
            echo '<p>' . esc_html__('No activity yet.', 'hdwebmobile-loyalty-points-store-credit') . '</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Date', 'hdwebmobile-loyalty-points-store-credit') . '</th>';
        echo '<th>' . esc_html__('Customer', 'hdwebmobile-loyalty-points-store-credit') . '</th>';
        echo '<th>' . esc_html__('Points', 'hdwebmobile-loyalty-points-store-credit') . '</th>';
        echo '<th>' . esc_html__('Type', 'hdwebmobile-loyalty-points-store-credit') . '</th>';
        echo '<th>' . esc_html__('Note', 'hdwebmobile-loyalty-points-store-credit') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $user = get_userdata($row->user_id);
            printf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                esc_html($row->created_at),
                esc_html($user ? $user->user_email : '#' . $row->user_id),
                esc_html($row->points > 0 ? '+' . $row->points : $row->points),
                esc_html($row->type),
                esc_html($row->note)
            );
        }

        echo '</tbody></table>';
    }

    public function handle_manual_adjustment()
    {
        if (!isset($_POST['hdlp_manual_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hdlp_manual_nonce'])), 'hdlp_manual_adjustment')) {
            wp_die(esc_html__('Invalid request.', 'hdwebmobile-loyalty-points-store-credit'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'hdwebmobile-loyalty-points-store-credit'));
        }

        $email  = isset($_POST['hdlp_user_email']) ? sanitize_email(wp_unslash($_POST['hdlp_user_email'])) : '';
        $points = isset($_POST['hdlp_adjust_points']) ? (int) $_POST['hdlp_adjust_points'] : 0;
        $note   = isset($_POST['hdlp_adjust_note']) ? sanitize_text_field(wp_unslash($_POST['hdlp_adjust_note'])) : __('Manual adjustment', 'hdwebmobile-loyalty-points-store-credit');

        $user = get_user_by('email', $email);
        $redirect = admin_url('admin.php?page=hdwebmobile&tab=loyalty-points');

        if (!$user) {
            set_transient('hdlp_admin_notice_' . get_current_user_id(), array('type' => 'error', 'message' => __('No user found with that email.', 'hdwebmobile-loyalty-points-store-credit')), 30);
            wp_safe_redirect($redirect);
            exit;
        }

        HDLP_Ledger::manual_adjustment($user->ID, $points, get_current_user_id(), $note);
        set_transient('hdlp_admin_notice_' . get_current_user_id(), array('type' => 'success', 'message' => __('Adjustment applied.', 'hdwebmobile-loyalty-points-store-credit')), 30);
        wp_safe_redirect($redirect);
        exit;
    }

    public function render_admin_notices()
    {
        $notice = get_transient('hdlp_admin_notice_' . get_current_user_id());
        if (!$notice) {
            return;
        }
        delete_transient('hdlp_admin_notice_' . get_current_user_id());
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            'error' === $notice['type'] ? 'error' : 'success',
            esc_html($notice['message'])
        );
    }

    public function page_init()
    {
        register_setting(
            'hdlp_option_group',
            'hdlp_options',
            array(
                'type'              => 'array',
                'sanitize_callback' => array($this, 'sanitize'),
                'default'           => self::get_default_options(),
            )
        );

        add_settings_section('hdlp_section_general', __('General', 'hdwebmobile-loyalty-points-store-credit'), '__return_false', 'hdlp-settings');

        add_settings_field('enabled', __('Enable loyalty points', 'hdwebmobile-loyalty-points-store-credit'), array($this, 'enabled_callback'), 'hdlp-settings', 'hdlp_section_general');
        add_settings_field('earn_status', __('Award points when order status becomes', 'hdwebmobile-loyalty-points-store-credit'), array($this, 'earn_status_callback'), 'hdlp-settings', 'hdlp_section_general');
        add_settings_field('points_per_currency', __('Points earned per 1 currency unit spent', 'hdwebmobile-loyalty-points-store-credit'), array($this, 'points_per_currency_callback'), 'hdlp-settings', 'hdlp_section_general');
        add_settings_field('points_redemption_rate', __('Points required per 1 currency unit of discount', 'hdwebmobile-loyalty-points-store-credit'), array($this, 'points_redemption_rate_callback'), 'hdlp-settings', 'hdlp_section_general');
        add_settings_field('min_points_to_redeem', __('Minimum points required to redeem', 'hdwebmobile-loyalty-points-store-credit'), array($this, 'min_points_callback'), 'hdlp-settings', 'hdlp_section_general');
        add_settings_field('max_redeem_percent', __('Maximum % of order redeemable with points', 'hdwebmobile-loyalty-points-store-credit'), array($this, 'max_redeem_percent_callback'), 'hdlp-settings', 'hdlp_section_general');
    }

    public static function get_default_options()
    {
        return array(
            'enabled'                => 1,
            'earn_status'            => 'completed',
            'points_per_currency'    => 1,
            'points_redemption_rate' => 100,
            'min_points_to_redeem'   => 100,
            'max_redeem_percent'     => 50,
        );
    }

    public static function get_options()
    {
        return wp_parse_args(get_option('hdlp_options', array()), self::get_default_options());
    }

    public function sanitize($input)
    {
        $defaults = self::get_default_options();
        $statuses = array_keys(wc_get_order_statuses());

        $new_input = array();
        $new_input['enabled']     = isset($input['enabled']) ? 1 : 0;
        $earn_status              = isset($input['earn_status']) ? sanitize_text_field($input['earn_status']) : $defaults['earn_status'];
        $earn_status              = str_replace('wc-', '', $earn_status);
        $new_input['earn_status'] = in_array($earn_status, array_map(function ($s) {
            return str_replace('wc-', '', $s);
        }, $statuses), true) ? $earn_status : $defaults['earn_status'];

        $new_input['points_per_currency']    = isset($input['points_per_currency']) ? max(0, absint($input['points_per_currency'])) : $defaults['points_per_currency'];
        $new_input['points_redemption_rate'] = isset($input['points_redemption_rate']) ? max(1, absint($input['points_redemption_rate'])) : $defaults['points_redemption_rate'];
        $new_input['min_points_to_redeem']   = isset($input['min_points_to_redeem']) ? max(0, absint($input['min_points_to_redeem'])) : $defaults['min_points_to_redeem'];
        $new_input['max_redeem_percent']     = isset($input['max_redeem_percent']) ? min(100, max(1, absint($input['max_redeem_percent']))) : $defaults['max_redeem_percent'];

        return $new_input;
    }

    public function enabled_callback()
    {
        $options = self::get_options();
        printf('<input type="checkbox" name="hdlp_options[enabled]" value="1" %s />', checked(1, $options['enabled'], false));
    }

    public function earn_status_callback()
    {
        $options  = self::get_options();
        $statuses = wc_get_order_statuses();
        echo '<select name="hdlp_options[earn_status]">';
        foreach ($statuses as $key => $label) {
            $value = str_replace('wc-', '', $key);
            printf('<option value="%s" %s>%s</option>', esc_attr($value), selected($options['earn_status'], $value, false), esc_html($label));
        }
        echo '</select>';
    }

    public function points_per_currency_callback()
    {
        $options = self::get_options();
        printf('<input type="number" min="0" step="1" name="hdlp_options[points_per_currency]" value="%s" class="small-text" />', esc_attr($options['points_per_currency']));
    }

    public function points_redemption_rate_callback()
    {
        $options = self::get_options();
        printf('<input type="number" min="1" step="1" name="hdlp_options[points_redemption_rate]" value="%s" class="small-text" />', esc_attr($options['points_redemption_rate']));
    }

    public function min_points_callback()
    {
        $options = self::get_options();
        printf('<input type="number" min="0" step="1" name="hdlp_options[min_points_to_redeem]" value="%s" class="small-text" />', esc_attr($options['min_points_to_redeem']));
    }

    public function max_redeem_percent_callback()
    {
        $options = self::get_options();
        printf('<input type="number" min="1" max="100" step="1" name="hdlp_options[max_redeem_percent]" value="%s" class="small-text" />%%', esc_attr($options['max_redeem_percent']));
    }
}
