<?php
/**
 * Page values: form schema, parse, save with revisions and a lock, read,
 * state and the publication gate.
 *
 * A page of a spilno template is an ordinary record of the site (it keeps
 * slug, status, SEO, parent); its values live in `{prefix}spilno_pages`, one
 * row per record, as JSON of the Ukrainian original. Every save is a new
 * revision: `revision` (counter), `rev_id` (UUID), `parent_rev_id` (the
 * revision it was made from). Stage 1 is the local mode: revisions are issued
 * by this site.
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema of a template for an admin form: the site's renderer draws it in its
 * own look. Every field carries its dotted `path` and the input `name`.
 *
 * The form must send back `hash` (as `schema_hash` of `spilno_save()`): a form
 * opened before the schema changed is refused.
 *
 * @param string $template Template key.
 * @param string $prefix   Name of the input array (`spilno` → `spilno[hero][title]`).
 * @return array|WP_Error `{template, label, version, hash, fields}`; `fields` —
 *                        sections as groups: `{key, path, name, type, label,
 *                        help, …}`; leaves add `max`, `required`, `default`;
 *                        groups add `count` and `fields` (for a list — `items`,
 *                        each with its own `fields`).
 */
function spilno_form_schema( $template, $prefix = 'spilno' ) {
	$definition = spilno_template( $template );

	if ( null === $definition ) {
		return _spilno_unknown_template_error();
	}

	return array(
		'template' => $definition['key'],
		'label'    => $definition['label'],
		'version'  => $definition['version'],
		'hash'     => spilno_schema_hash( $definition['key'] ),
		'fields'   => _spilno_form_fields( $definition['root']['fields'], '', (string) $prefix ),
	);
}

/**
 * Form description of a set of fields.
 *
 * @param array  $fields Normalized fields, by key.
 * @param string $path   Dotted path of the parent.
 * @param string $name   Input name of the parent.
 * @return array[]
 */
function _spilno_form_fields( array $fields, $path, $name ) {
	$out = array();

	foreach ( $fields as $key => $field ) {
		$entry = array(
			'key'   => $key,
			'path'  => _spilno_path( $path, $key ),
			'name'  => $name . '[' . $key . ']',
			'type'  => $field['type'],
			'label' => $field['label'],
			'help'  => $field['help'],
		);

		if ( 'group' !== $field['type'] ) {
			$entry['max']      = $field['max'];
			$entry['required'] = $field['required'];
			$entry['default']  = $field['default'];
		} elseif ( $field['count'] > 0 ) {
			$entry['count'] = $field['count'];
			$entry['items'] = array();

			for ( $i = 0; $i < $field['count']; $i++ ) {
				$entry['items'][] = array(
					'path'   => _spilno_path( $entry['path'], $i ),
					'name'   => $entry['name'] . '[' . $i . ']',
					'fields' => _spilno_form_fields( _spilno_list_item( $field, $i )['fields'], _spilno_path( $entry['path'], $i ), $entry['name'] . '[' . $i . ']' ),
				);
			}
		} else {
			$entry['count']  = 0;
			$entry['fields'] = _spilno_form_fields( $field['fields'], $entry['path'], $entry['name'] );
		}

		$out[] = $entry;
	}

	return $out;
}

/**
 * Parses raw input of a form (POST without slashes) or a payload.
 *
 * The admin does not sanitize these fields itself: it passes the raw input
 * here. Never save when `errors` is not empty; `values` then is what to show
 * back in the form.
 *
 * @param string $template Template key.
 * @param mixed  $raw      Raw input: `[ section => [ field => … ] ]`.
 * @return array{values: array, errors: array<string, string>}
 */
function spilno_parse( $template, $raw ) {
	$definition = spilno_template( $template );

	if ( null === $definition ) {
		return array(
			'values' => array(),
			'errors' => array( '' => _spilno_unknown_template_error()->get_error_message() ),
		);
	}

	$errors = array();
	$values = _spilno_group_parse( $definition['root'], $raw, '', $errors );

	return array(
		'values' => $values,
		'errors' => $errors,
	);
}

/**
 * Stored values of a page (the original, normalized against the current
 * schema; no defaults merged, no translation).
 *
 * @param int $record_id Record ID.
 * @return array|null Null when the page has no values or its template is unknown.
 */
