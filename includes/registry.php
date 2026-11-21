<?php
/**
 * Registry of page templates.
 *
 * Templates are registered only by the plugin: every directory
 * `templates/{key}/` with a `schema.php` that returns the definition. Sites
 * cannot add or change them. A test template directory is accepted through the
 * constant `SPILNO_DEV_TEMPLATES_DIR`, only on `local` / `development`.
 *
 * Definition:
 *
 *     array(
 *         'label'    => 'About',            // translatable, not in the hash
 *         'version'  => 1,                  // schema version, int >= 1
 *         'sections' => array(
 *             'hero' => array(
 *                 'label'  => '…',
 *                 'fields' => array(
 *                     'title' => array( 'type' => 'text', 'max' => 120, 'required' => true, 'default' => '…' ),
 *                     'cards' => array( 'type' => 'group', 'count' => 3, 'fields' => array( … ) ),
 *                 ),
 *             ),
 *         ),
 *         'slots'    => array( 'contacts', … ),
 *     )
 *
 * Field keys: `text` / `textarea` — `max` (required), `required`, `default`
 * (string); `image` — `required` (default is always none); `toggle` —
 * `default` (bool); `group` — `fields`, `count` (0 — a single group, N — a
 * fixed list of N items) and, for a list, `defaults` (a list of up to N
 * arrays «field => default» overriding the defaults of text fields per item:
 * the cards of a design differ). Every field takes `label` and `help`. Anything else
 * makes the template invalid: it is left out of the registry and reported by
 * `wp spilno doctor`.
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All valid templates, by key. Built once per request.
 *
 * @return array<string, array> Normalized definitions.
 */
function spilno_templates() {
	$registry = _spilno_registry();

	return $registry['templates'];
}

/**
 * One template.
 *
 * @param string $key Template key.
 * @return array|null Normalized definition, null when unknown.
 */
function spilno_template( $key ) {
	$templates = spilno_templates();

	return is_string( $key ) && isset( $templates[ $key ] ) ? $templates[ $key ] : null;
}

/**
 * Hash of a template's schema: the same on both sites when the schema is the
 * same. Covers the key, version, structure, types, limits, `required`,
 * defaults and slots; labels and help texts (translatable) are left out.
 *
 * @param string $key Template key.
 * @return string sha256 hex, '' for an unknown template.
 */
