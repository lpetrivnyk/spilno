<?php
/**
 * Adopt: move an existing page of a site onto a spilno template.
 *
 * The core only: the site's adapter answers the filter `spilno_adopt_map`
 * with the page's current values as a flat map «dotted field path → raw
 * value» (it knows where the site kept them). A path absent from the map is
 * empty, i.e. the template's default. The values go through the same parse as
 * a form and are saved as the first revision with the log event `adopt`.
 * Switching the record's template is the site's step; the site's own data is
 * not touched.
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adopts a record.
 *
 * @param int    $record_id Record ID.
 * @param string $template  Template key.
 * @param array  $args {
 *     @type bool   $dry_run      Parse only, write nothing.
 *     @type bool   $force        Replace values the page already has (as a new revision; the old ones stay in the log).
 *     @type string $author_label Who adopted.
 * }
 * @return array{values: array, revision?: int, rev_id?: string, dry_run: bool}|WP_Error
 */
function spilno_adopt( $record_id, $template, $args = array() ) {
	$record_id  = (int) $record_id;
	$args       = is_array( $args ) ? $args : array();
	$definition = spilno_template( $template );

	if ( null === $definition ) {
		return _spilno_unknown_template_error();
	}

	if ( ! _spilno_cli() && ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'spilno_forbidden', __( 'Only a site administrator can adopt pages.', 'spilno' ) );
	}

	$post = $record_id > 0 ? get_post( $record_id ) : null;

	if ( ! $post instanceof WP_Post ) {
		return new WP_Error( 'spilno_no_record', __( 'The page was not found.', 'spilno' ) );
	}

	$state = spilno_page_state( $record_id );

	if ( $state['has_values'] && empty( $args['force'] ) ) {
		return new WP_Error( 'spilno_already_adopted', __( 'This page already has values of a spilno template.', 'spilno' ) );
	}

	$map = apply_filters( 'spilno_adopt_map', null, $record_id, $definition['key'] );

	if ( null === $map ) {
		return new WP_Error( 'spilno_no_map', __( 'The site adapter gives no values for this page and template.', 'spilno' ) );
	}

	if ( ! is_array( $map ) ) {
		return new WP_Error( 'adapter_invalid', __( 'The site adapter returned an invalid adopt map.', 'spilno' ) );
	}

	$raw    = _spilno_adopt_blank( $definition['root'] );
	$errors = array();

	foreach ( $map as $path => $value ) {
		if ( ! _spilno_adopt_set( $raw, $definition['root'], explode( '.', (string) $path ), $value ) ) {
			$errors[ (string) $path ] = __( 'Unknown field.', 'spilno' );
		}
	}

	$parsed = spilno_parse( $definition['key'], $raw );
	$errors = array_merge( $errors, $parsed['errors'] );

	if ( $errors ) {
		return new WP_Error(
			'spilno_invalid',
			__( 'The page was not adopted: some values do not pass the template rules.', 'spilno' ),
			array(
				'errors' => $errors,
				'values' => $parsed['values'],
			)
		);
	}

	if ( ! empty( $args['dry_run'] ) ) {
		return array(
			'values'  => $parsed['values'],
			'dry_run' => true,
		);
	}

	$saved = spilno_save(
		$record_id,
		$parsed['values'],
		$state['rev_id'],
		array(
			'template'     => $definition['key'],
			'schema_hash'  => spilno_schema_hash( $definition['key'] ),
			'event'        => 'adopt',
			'author_label' => $args['author_label'] ?? '',
			'_trusted'     => true,
		)
	);

	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	return array(
		'values'   => $parsed['values'],
		'revision' => $saved['revision'],
		'rev_id'   => $saved['rev_id'],
		'dry_run'  => false,
	);
}

/**
 * Raw input with every leaf empty, the shape a form sends: text and images
 * `''`, toggles absent (off).
 *
 * @param array $field Normalized field.
 * @return mixed
 */
function _spilno_adopt_blank( array $field ) {
	if ( 'group' !== $field['type'] ) {
		return 'toggle' === $field['type'] ? null : '';
	}

	$item = array();

	foreach ( $field['fields'] as $key => $child ) {
		$blank = _spilno_adopt_blank( $child );

		if ( null !== $blank ) {
			$item[ $key ] = $blank;
		}
	}

	return $field['count'] > 0 ? array_fill( 0, $field['count'], $item ) : $item;
}

/**
 * Sets a leaf of the raw input by path.
 *
 * @param array    $raw   Raw input (changed).
 * @param array    $field Field at this level.
 * @param string[] $parts Remaining path parts.
 * @param mixed    $value Raw value.
 * @return bool False when the path is not a leaf of the schema.
 */
function _spilno_adopt_set( array &$raw, array $field, array $parts, $value ) {
	$part = array_shift( $parts );

	if ( 'group' !== $field['type'] || null === $part ) {
		return false;
	}

	if ( $field['count'] > 0 ) {
		if ( ! preg_match( '/^(0|[1-9][0-9]?)$/', $part ) || (int) $part >= $field['count'] ) {
			return false;
		}

		$child = array(
			'type'   => 'group',
			'count'  => 0,
			'fields' => $field['fields'],
		);
		$part  = (int) $part;
	} elseif ( isset( $field['fields'][ $part ] ) ) {
		$child = $field['fields'][ $part ];
	} else {
		return false;
	}

	if ( ! $parts ) {
		if ( 'group' === $child['type'] ) {
			return false;
		}

		$raw[ $part ] = 'toggle' === $child['type'] && is_bool( $value ) ? ( $value ? '1' : '0' ) : $value;

		return true;
	}

	if ( ! isset( $raw[ $part ] ) || ! is_array( $raw[ $part ] ) ) {
		$raw[ $part ] = array();
	}

	return _spilno_adopt_set( $raw[ $part ], $child, $parts, $value );
}
