<?php
/**
 * Dev-only harness of synchronisation (stage 4a), on both stands.
 *
 * - `SPILNO_DEV_ENDPOINT`: the plugin's test operation `dev/echo`;
 * - switches in options (set with `wp option`): `spilno_test_disabled`
 *   (spilno_enabled = false), `spilno_test_redirect` (a «core» that redirects
 *   every guest: init, admin_init, template_redirect, parse_request,
 *   muplugins_loaded, body);
 * - `wp spilno-test send`: a request to the partner built by hand (bad
 *   signature, foreign secret, shifted time, fixed nonce, other env / site,
 *   raw body, cookie, a direct connection from another address);
 * - `wp spilno-test journal|guard-reset|nonces|seed-nonces|sync-unit`.
 *
 * @package SpilnoTest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPILNO_DEV_ENDPOINT', true );

if ( get_option( 'spilno_test_disabled' ) ) {
	add_filter( 'spilno_enabled', '__return_false' );
}

/**
 * Emulated guest redirect of a site core.
 *
 * @return void
 */
function spilno_test_redirect_guest() {
	header( 'Location: /spilno-test-login/', true, 302 );
	exit;
}

$spilno_test_redirect = (string) get_option( 'spilno_test_redirect', '' );

if ( '' !== $spilno_test_redirect && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	$spilno_test_guest = static function () {
		return function_exists( 'is_user_logged_in' ) ? ! is_user_logged_in() : true;
	};

	if ( 'body' === $spilno_test_redirect ) {
		// In the body of a must-use plugin: no pluggable functions yet; any request without a login cookie.
		$spilno_test_cookie = false;
		foreach ( array_keys( $_COOKIE ) as $spilno_test_name ) {
			$spilno_test_cookie = $spilno_test_cookie || str_starts_with( $spilno_test_name, 'wordpress_logged_in_' );
		}
		if ( ! $spilno_test_cookie ) {
			spilno_test_redirect_guest();
		}
	} elseif ( 'muplugins_loaded' === $spilno_test_redirect ) {
		add_action(
			'muplugins_loaded',
			static function () {
				foreach ( array_keys( $_COOKIE ) as $name ) {
					if ( str_starts_with( $name, 'wordpress_logged_in_' ) ) {
						return;
					}
				}
				spilno_test_redirect_guest();
			}
		);
	} else {
		add_action(
			$spilno_test_redirect,
			static function () use ( $spilno_test_guest ) {
				if ( $spilno_test_guest() ) {
					spilno_test_redirect_guest();
				}
			},
			0
		);
	}
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * `wp spilno-test send [--op=] [--method=] [--b64=] [--raw=] [--size=] [--secret=current|previous|foreign]
 * [--site=] [--env=] [--shift=] [--nonce=] [--bad-sig] [--unsigned] [--cookie=] [--direct] [--query=]
 * [--repeat=]` — prints `STATUS BODY` per request (with --repeat: counts by status).
 */
WP_CLI::add_command(
	'spilno-test send',
	static function ( $args, $assoc ) {
		$cfg    = _spilno_sync_config();
		$op     = $assoc['op'] ?? 'ping';
		$method = $assoc['method'] ?? ( 'dev/echo' === $op ? 'POST' : 'GET' );

		if ( isset( $assoc['raw'] ) ) {
			$body = (string) $assoc['raw'];
		} elseif ( isset( $assoc['size'] ) ) {
			$body = (string) wp_json_encode( array( 'text' => str_repeat( 'x', (int) $assoc['size'] ) ) );
		} elseif ( isset( $assoc['b64'] ) ) {
			// Base64: JSON quotes do not survive the shells between the runner and WP-CLI.
			$body = (string) base64_decode( (string) $assoc['b64'] ); // phpcs:ignore
		} else {
			$body = 'POST' === $method ? (string) wp_json_encode( array( 'text' => 'hello' ) ) : '';
		}

		$secrets = array(
			'current'  => $cfg['secrets'][0] ?? '',
			'previous' => $cfg['secrets'][1] ?? '',
			'foreign'  => str_repeat( 'f0', 32 ),
		);
		$secret  = $secrets[ $assoc['secret'] ?? 'current' ];
		$repeat  = max( 1, (int) ( $assoc['repeat'] ?? 1 ) );
		$path    = $cfg['path'] . SPILNO_ENDPOINT_PREFIX . $op;
		$url     = $cfg['url'] . SPILNO_ENDPOINT_PREFIX . $op . ( isset( $assoc['query'] ) ? '?' . $assoc['query'] : '' );
		$resolve = array();

		if ( isset( $assoc['direct'] ) ) {
			// Straight to the partner's web container: another REMOTE_ADDR than the ddev router.
			$ip      = gethostbyname( 'ddev-' . explode( '.', $cfg['host'] )[0] . '-web' );
			$url     = 'https://' . $cfg['host'] . ':443' . $cfg['path'] . SPILNO_ENDPOINT_PREFIX . $op;
			$resolve = array( $cfg['host'] . ':443:' . $ip );
		}

		$counts = array();

		for ( $i = 0; $i < $repeat; $i++ ) {
			$headers = _spilno_sign_headers(
				$method,
				$path,
				$body,
				$secret,
				array(
					'site'  => $assoc['site'] ?? $cfg['site'],
					'env'   => $assoc['env'] ?? wp_get_environment_type(),
					'time'  => time() + (int) ( $assoc['shift'] ?? 0 ),
					'nonce' => $assoc['nonce'] ?? bin2hex( random_bytes( 16 ) ),
				)
			);

			if ( isset( $assoc['bad-sig'] ) ) {
				$headers['X-Spilno-Sig'] = ( '0' === $headers['X-Spilno-Sig'][0] ? '1' : '0' ) . substr( $headers['X-Spilno-Sig'], 1 );
			}

			if ( isset( $assoc['unsigned'] ) ) {
				unset( $headers['X-Spilno-Sig'] );
			}

			$lines = array( 'Expect:' );

			foreach ( $headers as $name => $value ) {
				$lines[] = $name . ': ' . $value;
			}

			if ( '' !== $body ) {
				$lines[] = 'Content-Type: application/json';
			}

			if ( isset( $assoc['cookie'] ) ) {
				$lines[] = 'Cookie: ' . $assoc['cookie'];
			}

			$ch = curl_init( $url ); // phpcs:ignore
			curl_setopt_array( // phpcs:ignore
				$ch,
				array(
					CURLOPT_CUSTOMREQUEST  => $method,
					CURLOPT_POSTFIELDS     => '' === $body ? null : $body,
					CURLOPT_HTTPHEADER     => $lines,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_SSL_VERIFYPEER => true,
					CURLOPT_TIMEOUT        => 15,
					CURLOPT_RESOLVE        => $resolve,
				)
			);

			if ( '' === $body ) {
				curl_setopt( $ch, CURLOPT_POSTFIELDS, null ); // phpcs:ignore
			}

			$out    = curl_exec( $ch ); // phpcs:ignore
			$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ); // phpcs:ignore
			$error  = curl_error( $ch ); // phpcs:ignore

			if ( 1 === $repeat ) {
				WP_CLI::line( $status . ' ' . ( false === $out ? 'curl: ' . $error : $out ) );
				return;
			}

			$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + 1;
		}

		ksort( $counts );
		$line = array();
		foreach ( $counts as $status => $n ) {
			$line[] = $status . 'x' . $n;
		}
		WP_CLI::line( implode( ' ', $line ) );
	}
);

