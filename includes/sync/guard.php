<?php
/**
 * Protection of the endpoint (plan §B3–B8): IP allowlist, rate limit and
 * block after invalid signatures, one-time nonces, the receiver's journal of
 * refusals and the administrator's banner.
 *
 * Rate limit and block are transients: in the object cache when the site has
 * a persistent one, otherwise rows of wp_options. Read-then-write, not atomic
 * (plan debt Б4): parallel requests may each count once less.
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Requests per window per key (§B4).
 */
const SPILNO_RATE_LIMIT = 60;

/**
 * Rate window, seconds.
 */
const SPILNO_RATE_WINDOW = 60;

/**
 * Invalid signatures per window before the key is blocked.
 */
const SPILNO_BAD_SIG_LIMIT = 10;

/**
 * Window of invalid signatures and length of the block, seconds.
 */
const SPILNO_BLOCK_TTL = 900;

/**
 * Nonces deleted at most per accepted request.
 */
const SPILNO_NONCE_PURGE = 20;

/**
 * Refusal rows the journal keeps.
 */
const SPILNO_JOURNAL_KEEP = 1000;

/**
 * Whether the request address may call the endpoint (§B3).
 *
 * An empty allowlist lets everybody in only on `local` / `development`; on
 * any other environment it refuses everybody (and the banner says so).
 *
 * @param string   $ip  REMOTE_ADDR.
 * @param string[] $ips Allowlist, canonical.
 * @param string   $env Environment.
 * @return bool
 */
function _spilno_ip_allowed( $ip, array $ips, $env ) {
	if ( ! $ips ) {
		return _spilno_dev_env( $env );
	}

	$ip = _spilno_normal_ip( $ip );

	return '' !== $ip && in_array( $ip, $ips, true );
}

/**
 * Key of the rate limit and block: the address plus the claimed site.
 *
 * Only the expected partner identifier gets its own key; any other claimed
 * value shares one key per address, so a sender cannot escape the limit by
 * changing the header and cannot block the partner's key by claiming it from
 * another address.
 *
 * @param string $ip      REMOTE_ADDR.
 * @param string $claimed `X-Spilno-Site` as received.
 * @param string $peer    `SPILNO_PEER_SITE_ID`.
 * @return array{key: string, site: string}
 */
function _spilno_guard_key( $ip, $claimed, $peer ) {
	$site = '' !== $peer && $claimed === $peer ? $peer : '*';

	return array(
		'key'  => md5( $ip . '|' . $site ),
		'site' => $site,
	);
}

/**
 * Counts the request against the rate limit and tells whether the key is
 * blocked or over the limit.
 *
 * @param string $key Key from `_spilno_guard_key()`.
 * @param int    $now Unix time.
 * @return array{refuse: string, first: bool} `refuse`: '', `blocked` or `rate`; `first`: the first refusal of its kind in the window (journaled).
 */
function _spilno_guard_count( $key, $now ) {
	$block = get_transient( 'spilno_bl_' . $key );

	if ( is_array( $block ) && (int) ( $block['until'] ?? 0 ) > $now ) {
		$first = empty( $block['logged'] );

		if ( $first ) {
			$block['logged'] = 1;
			set_transient( 'spilno_bl_' . $key, $block, max( 1, (int) $block['until'] - $now ) );
		}

		return array(
			'refuse' => 'blocked',
			'first'  => $first,
		);
	}

	$rate = get_transient( 'spilno_rl_' . $key );

	if ( ! is_array( $rate ) || (int) ( $rate['start'] ?? 0 ) + SPILNO_RATE_WINDOW <= $now ) {
		$rate = array(
			'start'  => $now,
			'count'  => 0,
			'logged' => 0,
		);
	}

	++$rate['count'];
	$over  = $rate['count'] > SPILNO_RATE_LIMIT;
	$first = $over && empty( $rate['logged'] );

	if ( $first ) {
		$rate['logged'] = 1;
	}

	set_transient( 'spilno_rl_' . $key, $rate, max( 1, (int) $rate['start'] + SPILNO_RATE_WINDOW - $now ) );

	return array(
		'refuse' => $over ? 'rate' : '',
		'first'  => $first,
	);
}

/**
 * Counts an invalid signature; the tenth in the window blocks the key.
 *
 * @param string $key Key from `_spilno_guard_key()`.
 * @param int    $now Unix time.
 * @return bool Whether the key is blocked now.
 */
