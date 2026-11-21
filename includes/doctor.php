<?php
/**
 * Diagnostics: what a site must have for the plugin to work.
 *
 * Stage 1 checks the database, the templates, the adapter and the stored
 * pages. Theme tokens (stage 2) and link constants (stage 4) are added with
 * their stages.
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the checks.
 *
 * @return array<int, array{status: string, check: string, message: string}> Status: `ok`, `info`, `warn`, `fail`.
 */
function spilno_doctor() {
	global $wpdb;

	$out = array();
	$add = static function ( $status, $check, $message ) use ( &$out ) {
		$out[] = array(
			'status'  => $status,
			'check'   => $check,
			'message' => $message,
		);
	};

	$add( 'info', 'version', sprintf( 'plugin %s, API %d, environment %s', SPILNO_VERSION, SPILNO_API, wp_get_environment_type() ) );

	// Database.
	$applied = get_option( SPILNO_DB_OPTION );

	if ( SPILNO_DB_VERSION === $applied ) {
		$add( 'ok', 'db_version', 'schema ' . SPILNO_DB_VERSION );
	} else {
		$add( 'fail', 'db_version', sprintf( 'schema applied %s, expected %s (migration runs on the next request)', is_string( $applied ) ? $applied : 'none', SPILNO_DB_VERSION ) );
	}

	$tables_ok = true;

	foreach ( _spilno_tables() as $table ) {
		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );

		if ( ! is_array( $status ) ) {
			$tables_ok = false;
			$add( 'fail', 'table', $table . ': missing' );
		} elseif ( 'InnoDB' !== $status['Engine'] ) {
			$add( 'fail', 'table', $table . ': engine ' . $status['Engine'] . ', InnoDB required (transactions)' );
		} else {
			$add( 'ok', 'table', $table . ': InnoDB' );
		}
	}

	// Templates.
	$templates = spilno_templates();

	foreach ( _spilno_template_problems() as $key => $problems ) {
		$add( 'fail', 'template', $key . ': ' . implode( '; ', $problems ) );
	}

	if ( ! $templates ) {
		$add( 'warn', 'template', 'no templates registered' );
	}

	foreach ( $templates as $key => $template ) {
		$add( 'ok', 'template', sprintf( '%s v%d %s', $key, $template['version'], substr( spilno_schema_hash( $key ), 0, 12 ) ) );
	}

	if ( defined( 'SPILNO_DEV_TEMPLATES_DIR' ) ) {
		$dev = in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		$add( $dev ? 'info' : 'warn', 'dev_templates', $dev ? 'test templates loaded from SPILNO_DEV_TEMPLATES_DIR' : 'SPILNO_DEV_TEMPLATES_DIR is defined and ignored outside local / development: remove it' );
	}

	// Adapter.
	foreach ( array( 'spilno_can', 'spilno_resolve_url', 'spilno_record_url', 'spilno_site_label', 'spilno_slot_link', 'spilno_slot_data' ) as $filter ) {
		$add( has_filter( $filter ) ? 'ok' : 'warn', 'adapter', $filter . ( has_filter( $filter ) ? '' : ': no adapter' ) );
	}

	// Transactions.
	$suppress = $wpdb->suppress_errors( true );
	$in_tx    = $wpdb->get_var( 'SELECT @@in_transaction' );
	$wpdb->suppress_errors( $suppress );
	$wpdb->last_error = '';
	$add( 'info', 'transactions', null === $in_tx ? '@@in_transaction unavailable (MySQL): a caller\'s open transaction is detected only by @@autocommit = 0' : '@@in_transaction available' );

	// Stored pages.
	if ( $tables_ok ) {
		$table = _spilno_tables()['pages'];
		$rows  = $wpdb->get_results( "SELECT p.record_id, p.template, p.schema_version, ( SELECT COUNT(*) FROM {$wpdb->posts} AS w WHERE w.ID = p.record_id ) AS record_exists FROM {$table} AS p", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names.
		$count = 0;

		foreach ( (array) $rows as $row ) {
			++$count;
			$template = $templates[ $row['template'] ] ?? null;

			if ( null === $template ) {
				$add( 'warn', 'page', sprintf( 'record %d: template %s is not available', $row['record_id'], $row['template'] ) );
			} elseif ( (int) $row['schema_version'] !== $template['version'] ) {
				$add( 'info', 'page', sprintf( 'record %d: stored with schema v%d, current v%d (normalized on read, written by the next save)', $row['record_id'], $row['schema_version'], $template['version'] ) );
			}

			if ( '0' === (string) $row['record_exists'] ) {
				$add( 'warn', 'page', sprintf( 'record %d: the post no longer exists; its values stay in the table', $row['record_id'] ) );
			}
		}

		$add( 'info', 'pages', sprintf( '%d page(s) with values', $count ) );
	}

	// Links (stage 4).
	$add( 'info', 'links', '' === _spilno_site_id() ? 'links are not configured: pages work locally' : 'site ' . _spilno_site_id() );

	return $out;
}
