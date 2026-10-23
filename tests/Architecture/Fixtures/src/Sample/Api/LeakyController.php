<?php
// Fixture: an Api class that touches $wpdb. Reading $_GET is allowed in Api. Never autoloaded or executed.

namespace Mercatus\Core\Sample\Api;

final class LeakyController
{
    public function handle()
    {
        $page = $_GET['page'];

        global $wpdb;
    }
}
