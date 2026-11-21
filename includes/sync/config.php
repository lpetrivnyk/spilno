<?php
/**
 * Link constants of a site (plan §E1) and the signature of a request (§B5).
 *
 * The constants live in wp-config.php of each site:
 * `SPILNO_SITE_ID`, `SPILNO_PEER_SITE_ID`, `SPILNO_PEER_URL`,
 * `SPILNO_PEER_IPS`, `SPILNO_SECRET`, `SPILNO_SECRET_PREVIOUS`. Without them
 * the link is «not configured»: the endpoint does not exist and pages work
 * locally.
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Path of the endpoint under the site's home path.
 */
const SPILNO_ENDPOINT_PREFIX = '/wp-spilno/v1/';

/**
 * Largest accepted request body, bytes (§B7).
 */
const SPILNO_BODY_MAX = 262144;

/**
 * Accepted clock difference between the two sites, seconds (§B6).
 */
const SPILNO_CLOCK_SKEW = 300;

/**
 * Clock difference from which the link check warns, seconds (§E2).
 */
const SPILNO_CLOCK_WARN = 60;

/**
 * A nonce is kept this long after its request time stops being accepted,
 * seconds.
 */
const SPILNO_NONCE_MARGIN = 60;

/**
 * Smallest secret, bytes.
 */
const SPILNO_SECRET_MIN = 32;

/**
 * Whether the environment is a development one (`local` / `development`).
 *
 * @param string|null $env Environment; the current one when null.
 * @return bool
 */
function _spilno_dev_env( $env = null ) {
	return in_array( null === $env ? wp_get_environment_type() : $env, array( 'local', 'development' ), true );
}

/**
 * Whether a site identifier is well-formed.
 *
 * @param mixed $id Identifier.
 * @return bool
 */
function _spilno_valid_site_id( $id ) {
	return is_string( $id ) && 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,31}$/', $id );
}

/**
 * The link configuration of this site, checked.
 *
 * `configured` — at least one link constant is defined; `ok` — every one is
 * present and well-formed (only then the endpoint exists and requests go
 * out). Problems are English diagnostics for doctor and the link check.
 *
 * @param string|null $env Environment; the current one when null.
 * @return array{configured: bool, ok: bool, problems: string[], site: string, peer: string, url: string, host: string, port: int, path: string, ips: string[], secrets: string[]}
 */
