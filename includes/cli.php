<?php
/**
 * WP-CLI commands: `wp spilno schema-hash`, `wp spilno doctor`,
 * `wp spilno adopt`.
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

WP_CLI::add_command(
	'spilno schema-hash',
	'_spilno_cli_schema_hash',
	array(
		'shortdesc' => 'Prints the schema hash of templates (the same on both sites when the schemas are the same).',
		'synopsis'  => array(
			array(
				'type'        => 'positional',
				'name'        => 'template',
				'optional'    => true,
				'description' => 'Template key; all templates when omitted.',
			),
			array(
				'type'     => 'assoc',
				'name'     => 'format',
				'optional' => true,
				'default'  => 'table',
				'options'  => array( 'table', 'json', 'csv' ),
			),
		),
	)
);

WP_CLI::add_command(
	'spilno doctor',
	'_spilno_cli_doctor',
	array(
		'shortdesc' => 'Checks the database, templates, adapter and stored pages. Exit code 1 on a failure.',
		'synopsis'  => array(
			array(
				'type'     => 'assoc',
				'name'     => 'format',
				'optional' => true,
				'default'  => 'table',
				'options'  => array( 'table', 'json', 'csv' ),
			),
		),
	)
);

WP_CLI::add_command(
	'spilno adopt',
	'_spilno_cli_adopt',
	array(
		'shortdesc' => 'Moves the values of an existing page onto a template (the site adapter gives them through spilno_adopt_map).',
		'synopsis'  => array(
			array(
				'type' => 'positional',
				'name' => 'record_id',
			),
			array(
				'type' => 'positional',
				'name' => 'template',
			),
			array(
				'type'        => 'flag',
				'name'        => 'dry-run',
				'optional'    => true,
				'description' => 'Parse and print the values, write nothing.',
			),
			array(
				'type'        => 'flag',
				'name'        => 'force',
				'optional'    => true,
				'description' => 'Replace values the page already has (a new revision; the old ones stay in the log).',
			),
			array(
				'type'     => 'assoc',
				'name'     => 'author',
				'optional' => true,
			),
		),
	)
);

/**
 * `wp spilno schema-hash`.
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Named arguments.
 * @return void
 */
function _spilno_cli_schema_hash( $args, $assoc_args ) {
	$keys = isset( $args[0] ) ? array( $args[0] ) : array_keys( spilno_templates() );
	$rows = array();

	foreach ( $keys as $key ) {
		$template = spilno_template( $key );

		if ( null === $template ) {
			WP_CLI::error( 'Unknown template: ' . $key );
		}

		$rows[] = array(
			'template' => $key,
			'version'  => $template['version'],
			'hash'     => spilno_schema_hash( $key ),
		);
	}

	if ( ! $rows ) {
		WP_CLI::warning( 'No templates registered.' );
		return;
	}

	WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'template', 'version', 'hash' ) );
}

/**
 * `wp spilno doctor`.
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Named arguments.
 * @return void
 */
function _spilno_cli_doctor( $args, $assoc_args ) {
	$checks = spilno_doctor();

	WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $checks, array( 'status', 'check', 'message' ) );

	$fails = count( wp_list_filter( $checks, array( 'status' => 'fail' ) ) );

	if ( $fails ) {
		WP_CLI::halt( 1 );
	}
}

/**
 * `wp spilno adopt`.
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Named arguments.
 * @return void
 */
function _spilno_cli_adopt( $args, $assoc_args ) {
	if ( ! preg_match( '/^[1-9][0-9]*$/', $args[0] ) ) {
		WP_CLI::error( 'record_id must be a positive integer.' );
	}

	$result = spilno_adopt(
		(int) $args[0],
		$args[1],
		array(
			'dry_run'      => ! empty( $assoc_args['dry-run'] ),
			'force'        => ! empty( $assoc_args['force'] ),
			'author_label' => $assoc_args['author'] ?? 'WP-CLI adopt',
		)
	);

	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();

		foreach ( (array) ( $data['errors'] ?? array() ) as $path => $message ) {
			WP_CLI::warning( ( '' === $path ? '(form)' : $path ) . ': ' . $message );
		}

		WP_CLI::error( $result->get_error_code() . ': ' . $result->get_error_message() );
	}

	WP_CLI::line( (string) wp_json_encode( $result['values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) );

	if ( $result['dry_run'] ) {
		WP_CLI::success( 'Dry run: the values pass the template rules; nothing written.' );
		return;
	}

	WP_CLI::success( sprintf( 'Adopted: revision %d (%s).', $result['revision'], $result['rev_id'] ) );
}
