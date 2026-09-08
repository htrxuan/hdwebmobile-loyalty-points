<?php

namespace htrxuan\hdlp;

if (!defined('ABSPATH')) {
    exit;
}

final class HDLP_Core
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
        $this->includes();
        $this->init_hooks();
    }

    private function __clone()
    {
    }

    private function includes()
    {
        require_once HDLP_PLUGIN_DIR . 'includes/class-hdlp-ledger.php';
        require_once HDLP_PLUGIN_DIR . 'includes/class-hdlp-earning.php';
        require_once HDLP_PLUGIN_DIR . 'includes/class-hdlp-redemption.php';
        require_once HDLP_PLUGIN_DIR . 'includes/class-hdlp-myaccount.php';
        require_once HDLP_PLUGIN_DIR . 'includes/class-hdlp-admin.php';
    }

    private function init_hooks()
    {
        add_action('admin_notices', array($this, 'render_missing_woocommerce_notice'));

        if (!class_exists('WooCommerce')) {
            return;
        }

        HDLP_Earning::get_instance();
        HDLP_Redemption::get_instance();
        HDLP_MyAccount::get_instance();

        if (is_admin()) {
            HDLP_Admin::get_instance();
        }
    }

    public function render_missing_woocommerce_notice()
    {
        $screen = get_current_screen();
        if (!$screen || 'plugins' !== $screen->id) {
            return;
        }

        if (!get_transient('hdlp_wc_missing_notice')) {
            return;
        }
        delete_transient('hdlp_wc_missing_notice');
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php esc_html_e('HDWebmobile Loyalty Points & Store Credit requires WooCommerce to be installed and active. The plugin has been deactivated.', 'hdwebmobile-loyalty-points-store-credit'); ?>
            </p>
        </div>
        <?php
    }
}
