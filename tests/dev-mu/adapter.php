<?php
/**
 * Dev-only harness adapter: plays the role of a site's adapter on the
 * spilno-dev stand. Symlinked into wp-content/mu-plugins/.
 *
 * - test template directory (SPILNO_DEV_TEMPLATES_DIR);
 * - contract filters answered from plain WordPress pages;
 * - `spilno_adopt_map` from the JSON meta `_spilno_test_map`;
 * - `POST /?spilno_test=parse` — a form endpoint that answers JSON of
 *   spilno_parse() for the test template (malformed input over real HTTP).
 *
 * @package SpilnoTest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SPILNO_DEV_TEMPLATES_DIR', __DIR__ . '/templates' );

add_filter(
	'spilno_can',
	static function ( $can, $action ) {
		return 'edit' === $action ? current_user_can( 'edit_pages' ) : current_user_can( 'manage_options' );
	},
	10,
	2
);

add_filter(
	'spilno_resolve_url',
	static function ( $answer, $url ) {
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return new WP_Error( 'foreign_host', 'Foreign host.' );
		}

		$id = url_to_postid( $url );

		if ( ! $id ) {
			return new WP_Error( 'not_found', 'Not found.' );
		}

		$template = (string) get_post_meta( $id, '_spilno_test_template', true );

		if ( '' === $template ) {
			return new WP_Error( 'not_spilno', 'Not a spilno page.' );
		}

		return array(
			'record_id' => $id,
			'template'  => $template,
			'status'    => get_post_status( $id ),
		);
	},
	10,
	2
);

add_filter(
	'spilno_record_url',
	static function ( $url, $id ) {
		return (string) get_permalink( $id );
	},
	10,
	2
);

add_filter(
	'spilno_site_label',
	static function () {
		return 'Spilno dev';
	}
);

add_filter(
	'spilno_adopt_map',
	static function ( $map, $id ) {
		$json = get_post_meta( $id, '_spilno_test_map', true );

		return is_string( $json ) && '' !== $json ? json_decode( $json, true ) : $map;
	},
	10,
	2
);

add_action(
	'init',
	static function () {
		// phpcs:disable WordPress.Security.NonceVerification -- dev harness only.
		if ( ! isset( $_GET['spilno_test'] ) || 'parse' !== $_GET['spilno_test'] || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		$raw = isset( $_POST['spilno'] ) ? wp_unslash( $_POST['spilno'] ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw input goes to spilno_parse().
		// phpcs:enable

		wp_send_json( spilno_parse( 'test', $raw ) );
	},
	20
);
