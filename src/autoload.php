<?php
/**
 * Autoload PHP classes for the plugin.
 *
 * @package Progress_Planner\OptionOptimizer
 */

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'Progress_Planner\\OptionOptimizer\\';

		if ( 0 !== \strpos( $class_name, $prefix ) ) {
			return;
		}

		$class_name = \str_replace( $prefix, '', $class_name );

		$file = AAA_OPTION_OPTIMIZER_DIR . '/src/class-' . \str_replace( '_', '-', \strtolower( $class_name ) ) . '.php';

		if ( \file_exists( $file ) ) {
			require_once $file;
		}
	}
);
