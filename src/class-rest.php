<?php
/**
 * REST functionality for AAA Option Optimizer.
 *
 * @package Emilia\MetaOptimizer
 */

namespace Emilia\MetaOptimizer;

use WP_Error;
use WP_REST_Response;

/**
 * REST functionality of AAA Option Optimizer.
 */
class REST {

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * Register the REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {

		\register_rest_route(
			'aaa-meta-optimizer/v1',
			'/reset',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'reset_stats' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);
	}

	/**
	 * Update autoload status of an option.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function reset_stats() {
		Plugin::get_instance()->reset();
		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}
}
