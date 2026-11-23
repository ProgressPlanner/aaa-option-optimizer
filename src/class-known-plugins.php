<?php
/**
 * Loader for the known-plugins mapping.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Known_Plugins
 *
 * Fetches the latest known-plugins mapping from the plugin maintainers' server
 * and caches it locally. Falls back to the JSON file bundled with the plugin
 * when no fresh remote copy is available.
 */
class Known_Plugins {

	const REMOTE_URL = 'https://option-optimizer-api.progressplanner.com/known-plugins.json';
	const CACHE_KEY  = 'aaa_option_optimizer_known_plugins';
	const CRON_HOOK  = 'aaa_option_optimizer_refresh_known_plugins';

	/**
	 * Whether the user has consented to contacting our servers.
	 *
	 * Gates the daily remote refresh (which fetches the mapping and sends the
	 * plugin + WordPress version for anonymous stats). Off until the user opts
	 * in, either on the settings page or via the Report popover.
	 *
	 * @return bool
	 */
	public static function has_consent(): bool {
		return (bool) Admin_Page::get_settings()['remote_data_consent'];
	}

	/**
	 * In-memory cache for the current request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private $list;

	/**
	 * Get the known-plugins mapping.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get(): array {
		if ( ! self::has_consent() ) {
			return $this->load_bundled();
		}

		if ( null !== $this->list ) {
			return $this->list;
		}

		$cached = \get_option( self::CACHE_KEY );
		if ( $this->is_valid_mapping( $cached ) ) {
			$this->list = $cached;
			return $this->list;
		}

		$this->list = $this->load_bundled();
		return $this->list;
	}

	/**
	 * Refresh the cached mapping from the remote URL.
	 *
	 * Sends the plugin and WordPress versions so the maintainers can keep
	 * anonymous usage statistics for the maintained mapping. No site URL is included in the payload or User-Agent. The service still
	 * receives the server IP address as part of the connection.
	 *
	 * @return bool True when a fresh copy was stored, false otherwise.
	 */
	public function refresh(): bool {
		// Never contact our servers without the user's consent.
		if ( ! self::has_consent() ) {
			return false;
		}

		/**
		 * Filters the URL the known-plugins mapping is fetched from.
		 *
		 * Allows users to redirect or disable the remote fetch.
		 *
		 * @param string|false $url The remote URL, or false to disable refresh.
		 */
		$url = \apply_filters( 'aaa_option_optimizer_known_plugins_url', self::REMOTE_URL );

		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}

		$response = \wp_safe_remote_post(
			$url,
			[
				'timeout'             => 10,
				'user-agent'          => 'AAA-Option-Optimizer',
				'limit_response_size' => 2 * MB_IN_BYTES,
				'headers'             => [ 'Content-Type' => 'application/json' ],
				'body'                => (string) \wp_json_encode( $this->stats_payload() ),
			]
		);

		if ( \is_wp_error( $response ) ) {
			return false;
		}

		if ( 200 !== \wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = \wp_remote_retrieve_body( $response );
		$data = \json_decode( $body, true );

		if ( ! $this->is_valid_mapping( $data ) ) {
			return false;
		}

		\update_option( self::CACHE_KEY, $data, false );
		$this->list = $data;
		return true;
	}

	/**
	 * Validate remote and stored data before the mapper consumes it.
	 *
	 * @param mixed $data Decoded mapping.
	 * @return bool
	 */
	private function is_valid_mapping( $data ): bool {
		if ( ! is_array( $data ) || ! $data ) {
			return false;
		}

		foreach ( $data as $slug => $plugin ) {
			if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]*$/D', $slug ) || ! is_array( $plugin ) ) {
				return false;
			}
			if ( ! isset( $plugin['name'] ) || ! is_string( $plugin['name'] ) || '' === trim( $plugin['name'] ) ) {
				return false;
			}
			if ( ! isset( $plugin['option_prefixes'] ) || ! is_array( $plugin['option_prefixes'] ) || ! $plugin['option_prefixes'] ) {
				return false;
			}
			if ( array_keys( $plugin['option_prefixes'] ) !== range( 0, count( $plugin['option_prefixes'] ) - 1 ) ) {
				return false;
			}
			foreach ( $plugin['option_prefixes'] as $prefix ) {
				if ( ! is_string( $prefix ) || '' === trim( $prefix ) || preg_match( '/[\x00-\x1f\x7f]/', $prefix ) || mb_strlen( $prefix ) > 191 ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Build the anonymous stats payload sent with the refresh request.
	 *
	 * @return array<string, string>
	 */
	private function stats_payload(): array {
		global $wp_version;

		$plugin_version = '';
		if ( \function_exists( 'get_file_data' ) ) {
			$data           = \get_file_data( AAA_OPTION_OPTIMIZER_FILE, [ 'Version' => 'Version' ] );
			$plugin_version = isset( $data['Version'] ) ? (string) $data['Version'] : '';
		}

		return [
			'plugin_version' => $plugin_version,
			'wp_version'     => (string) $wp_version,
		];
	}

	/**
	 * Load the JSON file bundled with the plugin.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function load_bundled(): array {
		$path = \plugin_dir_path( AAA_OPTION_OPTIMIZER_FILE ) . 'known-plugins/known-plugins.json';
		if ( ! \file_exists( $path ) ) {
			return [];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file bundled with the plugin.
		$data = \json_decode( (string) \file_get_contents( $path ), true );
		return $this->is_valid_mapping( $data ) ? $data : [];
	}
}
