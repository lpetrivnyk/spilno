<?php
/**
 * «Check the link» (plan §E3): the steps from the constants to a signed
 * `ping` in both directions, each with its result. CLI `wp spilno ping`
 * now; the button of the registry screen uses the same rows.
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the steps. A failed step stops the steps that depend on it.
 *
 * @param bool $reverse Ask the partner to ping this site too (§E3.6).
 * @return array<int, array{status: string, step: string, message: string}> Status: `ok`, `info`, `warn`, `fail`.
 */
function _spilno_link_check( $reverse = true ) {
	$out = array();
	$add = static function ( $status, $step, $message ) use ( &$out ) {
		$out[] = array(
			'status'  => $status,
			'step'    => $step,
			'message' => $message,
		);
	};

	$env    = wp_get_environment_type();
	$config = _spilno_sync_config( $env );

	// 1. Constants.
	if ( ! $config['configured'] ) {
		$add( 'info', 'constants', __( 'The link is not configured: pages work locally.', 'spilno' ) );
		return $out;
	}

	if ( ! $config['ok'] ) {
		foreach ( $config['problems'] as $problem ) {
			$add( 'fail', 'constants', $problem );
		}
		return $out;
	}

	/* translators: 1: this site's identifier, 2: partner's identifier, 3: partner URL, 4: environment. */
	$add( 'ok', 'constants', sprintf( __( 'Site %1$s, partner %2$s at %3$s, environment %4$s.', 'spilno' ), $config['site'], $config['peer'], $config['url'], $env ) );

	if ( ! $config['ips'] ) {
		if ( _spilno_dev_env( $env ) ) {
			$add( 'info', 'constants', __( 'SPILNO_PEER_IPS is empty: requests from any address are accepted (local / development only).', 'spilno' ) );
		} else {
			$add( 'fail', 'constants', __( 'SPILNO_PEER_IPS is empty: this site refuses every request of the partner. Add the partner server address.', 'spilno' ) );
		}
	}

	$host = $config['host'];
	$port = $config['port'];

	// 2. DNS.
	if ( false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
		/* translators: %s: IP address. */
		$add( 'info', 'dns', sprintf( __( '%s is an IP address.', 'spilno' ), $host ) );
	} else {
		$ips = gethostbynamel( $host );

		if ( false === $ips ) {
			/* translators: %s: host name. */
			$add( 'fail', 'dns', sprintf( __( 'The name %s does not resolve.', 'spilno' ), $host ) );
			return $out;
		}

		/* translators: 1: host name, 2: addresses. */
		$add( 'ok', 'dns', sprintf( __( '%1$s → %2$s', 'spilno' ), $host, implode( ', ', $ips ) ) );
	}

	// 3. TCP, and WordPress's own block of outgoing requests.
	$http = _wp_http_get_object();

	if ( $http->block_request( $config['url'] . SPILNO_ENDPOINT_PREFIX . 'ping' ) ) {
		/* translators: %s: host name. */
		$add( 'fail', 'tcp', sprintf( __( 'WP_HTTP_BLOCK_EXTERNAL is on in wp-config.php and %s is not in WP_ACCESSIBLE_HOSTS: add it there.', 'spilno' ), $host ) );
		return $out;
	}

	$errno  = 0;
	$errstr = '';
	$socket = @stream_socket_client( 'tcp://' . $host . ':' . $port, $errno, $errstr, SPILNO_CONNECT_TIMEOUT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the error is reported as the step result.

	if ( false === $socket ) {
		/* translators: 1: host name, 2: error, 3: host name, 4: port. */
		$add( 'fail', 'tcp', sprintf( __( 'The server cannot open a connection to %1$s: %2$s. Ask the hosting to allow outgoing HTTPS to %3$s:%4$d.', 'spilno' ), $host, '' !== $errstr ? $errstr : (string) $errno, $host, $port ) );
		return $out;
	}

	fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a socket.
	/* translators: 1: host name, 2: port. */
	$add( 'ok', 'tcp', sprintf( __( 'Connection to %1$s:%2$d opens.', 'spilno' ), $host, $port ) );

	// 4. TLS: the same trust as WordPress requests over curl (its bundle and the system store).
	$locations = openssl_get_cert_locations();
	$context   = stream_context_create(
		array(
			'ssl' => array(
				'verify_peer'       => true,
				'verify_peer_name'  => true,
				'peer_name'         => trim( $host, '[]' ),
				'cafile'            => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
				'capath'            => $locations['default_cert_dir'] ?? '',
				'capture_peer_cert' => true,
			),
		)
	);
	$tls       = @stream_socket_client( 'ssl://' . $host . ':' . $port, $errno, $errstr, SPILNO_CONNECT_TIMEOUT, STREAM_CLIENT_CONNECT, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the error is reported as the step result.

	if ( false === $tls ) {
		/* translators: 1: host name, 2: error. */
		$add( 'fail', 'tls', sprintf( __( 'The certificate of %1$s is not trusted or does not match the name: %2$s', 'spilno' ), $host, '' !== $errstr ? $errstr : __( 'handshake failed', 'spilno' ) ) );
		return $out;
	}

	$params = stream_context_get_params( $tls );
	$cert   = isset( $params['options']['ssl']['peer_certificate'] ) ? openssl_x509_parse( $params['options']['ssl']['peer_certificate'] ) : false;
	fclose( $tls ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- a socket.

	if ( is_array( $cert ) ) {
		/* translators: 1: issuer, 2: expiry date. */
		$add( 'ok', 'tls', sprintf( __( 'Certificate verified: issued by %1$s, valid until %2$s.', 'spilno' ), $cert['issuer']['O'] ?? ( $cert['issuer']['CN'] ?? '?' ), gmdate( 'Y-m-d', (int) ( $cert['validTo_time_t'] ?? 0 ) ) ) );
	} else {
		$add( 'ok', 'tls', __( 'Certificate verified.', 'spilno' ) );
	}

	// 5. Signed ping.
	$sent = time();
	$pong = _spilno_peer_request( 'ping' );

	if ( is_wp_error( $pong ) ) {
		/* translators: %s: error. */
		$add( 'fail', 'ping', sprintf( __( 'The request failed: %s', 'spilno' ), $pong->get_error_message() ) );
		return $out;
	}

	if ( 200 !== $pong['status'] || ! is_array( $pong['body'] ) || empty( $pong['body']['ok'] ) ) {
		/* translators: %d: HTTP status. */
		$add( 'fail', 'ping', sprintf( __( 'The partner refused: HTTP %d. The reason is in the partner\'s journal (secret, environment, site identifier, address or clock).', 'spilno' ), $pong['status'] ) );
		return $out;
	}

	foreach ( _spilno_pong_compare( $pong['body'], $config, $env, $sent + $pong['elapsed'] / 2 ) as $row ) {
		$add( $row[0], $row[1], $row[2] );
	}

	// 6. The other direction: the partner pings this site.
	if ( $reverse ) {
		$back = _spilno_peer_request( 'ping/back' );

		if ( is_wp_error( $back ) || 200 !== $back['status'] || ! is_array( $back['body'] ) ) {
			/* translators: %s: error or HTTP status. */
			$add( 'fail', 'reverse', sprintf( __( 'The partner did not run the check: %s', 'spilno' ), is_wp_error( $back ) ? $back->get_error_message() : 'HTTP ' . $back['status'] ) );
		} elseif ( ! empty( $back['body']['ok'] ) ) {
			$add( 'ok', 'reverse', __( 'The partner reaches this site: signed ping accepted.', 'spilno' ) );
		} else {
			$error = isset( $back['body']['error'] ) && is_string( $back['body']['error'] ) ? $back['body']['error'] : '';
			$code  = isset( $back['body']['status'] ) ? (int) $back['body']['status'] : 0;
			/* translators: %s: error or HTTP status. */
			$add( 'fail', 'reverse', sprintf( __( 'The partner cannot reach this site: %s. Check its outgoing HTTPS and this site\'s SPILNO_PEER_IPS.', 'spilno' ), '' !== $error ? $error : 'HTTP ' . $code ) );
		}
	}

	return $out;
}

/**
 * Compares the partner's `pong` with this site.
 *
 * @param array  $pong   Answer of `ping`.
 * @param array  $config This site's link configuration.
 * @param string $env    This site's environment.
 * @param float  $middle This site's time in the middle of the request.
 * @return array<int, array{0: string, 1: string, 2: string}>
 */
function _spilno_pong_compare( array $pong, array $config, $env, $middle ) {
	$rows = array();

	if ( ( $pong['site'] ?? '' ) !== $config['peer'] ) {
		/* translators: 1: identifier received, 2: identifier expected. */
		$rows[] = array( 'fail', 'site', sprintf( __( 'The partner calls itself %1$s, expected %2$s.', 'spilno' ), (string) ( $pong['site'] ?? '' ), $config['peer'] ) );
	} else {
		/* translators: %s: site identifier. */
		$rows[] = array( 'ok', 'site', sprintf( __( 'Partner %s answers.', 'spilno' ), $config['peer'] ) );
	}

	if ( ( $pong['env'] ?? '' ) !== $env ) {
		/* translators: 1: partner's environment, 2: this site's environment. */
		$rows[] = array( 'fail', 'env', sprintf( __( 'Partner environment %1$s, this site %2$s: pairs are local ↔ local, staging ↔ staging, production ↔ production.', 'spilno' ), (string) ( $pong['env'] ?? '' ), $env ) );
	} else {
		/* translators: %s: environment. */
		$rows[] = array( 'ok', 'env', sprintf( __( 'Both sites are %s.', 'spilno' ), $env ) );
	}

	if ( (int) ( $pong['api'] ?? 0 ) !== SPILNO_API ) {
		/* translators: 1: partner's API version, 2: this site's API version. */
		$rows[] = array( 'fail', 'version', sprintf( __( 'Contract version of the partner %1$d, of this site %2$d: install the same plugin tag on both.', 'spilno' ), (int) ( $pong['api'] ?? 0 ), SPILNO_API ) );
	} elseif ( ( $pong['version'] ?? '' ) !== SPILNO_VERSION ) {
		/* translators: 1: partner's plugin version, 2: this site's plugin version. */
		$rows[] = array( 'warn', 'version', sprintf( __( 'Plugin %1$s on the partner, %2$s here: the same tag on both is expected.', 'spilno' ), (string) ( $pong['version'] ?? '' ), SPILNO_VERSION ) );
	} else {
		/* translators: %s: plugin version. */
		$rows[] = array( 'ok', 'version', sprintf( __( 'Plugin %s on both.', 'spilno' ), SPILNO_VERSION ) );
	}

	$theirs = isset( $pong['schemas'] ) && is_array( $pong['schemas'] ) ? $pong['schemas'] : array();
	$same   = array();

	foreach ( array_keys( spilno_templates() + $theirs ) as $key ) {
		$mine = isset( spilno_templates()[ $key ] ) ? spilno_schema_hash( $key ) : null;
		$peer = isset( $theirs[ $key ] ) && is_string( $theirs[ $key ] ) ? $theirs[ $key ] : null;

		if ( null === $peer ) {
			/* translators: %s: template key. */
			$rows[] = array( 'warn', 'schema', sprintf( __( 'Template %s is missing on the partner.', 'spilno' ), $key ) );
		} elseif ( null === $mine ) {
			/* translators: %s: template key. */
			$rows[] = array( 'warn', 'schema', sprintf( __( 'Template %s is missing on this site.', 'spilno' ), $key ) );
		} elseif ( ! hash_equals( $mine, $peer ) ) {
			/* translators: %s: template key. */
			$rows[] = array( 'warn', 'schema', sprintf( __( 'Template %s differs between the sites: its pages will not synchronise.', 'spilno' ), $key ) );
		} else {
			$same[] = $key;
		}
	}

	if ( $same ) {
		/* translators: %s: template keys. */
		$rows[] = array( 'ok', 'schema', sprintf( __( 'Same schemas: %s.', 'spilno' ), implode( ', ', $same ) ) );
	}

	$offset = (int) round( (float) ( $pong['time'] ?? 0 ) - $middle );

	if ( abs( $offset ) > SPILNO_CLOCK_SKEW ) {
		/* translators: %d: seconds. */
		$rows[] = array( 'fail', 'clock', sprintf( __( 'The clocks differ by %d s: requests are refused beyond 300 s. Set up time synchronisation (NTP) on the servers.', 'spilno' ), $offset ) );
	} elseif ( abs( $offset ) >= SPILNO_CLOCK_WARN ) {
		/* translators: %d: seconds. */
		$rows[] = array( 'warn', 'clock', sprintf( __( 'The clocks differ by %d s (refused from 300 s). Set up time synchronisation (NTP) on the servers.', 'spilno' ), $offset ) );
	} else {
		/* translators: %d: seconds. */
		$rows[] = array( 'ok', 'clock', sprintf( __( 'Clock difference %d s.', 'spilno' ), $offset ) );
	}

	return $rows;
}

/**
 * `ping/back` on the receiver: pings the sender back and reports whether
 * that worked (§E3.6).
 *
 * @return array
 */
function _spilno_op_ping_back() {
	$pong = _spilno_peer_request( 'ping' );

	if ( is_wp_error( $pong ) ) {
		return array(
			'ok'     => false,
			'status' => 0,
			'error'  => substr( $pong->get_error_message(), 0, 200 ),
		);
	}

	return array(
		'ok'     => 200 === $pong['status'] && is_array( $pong['body'] ) && ! empty( $pong['body']['ok'] ),
		'status' => $pong['status'],
		'error'  => '',
	);
}
