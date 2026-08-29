<?php

namespace htrxuan\hdlp;

if (!defined('ABSPATH')) {
    exit;
}

class HDLP_Activator
{

    public static function activate()
    {
        if (!self::is_woocommerce_active()) {
            deactivate_plugins(plugin_basename(HDLP_PLUGIN_FILE));
            set_transient('hdlp_wc_missing_notice', true, 30);
            return;
        }

        self::maybe_upgrade_db();

        if (false === get_option('hdlp_options')) {
            add_option('hdlp_options', array(
                'enabled'               => 1,
                'earn_status'           => 'completed',
                'points_per_currency'   => 1,
                'points_redemption_rate' => 100,
                'min_points_to_redeem'  => 100,
                'max_redeem_percent'    => 50,
            ));
        }
    }

    public static function is_woocommerce_active()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('woocommerce/woocommerce.php') || class_exists('WooCommerce');
    }

    public static function maybe_upgrade_db()
    {
        if (get_option('hdlp_db_version') === HDLP_DB_VERSION) {
            return;
        }

        require_once HDLP_PLUGIN_DIR . 'includes/class-hdlp-ledger.php';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(HDLP_Ledger::get_schema_sql());

        update_option('hdlp_db_version', HDLP_DB_VERSION);
    }
}
