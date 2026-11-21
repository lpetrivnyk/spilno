<?php
/**
 * Plugin Name: Spilno
 * Description: Pages shared between two sites: page templates, their values with revisions, and an adapter contract for the sites.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: spilno
 * Domain Path: /languages
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPILNO_VERSION', '0.1.0' );

/**
 * Version of the site-to-site contract (payloads, endpoint). Both sites of a
 * link must have the same value.
 */
define( 'SPILNO_API', 1 );

/**
 * Version of the plugin's own tables. Bumped together with any change of
 * `_spilno_db_schema()`; the migration runs on the first request after that.
 */
define( 'SPILNO_DB_VERSION', '1.1.0' );

define( 'SPILNO_FILE', __FILE__ );
define( 'SPILNO_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Whether the plugin's front-end output and synchronisation are enabled.
 *
 * The `spilno_enabled` filter is the site's switch. The data API (schema,
 * parse, save, values, state) keeps working regardless, so admin forms never
 * lose their fields. Switched off, the synchronisation endpoint answers 404;
 * it reads the switch on `plugins_loaded:1`, so the filter must be added by a
 * plugin or a must-use plugin, not by the theme.
 *
 * @return bool
 */
function spilno_enabled() {
	return (bool) apply_filters( 'spilno_enabled', true );
}

require SPILNO_PATH . 'includes/db.php';
require SPILNO_PATH . 'includes/fields.php';
require SPILNO_PATH . 'includes/registry.php';
require SPILNO_PATH . 'includes/contract.php';
require SPILNO_PATH . 'includes/store.php';
require SPILNO_PATH . 'includes/adopt.php';
require SPILNO_PATH . 'includes/slots.php';
require SPILNO_PATH . 'includes/render.php';
require SPILNO_PATH . 'includes/doctor.php';
require SPILNO_PATH . 'includes/sync/config.php';
require SPILNO_PATH . 'includes/sync/guard.php';
require SPILNO_PATH . 'includes/sync/endpoint.php';
require SPILNO_PATH . 'includes/sync/client.php';
require SPILNO_PATH . 'includes/sync/check.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require SPILNO_PATH . 'includes/cli.php';
}

add_action( 'plugins_loaded', '_spilno_db_maybe_migrate', 0 );
add_action( 'init', '_spilno_load_textdomain', 1 );
add_action( 'admin_notices', '_spilno_sync_notices' );
register_activation_hook( __FILE__, '_spilno_db_maybe_migrate' );

/**
 * Loads the plugin translations.
 *
 * @return void
 */
function _spilno_load_textdomain() {
	load_plugin_textdomain( 'spilno', false, dirname( plugin_basename( SPILNO_FILE ) ) . '/languages' );
}
