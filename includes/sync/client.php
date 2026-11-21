<?php
/**
 * Signed requests to the partner (plan §B5, §E2): every later operation goes
 * through `_spilno_peer_request()`.
 *
 * https only, certificate always verified, no redirects; connection timeout
 * 3 s, the whole request by operation.
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connection timeout, seconds (§E2).
 */
const SPILNO_CONNECT_TIMEOUT = 3;

/**
 * Timeout of a whole request by operation, seconds (§E2).
 *
 * @param string $op Operation.
 * @return int
 */
function _spilno_peer_timeout( $op ) {
	if ( str_starts_with( $op, 'media/' ) ) {
		return 30;
	}

	if ( 'ping' === $op || 'page/head' === $op ) {
		return 3;
	}

	return 5;
}

/**
 * Signature headers of a request.
 *
 * @param string $method HTTP method.
 * @param string $path   Path on the receiver.
 * @param string $body   Raw body.
 * @param string $secret Secret.
 * @param array  $sender `site`, `env`, `time`, `nonce` (all given).
 * @return array<string, string>
 */
function _spilno_sign_headers( $method, $path, $body, $secret, array $sender ) {
	$signed = _spilno_sign_string( $method, $path, $sender['site'], $sender['env'], (int) $sender['time'], $sender['nonce'], $body );

	return array(
		'X-Spilno-Site'  => $sender['site'],
		'X-Spilno-Env'   => $sender['env'],
		'X-Spilno-Time'  => (string) $sender['time'],
		'X-Spilno-Nonce' => $sender['nonce'],
		'X-Spilno-Sig'   => _spilno_sign( $secret, $signed ),
	);
}

/**
 * A signed request to the partner's endpoint.
 *
 * @param string     $op   Operation, e.g. `ping`.
 * @param array|null $data JSON body (POST); null — GET.
 * @return array{status: int, body: array|null, elapsed: float}|WP_Error
 */
function _spilno_peer_request( $op, $data = null ) {
	$config = _spilno_sync_config();

	if ( ! $config['configured'] ) {
		return new WP_Error( 'spilno_not_configured', __( 'The link is not configured.', 'spilno' ) );
	}

	if ( ! $config['ok'] ) {
		return new WP_Error( 'spilno_bad_config', implode( '; ', $config['problems'] ) );
	}

	$method = null === $data ? 'GET' : 'POST';
	$body   = null === $data ? '' : (string) wp_json_encode( $data );
	$path   = $config['path'] . SPILNO_ENDPOINT_PREFIX . $op;
	$sign   = _spilno_sign_headers(
		$method,
		$path,
		$body,
		$config['secrets'][0],
		array(
			'site'  => $config['site'],
			'env'   => wp_get_environment_type(),
			'time'  => time(),
			'nonce' => bin2hex( random_bytes( 16 ) ),
		)
	);

	return _spilno_peer_send( $method, $config['url'] . SPILNO_ENDPOINT_PREFIX . $op, $body, $sign, _spilno_peer_timeout( $op ) );
}

/**
 * Sends a prepared request: https only, certificate verified, no redirects,
 * connection timeout `SPILNO_CONNECT_TIMEOUT`.
 *
 * @param string $method  HTTP method.
 * @param string $url     Full https URL.
 * @param string $body    Raw body.
 * @param array  $headers Signature headers.
 * @param int    $timeout Whole request, seconds.
 * @return array{status: int, body: array|null, elapsed: float}|WP_Error
 */
function _spilno_peer_send( $method, $url, $body, array $headers, $timeout ) {
	if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
		return new WP_Error( 'spilno_not_https', __( 'Only https:// addresses are allowed for the partner.', 'spilno' ) );
	}

	if ( '' !== $body ) {
		$headers['Content-Type'] = 'application/json';
	}

	$headers['Accept'] = 'application/json';

	$connect = static function ( $handle, $args, $target ) use ( $url ) {
		if ( $target === $url && defined( 'CURLOPT_CONNECTTIMEOUT' ) ) {
			curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, SPILNO_CONNECT_TIMEOUT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- connection timeout of the WordPress request itself.
		}
	};

	add_action( 'http_api_curl', $connect, 10, 3 );

	$start    = microtime( true );
	$response = wp_remote_request(
		$url,
		array(
			'method'              => $method,
			'body'                => '' === $body ? null : $body,
			'headers'             => $headers,
			'timeout'             => $timeout,
			'redirection'         => 0,
			'sslverify'           => true,
			'user-agent'          => 'spilno/' . SPILNO_VERSION,
			'limit_response_size' => SPILNO_BODY_MAX,
			'cookies'             => array(),
		)
	);
	$elapsed  = microtime( true ) - $start;

	remove_action( 'http_api_curl', $connect, 10 );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true, 16 );

	return array(
		'status'  => (int) wp_remote_retrieve_response_code( $response ),
		'body'    => is_array( $decoded ) ? $decoded : null,
		'elapsed' => $elapsed,
	);
}