function _spilno_sync_config( $env = null ) {
	$env   = null === $env ? wp_get_environment_type() : $env;
	$names = array( 'SPILNO_SITE_ID', 'SPILNO_PEER_SITE_ID', 'SPILNO_PEER_URL', 'SPILNO_PEER_IPS', 'SPILNO_SECRET', 'SPILNO_SECRET_PREVIOUS' );
	$value = static function ( $name ) {
		return defined( $name ) ? constant( $name ) : null;
	};

	$out = array(
		'configured' => false,
		'ok'         => false,
		'problems'   => array(),
		'site'       => '',
		'peer'       => '',
		'url'        => '',
		'host'       => '',
		'port'       => 443,
		'path'       => '',
		'ips'        => array(),
		'secrets'    => array(),
	);

	foreach ( $names as $name ) {
		if ( defined( $name ) ) {
			$out['configured'] = true;
		}
	}

	if ( ! $out['configured'] ) {
		return $out;
	}

	$problems = array();

	foreach ( array( 'SPILNO_SITE_ID', 'SPILNO_PEER_SITE_ID', 'SPILNO_PEER_URL', 'SPILNO_SECRET' ) as $name ) {
		if ( ! defined( $name ) ) {
			$problems[] = $name . ' is not defined';
		}
	}

	// Identifiers.
	$site = $value( 'SPILNO_SITE_ID' );
	$peer = $value( 'SPILNO_PEER_SITE_ID' );

	if ( null !== $site && ! _spilno_valid_site_id( $site ) ) {
		$problems[] = 'SPILNO_SITE_ID: lower-case letters, digits, - and _, at most 32';
	}

	if ( null !== $peer && ! _spilno_valid_site_id( $peer ) ) {
		$problems[] = 'SPILNO_PEER_SITE_ID: lower-case letters, digits, - and _, at most 32';
	}

	if ( null !== $site && $site === $peer ) {
		$problems[] = 'SPILNO_PEER_SITE_ID equals SPILNO_SITE_ID';
	}

	$out['site'] = _spilno_valid_site_id( $site ) ? $site : '';
	$out['peer'] = _spilno_valid_site_id( $peer ) ? $peer : '';

	// Partner URL: https only (§E2), no query, no credentials.
	$url = $value( 'SPILNO_PEER_URL' );

	if ( null !== $url ) {
		$parts = is_string( $url ) ? wp_parse_url( $url ) : false;

		if ( ! is_array( $parts ) || '' === ( $parts['host'] ?? '' ) ) {
			$problems[] = 'SPILNO_PEER_URL is not a URL';
		} elseif ( 'https' !== strtolower( $parts['scheme'] ?? '' ) ) {
			$problems[] = 'SPILNO_PEER_URL must start with https://';
		} elseif ( isset( $parts['query'] ) || isset( $parts['fragment'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			$problems[] = 'SPILNO_PEER_URL: only https://host[:port][/path]';
		} else {
			$out['host'] = strtolower( $parts['host'] );
			$out['port'] = isset( $parts['port'] ) ? (int) $parts['port'] : 443;
			$out['path'] = rtrim( $parts['path'] ?? '', '/' );
			$out['url']  = 'https://' . $out['host'] . ( isset( $parts['port'] ) ? ':' . $out['port'] : '' ) . $out['path'];

			// Production talks to a public partner only (§E1.6).
			if ( 'production' === $env ) {
				$host = trim( $out['host'], '[]' );

				if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) || 'localhost' === $host || str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.ddev.site' ) ) {
					$problems[] = 'SPILNO_PEER_URL on production must be a public host name (not an IP address, localhost or *.ddev.site)';
				}
			}
		}
	}

	// Partner IPs: a string separated by commas / spaces, or an array.
	$ips = $value( 'SPILNO_PEER_IPS' );

	if ( null !== $ips ) {
		$list = is_array( $ips ) ? $ips : ( is_string( $ips ) ? preg_split( '/[\s,]+/', $ips, -1, PREG_SPLIT_NO_EMPTY ) : null );

		if ( null === $list ) {
			$problems[] = 'SPILNO_PEER_IPS must be a string or an array';
		} else {
			foreach ( $list as $ip ) {
				$normal = _spilno_normal_ip( $ip );

				if ( '' === $normal ) {
					$problems[] = 'SPILNO_PEER_IPS: not an IP address: ' . ( is_scalar( $ip ) ? (string) $ip : gettype( $ip ) );
				} else {
					$out['ips'][] = $normal;
				}
			}

			$out['ips'] = array_values( array_unique( $out['ips'] ) );
		}
	}

	// Secrets.
	$secret   = $value( 'SPILNO_SECRET' );
	$previous = $value( 'SPILNO_SECRET_PREVIOUS' );

	if ( null !== $secret ) {
		if ( ! is_string( $secret ) || strlen( $secret ) < SPILNO_SECRET_MIN ) {
			$problems[] = 'SPILNO_SECRET: at least ' . SPILNO_SECRET_MIN . ' bytes';
		} else {
			$out['secrets'][] = $secret;
		}
	}

	if ( null !== $previous && '' !== $previous ) {
		if ( ! is_string( $previous ) || strlen( $previous ) < SPILNO_SECRET_MIN ) {
			$problems[] = 'SPILNO_SECRET_PREVIOUS: at least ' . SPILNO_SECRET_MIN . ' bytes (or empty)';
		} elseif ( $previous === $secret ) {
			$problems[] = 'SPILNO_SECRET_PREVIOUS equals SPILNO_SECRET';
		} else {
			$out['secrets'][] = $previous;
		}
	}

	$out['problems'] = $problems;
	$out['ok']       = ! $problems;

	return $out;
}

/**
 * An IP address in its canonical text form, '' when it is not one.
 *
 * @param mixed $ip Address.
 * @return string
 */
function _spilno_normal_ip( $ip ) {
	if ( ! is_string( $ip ) || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return '';
	}

	$packed = inet_pton( $ip );

	return false === $packed ? '' : (string) inet_ntop( $packed );
}

/**
 * The string a request signature covers (§B5).
 *
 * @param string $method HTTP method.
 * @param string $path   Request path as the receiver sees it (no query).
 * @param string $site   Sender's site identifier.
 * @param string $env    Sender's environment.
 * @param int    $time   Sender's Unix time.
 * @param string $nonce  32 hexadecimal characters.
 * @param string $body   Raw body ('' for GET).
 * @return string
 */
function _spilno_sign_string( $method, $path, $site, $env, $time, $nonce, $body ) {
	return implode( "\n", array( strtoupper( $method ), $path, $site, $env, (string) $time, $nonce, hash( 'sha256', $body ) ) );
}

/**
 * HMAC-SHA256 of the signed string, hexadecimal.
 *
 * @param string $secret Secret.
 * @param string $signed String from `_spilno_sign_string()`.
 * @return string
 */
function _spilno_sign( $secret, $signed ) {
	return hash_hmac( 'sha256', $signed, $secret );
}
