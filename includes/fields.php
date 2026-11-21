<?php
/**
 * Field types: parse (raw form / payload input → typed value), normalize
 * (stored value → typed value, lenient) and validate (typed value → errors,
 * strict).
 *
 * Types are the common denominator of both admin panels: `text`, `textarea`,
 * `image` (attachment ID), `toggle` and fixed `group`s (a group with `count`
 * is a fixed list of identical items; sections of a template are groups).
 *
 * The rule of the field: if cleaning would change a value, it is a field
 * error, never a silent change. Anything malformed — an array instead of a
 * string, an unknown key, a missing key — is a field error too, never a PHP
 * error. Errors are keyed by the dotted path of the field
 * (`cards.0.title`); the key `''` is the form as a whole.
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Leaf field types.
 *
 * @return string[]
 */
function _spilno_leaf_types() {
	return array( 'text', 'textarea', 'image', 'toggle' );
}

/**
 * Joins a parent path and a key into a dotted path.
 *
 * @param string     $path Parent path ('' at the root).
 * @param string|int $key  Child key.
 * @return string
 */
function _spilno_path( $path, $key ) {
	return '' === $path ? (string) $key : $path . '.' . $key;
}

/**
 * One item of a list group, as a plain group: the list's fields with the
 * item's own defaults.
 *
 * @param array $field Normalized list group.
 * @param int   $i     Item index.
 * @return array
 */
function _spilno_list_item( array $field, $i ) {
	$fields = $field['fields'];

	foreach ( $field['defaults'][ $i ] ?? array() as $key => $default ) {
		$fields[ $key ]['default'] = $default;
	}

	return array(
		'type'     => 'group',
		'count'    => 0,
		'label'    => $field['label'],
		'help'     => $field['help'],
		'fields'   => $fields,
		'defaults' => array(),
	);
}

/**
 * Empty value of a field: what a field holds when nothing was entered
 * (an empty text or image means «use the default» at render time).
 *
 * @param array $field Normalized field definition.
 * @return mixed
 */
function _spilno_field_empty( array $field ) {
	switch ( $field['type'] ) {
		case 'text':
		case 'textarea':
			return '';
		case 'image':
			return 0;
		case 'toggle':
			return false;
	}

	$value = array();

	foreach ( $field['fields'] as $key => $child ) {
		$value[ $key ] = _spilno_field_empty( $child );
	}

	if ( $field['count'] > 0 ) {
		return array_fill( 0, $field['count'], $value );
	}

	return $value;
}

/**
 * Plain text without markup, the way WordPress cleans it.
 *
 * `sanitize_text_field()` / `sanitize_textarea_field()` remove tags and
 * `%XX` octets and encode a lone `<` and `&`; the entities are decoded back
 * because a value is raw text (escaped where it is printed). If decoding
 * itself produced a tag (`&lt;b&gt;` typed), the pass repeats.
 *
 * @param string $raw       Raw text.
 * @param bool   $multiline Whether line breaks are kept.
 * @return string
 */
function _spilno_clean_text( $raw, $multiline ) {
	$clean = $raw;

	for ( $pass = 0; $pass < 3; $pass++ ) {
		$clean = $multiline ? sanitize_textarea_field( $clean ) : sanitize_text_field( $clean );
		$clean = wp_specialchars_decode( $clean, ENT_QUOTES );

		if ( ! preg_match( '/<[a-zA-Z\/!?][^>]*>/', $clean ) ) {
			return $clean;
		}
	}

	return wp_strip_all_tags( $clean );
}

/**
 * Changes a user does not notice and that are not a loss: `\r\n` → `\n`,
 * whitespace runs → one space (line breaks too, for a single line), spaces
 * around line breaks and at the edges trimmed.
 *
 * @param string $text      Text.
 * @param bool   $multiline Whether line breaks are kept.
 * @return string
 */
function _spilno_text_norm( $text, $multiline ) {
	$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
	$text = (string) preg_replace( $multiline ? '/[^\S\n]+/u' : '/\s+/u', ' ', $text );

	if ( $multiline ) {
		$text = (string) preg_replace( '/ *\n */u', "\n", $text );
	}

	return trim( $text );
}

/**
 * Parses one text value.
 *
 * @param array  $field Normalized field definition (`text` or `textarea`).
 * @param string $raw   Raw text.
 * @return array{0: string, 1: string} Value and error ('' when none).
 */
function _spilno_text_parse( array $field, $raw ) {
	$multiline = 'textarea' === $field['type'];

	if ( ! preg_match( '//u', $raw ) ) {
		return array( '', __( 'The text contains invalid characters.', 'spilno' ) );
	}

	$value = _spilno_text_norm( $raw, $multiline );

	if ( _spilno_text_norm( _spilno_clean_text( $raw, $multiline ), $multiline ) !== $value ) {
		return array( $value, __( 'Remove HTML tags and codes like %20 or &amp; — the field accepts plain text only.', 'spilno' ) );
	}

	if ( mb_strlen( $value ) > $field['max'] ) {
		/* translators: %d: maximum number of characters. */
		return array( $value, sprintf( _n( 'No more than %d character.', 'No more than %d characters.', $field['max'], 'spilno' ), $field['max'] ) );
	}

	return array( $value, '' );
}

