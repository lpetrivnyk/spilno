<?php
/**
 * Own tables of the plugin and their migration.
 *
 * `spilno_pages` (current values of a page, its revision), `spilno_log`
 * (revisions and events, the endpoint's refusals as record 0) and
 * `spilno_nonces` (one-time nonces of accepted requests, 1.1.0). Links,
 * queue and media come with their parts of synchronisation.
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option holding the schema version actually applied in this installation.
 */
const SPILNO_DB_OPTION = 'spilno_db_version';

/**
 * How many newest log rows of a page keep their values.
 */
const SPILNO_LOG_KEEP_VALUES = 20;

/**
 * Table names of the plugin, prefixed.
 *
 * @return array{pages: string, log: string, nonces: string}
 */
function _spilno_tables() {
	global $wpdb;

	return array(
		'pages'  => $wpdb->prefix . 'spilno_pages',
		'log'    => $wpdb->prefix . 'spilno_log',
		'nonces' => $wpdb->prefix . 'spilno_nonces',
	);
}

/**
 * CREATE TABLE statements in the form `dbDelta()` expects (two spaces after
 * PRIMARY KEY, one key per line, no quoted names).
 *
 * `values_json`, not `values`: VALUES is a reserved word and `dbDelta()`
 * does not quote column names.
 *
 * @return string[]
 */
function _spilno_db_schema() {
	global $wpdb;

	$tables  = _spilno_tables();
	$charset = $wpdb->get_charset_collate();

	return array(
		"CREATE TABLE {$tables['pages']} (
  record_id bigint(20) unsigned NOT NULL,
  template varchar(64) NOT NULL,
  schema_version int(10) unsigned NOT NULL,
  values_json longtext NOT NULL,
  revision bigint(20) unsigned NOT NULL DEFAULT 0,
  rev_id char(36) NOT NULL,
  parent_rev_id char(36) NOT NULL DEFAULT '',
  updated_at datetime NOT NULL,
  updated_by_label varchar(190) NOT NULL DEFAULT '',
  origin_site varchar(32) NOT NULL DEFAULT '',
  PRIMARY KEY  (record_id),
  KEY template (template)
) ENGINE=InnoDB {$charset};",
		"CREATE TABLE {$tables['log']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  record_id bigint(20) unsigned NOT NULL,
  event varchar(32) NOT NULL,
  template varchar(64) NOT NULL DEFAULT '',
  schema_version int(10) unsigned NOT NULL DEFAULT 0,
  revision bigint(20) unsigned NOT NULL DEFAULT 0,
  rev_id char(36) NOT NULL DEFAULT '',
  parent_rev_id char(36) NOT NULL DEFAULT '',
  values_hash char(64) NOT NULL DEFAULT '',
  values_json longtext NULL,
  author_label varchar(190) NOT NULL DEFAULT '',
  site varchar(32) NOT NULL DEFAULT '',
  note varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY record_id (record_id,id)
) ENGINE=InnoDB {$charset};",
		"CREATE TABLE {$tables['nonces']} (
  site varchar(32) NOT NULL,
  nonce char(32) NOT NULL,
  req_time bigint(20) NOT NULL,
  PRIMARY KEY  (site,nonce),
  KEY req_time (req_time)
) ENGINE=InnoDB {$charset};",
	);
}

/**
 * Runs the migration when the applied version differs from
 * `SPILNO_DB_VERSION`.
 *
 * Runs on `plugins_loaded:0` (deployment is by files, the activation hook
 * does not fire on update) and on activation. Costs one autoloaded option
 * read when current. A named lock keeps two first requests from running
 * `dbDelta()` at once: the one without the lock skips and the next request
 * retries. The option is written only after every table is confirmed to exist.
 *
 * @return string `current`, `migrated`, `busy` or `failed`.
 */
function _spilno_db_maybe_migrate() {
	global $wpdb;

	if ( SPILNO_DB_VERSION === get_option( SPILNO_DB_OPTION ) ) {
		return 'current';
	}

	$lock = 'spilno_migrate_' . md5( DB_NAME . '|' . $wpdb->prefix );

	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $lock ) ) ) {
		return 'busy';
	}

	try {
		// Another request may have finished while this one waited for the lock.
		wp_cache_delete( SPILNO_DB_OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		if ( SPILNO_DB_VERSION === get_option( SPILNO_DB_OPTION ) ) {
			return 'current';
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( _spilno_db_schema() );

		foreach ( _spilno_tables() as $table ) {
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				// Visible in the PHP log of the server; doctor reports it too.
				error_log( 'spilno: migration to ' . SPILNO_DB_VERSION . ' failed, table missing: ' . $table ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return 'failed';
			}
		}

		update_option( SPILNO_DB_OPTION, SPILNO_DB_VERSION, true );

		return 'migrated';
	} finally {
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
	}
}
