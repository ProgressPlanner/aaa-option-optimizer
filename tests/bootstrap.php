<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer\Tests;

use Yoast\WPTestUtils\WPIntegration;

require_once dirname( __DIR__ ) . '/vendor/yoast/wp-test-utils/src/WPIntegration/bootstrap-functions.php';

// Give access to tests_add_filter() function.
require_once rtrim( WPIntegration\get_path_to_wp_test_dir(), '/' ) . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 *
 * @return void
 */
function manually_load_plugin() {
	require dirname( __DIR__ ) . '/aaa-option-optimizer.php';
}

// Add plugin to active mu-plugins - to make sure it gets loaded.
\tests_add_filter( 'muplugins_loaded', __NAMESPACE__ . '\manually_load_plugin' );

/*
 * Load WordPress, which will load the Composer autoload file, and load the MockObject autoloader after that.
 */
WPIntegration\bootstrap_it();

require_once __DIR__ . '/phpunit/class-test-case.php';