function _spilno_guard_bad_signature( $key, $now ) {
	$bad = get_transient( 'spilno_bs_' . $key );

	if ( ! is_array( $bad ) || (int) ( $bad['start'] ?? 0 ) + SPILNO_BLOCK_TTL <= $now ) {
		$bad = array(
			'start' => $now,
			'count' => 0,
		);
	}

	++$bad['count'];

	if ( $bad['count'] >= SPILNO_BAD_SIG_LIMIT ) {
		delete_transient( 'spilno_bs_' . $key );
		set_transient(
			'spilno_bl_' . $key,
			array(
				'until'  => $now + SPILNO_BLOCK_TTL,
				'logged' => 0,
			),
			SPILNO_BLOCK_TTL
		);

		return true;
	}

	set_transient( 'spilno_bs_' . $key, $bad, max( 1, (int) $bad['start'] + SPILNO_BLOCK_TTL - $now ) );

	return false;
}

/**
 * Remembers a nonce: `stored`, `used` (a replay) or `error` (the table cannot
 * be written). After a stored one deletes at most `SPILNO_NONCE_PURGE` nonces whose request time
 * is no longer accepted (no cron needed).
 *
 * @param string $site  Sender.
 * @param string $nonce 32 hexadecimal characters.
 * @param int    $time  Request time.
 * @param int    $now   Unix time.
 * @return string
 */
function _spilno_nonce_use( $site, $nonce, $time, $now ) {
	global $wpdb;

	$table = _spilno_tables()['nonces'];
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time insert, the unique key decides.
	$added = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} ( site, nonce, req_time ) VALUES ( %s, %s, %d )", $site, $nonce, $time ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.

	if ( false === $added ) {
		return 'error';
	}

	if ( 1 !== $added ) {
		return 'used';
	}

	_spilno_nonce_purge( $now );

	return 'stored';
}

/**
 * Deletes nonces older than the accepted clock window plus a margin, at most
 * `$limit` rows (index `req_time`).
 *
 * @param int $now   Unix time.
 * @param int $limit Rows at most.
 * @return int Rows deleted.
 */
function _spilno_nonce_purge( $now, $limit = SPILNO_NONCE_PURGE ) {
	global $wpdb;

	$table = _spilno_tables()['nonces'];
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded cleanup.
	$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE req_time < %d ORDER BY req_time LIMIT %d", $now - SPILNO_CLOCK_SKEW - SPILNO_NONCE_MARGIN, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.

	return (int) $deleted;
}

/**
 * Writes a refusal to the receiver's journal (`spilno_log`, record 0, event
 * `refused`): what the sender is not told.
 *
 * @param int    $status HTTP status sent.
 * @param string $reason Short reason.
 * @param array  $req    Request facts: `op`, `ip`, `site`.
 * @return void
 */
function _spilno_journal_refusal( $status, $reason, array $req ) {
	global $wpdb;

	$table = _spilno_tables()['log'];
	$site  = preg_replace( '/[^a-z0-9_*-]/', '', strtolower( substr( (string) ( $req['site'] ?? '' ), 0, 32 ) ) );
	$note  = sprintf( '%d %s op=%s ip=%s', $status, $reason, substr( (string) ( $req['op'] ?? '' ), 0, 64 ), (string) ( $req['ip'] ?? '' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- journal row.
	$wpdb->insert(
		$table,
		array(
			'record_id'  => 0,
			'event'      => 'refused',
			'site'       => $site,
			'note'       => substr( $note, 0, 255 ),
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		),
		array( '%d', '%s', '%s', '%s', '%s' )
	);

	// Keep the newest rows; one write in twenty trims the rest.
	if ( 1 === wp_rand( 1, 20 ) ) {
		_spilno_journal_trim();
	}
}

/**
 * Deletes refusal rows beyond the newest `SPILNO_JOURNAL_KEEP`, at most 500
 * per call.
 *
 * @return void
 */
function _spilno_journal_trim() {
	global $wpdb;

	$table = _spilno_tables()['log'];
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name; journal maintenance.
	$edge = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE record_id = 0 AND event = 'refused' ORDER BY id DESC LIMIT 1 OFFSET %d", SPILNO_JOURNAL_KEEP ) );

	if ( null !== $edge ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE record_id = 0 AND event = 'refused' AND id <= %d ORDER BY id LIMIT 500", (int) $edge ) );
	}
	// phpcs:enable
}

/**
 * Banner for administrators: on staging / production an empty allowlist
 * refuses every request of the partner.
 *
 * @return void
 */
function _spilno_sync_notices() {
	if ( ! current_user_can( 'manage_options' ) || ! _spilno_allowlist_missing() ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Spilno: SPILNO_PEER_IPS is empty, so the synchronisation endpoint refuses every request. Add the partner server address to wp-config.php.', 'spilno' )
	);
}

/**
 * Whether the link is configured on a non-development environment without an
 * allowlist.
 *
 * @param string|null $env Environment; the current one when null.
 * @return bool
 */
function _spilno_allowlist_missing( $env = null ) {
	$env    = null === $env ? wp_get_environment_type() : $env;
	$config = _spilno_sync_config( $env );

	return $config['ok'] && ! $config['ips'] && ! _spilno_dev_env( $env );
}