/**
 * Parses a raw value (form POST without slashes, or a decoded payload).
 *
 * `null` means the key was absent from the input. A toggle absent from a form
 * is «off» (an unchecked checkbox is not sent); any other absent field is an
 * error, so a truncated POST cannot blank the page.
 *
 * @param array  $field  Normalized field definition.
 * @param mixed  $raw    Raw value or null when absent.
 * @param string $path   Dotted path of the field.
 * @param array  $errors Errors, by path (appended to).
 * @return mixed Typed value (for an invalid field — what to show back in the form).
 */
function _spilno_field_parse( array $field, $raw, $path, array &$errors ) {
	$invalid = __( 'Invalid value.', 'spilno' );

	if ( 'group' === $field['type'] ) {
		return _spilno_group_parse( $field, $raw, $path, $errors );
	}

	if ( 'toggle' === $field['type'] ) {
		if ( null === $raw ) {
			return false;
		}

		if ( in_array( $raw, array( true, 1, '1', 'on' ), true ) ) {
			return true;
		}

		if ( in_array( $raw, array( false, 0, '0', '' ), true ) ) {
			return false;
		}

		$errors[ $path ] = $invalid;

		return false;
	}

	if ( null === $raw ) {
		$errors[ $path ] = __( 'This field is missing from the form. Reload the page and try again.', 'spilno' );

		return _spilno_field_empty( $field );
	}

	if ( 'image' === $field['type'] ) {
		return _spilno_image_parse( $raw, $path, $errors );
	}

	if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
		$errors[ $path ] = $invalid;

		return '';
	}

	list( $value, $error ) = _spilno_text_parse( $field, (string) $raw );

	if ( '' !== $error ) {
		$errors[ $path ] = $error;
	}

	return $value;
}

/**
 * Parses an image value: an attachment ID as digits, `''` / `0` for none.
 *
 * @param mixed  $raw    Raw value.
 * @param string $path   Dotted path.
 * @param array  $errors Errors, by path.
 * @return int
 */
function _spilno_image_parse( $raw, $path, array &$errors ) {
	if ( '' === $raw ) {
		return 0;
	}

	if ( is_string( $raw ) && preg_match( '/^(0|[1-9][0-9]{0,18})$/', $raw ) ) {
		$raw = (int) $raw;
	}

	if ( ! is_int( $raw ) || $raw < 0 ) {
		$errors[ $path ] = __( 'Invalid value.', 'spilno' );

		return 0;
	}

	$error = _spilno_image_error( $raw );

	if ( '' !== $error ) {
		$errors[ $path ] = $error;
	}

	return $raw;
}

/**
 * Whether an attachment ID is usable as an image.
 *
 * @param int $id Attachment ID (0 — none).
 * @return string Error ('' when fine).
 */
function _spilno_image_error( $id ) {
	if ( 0 === $id || wp_attachment_is_image( $id ) ) {
		return '';
	}

	return __( 'Choose an image from the media library.', 'spilno' );
}

/**
 * Parses a group: an array with exactly the keys of the schema (a fixed list:
 * keys 0 … count − 1).
 *
 * @param array  $field  Normalized group definition.
 * @param mixed  $raw    Raw value or null when absent.
 * @param string $path   Dotted path ('' at the root).
 * @param array  $errors Errors, by path.
 * @return array
 */
function _spilno_group_parse( array $field, $raw, $path, array &$errors ) {
	if ( null !== $raw && ! is_array( $raw ) ) {
		$errors[ $path ] = __( 'Invalid value.', 'spilno' );

		return _spilno_field_empty( $field );
	}

	$raw = (array) $raw;

	if ( $field['count'] > 0 ) {
		$value = array();

		_spilno_unknown_keys( $raw, range( 0, $field['count'] - 1 ), $path, $errors );

		for ( $i = 0; $i < $field['count']; $i++ ) {
			$value[] = _spilno_group_parse( _spilno_list_item( $field, $i ), $raw[ $i ] ?? null, _spilno_path( $path, $i ), $errors );
		}

		return $value;
	}

	_spilno_unknown_keys( $raw, array_keys( $field['fields'] ), $path, $errors );

	$value = array();

	foreach ( $field['fields'] as $key => $child ) {
		$value[ $key ] = _spilno_field_parse( $child, $raw[ $key ] ?? null, _spilno_path( $path, $key ), $errors );
	}

	return $value;
}

/**
 * Reports keys of the input that the schema does not have.
 *
 * @param array  $raw    Input array.
 * @param array  $known  Known keys.
 * @param string $path   Dotted path of the group.
 * @param array  $errors Errors, by path.
 * @return void
 */
