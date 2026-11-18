<?php
/**
 * Tests for the tracking table.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer\Tests;

use Progress_Planner\OptionOptimizer\Database;

/**
 * Tests the Database class.
 *
 * @covers \Progress_Planner\OptionOptimizer\Database
 */
class Database_Test extends Test_Case {

	/**
	 * The table name is prefixed.
	 *
	 * @return void
	 */
	public function test_table_name_uses_the_site_prefix() {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'option_optimizer_tracked', Database::get_table_name() );
	}

	/**
	 * The table can be dropped and created again.
	 *
	 * @return void
	 */
	public function test_drop_and_create_table() {
		// The test suite turns CREATE/DROP TABLE into their TEMPORARY variants, which SHOW TABLES can't see.
		\remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		\remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

		$this->assertTrue( Database::table_exists() );

		Database::drop_table();
		$this->assertFalse( Database::table_exists() );

		Database::create_table();
		$this->assertTrue( Database::table_exists() );
		$this->assertSame( [], Database::get_tracked_options() );
	}

	/**
	 * Inserting nothing is a no-op.
	 *
	 * @return void
	 */
	public function test_batch_insert_ignores_an_empty_list() {
		Database::batch_insert( [] );

		$this->assertSame( [], Database::get_tracked_options() );
		$this->assertSame( [], Database::get_tracked_option_keys() );
	}

	/**
	 * Counts are stored per option.
	 *
	 * @return void
	 */
	public function test_batch_insert_stores_counts() {
		Database::batch_insert(
			[
				self::PREFIX . 'b' => 3,
				self::PREFIX . 'a' => 1,
			]
		);

		$tracked = Database::get_tracked_options();
		\ksort( $tracked );

		$this->assertSame(
			[
				self::PREFIX . 'a' => 1,
				self::PREFIX . 'b' => 3,
			],
			$tracked
		);
	}

	/**
	 * A second write adds to the existing count instead of replacing it.
	 *
	 * @return void
	 */
	public function test_batch_insert_accumulates_counts() {
		Database::batch_insert( [ self::PREFIX . 'a' => 2 ] );
		Database::batch_insert(
			[
				self::PREFIX . 'a' => 5,
				self::PREFIX . 'b' => 1,
			]
		);

		$tracked = Database::get_tracked_options();

		$this->assertSame( 7, $tracked[ self::PREFIX . 'a' ] );
		$this->assertSame( 1, $tracked[ self::PREFIX . 'b' ] );
	}

	/**
	 * Everything is written when the list is larger than one chunk.
	 *
	 * @return void
	 */
	public function test_batch_insert_writes_all_chunks() {
		$options = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$options[ self::PREFIX . $i ] = $i + 1;
		}

		Database::batch_insert( $options, 10 );

		$tracked = Database::get_tracked_options();

		$this->assertCount( 25, $tracked );
		$this->assertSame( 1, $tracked[ self::PREFIX . '0' ] );
		$this->assertSame( 25, $tracked[ self::PREFIX . '24' ] );
	}

	/**
	 * Numeric option names (which PHP turns into integer array keys) are stored as names.
	 *
	 * @return void
	 */
	public function test_batch_insert_keeps_numeric_option_names() {
		Database::batch_insert(
			[
				'123'              => 2,
				self::PREFIX . 'a' => 1,
			]
		);

		$this->assertSame( 2, Database::get_tracked_options()['123'] );
	}

	/**
	 * Option names with quotes and SQL wildcards are stored literally.
	 *
	 * @return void
	 */
	public function test_batch_insert_stores_special_characters_literally() {
		$name = self::PREFIX . "it's 100%_\"quoted\"";

		Database::batch_insert( [ $name => 4 ] );

		$this->assertSame( [ $name => 4 ], Database::get_tracked_options() );
	}

	/**
	 * The keyed lookup contains every tracked name.
	 *
	 * @return void
	 */
	public function test_get_tracked_option_keys() {
		Database::batch_insert(
			[
				self::PREFIX . 'a' => 1,
				self::PREFIX . 'b' => 9,
			]
		);

		$keys = Database::get_tracked_option_keys();
		\ksort( $keys );

		$this->assertSame(
			[
				self::PREFIX . 'a' => true,
				self::PREFIX . 'b' => true,
			],
			$keys
		);
	}

	/**
	 * Clearing removes all tracked options.
	 *
	 * @return void
	 */
	public function test_clear_tracked_options() {
		Database::batch_insert( [ self::PREFIX . 'a' => 1 ] );

		Database::clear_tracked_options();

		$this->assertSame( [], Database::get_tracked_options() );
	}

	/**
	 * Without legacy data there is nothing to migrate.
	 *
	 * @return void
	 */
	public function test_migration_status_without_legacy_data() {
		$expected = [
			'needs_migration' => false,
			'total'           => 0,
			'remaining'       => 0,
		];

		$this->assertSame( $expected, Database::get_migration_status() );

		\update_option( 'option_optimizer', [ 'used_options' => [] ], false );

		$this->assertSame( $expected, Database::get_migration_status() );
	}

	/**
	 * Legacy data stored in the plugin option is moved to the table.
	 *
	 * @return void
	 */
	public function test_migrate_chunk_moves_legacy_data_to_the_table() {
		$used = [
			self::PREFIX . 'a' => 2,
			self::PREFIX . 'b' => 5,
		];

		\update_option(
			'option_optimizer',
			[
				'starting_point_num' => 10,
				'used_options'       => $used,
			],
			false
		);

		$this->assertSame(
			[
				'needs_migration' => true,
				'total'           => 2,
				'remaining'       => 2,
			],
			Database::get_migration_status()
		);

		$this->assertSame(
			[
				'success'   => true,
				'remaining' => 0,
				'total'     => 2,
			],
			Database::migrate_chunk()
		);

		$tracked = Database::get_tracked_options();
		\ksort( $tracked );
		$this->assertSame( $used, $tracked );

		// The legacy list is emptied, the rest of the option is kept, and it is still not autoloaded.
		$option = \get_option( 'option_optimizer' );
		$this->assertSame( [], $option['used_options'] );
		$this->assertSame( 10, $option['starting_point_num'] );
		$this->assertNotAutoloaded( 'option_optimizer' );

		$this->assertFalse( Database::get_migration_status()['needs_migration'] );
	}
}
