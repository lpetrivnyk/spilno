<?php
/**
 * The synchronisation endpoint `/wp-spilno/v1/*` (plan §B).
 *
 * An early interceptor on `plugins_loaded:1` matches the request path
 * exactly, answers and exits: earlier than `init`, `wp_loaded`,
 * `admin_init`, the `request` filter and `template_redirect`, where the sites
 * redirect guests. No rewrite rules. No cookies, sessions or current user.
 *
 * Checks, in this order: path → method → IP → rate limit → size → headers →
 * environment and site → time → signature → nonce → body. A refusal tells the
 * sender only its status; the reason goes to the receiver's journal.
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'plugins_loaded', '_spilno_endpoint_intercept', 1 );

/**
 * Operations of the endpoint: path after the prefix → method and handler.
 *
 * Stage 4a has `ping` and `ping/back` (the partner pings the sender back,
 * «Check the link» §E3.6). `dev/echo` (POST with a JSON body) exists only on
 * `local` / `development` with `SPILNO_DEV_ENDPOINT` set by the test harness:
 * it exercises the body checks before the real POST operations arrive.
 *
 * @return array<string, array{method: string, handler: callable, fields?: array<string, int>}> `fields`: allowed body keys → maximum length of their string value.
 */
function _spilno_endpoint_ops() {
	$ops = array(
		'ping'      => array(
			'method'  => 'GET',
			'handler' => '_spilno_op_ping',
		),
		'ping/back' => array(
			'method'  => 'GET',
			'handler' => '_spilno_op_ping_back',
		),
	);

	if ( _spilno_dev_env() && defined( 'SPILNO_DEV_ENDPOINT' ) && true === SPILNO_DEV_ENDPOINT ) {
		$ops['dev/echo'] = array(
			'method'  => 'POST',
			'handler' => '_spilno_op_dev_echo',
			'fields'  => array( 'text' => 200 ),
		);
	}

	return $ops;
}

/**
 * Whether a request URI is under the endpoint; null when it is not.
 *
 * @param string $uri       REQUEST_URI.
 * @param string $home_path Path of the home URL, no trailing slash.
 * @return array{path: string, op: string, query: bool}|null `op` is '' when the path is under `/wp-spilno/` but not a known form.
 */
function _spilno_endpoint_match( $uri, $home_path ) {
	$cut   = strpos( $uri, '?' );
	$path  = false === $cut ? $uri : substr( $uri, 0, $cut );
	$root  = $home_path . '/wp-spilno';
	$known = $home_path . SPILNO_ENDPOINT_PREFIX;

	if ( $path !== $root && ! str_starts_with( $path, $root . '/' ) ) {
		return null;
	}

	return array(
		'path'  => $path,
		'op'    => str_starts_with( $path, $known ) ? substr( $path, strlen( $known ) ) : '',
		'query' => false !== $cut,
	);
}

/**
 * The interceptor. Leaves every other request to WordPress.
 *
 * @return void
 */