function spilno_schema_hash( $key ) {
	$template = spilno_template( $key );

	if ( null === $template ) {
		return '';
	}

	$canonical = array(
		'template' => $template['key'],
		'version'  => $template['version'],
		'fields'   => _spilno_schema_canonical( $template['root'] ),
		'slots'    => $template['slots'],
	);

	return hash( 'sha256', (string) wp_json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
}

/**
 * Canonical form of a field for the hash: fixed key order, no labels.
 *
 * @param array $field Normalized field definition.
 * @return array
 */
function _spilno_schema_canonical( array $field ) {
	if ( 'group' !== $field['type'] ) {
		return array(
			'type'     => $field['type'],
			'max'      => $field['max'],
			'required' => $field['required'],
			'default'  => $field['default'],
		);
	}

	$fields = array();

	foreach ( $field['fields'] as $key => $child ) {
		$fields[] = array( $key, _spilno_schema_canonical( $child ) );
	}

	return array(
		'type'     => 'group',
		'count'    => $field['count'],
		'fields'   => $fields,
		'defaults' => $field['defaults'],
	);
}

/**
 * Problems found in template definitions, by template key (for doctor).
 *
 * @return array<string, string[]>
 */
function _spilno_template_problems() {
	$registry = _spilno_registry();

	return $registry['problems'];
}

/**
 * Directories searched for templates.
 *
 * @return string[]
 */
function _spilno_template_dirs() {
	$dirs = array( SPILNO_PATH . 'templates' );

	if ( defined( 'SPILNO_DEV_TEMPLATES_DIR' ) && in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
		$dirs[] = untrailingslashit( (string) SPILNO_DEV_TEMPLATES_DIR );
	}

	return $dirs;
}

/**
 * Loads and validates the templates; cached for the request.
 *
 * @param bool $reset Drop the cache (tests, after a definition changed).
 * @return array{templates: array<string, array>, problems: array<string, string[]>}
 */
function _spilno_registry( $reset = false ) {
	static $registry = null;

	if ( null !== $registry && ! $reset ) {
		return $registry;
	}

	$registry = array(
		'templates' => array(),
		'problems'  => array(),
	);

	foreach ( _spilno_template_dirs() as $dir ) {
		foreach ( (array) glob( $dir . '/*/schema.php' ) as $file ) {
			$key = basename( dirname( $file ) );

			if ( isset( $registry['templates'][ $key ] ) || isset( $registry['problems'][ $key ] ) ) {
				$registry['problems'][ $key ][] = 'duplicate template key';
				unset( $registry['templates'][ $key ] );
				continue;
			}

			$problems = array();
			$template = _spilno_template_normalize( $key, include $file, $problems );

			if ( $problems ) {
				$registry['problems'][ $key ] = $problems;
				continue;
			}

			$template['dir']               = dirname( $file );
			$registry['templates'][ $key ] = $template;
		}
	}

	ksort( $registry['templates'] );

	return $registry;
}

/**
 * Validates and normalizes a template definition.
 *
 * @param string $key      Template key (directory name).
 * @param mixed  $raw      What `schema.php` returned.
 * @param array  $problems Problems found (appended to).
 * @return array Normalized definition (meaningful only without problems).
 */
function _spilno_template_normalize( $key, $raw, array &$problems ) {
	if ( ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $key ) ) {
		$problems[] = 'template key must match [a-z][a-z0-9_]{0,31}';
	}

	if ( ! is_array( $raw ) ) {
		$problems[] = 'schema.php must return an array';
		return array();
	}

	foreach ( array_diff( array_keys( $raw ), array( 'label', 'version', 'sections', 'slots' ) ) as $unknown ) {
		$problems[] = 'unknown key: ' . $unknown;
	}

	if ( ! is_string( $raw['label'] ?? null ) || '' === $raw['label'] ) {
		$problems[] = 'label must be a non-empty string';
	}

	if ( ! is_int( $raw['version'] ?? null ) || $raw['version'] < 1 ) {
		$problems[] = 'version must be an int >= 1';
	}

	$slots = $raw['slots'] ?? array();

	if ( ! is_array( $slots ) || ! array_is_list( $slots ) ) {
		$problems[] = 'slots must be a list';
		$slots      = array();
	}

	foreach ( $slots as $slot ) {
		if ( ! is_string( $slot ) || ! preg_match( '/^[a-z][a-z0-9_]*$/', $slot ) ) {
			$problems[] = 'invalid slot name';
		}
	}

	$sections = $raw['sections'] ?? null;

	if ( ! is_array( $sections ) || ! $sections ) {
		$problems[] = 'sections must be a non-empty array';
		$sections   = array();
	}

	$root = array(
		'type'     => 'group',
		'count'    => 0,
		'label'    => '',
		'help'     => '',
		'fields'   => array(),
		'defaults' => array(),
	);

	foreach ( $sections as $section_key => $section ) {
		if ( ! is_array( $section ) ) {
			$problems[] = 'section ' . $section_key . ' must be an array';
			continue;
		}

		foreach ( array_diff( array_keys( $section ), array( 'label', 'help', 'fields' ) ) as $unknown ) {
			$problems[] = 'section ' . $section_key . ': unknown key ' . $unknown;
		}

		$root['fields'][ $section_key ] = _spilno_field_normalize_def(
			(string) $section_key,
			array(
				'type'   => 'group',
				'label'  => $section['label'] ?? null,
				'help'   => $section['help'] ?? '',
				'fields' => $section['fields'] ?? null,
			),
			$problems
		);
	}

	$slots = array_values( array_unique( array_filter( $slots, 'is_string' ) ) );
	sort( $slots );

	return array(
		'key'     => $key,
		'label'   => is_string( $raw['label'] ?? null ) ? $raw['label'] : $key,
		'version' => is_int( $raw['version'] ?? null ) ? $raw['version'] : 0,
		'root'    => $root,
		'slots'   => $slots,
	);
}

/**
 * Validates and normalizes one field definition.
 *
 * @param string $path     Dotted path (for messages).
 * @param mixed  $raw      Definition.
 * @param array  $problems Problems found (appended to).
 * @return array Normalized field.
 */
