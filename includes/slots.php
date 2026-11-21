<?php
/**
 * Data slots: the shapes the plugin accepts from `spilno_slot_data`.
 *
 * `facts` (phase 1 of the hospital figures, plan §D): an array «key =>
 * {value, label?}» of the figures the site may show right now (its own rule
 * of confirmation); null or anything else — no figures. Keys the templates
 * read: founded, beds, specialties, doctors, nurses, junior_staff,
 * other_staff, accreditation, license, nhsu.
 *
 * `news`: a list of «{title, url, meta}» (newest first); null — no block.
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Figures of the slot `facts`, cleaned: only items with a non-empty value.
 *
 * @param string $template Template key.
 * @return array<string, array{value: string, label: string}>
 */
function _spilno_slot_facts( $template ) {
	$data  = _spilno_slot_data( 'facts', $template );
	$facts = array();

	foreach ( is_array( $data ) ? $data : array() as $key => $fact ) {
		$value = is_array( $fact ) && is_scalar( $fact['value'] ?? null ) ? trim( (string) $fact['value'] ) : '';

		if ( is_string( $key ) && '' !== $value ) {
			$facts[ $key ] = array(
				'value' => $value,
				'label' => is_scalar( $fact['label'] ?? null ) ? trim( (string) $fact['label'] ) : '',
			);
		}
	}

	return $facts;
}

/**
 * Items of the slot `news`, cleaned: only items with a title, at most `$limit`.
 *
 * @param string $template Template key.
 * @param int    $limit    Maximum number of items.
 * @return array<int, array{title: string, url: string, meta: string}>
 */
function _spilno_slot_news( $template, $limit ) {
	$data  = _spilno_slot_data( 'news', $template );
	$items = array();

	foreach ( is_array( $data ) ? $data : array() as $item ) {
		$title = is_array( $item ) && is_scalar( $item['title'] ?? null ) ? trim( (string) $item['title'] ) : '';

		if ( '' === $title ) {
			continue;
		}

		$url     = _spilno_valid_url( $item['url'] ?? null ) ? $item['url'] : '';
		$items[] = array(
			'title' => $title,
			'url'   => $url,
			'meta'  => is_scalar( $item['meta'] ?? null ) ? trim( (string) $item['meta'] ) : '',
		);

		if ( count( $items ) >= $limit ) {
			break;
		}
	}

	return $items;
}

/**
 * Sentences about the staff, one per figure, with the plural of its number
 * («508 doctors»).
 *
 * @param array<string, array{value: string, label: string}> $facts Figures.
 * @return string[]
 */
function _spilno_facts_staff_lines( array $facts ) {
	$lines = array();

	foreach ( array( 'doctors', 'nurses', 'junior_staff', 'other_staff' ) as $key ) {
		if ( ! isset( $facts[ $key ] ) ) {
			continue;
		}

		$value  = $facts[ $key ]['value'];
		$number = (int) preg_replace( '/\D/', '', $value );

		switch ( $key ) {
			case 'doctors':
				/* translators: %s: number of doctors. */
				$format = _n( '%s doctor', '%s doctors', $number, 'spilno' );
				break;
			case 'nurses':
				/* translators: %s: number of nurses and other mid-level medical staff. */
				$format = _n( '%s nurse', '%s nurses', $number, 'spilno' );
				break;
			case 'junior_staff':
				/* translators: %s: number of junior medical staff. */
				$format = _n( '%s junior medical staff member', '%s junior medical staff members', $number, 'spilno' );
				break;
			default:
				/* translators: %s: number of staff of other services. */
				$format = _n( '%s staff member of other services', '%s staff members of other services', $number, 'spilno' );
		}

		$lines[] = sprintf( $format, $value );
	}

	return $lines;
}
