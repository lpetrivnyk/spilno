<?php
/**
 * The adapter contract: filters a site answers in its own adapter.
 *
 * The plugin never knows the site's post types, capabilities, routes or data;
 * it asks through these filters and checks the shape of every answer, so a
 * wrong answer from an adapter is a refusal, not a PHP error.
 *
 * | Filter                                         | Answer                                         |
 * |------------------------------------------------|------------------------------------------------|
 * | `spilno_can( false, $action, $ctx )`           | bool; actions `edit`, `links`, `confirm_facts` |
 * | `spilno_resolve_url( null, $url )`             | `{record_id, template, status}` or `WP_Error`  |
 * | `spilno_record_url( '', $record_id )`          | current public URL (preview URL for a draft)   |
 * | `spilno_slot_link( '', $slot, $template )`     | URL or ''                                      |
 * | `spilno_slot_data( null, $slot, $template )`   | data or null (block not rendered)              |
 * | `spilno_is_preview( false )`                   | outside production — placeholder photos        |
 * | `spilno_site_label( '' )`                      | human name of this site                        |
 * | `spilno_render_values( $values, $id, $t, $c )` | render only (translations); stage 2            |
 * | `spilno_adopt_map( null, $record_id, $t )`     | dotted field path → raw value (adopt)          |
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Error codes an adapter may return from `spilno_resolve_url`.
 *
 * @return string[]
 */
function _spilno_resolve_url_codes() {
	return array( 'not_found', 'not_spilno', 'foreign_host', 'not_a_record' );
}

/**
 * Whether the current user may do an action. Without an adapter — nobody.
 *
 * @param string $action `edit`, `links` or `confirm_facts`.
 * @param array  $ctx    Context: `record_id`, `template`.
 * @return bool
 */
function _spilno_can( $action, array $ctx = array() ) {
	return true === apply_filters( 'spilno_can', false, $action, $ctx );
}

/**
 * A URL of this site → the record behind it.
 *
 * @param string $url URL typed by an administrator.
 * @return array{record_id: int, template: string, status: string}|WP_Error
 */
function spilno_resolve_url( $url ) {
	$answer = apply_filters( 'spilno_resolve_url', null, (string) $url );

	if ( is_wp_error( $answer ) ) {
		return in_array( $answer->get_error_code(), _spilno_resolve_url_codes(), true )
			? $answer
			: new WP_Error( 'adapter_invalid', __( 'The site adapter returned an unknown error for this URL.', 'spilno' ) );
	}

	if ( null === $answer ) {
		return new WP_Error( 'adapter_missing', __( 'The site has no adapter for page addresses.', 'spilno' ) );
	}

	if ( ! is_array( $answer ) || ! is_int( $answer['record_id'] ?? null ) || $answer['record_id'] < 1 || ! is_string( $answer['template'] ?? null ) || ! is_string( $answer['status'] ?? null ) ) {
		return new WP_Error( 'adapter_invalid', __( 'The site adapter returned an invalid answer for this URL.', 'spilno' ) );
	}

	return array(
		'record_id' => $answer['record_id'],
		'template'  => $answer['template'],
		'status'    => $answer['status'],
	);
}

/**
 * Current public address of a record (preview address for a draft).
 *
 * @param int $record_id Record ID.
 * @return string URL or '' when the site gives none.
 */
function spilno_record_url( $record_id ) {
	$url = apply_filters( 'spilno_record_url', '', (int) $record_id );

	return is_string( $url ) && wp_http_validate_url( $url ) ? $url : '';
}

/**
 * Target of a link slot of a template.
 *
 * @param string $slot     Slot name.
 * @param string $template Template key.
 * @return string URL or ''.
 */
function _spilno_slot_link( $slot, $template ) {
	$url = apply_filters( 'spilno_slot_link', '', (string) $slot, (string) $template );

	return is_string( $url ) && '' !== $url && wp_http_validate_url( $url ) ? $url : '';
}

/**
 * Data of a data slot (news, facts); null — the block is not rendered.
 *
 * @param string $slot     Slot name.
 * @param string $template Template key.
 * @return mixed
 */
function _spilno_slot_data( $slot, $template ) {
	return apply_filters( 'spilno_slot_data', null, (string) $slot, (string) $template );
}

/**
 * Whether placeholders are shown instead of real photos (outside production).
 *
 * @return bool
 */
function _spilno_is_preview() {
	return true === apply_filters( 'spilno_is_preview', false );
}

/**
 * Human name of this site; the site title when the adapter gives none.
 *
 * @return string
 */
function _spilno_site_label() {
	$label = apply_filters( 'spilno_site_label', '' );

	return is_string( $label ) && '' !== trim( $label ) ? trim( $label ) : (string) get_bloginfo( 'name' );
}

/**
 * Identifier of this site among the two (`SPILNO_SITE_ID`), '' when links are
 * not configured.
 *
 * @return string
 */
function _spilno_site_id() {
	return defined( 'SPILNO_SITE_ID' ) && is_string( SPILNO_SITE_ID ) ? SPILNO_SITE_ID : '';
}