// Last refusal notes of the journal, newest first.
WP_CLI::add_command(
	'spilno-test journal',
	static function ( $args, $assoc ) {
		global $wpdb;
		$table = _spilno_tables()['log'];
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, site, note FROM {$table} WHERE record_id = 0 AND event = 'refused' ORDER BY id DESC LIMIT %d", (int) ( $assoc['n'] ?? 1 ) ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $row ) {
			WP_CLI::line( $row['id'] . ' ' . $row['site'] . ' ' . $row['note'] );
		}
	}
);

// Forget rate limits, blocks and the journal throttle.
WP_CLI::add_command(
	'spilno-test guard-reset',
	static function () {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_spilno\_%' OR option_name LIKE '\_transient\_timeout\_spilno\_%'" ); // phpcs:ignore
		wp_cache_flush();
		WP_CLI::line( 'reset' );
	}
);

// Rows of spilno_nonces: total and older than the accepted window plus the margin.
WP_CLI::add_command(
	'spilno-test nonces',
	static function () {
		global $wpdb;
		$table = _spilno_tables()['nonces'];
		$edge  = time() - SPILNO_CLOCK_SKEW - SPILNO_NONCE_MARGIN;
		WP_CLI::line( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) . ' ' . (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE req_time < %d", $edge ) ) ); // phpcs:ignore
	}
);

// Adds N nonces of a request time `age` seconds ago.
WP_CLI::add_command(
	'spilno-test seed-nonces',
	static function ( $args ) {
		global $wpdb;
		$table = _spilno_tables()['nonces'];
		$n     = (int) $args[0];
		$time  = time() - (int) $args[1];
		for ( $i = 0; $i < $n; $i++ ) {
			$wpdb->insert( $table, array( 'site' => 'a', 'nonce' => bin2hex( random_bytes( 16 ) ), 'req_time' => $time ) ); // phpcs:ignore
		}
		WP_CLI::line( 'seeded ' . $n );
	}
);

// Fingerprint of what a refusal must not change (pages, page log, nonces, options other than transients) | the last journal row.
WP_CLI::add_command(
	'spilno-test state',
	static function () {
		global $wpdb;
		$t = _spilno_tables();
		$parts = array(
			$wpdb->get_var( "SELECT CONCAT( COUNT(*), ':', COALESCE( SUM( CRC32( CONCAT( record_id, rev_id ) ) ), 0 ) ) FROM {$t['pages']}" ), // phpcs:ignore
			$wpdb->get_var( "SELECT CONCAT( COUNT(*), ':', COALESCE( MAX( id ), 0 ) ) FROM {$t['log']} WHERE record_id <> 0" ), // phpcs:ignore
			$wpdb->get_var( "SELECT COUNT(*) FROM {$t['nonces']}" ), // phpcs:ignore
			$wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE '\_transient%' AND option_name NOT LIKE '\_site\_transient%'" ), // phpcs:ignore
		);
		$last = $wpdb->get_row( "SELECT id, site, note FROM {$t['log']} WHERE record_id = 0 AND event = 'refused' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore
		WP_CLI::line( implode( ' ', $parts ) . '|' . ( $last ? $last['id'] . ' ' . $last['site'] . ' ' . $last['note'] : '0' ) );
	}
);

// PHP checks of configuration, allowlist, banner, signature and path matching.
WP_CLI::add_command(
	'spilno-test sync-unit',
	static function () {
		$pass  = 0;
		$fails = array();
		$check = static function ( $name, $ok ) use ( &$pass, &$fails ) {
			if ( $ok ) {
				++$pass;
			} else {
				$fails[] = $name;
			}
		};

		$cfg = _spilno_sync_config();
		$check( 'config ok on the stand', $cfg['ok'] && 2 === count( $cfg['secrets'] ) );
		$check( 'config: production refuses a ddev host', ! _spilno_sync_config( 'production' )['ok'] );
		$check( 'config: staging accepts a ddev host', _spilno_sync_config( 'staging' )['ok'] );

		$check( 'ip: empty allowlist, local → allowed', _spilno_ip_allowed( '203.0.113.9', array(), 'local' ) );
		$check( 'ip: empty allowlist, development → allowed', _spilno_ip_allowed( '203.0.113.9', array(), 'development' ) );
		$check( 'ip: empty allowlist, staging → refused', ! _spilno_ip_allowed( '203.0.113.9', array(), 'staging' ) );
		$check( 'ip: empty allowlist, production → refused', ! _spilno_ip_allowed( '203.0.113.9', array(), 'production' ) );
		$check( 'ip: listed → allowed', _spilno_ip_allowed( '203.0.113.9', array( '203.0.113.9' ), 'production' ) );
		$check( 'ip: not listed → refused', ! _spilno_ip_allowed( '203.0.113.8', array( '203.0.113.9' ), 'production' ) );
		$check( 'ip: IPv6 canonical', _spilno_ip_allowed( '2001:db8:0:0::1', array( _spilno_normal_ip( '2001:db8::1' ) ), 'production' ) );
		$check( 'banner: staging without allowlist', _spilno_allowlist_missing( 'staging' ) );
		$check( 'banner: not on local', ! _spilno_allowlist_missing( 'local' ) );

		$check( 'guard key: other claimed site shares the address key', _spilno_guard_key( '1.2.3.4', 'x', 'a' )['key'] === _spilno_guard_key( '1.2.3.4', 'y', 'a' )['key'] );
		$check( 'guard key: partner has its own key', _spilno_guard_key( '1.2.3.4', 'a', 'a' )['key'] !== _spilno_guard_key( '1.2.3.4', 'x', 'a' )['key'] );
		$check( 'guard key: per address', _spilno_guard_key( '1.2.3.4', 'a', 'a' )['key'] !== _spilno_guard_key( '1.2.3.5', 'a', 'a' )['key'] );

		$check( 'sign string', "GET\n/wp-spilno/v1/ping\na\nlocal\n5\n" . str_repeat( '0', 32 ) . "\n" . hash( 'sha256', '' ) === _spilno_sign_string( 'get', '/wp-spilno/v1/ping', 'a', 'local', 5, str_repeat( '0', 32 ), '' ) );

		$m = _spilno_endpoint_match( '/wp-spilno/v1/ping', '' );
		$check( 'match: ping', is_array( $m ) && 'ping' === $m['op'] && ! $m['query'] );
		$m = _spilno_endpoint_match( '/wp-spilno/v1/ping?x', '' );
		$check( 'match: query flagged', is_array( $m ) && $m['query'] );
		$check( 'match: other prefix', null === _spilno_endpoint_match( '/wp-spilnox/v1/ping', '' ) );
		$check( 'match: page path', null === _spilno_endpoint_match( '/about/', '' ) );
		$m = _spilno_endpoint_match( '/wp-spilno/v2/ping', '' );
		$check( 'match: unknown version is ours, no op', is_array( $m ) && '' === $m['op'] );
		$m = _spilno_endpoint_match( '/sub/wp-spilno/v1/ping', '/sub' );
		$check( 'match: home path', is_array( $m ) && 'ping' === $m['op'] );
		$check( 'client: http refused', is_wp_error( _spilno_peer_send( 'GET', 'http://example.test/', '', array(), 3 ) ) );

		foreach ( $fails as $f ) {
			WP_CLI::line( 'FAIL ' . $f );
		}
		WP_CLI::line( 'sync-unit: ' . $pass . '/' . ( $pass + count( $fails ) ) . ' passed' );
	}
);
