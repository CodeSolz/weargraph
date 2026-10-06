<?php
/**
 * Table names and CREATE TABLE statements (see to-do/06-DB-SCHEMA.md).
 *
 * @package WearGraph
 */

namespace WearGraph\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for WearGraph's table definitions. dbDelta-compatible:
 * two spaces after PRIMARY KEY, one column per line, KEY not INDEX.
 */
final class Schema {

	public const TABLES = array(
		'generations',
		'generation_states',
		'assets',
		'catalog_meta',
		'sessions',
		'events',
		'event_daily',
		'usage_ledger',
	);

	/**
	 * Full table name.
	 *
	 * @param string $name Short name from TABLES.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'weargraph_' . $name;
	}

	/**
	 * CREATE TABLE statements for schema version 1.
	 *
	 * @return array<string,string> short name => SQL
	 */
	public static function v1(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		return array(
			'generations'       => "CREATE TABLE {$wpdb->prefix}weargraph_generations (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				kind VARCHAR(32) NOT NULL,
				state VARCHAR(24) NOT NULL,
				context VARCHAR(16) NOT NULL DEFAULT 'studio',
				product_id BIGINT UNSIGNED NULL,
				variant_id BIGINT UNSIGNED NULL,
				session_id CHAR(26) NULL,
				user_id BIGINT UNSIGNED NULL,
				idempotency_key CHAR(64) NOT NULL,
				input_json LONGTEXT NULL,
				provider VARCHAR(64) NULL,
				model VARCHAR(64) NULL,
				transport VARCHAR(16) NOT NULL DEFAULT 'local',
				cost_usd DECIMAL(10,5) NOT NULL DEFAULT 0,
				cost_estimate_usd DECIMAL(10,5) NOT NULL DEFAULT 0,
				latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
				tokens_in INT UNSIGNED NULL,
				tokens_out INT UNSIGNED NULL,
				attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
				quality_json TEXT NULL,
				error_code VARCHAR(32) NULL,
				error_message VARCHAR(255) NULL,
				output_asset_ids TEXT NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				expires_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY state (state),
				KEY product_id (product_id),
				KEY session_id (session_id),
				KEY idempotency_key (idempotency_key),
				KEY created_at (created_at),
				KEY expires_at (expires_at)
			) {$charset_collate};",

			'generation_states' => "CREATE TABLE {$wpdb->prefix}weargraph_generation_states (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				generation_id BIGINT UNSIGNED NOT NULL,
				from_state VARCHAR(24) NULL,
				to_state VARCHAR(24) NOT NULL,
				meta_json TEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY generation_id (generation_id)
			) {$charset_collate};",

			'assets'            => "CREATE TABLE {$wpdb->prefix}weargraph_assets (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				type VARCHAR(32) NOT NULL,
				storage VARCHAR(16) NOT NULL,
				path VARCHAR(255) NULL,
				attachment_id BIGINT UNSIGNED NULL,
				parent_asset_id BIGINT UNSIGNED NULL,
				parent_attachment_id BIGINT UNSIGNED NULL,
				generation_id BIGINT UNSIGNED NULL,
				session_id CHAR(26) NULL,
				sha256 CHAR(64) NULL,
				phash CHAR(16) NULL,
				width INT UNSIGNED NULL,
				height INT UNSIGNED NULL,
				mime VARCHAR(32) NULL,
				bytes INT UNSIGNED NULL,
				provenance_json TEXT NULL,
				published_to TEXT NULL,
				created_at DATETIME NOT NULL,
				expires_at DATETIME NULL,
				deleted_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY type (type),
				KEY generation_id (generation_id),
				KEY session_id (session_id),
				KEY attachment_id (attachment_id),
				KEY expires_at (expires_at)
			) {$charset_collate};",

			'catalog_meta'      => "CREATE TABLE {$wpdb->prefix}weargraph_catalog_meta (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				product_id BIGINT UNSIGNED NOT NULL,
				variant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				category VARCHAR(32) NULL,
				category_source VARCHAR(8) NOT NULL DEFAULT 'ai',
				garment_region VARCHAR(16) NULL,
				layer VARCHAR(16) NULL,
				layer_source VARCHAR(8) NOT NULL DEFAULT 'ai',
				gender_presentation VARCHAR(16) NULL,
				pattern VARCHAR(16) NULL,
				dominant_colors VARCHAR(191) NULL,
				tryon_mode VARCHAR(32) NULL,
				tryon_enabled TINYINT(1) NOT NULL DEFAULT 0,
				tryon_enabled_source VARCHAR(8) NOT NULL DEFAULT 'ai',
				best_image_id BIGINT UNSIGNED NULL,
				best_image_source VARCHAR(8) NOT NULL DEFAULT 'ai',
				image_scores_json TEXT NULL,
				readiness_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
				problems_json TEXT NULL,
				confidence DECIMAL(4,3) NOT NULL DEFAULT 0,
				content_hash CHAR(64) NULL,
				scanned_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY product_variant (product_id,variant_id),
				KEY readiness_score (readiness_score),
				KEY tryon_enabled (tryon_enabled)
			) {$charset_collate};",

			'sessions'          => "CREATE TABLE {$wpdb->prefix}weargraph_sessions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(26) NOT NULL,
				token_hash CHAR(64) NOT NULL,
				ip_hash CHAR(64) NULL,
				ua_hash CHAR(64) NULL,
				user_id BIGINT UNSIGNED NULL,
				consent_version VARCHAR(32) NULL,
				consent_at DATETIME NULL,
				retention_mode VARCHAR(16) NOT NULL DEFAULT 'ephemeral',
				photo_asset_id BIGINT UNSIGNED NULL,
				tryon_count INT UNSIGNED NOT NULL DEFAULT 0,
				regen_count INT UNSIGNED NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				expires_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY token_hash (token_hash),
				KEY user_id (user_id),
				KEY expires_at (expires_at)
			) {$charset_collate};",

			'events'            => "CREATE TABLE {$wpdb->prefix}weargraph_events (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				name VARCHAR(48) NOT NULL,
				session_id CHAR(26) NULL,
				anonymous_shopper_id CHAR(26) NULL,
				product_id BIGINT UNSIGNED NULL,
				variant_id BIGINT UNSIGNED NULL,
				look_id CHAR(26) NULL,
				generation_id BIGINT UNSIGNED NULL,
				order_id BIGINT UNSIGNED NULL,
				props_json TEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY name_created (name,created_at),
				KEY session_id (session_id),
				KEY product_id (product_id),
				KEY created_at (created_at)
			) {$charset_collate};",

			'event_daily'       => "CREATE TABLE {$wpdb->prefix}weargraph_event_daily (
				day DATE NOT NULL,
				product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				name VARCHAR(48) NOT NULL,
				count INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY  (day,product_id,name)
			) {$charset_collate};",

			'usage_ledger'      => "CREATE TABLE {$wpdb->prefix}weargraph_usage_ledger (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				generation_id BIGINT UNSIGNED NOT NULL,
				kind VARCHAR(32) NOT NULL,
				units SMALLINT UNSIGNED NOT NULL DEFAULT 1,
				counts_toward_quota TINYINT(1) NOT NULL DEFAULT 1,
				provider VARCHAR(64) NULL,
				model VARCHAR(64) NULL,
				cost_usd DECIMAL(10,5) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY generation_id (generation_id),
				KEY created_at (created_at)
			) {$charset_collate};",
		);
	}
}