function _spilno_field_normalize_def( $path, $raw, array &$problems ) {
	$key = substr( $path, (int) strrpos( '.' . $path, '.' ) );

	if ( ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $key ) ) {
		$problems[] = $path . ': key must match [a-z][a-z0-9_]{0,31}';
	}

	if ( ! is_array( $raw ) ) {
		$problems[] = $path . ': definition must be an array';
		$raw        = array( 'type' => 'text' );
	}

	$type    = $raw['type'] ?? null;
	$allowed = array(
		'text'     => array( 'type', 'label', 'help', 'max', 'required', 'default' ),
		'textarea' => array( 'type', 'label', 'help', 'max', 'required', 'default' ),
		'image'    => array( 'type', 'label', 'help', 'required' ),
		'toggle'   => array( 'type', 'label', 'help', 'default' ),
		'group'    => array( 'type', 'label', 'help', 'count', 'fields', 'defaults' ),
	);

	if ( ! is_string( $type ) || ! isset( $allowed[ $type ] ) ) {
		$problems[] = $path . ': unknown type';
		$type       = 'text';
	}

	foreach ( array_diff( array_keys( $raw ), $allowed[ $type ] ) as $unknown ) {
		$problems[] = $path . ': key not allowed for ' . $type . ': ' . $unknown;
	}

	if ( ! is_string( $raw['label'] ?? null ) || '' === $raw['label'] ) {
		$problems[] = $path . ': label must be a non-empty string';
	}

	if ( isset( $raw['help'] ) && ! is_string( $raw['help'] ) ) {
		$problems[] = $path . ': help must be a string';
	}

	$field = array(
		'type'     => $type,
		'label'    => is_string( $raw['label'] ?? null ) ? $raw['label'] : $path,
		'help'     => is_string( $raw['help'] ?? null ) ? $raw['help'] : '',
		'max'      => 0,
		'required' => false,
		'default'  => null,
	);

	if ( isset( $raw['required'] ) && ! is_bool( $raw['required'] ) ) {
		$problems[] = $path . ': required must be a bool';
	}

	switch ( $type ) {
		case 'text':
		case 'textarea':
			if ( ! is_int( $raw['max'] ?? null ) || $raw['max'] < 1 || $raw['max'] > 20000 ) {
				$problems[] = $path . ': max must be an int 1…20000';
			}

			$field['max']      = is_int( $raw['max'] ?? null ) ? $raw['max'] : 1;
			$field['required'] = true === ( $raw['required'] ?? false );
			$field['default']  = $raw['default'] ?? '';

			if ( ! is_string( $field['default'] ) ) {
				$problems[]       = $path . ': default must be a string';
				$field['default'] = '';
			} else {
				list( $parsed, $error ) = _spilno_text_parse( $field, $field['default'] );

				if ( '' !== $error || $parsed !== $field['default'] ) {
					$problems[] = $path . ': default does not pass the field rules';
				}
			}
			break;
		case 'image':
			$field['required'] = true === ( $raw['required'] ?? false );
			$field['default']  = 0;
			break;
		case 'toggle':
			$field['default'] = $raw['default'] ?? false;

			if ( ! is_bool( $field['default'] ) ) {
				$problems[]       = $path . ': default must be a bool';
				$field['default'] = false;
			}
			break;
		case 'group':
			$count = $raw['count'] ?? 0;

			if ( ! is_int( $count ) || $count < 0 || $count > 50 ) {
				$problems[] = $path . ': count must be an int 0…50';
				$count      = 0;
			}

			$children = $raw['fields'] ?? null;

			if ( ! is_array( $children ) || ! $children ) {
				$problems[] = $path . ': fields must be a non-empty array';
				$children   = array();
			}

			$field = array(
				'type'   => 'group',
				'label'  => $field['label'],
				'help'   => $field['help'],
				'count'  => $count,
				'fields' => array(),
			);

			foreach ( $children as $child_key => $child ) {
				$field['fields'][ $child_key ] = _spilno_field_normalize_def( _spilno_path( $path, $child_key ), $child, $problems );
			}

			$field['defaults'] = _spilno_list_defaults_def( $path, $field, $raw['defaults'] ?? array(), $problems );
			break;
	}

	return $field;
}

/**
 * Validates per-item defaults of a list group.
 *
 * @param string $path     Dotted path (for messages).
 * @param array  $field    Normalized group (fields already normalized).
 * @param mixed  $raw      Definition of `defaults`.
 * @param array  $problems Problems found (appended to).
 * @return array<int, array<string, string>> Exactly `count` arrays (or none).
 */
function _spilno_list_defaults_def( $path, array $field, $raw, array &$problems ) {
	if ( array() === $raw ) {
		return array();
	}

	if ( 0 === $field['count'] || ! is_array( $raw ) || ! array_is_list( $raw ) || count( $raw ) > $field['count'] ) {
		$problems[] = $path . ': defaults must be a list of at most count items (lists only)';
		return array();
	}

	$out = array();

	foreach ( array_pad( $raw, $field['count'], array() ) as $i => $item ) {
		$out[ $i ] = array();

		if ( ! is_array( $item ) ) {
			$problems[] = $path . '.' . $i . ': defaults item must be an array';
			continue;
		}

		foreach ( $item as $key => $value ) {
			$child = $field['fields'][ $key ] ?? null;

			if ( null === $child || ! in_array( $child['type'], array( 'text', 'textarea' ), true ) || ! is_string( $value ) ) {
				$problems[] = $path . '.' . $i . '.' . $key . ': default only for an existing text field, as a string';
				continue;
			}

			list( $parsed, $error ) = _spilno_text_parse( $child, $value );

			if ( '' !== $error || $parsed !== $value ) {
				$problems[] = $path . '.' . $i . '.' . $key . ': default does not pass the field rules';
				continue;
			}

			$out[ $i ][ $key ] = $value;
		}
	}

	return $out;
}
