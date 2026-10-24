<?php
/**
 * Scale-suite bootstrap (TEST-1 Layer 6).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

// Loads the real site that bin/scale.sh restored from the seeded dump. The WordPress test suite would reinstall
// its database instead.

// WordPress records each query's call stack only with SAVEQUERIES, and the gate needs it to tell Mercatus's
// queries apart.
define( 'SAVEQUERIES', true );

$_SERVER['HTTP_HOST']      = 'scale.example';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

$mercatus_scale_site = getenv( 'MERCATUS_SCALE_SITE' );
if ( false === $mercatus_scale_site || '' === $mercatus_scale_site ) {
	$mercatus_scale_site = '/usr/src/wordpress';
}
require_once $mercatus_scale_site . '/wp-load.php';