function spilno_values( $record_id ) {
	$row = _spilno_page_row( (int) $record_id );

	if ( null === $row ) {
		return null;
	}

	$definition = spilno_template( $row['template'] );

	return null === $definition ? null : _spilno_row_values( $definition, $row );
}

/**
 * State of a page for the form: revision to send back, who saved it last,
 * link and read-only flags.
 *
 * Stage 1 is the local mode: no link, the source is this site, never
 * read-only.
 *
 * @param int $record_id Record ID.
 * @return array
 */
function spilno_page_state( $record_id ) {
	$row        = _spilno_page_row( (int) $record_id );
	$template   = null === $row ? '' : $row['template'];
	$definition = spilno_template( $template );

	return array(
		'record_id'          => (int) $record_id,
		'has_values'         => null !== $row,
		'template'           => $template,
		'template_available' => null !== $definition,
		'schema_version'     => null === $row ? 0 : (int) $row['schema_version'],
		'schema_current'     => null !== $definition && (int) $row['schema_version'] === $definition['version'],
		'schema_hash'        => null === $definition ? '' : spilno_schema_hash( $template ),
		'revision'           => null === $row ? 0 : (int) $row['revision'],
		'rev_id'             => null === $row ? '' : $row['rev_id'],
		'updated_at'         => null === $row ? '' : $row['updated_at'],
		'updated_by_label'   => null === $row ? '' : $row['updated_by_label'],
		'origin_site'        => null === $row ? '' : $row['origin_site'],
		'mode'               => 'local',
		'link'               => null,
		'source'             => 'local',
		'sync'               => null,
		'read_only'          => false,
	);
}

/**
 * Publication gate: whether a page may be published (or stay published) with
 * these values. Called by the site at each of its publication ramparts.
 *
 * @param int         $record_id Record ID.
 * @param array|null  $values    Values about to be saved (typed); null — the stored ones.
 * @param string|null $template  Template of `$values`; null — the stored one.
 * @return true|WP_Error `spilno_not_publishable` with `errors` by path in the data.
 */
function spilno_publishable( $record_id, $values = null, $template = null ) {
	$row      = _spilno_page_row( (int) $record_id );
	$template = null === $template ? ( null === $row ? '' : $row['template'] ) : (string) $template;

	$definition = spilno_template( $template );

	if ( null === $definition ) {
		return new WP_Error( 'spilno_not_publishable', _spilno_unknown_template_error()->get_error_message(), array( 'errors' => array() ) );
	}

	if ( null === $values ) {
		$values = null === $row || $row['template'] !== $template ? _spilno_field_empty( $definition['root'] ) : _spilno_row_values( $definition, $row );
	} else {
		$errors = array();
		_spilno_field_validate( $definition['root'], $values, '', $errors );

		if ( $errors ) {
			return new WP_Error( 'spilno_not_publishable', __( 'The page cannot be published: fix the fields marked below.', 'spilno' ), array( 'errors' => $errors ) );
		}
	}

	$errors = array();
	_spilno_field_required( $definition['root'], $values, '', $errors );

	if ( $errors ) {
		return new WP_Error( 'spilno_not_publishable', __( 'The page cannot be published: fill in the required fields.', 'spilno' ), array( 'errors' => $errors ) );
	}

	return true;
}

/**
 * Saves values of a page as a new revision.
 *
 * Order: record, template, right (`spilno_can( 'edit' )`, except WP-CLI),
 * schema hash of the form, strict validation, then under a named lock of the
 * page and in a transaction: `SELECT … FOR UPDATE` of the row, the
 * optimistic lock (`$expected_rev` must be the current `rev_id`), write of the
 * row and of the log row. Any failure writes nothing.
 *
 * @param int    $record_id    Record ID (a post of the site).
 * @param array  $values       Typed values (from `spilno_parse()`).
 * @param string $expected_rev `rev_id` the form was opened with; '' for a page without values yet.
 * @param array  $args {
 *     @type string $template     Template key (required).
 *     @type string $schema_hash  Hash the form was built with (required).
 *     @type string $author_label Who saved, as text; default — the current user's display name.
 *     @type string $event        Log event: `save` (default) or `adopt`.
 *     @type string $note         Short note for the log.
 * }
 * @return array{revision: int, rev_id: string, parent_rev_id: string}|WP_Error
 */
