<?php
// Fixture: infrastructure that calls another host without the outbound allow-list. Never autoloaded or executed.

namespace Mercatus\Core\Sample\Infrastructure;

final class CallsOut
{
    public function fetch()
    {
        return wp_remote_get( 'https://example.org/' );
    }
}
