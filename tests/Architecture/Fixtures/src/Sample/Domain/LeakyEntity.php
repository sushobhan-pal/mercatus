<?php
// Fixture: breaks the Domain rules on purpose. Never autoloaded or executed. Line numbers are asserted in DependencyRulesTest.

namespace Mercatus\Core\Sample\Domain;

use Mercatus\Pro\Payouts\Payout;

final class LeakyEntity
{
    public function leak()
    {
        $id = $_POST['id'];

        $label = __('Label', 'mercatus');
        wp_send_json_success($label);
        global $wpdb;
        $wpdb->query('SELECT 1');
        $GLOBALS['wpdb']->query('SELECT 1');
        echo $id;
        $payout = new Payout();
        $redis = new \Redis();
        $predis = new \Predis\Client();
    }
}