function _spilno_endpoint_intercept() {
	if ( ! isset( $_SERVER['REQUEST_URI'] ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	$uri   = (string) $_SERVER['REQUEST_URI']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared byte for byte, never printed.
	$match = _spilno_endpoint_match( $uri, rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' ) );

	if ( null === $match ) {
		return;
	}

	// No user for this request, whatever cookies it carries.
	wp_set_current_user( 0 );

	// Answers read template schemas (their labels go through __()): load the
	// translations now; when there are none for the locale known this early
	// (a theme may set it later), unload the domain so that no just-in-time
	// load runs before `init` — the answers are machine-read.
	_spilno_load_textdomain();

	if ( ! is_textdomain_loaded( 'spilno' ) ) {
		// unload_textdomain() marks only a loaded domain; the override marks this one.
		$mark = static function ( $override, $domain ) {
			return 'spilno' === $domain ? true : $override;
		};
		add_filter( 'override_unload_textdomain', $mark, 10, 2 );
		unload_textdomain( 'spilno' );
		remove_filter( 'override_unload_textdomain', $mark, 10 );
	}

	$header = static function ( $name ) {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );

		return isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? wp_unslash( $_SERVER[ $key ] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked by format later.
	};

	$req = array(
		'method'  => isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( $_SERVER['REQUEST_METHOD'] ) ) : '',
		'path'    => $match['path'],
		'op'      => $match['op'],
		'query'   => $match['query'],
		'ip'      => isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? _spilno_normal_ip( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized as an IP.
		'length'  => isset( $_SERVER['CONTENT_LENGTH'] ) && is_string( $_SERVER['CONTENT_LENGTH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : null,
		'type'    => isset( $_SERVER['CONTENT_TYPE'] ) && is_string( $_SERVER['CONTENT_TYPE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) : '',
		'headers' => array(
			'site'  => $header( 'X-Spilno-Site' ),
			'env'   => $header( 'X-Spilno-Env' ),
			'time'  => $header( 'X-Spilno-Time' ),
			'nonce' => $header( 'X-Spilno-Nonce' ),
			'sig'   => $header( 'X-Spilno-Sig' ),
		),
	);

	$result = _spilno_endpoint_handle(
		$req,
		static function ( $max ) {
			return (string) file_get_contents( 'php://input', false, null, 0, $max ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- request body.
		}
	);

	_spilno_endpoint_send( $result['status'], $result['body'] );
	exit;
}

/**
 * Runs the checks and the operation.
 *
 * @param array    $req  Request facts (see `_spilno_endpoint_intercept()`).
 * @param callable $read Reads at most N bytes of the body.
 * @param int|null $now  Unix time; now when null.
 * @return array{status: int, body: array, reason: string}
 */
function _spilno_endpoint_handle( array $req, callable $read, $now = null ) {
	$now    = null === $now ? time() : (int) $now;
	$env    = wp_get_environment_type();
	$config = _spilno_sync_config( $env );
	$ops    = _spilno_endpoint_ops();
	$facts  = array(
		'op'   => $req['op'],
		'ip'   => $req['ip'],
		'site' => is_string( $req['headers']['site'] ) ? $req['headers']['site'] : '',
	);
	$refuse = static function ( $status, $reason, $journal = true ) use ( &$facts ) {
		if ( $journal ) {
			_spilno_journal_refusal( $status, $reason, $facts );
		}

		return array(
			'status' => $status,
			'body'   => array( 'ok' => false ),
			'reason' => $reason,
		);
	};

	// Path: switched off, not configured, unknown form, a query — no endpoint.
	if ( ! spilno_enabled() || ! $config['ok'] || $req['query'] || ! isset( $ops[ $req['op'] ] ) ) {
		return $refuse( 404, 'path', false );
	}

	$op = $ops[ $req['op'] ];

	// Method.
	if ( $req['method'] !== $op['method'] ) {
		return $refuse( 404, 'method', false );
	}

	// IP. The journal gets at most one such row per 10 s: anybody can send these.
	if ( ! _spilno_ip_allowed( $req['ip'], $config['ips'], $env ) ) {
		$journal = false === get_transient( 'spilno_jq_ip' );

		if ( $journal ) {
			set_transient( 'spilno_jq_ip', 1, 10 );
		}

		return $refuse( 403, $config['ips'] ? 'ip' : 'allowlist-empty', $journal );
	}

	// Rate limit and block: the address plus the claimed site.
	$guard         = _spilno_guard_key( $req['ip'], $facts['site'], $config['peer'] );
	$facts['site'] = '*' === $guard['site'] ? '*' . substr( $facts['site'], 0, 31 ) : $guard['site'];
	$count         = _spilno_guard_count( $guard['key'], $now );

	if ( '' !== $count['refuse'] ) {
		return $refuse( 403, $count['refuse'], $count['first'] );
	}

	// Size, before reading.
	$length = $req['length'];

	if ( null !== $length && '' !== $length && ! ctype_digit( $length ) ) {
		return $refuse( 400, 'length' );
	}

	if ( null !== $length && '' !== $length && (int) $length > SPILNO_BODY_MAX ) {
		return $refuse( 413, 'size' );
	}

	$body = $read( SPILNO_BODY_MAX + 1 );

	if ( strlen( $body ) > SPILNO_BODY_MAX ) {
		return $refuse( 413, 'size' );
	}

	// Headers.
	$h = $req['headers'];

	if ( ! _spilno_valid_site_id( $h['site'] )
		|| ! is_string( $h['env'] ) || ! in_array( $h['env'], array( 'local', 'development', 'staging', 'production' ), true )
		|| ! is_string( $h['time'] ) || 1 !== preg_match( '/^[0-9]{1,12}$/', $h['time'] )
		|| ! is_string( $h['nonce'] ) || 1 !== preg_match( '/^[0-9a-f]{32}$/', $h['nonce'] )
		|| ! is_string( $h['sig'] ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $h['sig'] ) ) {
		return $refuse( 401, 'headers' );
	}

	// Environment and site: stage never writes to production (§E1).
	if ( $h['env'] !== $env ) {
		return $refuse( 403, 'env ' . $h['env'] );
	}

	if ( $h['site'] !== $config['peer'] ) {
		return $refuse( 403, 'site' );
	}

	// Time.
	$time = (int) $h['time'];

	if ( abs( $now - $time ) > SPILNO_CLOCK_SKEW ) {
		return $refuse( 401, 'time ' . ( $time - $now ) );
	}

	// Signature: the current secret, then the previous one (rotation).
	$signed  = _spilno_sign_string( $req['method'], $req['path'], $h['site'], $h['env'], $time, $h['nonce'], $body );
	$matched = '';

	foreach ( $config['secrets'] as $i => $secret ) {
		if ( hash_equals( _spilno_sign( $secret, $signed ), $h['sig'] ) ) {
			$matched = 0 === $i ? 'current' : 'previous';
			break;
		}
	}

	if ( '' === $matched ) {
		$blocked = _spilno_guard_bad_signature( $guard['key'], $now );

		return $refuse( 401, $blocked ? 'signature, blocked' : 'signature' );
	}

	// Nonce.
	$nonce = _spilno_nonce_use( $h['site'], $h['nonce'], $time, $now );

	if ( 'stored' !== $nonce ) {
		return 'used' === $nonce ? $refuse( 401, 'replay' ) : $refuse( 503, 'nonce store' );
	}

	// Body.
	if ( 'GET' === $op['method'] ) {
		if ( '' !== $body ) {
			return $refuse( 400, 'body on GET' );
		}

		$data = array();
	} else {
		if ( ! str_starts_with( strtolower( $req['type'] ), 'application/json' ) ) {
			return $refuse( 400, 'content type' );
		}

		$data = json_decode( $body, true, 16 );

		if ( ! is_array( $data ) || ( $data && array_is_list( $data ) ) ) {
			return $refuse( 400, 'not a JSON object' );
		}

		$fields = $op['fields'] ?? array();

		foreach ( $data as $key => $value ) {
			if ( ! isset( $fields[ $key ] ) ) {
				return $refuse( 400, 'unknown field ' . substr( (string) $key, 0, 40 ) );
			}

			if ( ! is_string( $value ) || mb_strlen( $value ) > $fields[ $key ] ) {
				return $refuse( 400, 'field ' . $key );
			}
		}

		foreach ( array_keys( $fields ) as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				return $refuse( 400, 'missing field ' . $key );
			}
		}
	}

	$answer = call_user_func( $op['handler'], $data, array( 'secret' => $matched ) );

	return array(
		'status' => 200,
		'body'   => $answer,
		'reason' => '',
	);
}

/**
 * Sends the answer: JSON, never cached or indexed, no cookies.
 *
 * @param int   $status HTTP status.
 * @param array $body   Answer.
 * @return void
 */
function _spilno_endpoint_send( $status, array $body ) {
	if ( headers_sent() ) {
		return;
	}

	header_remove( 'Set-Cookie' );
	status_header( $status );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store, max-age=0' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'X-Content-Type-Options: nosniff' );

	echo wp_json_encode( $body );
}

/**
 * `ping`: who answers and with what (§E3.5).
 *
 * @return array
 */
function _spilno_op_ping() {
	$schemas = array();

	foreach ( array_keys( spilno_templates() ) as $key ) {
		$schemas[ $key ] = spilno_schema_hash( $key );
	}

	return array(
		'ok'      => true,
		'site'    => _spilno_site_id(),
		'env'     => wp_get_environment_type(),
		'version' => SPILNO_VERSION,
		'api'     => SPILNO_API,
		'schemas' => $schemas,
		'time'    => time(),
	);
}

/**
 * `dev/echo` (test harness only): the body back, the current user and which
 * secret matched.
 *
 * @param array $data Checked body.
 * @param array $ctx  `secret`: `current` or `previous`.
 * @return array
 */
function _spilno_op_dev_echo( array $data, array $ctx ) {
	return array(
		'ok'     => true,
		'text'   => $data['text'],
		'user'   => get_current_user_id(),
		'secret' => $ctx['secret'],
	);
}
