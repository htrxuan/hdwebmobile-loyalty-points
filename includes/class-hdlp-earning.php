<?php

namespace htrxuan\hdlp;

if (!defined('ABSPATH')) {
    exit;
}

final class HDLP_Earning
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
        add_action('woocommerce_order_status_changed', array($this, 'handle_status_change'), 10, 4);
    }

    public function handle_status_change($order_id, $old_status, $new_status, $order)
    {
        $options = HDLP_Admin::get_options();
        if (empty($options['enabled'])) {
            return;
        }

        if ($new_status === $options['earn_status']) {
            HDLP_Ledger::award_for_order($order);
        }

        if (in_array($new_status, array('cancelled', 'refunded', 'failed'), true)) {
            HDLP_Ledger::reverse_for_order($order);
        }
    }
}