function spilno_save( $record_id, $values, $expected_rev, $args = array() ) {
	$record_id = (int) $record_id;
	$args      = is_array( $args ) ? $args : array();
	$template  = is_string( $args['template'] ?? null ) ? $args['template'] : '';
	$event     = in_array( $args['event'] ?? 'save', array( 'save', 'adopt' ), true ) ? ( $args['event'] ?? 'save' ) : 'save';

	$post = $record_id > 0 ? get_post( $record_id ) : null;

	if ( ! $post instanceof WP_Post || 'auto-draft' === $post->post_status ) {
		return new WP_Error( 'spilno_no_record', __( 'The page was not found.', 'spilno' ) );
	}

	$definition = spilno_template( $template );

	if ( null === $definition ) {
		return _spilno_unknown_template_error();
	}

	if ( ! _spilno_cli() && empty( $args['_trusted'] ) && ! _spilno_can(
		'edit',
		array(
			'record_id' => $record_id,
			'template'  => $template,
		)
	) ) {
		return new WP_Error( 'spilno_forbidden', __( 'You are not allowed to edit this page.', 'spilno' ) );
	}

	if ( ! is_string( $args['schema_hash'] ?? null ) || ! hash_equals( spilno_schema_hash( $template ), $args['schema_hash'] ) ) {
		return new WP_Error(
			'spilno_schema_changed',
			__( 'The page template changed while the form was open. Your changes were not saved: reload the page and enter them again.', 'spilno' ),
			array( 'values' => $values )
		);
	}

	$errors = array();
	_spilno_field_validate( $definition['root'], $values, '', $errors );

	if ( $errors ) {
		return new WP_Error(
			'spilno_invalid',
			__( 'The page was not saved: fix the fields marked below.', 'spilno' ),
			array(
				'errors' => $errors,
				'values' => $values,
			)
		);
	}

	$lock = _spilno_lock( $record_id );

	if ( null === $lock ) {
		return new WP_Error( 'spilno_busy', __( 'This page is being saved by someone else right now. Try again in a few seconds.', 'spilno' ), array( 'values' => $values ) );
	}

	try {
		$saved = _spilno_save_locked( $record_id, $definition, $values, (string) $expected_rev, $event, $args );
	} finally {
		_spilno_unlock( $lock );
	}

	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	/**
	 * Values of a page were saved (a new revision exists).
	 *
	 * @param int        $record_id Record ID.
	 * @param string     $rev_id    New revision ID.
	 * @param array      $values    New values.
	 * @param array|null $prev      Previous values (normalized), null for the first save.
	 */
	do_action( 'spilno_values_saved', $record_id, $saved['rev_id'], $values, $saved['prev'] );

	unset( $saved['prev'] );

	return $saved;
}

/**
 * Body of `spilno_save()` under the page lock: the transaction.
 *
 * @param int    $record_id    Record ID.
 * @param array  $definition   Template.
 * @param array  $values       Validated values.
 * @param string $expected_rev Expected current `rev_id`.
 * @param string $event        Log event.
 * @param array  $args         Arguments of `spilno_save()`.
 * @return array|WP_Error
 */