function _spilno_unknown_keys( array $raw, array $known, $path, array &$errors ) {
	$known = array_map( 'strval', $known );

	foreach ( array_keys( $raw ) as $key ) {
		if ( ! in_array( (string) $key, $known, true ) ) {
			$errors[ _spilno_path( $path, $key ) ] = __( 'Unknown field.', 'spilno' );
		}
	}
}

/**
 * Normalizes a stored value against the current schema (read path, lenient):
 * missing → empty, unknown → dropped, wrong type or over the limit → empty.
 *
 * @param array $field  Normalized field definition.
 * @param mixed $stored Stored value.
 * @return mixed
 */
function _spilno_field_normalize( array $field, $stored ) {
	switch ( $field['type'] ) {
		case 'text':
		case 'textarea':
			return is_string( $stored ) && mb_strlen( $stored ) <= $field['max'] ? $stored : '';
		case 'image':
			return is_int( $stored ) && $stored >= 0 ? $stored : 0;
		case 'toggle':
			return true === $stored;
	}

	$stored = is_array( $stored ) ? $stored : array();

	if ( $field['count'] > 0 ) {
		$value = array();

		for ( $i = 0; $i < $field['count']; $i++ ) {
			$value[] = _spilno_field_normalize( _spilno_list_item( $field, $i ), $stored[ $i ] ?? null );
		}

		return $value;
	}

	$value = array();

	foreach ( $field['fields'] as $key => $child ) {
		$value[ $key ] = _spilno_field_normalize( $child, $stored[ $key ] ?? null );
	}

	return $value;
}

/**
 * Validates a typed value (save path, strict): exact types, exact keys, text
 * that parsing would keep unchanged, limits, existing images.
 *
 * @param array  $field  Normalized field definition.
 * @param mixed  $value  Typed value.
 * @param string $path   Dotted path ('' at the root).
 * @param array  $errors Errors, by path.
 * @return void
 */
function _spilno_field_validate( array $field, $value, $path, array &$errors ) {
	$invalid = __( 'Invalid value.', 'spilno' );

	switch ( $field['type'] ) {
		case 'text':
		case 'textarea':
			if ( ! is_string( $value ) ) {
				$errors[ $path ] = $invalid;
				return;
			}

			list( $parsed, $error ) = _spilno_text_parse( $field, $value );

			if ( '' !== $error ) {
				$errors[ $path ] = $error;
			} elseif ( $parsed !== $value ) {
				$errors[ $path ] = $invalid;
			}
			return;
		case 'image':
			if ( ! is_int( $value ) || $value < 0 ) {
				$errors[ $path ] = $invalid;
				return;
			}

			$error = _spilno_image_error( $value );

			if ( '' !== $error ) {
				$errors[ $path ] = $error;
			}
			return;
		case 'toggle':
			if ( ! is_bool( $value ) ) {
				$errors[ $path ] = $invalid;
			}
			return;
	}

	if ( ! is_array( $value ) ) {
		$errors[ $path ] = $invalid;
		return;
	}

	if ( $field['count'] > 0 ) {
		$keys = range( 0, $field['count'] - 1 );
	} else {
		$keys = array_keys( $field['fields'] );
	}

	_spilno_unknown_keys( $value, $keys, $path, $errors );

	foreach ( $keys as $key ) {
		$child = $field['count'] > 0 ? _spilno_list_item( $field, $key ) : $field['fields'][ $key ];

		if ( ! array_key_exists( $key, $value ) ) {
			$errors[ _spilno_path( $path, $key ) ] = __( 'This field is missing.', 'spilno' );
			continue;
		}

		_spilno_field_validate( $child, $value[ $key ], _spilno_path( $path, $key ), $errors );
	}
}

/**
 * Required fields that are empty and have no default to fall back on.
 *
 * Empty means «use the default» at render time, so a required field with a
 * default is always satisfied. Toggles are never required.
 *
 * @param array  $field  Normalized field definition.
 * @param mixed  $value  Typed (normalized) value.
 * @param string $path   Dotted path.
 * @param array  $errors Errors, by path.
 * @return void
 */
function _spilno_field_required( array $field, $value, $path, array &$errors ) {
	if ( 'group' === $field['type'] ) {
		$value = is_array( $value ) ? $value : array();

		if ( $field['count'] > 0 ) {

			for ( $i = 0; $i < $field['count']; $i++ ) {
				_spilno_field_required( _spilno_list_item( $field, $i ), $value[ $i ] ?? null, _spilno_path( $path, $i ), $errors );
			}

			return;
		}

		foreach ( $field['fields'] as $key => $child ) {
			_spilno_field_required( $child, $value[ $key ] ?? null, _spilno_path( $path, $key ), $errors );
		}

		return;
	}

	if ( empty( $field['required'] ) || 'toggle' === $field['type'] ) {
		return;
	}

	if ( _spilno_field_empty( $field ) === $value && _spilno_field_empty( $field ) === $field['default'] ) {
		$errors[ $path ] = __( 'This field is required.', 'spilno' );
	}
}
