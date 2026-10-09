<?php
/**
 * PHPUnit bootstrap — loads WordPress, WooCommerce and Hifqa into the test DB.
 */

$plugin_dir = dirname( __DIR__ );

require_once $plugin_dir . '/vendor/autoload.php';

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $plugin_dir . '/vendor/yoast/phpunit-polyfills' );
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: $plugin_dir . '/vendor/wp-phpunit/wp-phpunit';

require_once $wp_tests_dir . '/includes/functions.php';

$wc_dir = getenv( 'WC_DIR' ) ?: dirname( $plugin_dir ) . '/woocommerce';

tests_add_filter( 'muplugins_loaded', function () use ( $plugin_dir, $wc_dir ) {
	require_once $wc_dir . '/woocommerce.php';
	require_once $plugin_dir . '/hifqa-fraud-detector.php';
} );

// Fresh WooCommerce tables + roles in the test DB.
tests_add_filter( 'setup_theme', function () {
	WC_Install::install();
	$GLOBALS['wp_roles'] = null;
	wp_roles();

	// AIFD_TEST_HPOS=1 runs the whole suite on High-Performance Order Storage
	// instead of the legacy posts tables.
	if ( getenv( 'AIFD_TEST_HPOS' ) ) {
		wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
	}
} );

// Keep test runs from writing log files; tests that need logs add a handler.
tests_add_filter( 'woocommerce_register_log_handlers', '__return_empty_array' );

require_once $wp_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/TestCase.php';