function _spilno_save_locked( $record_id, array $definition, array $values, $expected_rev, $event, array $args ) {
	global $wpdb;

	$tables   = _spilno_tables();
	$db_error = new WP_Error( 'spilno_db', __( 'The page was not saved: database error. Try again.', 'spilno' ), array( 'values' => $values ) );

	if ( ! _spilno_tx_begin() ) {
		return $db_error;
	}

	$connection = (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
	$row        = _spilno_page_row( $record_id, true );

	if ( '' !== $wpdb->last_error ) {
		return _spilno_tx_fail( $db_error );
	}

	$current = null === $row ? '' : $row['rev_id'];

	if ( ! hash_equals( $current, $expected_rev ) ) {
		_spilno_tx_rollback();

		return new WP_Error(
			'spilno_conflict',
			null === $row
				? __( 'While the form was open, the values of this page were removed. Your changes were not saved: reload the page.', 'spilno' )
				: sprintf(
					/* translators: 1: who saved the page, 2: date and time. */
					__( 'While the form was open, this page was saved by %1$s at %2$s. Your changes were not saved: reload the page and enter them again.', 'spilno' ),
					'' !== $row['updated_by_label'] ? $row['updated_by_label'] : __( 'someone', 'spilno' ),
					wp_date( get_option( 'date_format' ) . ' H:i', (int) strtotime( $row['updated_at'] . ' UTC' ) )
				),
			array(
				'values'  => $values,
				'current' => null === $row ? null : array(
					'rev_id'           => $row['rev_id'],
					'revision'         => (int) $row['revision'],
					'updated_at'       => $row['updated_at'],
					'updated_by_label' => $row['updated_by_label'],
				),
			)
		);
	}

	$prev   = null === $row ? null : ( $row['template'] === $definition['key'] ? _spilno_row_values( $definition, $row ) : null );
	$json   = _spilno_json( $values );
	$now    = gmdate( 'Y-m-d H:i:s' );
	$author = _spilno_author_label( $args );
	$saved  = array(
		'revision'      => ( null === $row ? 0 : (int) $row['revision'] ) + 1,
		'rev_id'        => wp_generate_uuid4(),
		'parent_rev_id' => $current,
	);
	$data   = array(
		'record_id'        => $record_id,
		'template'         => $definition['key'],
		'schema_version'   => $definition['version'],
		'values_json'      => $json,
		'revision'         => $saved['revision'],
		'rev_id'           => $saved['rev_id'],
		'parent_rev_id'    => $saved['parent_rev_id'],
		'updated_at'       => $now,
		'updated_by_label' => $author,
		'origin_site'      => _spilno_site_id(),
	);
	$format = array( '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' );

	$written = null === $row
		? $wpdb->insert( $tables['pages'], $data, $format )
		: $wpdb->update( $tables['pages'], $data, array( 'record_id' => $record_id ), $format, array( '%d' ) );

	if ( 1 !== $written ) {
		return _spilno_tx_fail( $db_error );
	}

	$logged = $wpdb->insert(
		$tables['log'],
		array(
			'record_id'      => $record_id,
			'event'          => $event,
			'template'       => $definition['key'],
			'schema_version' => $definition['version'],
			'revision'       => $saved['revision'],
			'rev_id'         => $saved['rev_id'],
			'parent_rev_id'  => $saved['parent_rev_id'],
			'values_hash'    => hash( 'sha256', $json ),
			'values_json'    => $json,
			'author_label'   => $author,
			'site'           => _spilno_site_id(),
			'note'           => mb_substr( is_string( $args['note'] ?? null ) ? $args['note'] : '', 0, 255 ),
			'created_at'     => $now,
		),
		array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	if ( 1 !== $logged ) {
		return _spilno_tx_fail( $db_error );
	}

	// Values are kept for the newest SPILNO_LOG_KEEP_VALUES rows of the page;
	// older rows keep who, when and the hash.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$tables['log']} SET values_json = NULL WHERE record_id = %d AND values_json IS NOT NULL AND id <= ( SELECT id FROM ( SELECT id FROM {$tables['log']} WHERE record_id = %d ORDER BY id DESC LIMIT 1 OFFSET %d ) AS keep )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names.
			$record_id,
			$record_id,
			SPILNO_LOG_KEEP_VALUES
		)
	);

	if ( '' !== $wpdb->last_error ) {
		return _spilno_tx_fail( $db_error );
	}

	// A silent reconnect of $wpdb would have run the writes above outside the
	// transaction; refuse to report success then.
	if ( (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' ) !== $connection || false === $wpdb->query( 'COMMIT' ) ) {
		_spilno_tx_rollback();
		error_log( 'spilno: commit of page ' . $record_id . ' failed or the connection changed; part of the changes may have been saved' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		return new WP_Error( 'spilno_db', __( 'The page may have been saved only partly: database error. Reload the page and check it.', 'spilno' ), array( 'values' => $values ) );
	}

	$saved['prev'] = $prev;

	return $saved;
}

/**
 * The row of a page, read straight from the table (never the object cache).
 *
 * @param int  $record_id  Record ID.
 * @param bool $for_update Lock the row (inside a transaction).
 * @return array|null
 */
function _spilno_page_row( $record_id, $for_update = false ) {
	global $wpdb;

	$table = _spilno_tables()['pages'];
	$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE record_id = %d", $record_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
	$row   = $wpdb->get_row( $for_update ? $sql . ' FOR UPDATE' : $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.

	return is_array( $row ) ? $row : null;
}

/**
 * Values of a row, normalized against the template.
 *
 * @param array $definition Template.
 * @param array $row        Row of `spilno_pages`.
 * @return array
 */
function _spilno_row_values( array $definition, array $row ) {
	$stored = json_decode( (string) $row['values_json'], true );

	return _spilno_field_normalize( $definition['root'], is_array( $stored ) ? $stored : array() );
}

/**
 * JSON of values as stored and hashed: UTF-8 as is, no escaped slashes.
 *
 * @param array $values Values.
 * @return string
 */
function _spilno_json( array $values ) {
	return (string) wp_json_encode( $values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

/**
 * Who saved, as text (never an ID: IDs mean nothing on the partner site).
 *
 * @param array $args Arguments of `spilno_save()`.
 * @return string
 */
function _spilno_author_label( array $args ) {
	if ( is_string( $args['author_label'] ?? null ) && '' !== trim( $args['author_label'] ) ) {
		return mb_substr( trim( $args['author_label'] ), 0, 190 );
	}

	$user = wp_get_current_user();

	if ( $user->exists() ) {
		return mb_substr( $user->display_name, 0, 190 );
	}

	return _spilno_cli() ? 'WP-CLI' : '';
}

/**
 * Whether the request is WP-CLI.
 *
 * @return bool
 */
function _spilno_cli() {
	return defined( 'WP_CLI' ) && WP_CLI;
}

/**
 * Name of the named lock of a page. GET_LOCK names are shared by the whole
 * database server, so the database and the table prefix are in the name.
 *
 * @param int $record_id Record ID.
 * @return string
 */
function _spilno_lock_name( $record_id ) {
	global $wpdb;

	return 'spilno_page_' . md5( DB_NAME . '|' . $wpdb->prefix . '|' . $record_id );
}

/**
 * Takes the named lock of a page (waits up to 5 s).
 *
 * @param int $record_id Record ID.
 * @return string|null Lock name; null when not acquired.
 */
function _spilno_lock( $record_id ) {
	global $wpdb;

	$name = _spilno_lock_name( $record_id );

	return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 5 )', $name ) ) ? $name : null;
}

/**
 * Releases the named lock of a page.
 *
 * @param string $name Lock name.
 * @return void
 */
function _spilno_unlock( $name ) {
	global $wpdb;

	$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) );
}

/**
 * Starts a transaction; refuses inside someone else's (START TRANSACTION
 * would commit it implicitly). `@@in_transaction` exists on MariaDB only; on
 * MySQL `@@autocommit = 0` is the only sign.
 *
 * @return bool
 */
function _spilno_tx_begin() {
	global $wpdb;

	$suppress = $wpdb->suppress_errors( true );
	$state    = $wpdb->get_row( 'SELECT @@autocommit AS autocommit', ARRAY_A );
	$open     = $wpdb->get_var( 'SELECT @@in_transaction' );
	$wpdb->suppress_errors( $suppress );
	$wpdb->last_error = '';

	if ( ! is_array( $state ) || '1' !== (string) $state['autocommit'] || '1' === (string) $open ) {
		_doing_it_wrong( 'spilno_save', 'spilno_save() must not be called inside an open transaction.', '0.1.0' );
		return false;
	}

	return false !== $wpdb->query( 'START TRANSACTION' );
}

/**
 * Rolls the transaction back.
 *
 * @return void
 */
function _spilno_tx_rollback() {
	global $wpdb;

	$wpdb->query( 'ROLLBACK' );
}

/**
 * Rolls back and returns the error.
 *
 * @param WP_Error $error Error.
 * @return WP_Error
 */
function _spilno_tx_fail( WP_Error $error ) {
	_spilno_tx_rollback();

	return $error;
}

/**
 * Error for an unknown or invalid template.
 *
 * @return WP_Error
 */
function _spilno_unknown_template_error() {
	return new WP_Error( 'spilno_unknown_template', __( 'The page template is not available. Update the Spilno plugin or choose another template.', 'spilno' ) );
}
