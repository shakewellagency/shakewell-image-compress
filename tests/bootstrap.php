<?php
/**
 * Loads the WordPress test library, then the plugin.
 *
 * Runs inside wp-env's tests container, which sets WP_TESTS_DIR (npm test).
 *
 * @package Shakewell\ImageCompress
 */

$tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $tests_dir || ! is_file( $tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set. Run the tests with: npm test\n" );
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/' . basename( dirname( __DIR__ ) ) . '.php';
		// Shakewell Media Offload, mapped in by .wp-env.json (tests only): its code is loaded, its hooks are not registered until a test switches it on.
		$offload = WP_PLUGIN_DIR . '/shakewell-media-offload/shakewell-media-offload.php';
		if ( is_file( $offload ) ) {
			require $offload;
		}
	}
);

require $tests_dir . '/includes/bootstrap.php';
