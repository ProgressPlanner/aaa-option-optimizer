<?php
/**
 * Autoload PHP classes for the plugin.
 *
 * @package Emilia\MetaOptimizer
 */

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'Emilia\\MetaOptimizer\\';

		if ( 0 !== \strpos( $class_name, $prefix ) ) {
			return;
		}

		$class_name = \str_replace( $prefix, '', $class_name );

		$file = AAA_META_OPTIMIZER_DIR . '/src/class-' . \str_replace( '_', '-', \strtolower( $class_name ) ) . '.php';

		if ( \file_exists( $file ) ) {
			require_once $file;
		}
	}
);
