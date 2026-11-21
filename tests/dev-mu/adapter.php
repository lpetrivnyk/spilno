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

/*
 * Slots of the about template, as the reference site answers them on its
 * stand (links to the same paths, figures and news of its stand data).
 * Option `spilno_test_slots`: '' — all filled; 'data-empty' — facts and news
 * null (as on the second site); 'all-empty' — data null and no link targets.
 */
add_filter(
	'spilno_slot_link',
	static function ( $url, $slot ) {
		if ( 'all-empty' === get_option( 'spilno_test_slots' ) ) {
			return '';
		}

		$partner = 'https://bpr-okl.ddev.site:33001';
		$map     = array(
			'departments' => home_url( '/departments/' ),
			'patients'    => home_url( '/patients/' ),
			'medics'      => $partner . '/',
			'vacancies'   => $partner . '/vacancies/',
			'history'     => home_url( '/history/' ),
			'director'    => home_url( '/leadership/' ),
			'leadership'  => home_url( '/leadership/' ),
			'reports'     => home_url( '/reports/' ),
			'contacts'    => home_url( '/contacts/' ),
		);

		return $map[ $slot ] ?? $url;
	},
	10,
	2
);

add_filter(
	'spilno_slot_data',
	static function ( $data, $slot ) {
		if ( '' !== (string) get_option( 'spilno_test_slots' ) ) {
			return null;
		}

		if ( 'facts' === $slot ) {
			return array(
				'founded'       => array( 'value' => '1949' ),
				'beds'          => array( 'value' => '1 200', 'label' => 'Ліжко-місць' ),
				'specialties'   => array( 'value' => '36', 'label' => 'Спеціальностей' ),
				'doctors'       => array( 'value' => '508', 'label' => 'лікарів' ),
				'nurses'        => array( 'value' => '702', 'label' => 'середніх медичних працівників' ),
				'junior_staff'  => array( 'value' => '172', 'label' => 'молодшого медичного персоналу' ),
				'other_staff'   => array( 'value' => '297', 'label' => 'господарського та іншого персоналу' ),
				'accreditation' => array( 'value' => 'Акредитація вищої категорії' ),
				'license'       => array( 'value' => 'Ліцензія МОЗ №519 від 24.02.2020' ),
				'nhsu'          => array( 'value' => 'Договір з НСЗУ' ),
			);
		}

		if ( 'news' === $slot ) {
			return array(
				array( 'title' => 'Лікарня встановила систему резервного живлення', 'url' => home_url( '/news/likarnia-vstanovyla-systemu-rezervnoho-zhyvlennia/' ), 'meta' => '10 серпня 2026 · для пацієнтів' ),
				array( 'title' => 'Пам’ятка для пацієнтів перед КТ-дослідженням1', 'url' => home_url( '/news/pamiatka-dlia-patsiientiv-pered-kt-doslidzhenniam1/' ), 'meta' => '6 серпня 2026 · закупівлі' ),
				array( 'title' => 'Скринінг здоровʼя 40+ тепер і у вечірні години', 'url' => home_url( '/news/skryninh-zdorovia-40-teper-i-u-vechirni-hodyny/' ), 'meta' => '4 серпня 2026 · для пацієнтів' ),
				array( 'title' => 'Четверта новина не показується', 'url' => home_url( '/news/4/' ), 'meta' => '' ),
			);
		}

		return $data;
	},
	10,
	2
);

// Outside production: photo placeholders, as on the reference stand.
add_filter( 'spilno_is_preview', static fn() => 'production' !== wp_get_environment_type() );
