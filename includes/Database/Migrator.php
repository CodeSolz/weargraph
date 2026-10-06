<?php
/**
 * Versioned, reversible schema migrations.
 *
 * @package WearGraph
 */

namespace WearGraph\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Each migration has up() and down(). The installed version lives in the
 * weargraph_db_version option. Up-migrations are dbDelta-based, so re-running
 * one is harmless.
 */
final class Migrator {

	public const OPTION = 'weargraph_db_version';

	/**
	 * Registered migrations, keyed by the schema version they produce.
	 *
	 * @return array<int,array{up:callable,down:callable}>
	 */
	private static function migrations(): array {
		return array(
			1 => array(
				'up'   => array( self::class, 'up_1' ),
				'down' => array( self::class, 'down_1' ),
			),
			2 => array(
				'up'   => array( self::class, 'up_2' ),
				'down' => array( self::class, 'down_2' ),
			),
			3 => array(
				'up'   => array( self::class, 'up_3' ),
				'down' => array( self::class, 'down_3' ),
			),
		);
	}

	/**
	 * Installed schema version.
	 */
	public static function installed_version(): int {
		return (int) get_option( self::OPTION, 0 );
	}

	/**
	 * Run every migration above the installed version, up to $target.
	 *
	 * @param int $target Target schema version.
	 */
	public static function migrate( int $target ): void {
		$current = self::installed_version();

		foreach ( self::migrations() as $version => $migration ) {
			if ( $version > $current && $version <= $target ) {
				call_user_func( $migration['up'] );
				update_option( self::OPTION, $version, true );
			}
		}
	}

	/**
	 * Roll back down to $target (0 drops everything).
	 *
	 * @param int $target Target schema version.
	 */
	public static function rollback( int $target = 0 ): void {
		$migrations = self::migrations();
		krsort( $migrations );

		foreach ( $migrations as $version => $migration ) {
			if ( $version > $target && $version <= self::installed_version() ) {
				call_user_func( $migration['down'] );
				update_option( self::OPTION, $version - 1, true );
			}
		}

		if ( 0 === $target ) {
			delete_option( self::OPTION );
		}
	}

	/**
	 * Version 1: initial tables.
	 */
	public static function up_1(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::v1() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Undo version 1.
	 */
	public static function down_1(): void {
		global $wpdb;

		foreach ( array_keys( Schema::v1() ) as $name ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', Schema::table( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own tables.
		}
	}

	/**
	 * Version 2: text/structured outputs (alt text, classifications) on generations.
	 */
	public static function up_2(): void {
		global $wpdb;

		$table = Schema::table( 'generations' );
		if ( ! self::has_column( $table, 'output_json' ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN output_json LONGTEXT NULL AFTER output_asset_ids', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- plugin table migration.
		}
	}

	/**
	 * Undo version 2.
	 */
	public static function down_2(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN output_json', Schema::table( 'generations' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- plugin table migration.
	}

	/**
	 * Version 3: catalog readiness status, variant label and full classification.
	 */
	public static function up_3(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- plugin table migration.
		$table = Schema::table( 'catalog_meta' );
		if ( ! self::has_column( $table, 'readiness_status' ) ) {
			$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN readiness_status VARCHAR(16) NOT NULL DEFAULT 'unscanned' AFTER readiness_score, ADD KEY readiness_status (readiness_status)", $table ) );
		}
		if ( ! self::has_column( $table, 'variant_label' ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN variant_label VARCHAR(191) NULL AFTER variant_id', $table ) );
		}
		if ( ! self::has_column( $table, 'classification_json' ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN classification_json TEXT NULL AFTER problems_json', $table ) );
		}
		// phpcs:enable
	}

	/**
	 * Undo version 3.
	 */
	public static function down_3(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP KEY readiness_status, DROP COLUMN readiness_status, DROP COLUMN variant_label, DROP COLUMN classification_json', Schema::table( 'catalog_meta' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- plugin table migration.
	}

	/**
	 * Whether a plugin table has a column.
	 *
	 * @param string $table  Table name from Schema.
	 * @param string $column Column name.
	 */
	private static function has_column( string $table, string $column ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection.
	}
}
