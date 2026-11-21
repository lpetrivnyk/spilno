<?php
/**
 * Dev-only checks of stage 1: `wp spilno-test run`.
 *
 * Works on the test template and on pages it creates itself (titles
 * «spilno-test …»; removed at the start of every run together with their rows).
 * Prints one line per failed check and a total; exit code 1 on a failure.
 *
 * @package SpilnoTest
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

WP_CLI::add_command( 'spilno-test run', 'spilno_test_run' );
WP_CLI::add_command( 'spilno-test lock-name', 'spilno_test_lock_name' );
WP_CLI::add_command( 'spilno-test save', 'spilno_test_save_once' );

/**
 * Prints the lock name of a record (for the shell lock check).
 *
 * @param array $args [ record_id ].
 */
function spilno_test_lock_name( $args ) {
	WP_CLI::line( _spilno_lock_name( (int) $args[0] ) );
}

/**
 * One save of the current values of a record (for the shell lock check):
 * prints the result code and the time it took.
 *
 * @param array $args [ record_id ].
 */
function spilno_test_save_once( $args ) {
	$id    = (int) $args[0];
	$state = spilno_page_state( $id );
	$start = microtime( true );
	$r     = spilno_save( $id, spilno_values( $id ), $state['rev_id'], array( 'template' => 'test', 'schema_hash' => spilno_schema_hash( 'test' ) ) );
	WP_CLI::line( ( is_wp_error( $r ) ? $r->get_error_code() : 'saved' ) . ' ' . round( microtime( true ) - $start, 1 ) . 's' );
}

/**
 * Valid raw input of the test template, as a form sends it.
 *
 * @return array
 */
function spilno_test_raw() {
	return array(
		'hero'   => array(
			'title' => 'Про нас',
			'lede'  => "Рядок один\r\nрядок два",
			'photo' => '',
		),
		'cards'  => array(
			'title' => 'Напрями',
			'items' => array(
				array(
					'title' => 'Перша',
					'text'  => 'Текст',
				),
				array(
					'title' => '',
					'text'  => '',
				),
			),
		),
		'hidden' => array(),
	);
}

/**
 * Runs the checks.
 */
function spilno_test_run() {
	global $wpdb;

	$pass  = 0;
	$fails = array();
	$check = static function ( $name, $ok, $detail = '' ) use ( &$pass, &$fails ) {
		if ( $ok ) {
			++$pass;
		} else {
			$fails[] = $name . ( '' !== $detail ? ' — ' . $detail : '' );
		}
	};
	$err   = static function ( array $parsed, $path ) {
		return $parsed['errors'][ $path ] ?? '';
	};

	wp_set_current_user( 1 );
	delete_option( 'spilno_test_schema_variant' );
	_spilno_registry( true );

	// Clean up previous runs.
	$tables = _spilno_tables();
	foreach ( get_posts( array( 'post_type' => array( 'page', 'attachment' ), 'post_status' => 'any', 'numberposts' => -1, 's' => 'spilno-test' ) ) as $old ) {
		$wpdb->delete( $tables['pages'], array( 'record_id' => $old->ID ) );
		$wpdb->delete( $tables['log'], array( 'record_id' => $old->ID ) );
		wp_delete_post( $old->ID, true );
	}

	$hash = spilno_schema_hash( 'test' );

	// ---- Registry and schema hash.
	$check( 'registry: test template loaded', null !== spilno_template( 'test' ) );
	$check( 'registry: no problems', array() === _spilno_template_problems(), wp_json_encode( _spilno_template_problems() ) );
	$check( 'hash: sha256', 1 === preg_match( '/^[0-9a-f]{64}$/', $hash ) );
	_spilno_registry( true );
	$check( 'hash: stable after a registry reload', spilno_schema_hash( 'test' ) === $hash );
	$check( 'hash: same with every label translated differently', spilno_test_hash_relabelled( 'test' ) === $hash );

	foreach ( array(
		'label' => true,
		'max'   => false,
		'v2'    => false,
	) as $variant => $same ) {
		update_option( 'spilno_test_schema_variant', $variant );
		_spilno_registry( true );
		$check( "hash: variant $variant " . ( $same ? 'keeps' : 'changes' ) . ' the hash', ( spilno_schema_hash( 'test' ) === $hash ) === $same );
	}
	delete_option( 'spilno_test_schema_variant' );
	_spilno_registry( true );
	$check( 'hash: back to base', spilno_schema_hash( 'test' ) === $hash );
	$check( 'hash: unknown template is empty', '' === spilno_schema_hash( 'nope' ) );

	// ---- Invalid definitions are refused, not fatal.
	$problems = array();
	_spilno_template_normalize(
		'bad',
		array(
			'label'    => 'Bad',
			'version'  => 1,
			'sections' => array(
				's' => array(
					'label'  => 'S',
					'fields' => array(
						'a' => array( 'type' => 'text', 'label' => 'A' ),
						'b' => array( 'type' => 'wysiwyg', 'label' => 'B' ),
						'c' => array( 'type' => 'text', 'label' => 'C', 'max' => 5, 'default' => 'too long' ),
						'd' => array( 'type' => 'image', 'label' => 'D', 'default' => 5 ),
					),
				),
			),
		),
		$problems
	);
	$check( 'registry: invalid definition reports 4 problems', count( $problems ) >= 4, implode( '; ', $problems ) );

	// ---- Form schema.
	$form = spilno_form_schema( 'test' );
	$check( 'form: hash', is_array( $form ) && $form['hash'] === $hash );
	$check( 'form: names', is_array( $form ) && 'spilno[cards][items][1][title]' === $form['fields'][1]['fields'][1]['items'][1]['fields'][0]['name'] );
	$check( 'form: unknown template', is_wp_error( spilno_form_schema( 'nope' ) ) );

	// ---- Parse.
	$ok = spilno_parse( 'test', spilno_test_raw() );
	$check( 'parse: valid input has no errors', array() === $ok['errors'], wp_json_encode( $ok['errors'], JSON_UNESCAPED_UNICODE ) );
	$check( 'parse: \r\n → \n', "Рядок один\nрядок два" === $ok['values']['hero']['lede'] );
	$check( 'parse: empty image → 0', 0 === $ok['values']['hero']['photo'] );
	$check( 'parse: absent toggle → false', false === $ok['values']['hidden']['cards'] );

	$case = static function ( $mutate ) {
		$raw = spilno_test_raw();
		$mutate( $raw );
		return spilno_parse( 'test', $raw );
	};

	$p = $case( static function ( &$r ) { $r['hero']['title'] = str_repeat( 'Ї', 20 ); } );
	$check( 'limit: 20 Cyrillic chars fit max 20', '' === $err( $p, 'hero.title' ) );
	$p = $case( static function ( &$r ) { $r['hero']['title'] = str_repeat( 'Ї', 21 ); } );
	$check( 'limit: 21 chars → field error', str_contains( $err( $p, 'hero.title' ), '20' ) );
	$p = $case( static function ( &$r ) { $r['hero']['lede'] = str_repeat( 'а', 14 ) . "\r\n" . str_repeat( 'б', 15 ); } );
	$check( 'limit: \r\n counts as one char', '' === $err( $p, 'hero.lede' ) );
	$p = $case( static function ( &$r ) { $r['cards']['items'][1]['title'] = str_repeat( 'x', 11 ); } );
	$check( 'limit: in a list item', '' !== $err( $p, 'cards.items.1.title' ) );

	$p = $case( static function ( &$r ) { $r['hero']['title'] = 'Про <b>нас</b>'; } );
	$check( 'clean: tag → field error', '' !== $err( $p, 'hero.title' ) && 'Про <b>нас</b>' === $p['values']['hero']['title'] );
	$p = $case( static function ( &$r ) { $r['hero']['title'] = 'Про%20нас'; } );
	$check( 'clean: %20 → field error', '' !== $err( $p, 'hero.title' ) );
	$p = $case( static function ( &$r ) { $r['hero']['title'] = 'AT&amp;T'; } );
	$check( 'clean: &amp; → field error', '' !== $err( $p, 'hero.title' ) );
	$p = $case( static function ( &$r ) { $r['hero']['title'] = 'a < b & c'; } );
	$check( 'clean: lone < and & are plain text', '' === $err( $p, 'hero.title' ) && 'a < b & c' === $p['values']['hero']['title'] );
	$p = $case( static function ( &$r ) { $r['hero']['title'] = "  два   пробіли\t"; } );
	$check( 'clean: whitespace collapsed silently', '' === $err( $p, 'hero.title' ) && 'два пробіли' === $p['values']['hero']['title'] );
	$p = $case( static function ( &$r ) { $r['hero']['title'] = "bad \xC3\x28 utf"; } );
	$check( 'clean: invalid UTF-8 → field error', '' !== $err( $p, 'hero.title' ) );

	$p = $case( static function ( &$r ) { $r['hero']['bogus'] = 'x'; } );
	$check( 'unknown: field', '' !== $err( $p, 'hero.bogus' ) );
	$p = $case( static function ( &$r ) { $r['extra'] = array(); } );
	$check( 'unknown: section', '' !== $err( $p, 'extra' ) );
	$p = $case( static function ( &$r ) { $r['cards']['items'][2] = array( 'title' => 'x', 'text' => '' ); } );
	$check( 'unknown: list index beyond count', '' !== $err( $p, 'cards.items.2' ) );

	$p = $case( static function ( &$r ) { $r['hero']['title'] = array( 'x' ); } );
	$check( 'shape: array instead of string → field error', '' !== $err( $p, 'hero.title' ) && '' === $p['values']['hero']['title'] );
	$p = $case( static function ( &$r ) { $r['hero'] = 'x'; } );
	$check( 'shape: string instead of group → field error', '' !== $err( $p, 'hero' ) );
	$p = $case( static function ( &$r ) { $r['cards']['items'] = 'x'; } );
	$check( 'shape: string instead of list → field error', '' !== $err( $p, 'cards.items' ) );
	$p = spilno_parse( 'test', 'x' );
	$check( 'shape: string instead of the form → error', '' !== $err( $p, '' ) );
	$p = $case( static function ( &$r ) { unset( $r['hero']['title'] ); } );
	$check( 'missing: text field → error', '' !== $err( $p, 'hero.title' ) );

	foreach ( array( 'abc', '-1', '01', '1.5', array( 1 ) ) as $bad ) {
		$p = $case( static function ( &$r ) use ( $bad ) { $r['hero']['photo'] = $bad; } );
		$check( 'image: ' . wp_json_encode( $bad ) . ' → field error', '' !== $err( $p, 'hero.photo' ) );
	}
	$p = $case( static function ( &$r ) { $r['hero']['photo'] = '999999'; } );
	$check( 'image: missing attachment → field error', '' !== $err( $p, 'hero.photo' ) );
	$image = wp_insert_attachment( array( 'post_title' => 'spilno-test image', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), 'spilno-test.png' );
	$p     = $case( static function ( &$r ) use ( $image ) { $r['hero']['photo'] = (string) $image; } );
	$check( 'image: real image attachment', '' === $err( $p, 'hero.photo' ) && $image === $p['values']['hero']['photo'] );
	$page_id = wp_insert_post( array( 'post_title' => 'spilno-test record', 'post_type' => 'page', 'post_status' => 'draft' ) );
	$p       = $case( static function ( &$r ) use ( $page_id ) { $r['hero']['photo'] = (string) $page_id; } );
	$check( 'image: a page is not an image', '' !== $err( $p, 'hero.photo' ) );

	$p = $case( static function ( &$r ) { $r['hidden']['cards'] = '1'; } );
	$check( 'toggle: "1" → true', true === $p['values']['hidden']['cards'] && array() === $p['errors'] );
	$p = $case( static function ( &$r ) { $r['hidden']['cards'] = 'yes'; } );
	$check( 'toggle: "yes" → field error', '' !== $err( $p, 'hidden.cards' ) );
	$p = spilno_parse( 'nope', array() );
	$check( 'parse: unknown template → form error', '' !== $err( $p, '' ) );

	// ---- Required / publication gate.
	$p = $case( static function ( &$r ) { $r['hero']['title'] = ''; } );
	$check( 'required: empty title parses (draft allowed)', array() === $p['errors'] );
	$gate = spilno_publishable( $page_id, $p['values'], 'test' );
	$check( 'required: empty title without default → not publishable', is_wp_error( $gate ) && isset( $gate->get_error_data()['errors']['hero.title'] ) );
	$check( 'required: empty card title with default → fine', is_wp_error( $gate ) && ! isset( $gate->get_error_data()['errors']['cards.items.1.title'] ) );
	$check( 'required: filled → publishable', true === spilno_publishable( $page_id, $ok['values'], 'test' ) );
	$check( 'required: page without values → not publishable', is_wp_error( spilno_publishable( $page_id ) ) );

	// ---- Save, revisions, lock.
	$args   = array( 'template' => 'test', 'schema_hash' => $hash );
	$events = array();
	$listen = static function ( $id, $rev, $values, $prev ) use ( &$events ) {
		$events[] = array( $rev, $prev );
	};
	add_action( 'spilno_values_saved', $listen, 10, 4 );

	$s1 = spilno_save( $page_id, $ok['values'], '', $args );
	$check( 'save: first save → revision 1', is_array( $s1 ) && 1 === $s1['revision'] && wp_is_uuid( $s1['rev_id'], 4 ) && '' === $s1['parent_rev_id'], is_wp_error( $s1 ) ? $s1->get_error_code() : '' );
	$check( 'save: values read back', spilno_values( $page_id ) === $ok['values'] );
	$state = spilno_page_state( $page_id );
	$check( 'state: local mode, rev_id, author', is_array( $s1 ) && $s1['rev_id'] === $state['rev_id'] && 'local' === $state['mode'] && false === $state['read_only'] && '' !== $state['updated_by_label'] && $state['schema_hash'] === $hash );
	$check( 'action: spilno_values_saved, prev null', 1 === count( $events ) && null === $events[0][1] );
	$check( 'gate: stored values publishable', true === spilno_publishable( $page_id ) );

	// Two forms opened at revision 1.
	$form_a        = $ok['values'];
	$form_a['hero']['title'] = 'Форма А';
	$form_b        = $ok['values'];
	$form_b['hero']['title'] = 'Форма Б';
	$sa            = spilno_save( $page_id, $form_a, $s1['rev_id'], $args );
	$sb            = spilno_save( $page_id, $form_b, $s1['rev_id'], $args );
	$check( 'conflict: form A saves → revision 2', is_array( $sa ) && 2 === $sa['revision'] && $sa['parent_rev_id'] === $s1['rev_id'] );
	$check( 'conflict: form B (same base) refused', is_wp_error( $sb ) && 'spilno_conflict' === $sb->get_error_code() );
	$check( 'conflict: B values come back to the form', is_wp_error( $sb ) && 'Форма Б' === $sb->get_error_data()['values']['hero']['title'] );
	$check( 'conflict: message names who saved', is_wp_error( $sb ) && str_contains( $sb->get_error_message(), wp_get_current_user()->display_name ) );
	$check( 'conflict: nothing written', 'Форма А' === spilno_values( $page_id )['hero']['title'] && 2 === spilno_page_state( $page_id )['revision'] );
	$check( 'action: prev is the previous values', 2 === count( $events ) && $events[1][1] === $ok['values'] );
	$sc = spilno_save( $page_id, $form_b, '', $args );
	$check( 'conflict: "" against existing values refused', is_wp_error( $sc ) && 'spilno_conflict' === $sc->get_error_code() );

	$bad = spilno_save( $page_id, $form_a, $sa['rev_id'], array( 'template' => 'test', 'schema_hash' => str_repeat( '0', 64 ) ) );
	$check( 'schema: form with an old hash refused', is_wp_error( $bad ) && 'spilno_schema_changed' === $bad->get_error_code() );
	$bad = spilno_save( $page_id, $form_a, $sa['rev_id'], array( 'template' => 'test' ) );
	$check( 'schema: form without a hash refused', is_wp_error( $bad ) && 'spilno_schema_changed' === $bad->get_error_code() );

	$typed                   = $form_a;
	$typed['hero']['title']  = 42;
	$typed['hero']['photo']  = '5';
	$typed['hidden']['cards'] = 'yes';
	unset( $typed['cards']['title'] );
	$typed['cards']['bogus'] = '';
	$bad                     = spilno_save( $page_id, $typed, $sa['rev_id'], $args );
	$errors                  = is_wp_error( $bad ) ? $bad->get_error_data()['errors'] : array();
	$check( 'validate: typed values checked strictly on save', is_wp_error( $bad ) && 'spilno_invalid' === $bad->get_error_code() && isset( $errors['hero.title'], $errors['hero.photo'], $errors['hidden.cards'], $errors['cards.title'], $errors['cards.bogus'] ), wp_json_encode( $errors ) );
	$typed                  = $form_a;
	$typed['hero']['title'] = 'Про <b>нас</b>';
	$bad                    = spilno_save( $page_id, $typed, $sa['rev_id'], $args );
	$check( 'validate: tag in a typed value refused on save', is_wp_error( $bad ) && isset( $bad->get_error_data()['errors']['hero.title'] ) );
	$check( 'validate: nothing written', 2 === spilno_page_state( $page_id )['revision'] );

	$check( 'save: unknown template', is_wp_error( spilno_save( $page_id, $form_a, $sa['rev_id'], array( 'template' => 'nope', 'schema_hash' => '' ) ) ) );
	$check( 'save: missing record', 'spilno_no_record' === spilno_save( 99999999, $form_a, '', $args )->get_error_code() );

	// Open transaction of a caller.
	$wpdb->query( 'START TRANSACTION' );
	$tx = @spilno_save( $page_id, $form_a, $sa['rev_id'], $args ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- _doing_it_wrong notice expected.
	$wpdb->query( 'ROLLBACK' );
	$check( 'tx: refused inside an open transaction', is_wp_error( $tx ) && 'spilno_db' === $tx->get_error_code() );

	// Rights through the adapter.
	wp_set_current_user( 0 );
	$check( 'can: anonymous cannot edit', false === _spilno_can( 'edit' ) );
	wp_set_current_user( 1 );
	$check( 'can: administrator can edit', true === _spilno_can( 'edit' ) );

	// Log: values kept for the newest 20 rows only.
	$rev = $sa['rev_id'];
	for ( $i = 0; $i < 21; $i++ ) {
		$form_a['cards']['title'] = 'Ревізія ' . $i;
		$rev                      = spilno_save( $page_id, $form_a, $rev, $args )['rev_id'];
	}
	$log = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, COUNT(values_json) AS with_values, COUNT(DISTINCT rev_id) AS revs, MAX(revision) AS last FROM {$tables['log']} WHERE record_id = %d", $page_id ), ARRAY_A );
	$check( 'log: 23 rows, 20 with values', '23' === $log['total'] && '20' === $log['with_values'] && '23' === $log['revs'] && '23' === $log['last'], wp_json_encode( $log ) );
	$chain = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['log']} AS c JOIN {$tables['log']} AS p ON p.rev_id = c.parent_rev_id AND p.revision = c.revision - 1 WHERE c.record_id = %d", $page_id ) );
	$check( 'log: parent_rev_id chain unbroken', '22' === $chain, (string) $chain );
	$hash_ok = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tables['log']} WHERE record_id = %d AND values_json IS NOT NULL AND values_hash = SHA2( values_json, 256 )", $page_id ) );
	$check( 'log: values_hash = sha256(values_json)', '20' === $hash_ok );

	// ---- Normalization on read and a schema version change.
	$stored                   = $form_a;
	$stored['hero']['title']  = str_repeat( 'x', 25 );
	$stored['hero']['legacy'] = 'old';
	$wpdb->update( $tables['pages'], array( 'values_json' => _spilno_json( $stored ) ), array( 'record_id' => $page_id ) );
	$read = spilno_values( $page_id );
	$check( 'normalize: over-limit stored value → empty', '' === $read['hero']['title'] );
	$check( 'normalize: unknown stored key dropped', ! isset( $read['hero']['legacy'] ) );
	update_option( 'spilno_test_schema_variant', 'v2' );
	_spilno_registry( true );
	$state = spilno_page_state( $page_id );
	$check( 'schema v2: page reports an old schema', false === $state['schema_current'] && 1 === $state['schema_version'] );
	$check( 'schema v2: new field read as empty', '' === spilno_values( $page_id )['hero']['note'] );
	$form_v1 = $form_a;
	$bad     = spilno_save( $page_id, $form_v1, $state['rev_id'], $args );
	$check( 'schema v2: form of v1 refused', is_wp_error( $bad ) && 'spilno_schema_changed' === $bad->get_error_code() );
	delete_option( 'spilno_test_schema_variant' );
	_spilno_registry( true );

	// ---- Adopt.
	$adopt_id = wp_insert_post( array( 'post_title' => 'spilno-test adopt', 'post_type' => 'page', 'post_status' => 'publish' ) );
	update_post_meta( $adopt_id, '_spilno_test_map', wp_slash( wp_json_encode( array( 'hero.title' => 'Перенесено', 'cards.items.0.title' => 'К1', 'hidden.cards' => true, 'hero.photo' => $image ), JSON_UNESCAPED_UNICODE ) ) );
	$dry = spilno_adopt( $adopt_id, 'test', array( 'dry_run' => true ) );
	$check( 'adopt: dry run writes nothing', is_array( $dry ) && $dry['dry_run'] && ! spilno_page_state( $adopt_id )['has_values'] );
	$ad = spilno_adopt( $adopt_id, 'test' );
	$v  = spilno_values( $adopt_id );
	$check( 'adopt: revision 1, values mapped', is_array( $ad ) && 1 === $ad['revision'] && 'Перенесено' === $v['hero']['title'] && true === $v['hidden']['cards'] && $image === $v['hero']['photo'] );
	$check( 'adopt: absent path = empty (default)', '' === $v['hero']['lede'] && '' === $v['cards']['items'][1]['title'] );
	$check( 'adopt: log event', 'adopt' === $wpdb->get_var( $wpdb->prepare( "SELECT event FROM {$tables['log']} WHERE record_id = %d", $adopt_id ) ) );
	$check( 'adopt: second time refused', is_wp_error( spilno_adopt( $adopt_id, 'test' ) ) );
	$check( 'adopt: --force → revision 2', 2 === ( spilno_adopt( $adopt_id, 'test', array( 'force' => true ) )['revision'] ?? 0 ) );

	$adopt_bad = wp_insert_post( array( 'post_title' => 'spilno-test adopt bad', 'post_type' => 'page', 'post_status' => 'draft' ) );
	update_post_meta( $adopt_bad, '_spilno_test_map', wp_slash( wp_json_encode( array( 'hero.title' => '<i>x</i>', 'hero.nope' => 'x', 'cards.items.5.title' => 'x', 'cards' => 'x' ) ) ) );
	$r = spilno_adopt( $adopt_bad, 'test' );
	$e = is_wp_error( $r ) ? $r->get_error_data()['errors'] : array();
	$check( 'adopt: bad map → errors by path, nothing written', isset( $e['hero.title'], $e['hero.nope'], $e['cards.items.5.title'], $e['cards'] ) && ! spilno_page_state( $adopt_bad )['has_values'], wp_json_encode( $e ) );
	$check( 'adopt: no map → refused', 'spilno_no_map' === spilno_adopt( $page_id, 'test', array( 'force' => true ) )->get_error_code() );

	// ---- Contract wrappers.
	$check( 'contract: page without a spilno template → not_spilno', 'not_spilno' === spilno_resolve_url( get_permalink( $adopt_bad ) )->get_error_code() );
	update_post_meta( $adopt_id, '_spilno_test_template', 'test' );
	$check( 'contract: resolve_url of a spilno page', $adopt_id === ( spilno_resolve_url( get_permalink( $adopt_id ) )['record_id'] ?? 0 ) );
	$check( 'contract: foreign host', 'foreign_host' === spilno_resolve_url( 'https://example.org/x/' )->get_error_code() );
	add_filter( 'spilno_resolve_url', $bad_answer = static fn() => array( 'record_id' => '5' ), 99 );
	$check( 'contract: invalid adapter answer → adapter_invalid', 'adapter_invalid' === spilno_resolve_url( home_url( '/' ) )->get_error_code() );
	remove_filter( 'spilno_resolve_url', $bad_answer, 99 );
	$check( 'contract: record_url', get_permalink( $adopt_id ) === spilno_record_url( $adopt_id ) );
	$check( 'contract: site label', 'Spilno dev' === _spilno_site_label() );

	remove_action( 'spilno_values_saved', $listen, 10 );

	spilno_test_about( $check );

	foreach ( $fails as $fail ) {
		WP_CLI::line( 'FAIL ' . $fail );
	}

	WP_CLI::line( sprintf( 'spilno-test: %d/%d passed', $pass, $pass + count( $fails ) ) );

	if ( $fails ) {
		WP_CLI::halt( 1 );
	}
}

/**
 * Checks of the template «about»: schema, limits, defaults, hash, render with
 * full and empty slots, hiding of blocks.
 *
 * @param callable $check The check recorder.
 */
function spilno_test_about( $check ) {
	global $wpdb;

	$tables = _spilno_tables();
	$hash   = spilno_schema_hash( 'about' );

	$check( 'about: registered, valid', null !== spilno_template( 'about' ) && ! isset( _spilno_template_problems()['about'] ) );
	_spilno_registry( true );
	$check( 'about: hash stable after a reload', spilno_schema_hash( 'about' ) === $hash );
	$check( 'about: hash does not depend on the labels (locale)', spilno_test_hash_relabelled( 'about' ) === $hash );
	$check( 'about: slots', array( 'contacts', 'departments', 'director', 'facts', 'history', 'leadership', 'medics', 'news', 'patients', 'reports', 'vacancies' ) === spilno_template( 'about' )['slots'] );

	$form = spilno_form_schema( 'about' );
	$find = static function ( $fields, $path ) use ( &$find ) {
		foreach ( $fields as $f ) {
			if ( ( $f['path'] ?? '' ) === $path ) {
				return $f;
			}
			$found = $find( array_merge( $f['fields'] ?? array(), ...array_map( static fn( $i ) => $i['fields'], $f['items'] ?? array() ) ), $path );
			if ( $found ) {
				return $found;
			}
		}
		return null;
	};
	$check( 'about: default of card 2 title (Figma)', 'Ми вітаємо вас' === ( $find( $form['fields'], 'directions.cards.1.title' )['default'] ?? '' ) );
	$check( 'about: default of tile 4 text (Figma)', 'Від 1949 року до сьогодні' === ( $find( $form['fields'], 'sections.tiles.3.text' )['default'] ?? '' ) );
	$check( 'about: default of mosaic link 5', 'Контакти' === ( $find( $form['fields'], 'mosaic.links.4.label' )['default'] ?? '' ) );
	$check( 'about: limits 120 / 300 / 40 / 400', 120 === $find( $form['fields'], 'directions.title' )['max'] && 300 === $find( $form['fields'], 'directions.cards.0.text' )['max'] && 40 === $find( $form['fields'], 'hero.action' )['max'] && 400 === $find( $form['fields'], 'sections.lede' )['max'] && 400 === $find( $form['fields'], 'hero.legal' )['max'] );

	$empty = _spilno_field_empty( spilno_template( 'about' )['root'] );
	$raw   = _spilno_adopt_blank( spilno_template( 'about' )['root'] );
	$p     = spilno_parse( 'about', $raw );
	$check( 'about: empty form parses', array() === $p['errors'] && $p['values'] === $empty, wp_json_encode( $p['errors'] ) );

	$over = static function ( $path, $len ) use ( $raw ) {
		$r = $raw;
		_spilno_adopt_set( $r, spilno_template( 'about' )['root'], explode( '.', $path ), str_repeat( 'ж', $len ) );
		return spilno_parse( 'about', $r )['errors'][ $path ] ?? '';
	};
	$check( 'about: title 120 ok, 121 error', '' === $over( 'mosaic.title', 120 ) && '' !== $over( 'mosaic.title', 121 ) );
	$check( 'about: card text 300 ok, 301 error', '' === $over( 'directions.cards.2.text', 300 ) && '' !== $over( 'directions.cards.2.text', 301 ) );
	$check( 'about: caption 40 ok, 41 error', '' === $over( 'sections.tiles.5.cta', 40 ) && '' !== $over( 'mosaic.links.0.label', 41 ) );
	$check( 'about: lede 400 ok, 401 error', '' === $over( 'sections.lede', 400 ) && '' !== $over( 'hero.legal', 401 ) );
	$check( 'about: alt 300', '' === $over( 'photo_main.alt', 300 ) && '' !== $over( 'photo_main.alt', 301 ) );

	// Render.
	$page = wp_insert_post( array( 'post_title' => 'spilno-test about', 'post_type' => 'page', 'post_status' => 'publish' ) );
	$check( 'about: publishable with defaults only', true === spilno_publishable( $page, $empty, 'about' ) );
	$render = static function ( $context = array() ) use ( $page ) {
		ob_start();
		spilno_render( $page, $context + array( 'template' => 'about' ) );
		return (string) ob_get_clean();
	};
	$sections = static function ( $html ) {
		preg_match_all( '/<section\b[^>]*aria-labelledby="([^"]*)"/', $html, $m );
		return $m[1];
	};
	$no_empty = static function ( $html ) {
		// No empty heading, paragraph, list or section (a section has more than its head).
		if ( preg_match( '#<(h[1-3]|p|ul|li)\b[^>]*>\s*</\1>#', $html ) ) {
			return false;
		}
		preg_match_all( '#<section\b.*?</section>#s', $html, $m );
		foreach ( $m[0] as $section ) {
			$rest = preg_replace( '#<div class="spilno-section-head.*?</div></div>#s', '', $section );
			if ( ! preg_match( '#<(ul|div class="spilno-bento|div class="spilno-section__actions)#', $rest ) ) {
				return false;
			}
		}
		return true;
	};

	update_option( 'spilno_test_slots', '' );
	$full = $render( array( 'before' => '<nav class="x-crumbs">crumbs</nav>' ) );
	$check( 'render: defaults without values', str_contains( $full, 'Люди, які змінюють медицину' ) && str_contains( $full, 'spilno-test about</h1>' ) );
	$check( 'render: four sections in order', array( 'spilno-about-directions', 'spilno-about-mosaic', 'spilno-about-sections', 'spilno-about-news' ) === $sections( $full ), implode( ',', $sections( $full ) ) );
	$check( 'render: before printed inside the root', 1 === preg_match( '#<div class="spilno spilno--about">\s*<div class="spilno-backdrop"[^>]*></div>\s*<nav class="x-crumbs">#', $full ) );
	$check( 'render: lede with both figure sentences', str_contains( $full, 'обласної ради. Заклад вищої акредитаційної категорії. Надає' ) );
	$check( 'render: plural lines', str_contains( $full, '<li>702 середніх медичних працівники</li>' ) && str_contains( $full, '<li>508 лікарів</li>' ) );
	$check( 'render: 3 news of 4', 3 === substr_count( $full, 'spilno-news-card__link" href="' . home_url( '/news/' ) ) );
	$check( 'render: mosaic 4 columns, 3 pills', str_contains( $full, 'spilno-bento--cols-4' ) && 3 === substr_count( $full, 'class="spilno-pill"' ) );
	$check( 'render: no empty elements (full)', $no_empty( $full ) );

	update_option( 'spilno_test_slots', 'data-empty' );
	$data = $render();
	$check( 'empty data: no figures anywhere', ! preg_match( '/1949|1 200|508|702|36 спец|Команда|Акредитація|Ліцензія|НСЗУ/u', wp_strip_all_tags( $data ) ) && ! preg_match( '/spilno-stat|spilno-pill|spilno-bento__card--stat/', $data ) );
	$check( 'empty data: lede is the legal name only', str_contains( $data, 'обласної ради.</p>' ) );
	$check( 'empty data: no news section', ! str_contains( $data, 'spilno-about-news' ) && ! str_contains( $data, 'Життя лікарні' ) );
	$check( 'empty data: history tile without description', 1 === preg_match( '#Історія лікарні</a></h3></div>#u', $data ) );
	$check( 'empty data: mosaic 3 columns (photo, photo, links)', str_contains( $data, 'spilno-bento--cols-3' ) );
	$check( 'empty data: sections in order', array( 'spilno-about-directions', 'spilno-about-mosaic', 'spilno-about-sections' ) === $sections( $data ) );
	$check( 'empty data: no empty elements', $no_empty( $data ) );

	update_option( 'spilno_test_slots', 'all-empty' );
	$none = $render();
	$check( 'no targets: no links or buttons at all', ! str_contains( $none, '<a ' ) && ! str_contains( $none, 'spilno-btn' ) );
	$check( 'no targets: cards and tiles are text', ! str_contains( $none, 'spilno-news-card--link' ) && ! str_contains( $none, 'spilno-tile--link' ) && 6 === substr_count( $none, 'spilno-tile--text' ) && ! str_contains( $none, 'spilno-news-card__more' ) && ! str_contains( $none, 'spilno-tile__cta' ) );
	$check( 'no targets: mosaic of two photos', str_contains( $none, 'spilno-bento--cols-2' ) );
	$check( 'no targets: no empty elements', $no_empty( $none ) );

	// Hide blocks, one flag at a time (full slots).
	update_option( 'spilno_test_slots', '' );
	$rev = '';
	foreach ( array(
		'directions' => static fn( $h ) => ! str_contains( $h, 'spilno-about-directions' ) && ! str_contains( $h, 'Ми вітаємо вас' ),
		'mosaic'     => static fn( $h ) => ! str_contains( $h, 'spilno-about-mosaic' ) && ! str_contains( $h, 'spilno-pill' ) && ! str_contains( $h, 'Усі відділення' ),
		'sections'   => static fn( $h ) => ! str_contains( $h, 'spilno-tile' ) && ! str_contains( $h, 'РОЗДІЛИ' ) && str_contains( $h, 'spilno-stat-grid' ) && 1 === preg_match( '#<section class="[^"]*spilno-section--paper[^"]*"><div[^>]*><ul class="spilno-stat-grid#', $h ),
		'news'       => static fn( $h ) => ! str_contains( $h, 'spilno-about-news' ),
	) as $flag => $ok ) {
		$values                    = $empty;
		$values['hidden'][ $flag ] = true;
		$saved                     = spilno_save( $page, $values, $rev, array( 'template' => 'about', 'schema_hash' => $hash ) );
		$rev                       = is_array( $saved ) ? $saved['rev_id'] : $rev;
		$html                      = $render();
		$check( "hide $flag: block gone, the rest stays", is_array( $saved ) && $ok( $html ) && 3 === count( $sections( $html ) ), implode( ',', $sections( $html ) ) );
		$check( "hide $flag: no empty elements", $no_empty( $html ) );
	}

	// Stored values are rendered, empty ones fall back to defaults.
	$values                                   = $empty;
	$values['directions']['cards'][1]['title'] = 'Свій заголовок';
	$values['photo_main']['alt']              = 'Корпус лікарні';
	$saved                                    = spilno_save( $page, $values, $rev, array( 'template' => 'about', 'schema_hash' => $hash ) );
	$html                                     = $render();
	$check( 'render: stored value shown, others default', is_array( $saved ) && str_contains( $html, '>Свій заголовок</a>' ) && str_contains( $html, '>Змінюємо медицину</a>' ) );
	$check( 'render: placeholder photo stays decorative', 1 === preg_match( '#spilno-photo-band__media" aria-hidden="true">\\s*<div class="spilno-photo-band__frame"></div>#', $html ) );

	// The render filter: applied on render only, a malformed answer is ignored.
	$seen = array();
	$filter = static function ( $values, $id, $template, $context ) use ( &$seen ) {
		$seen = $context;
		$values['news']['title'] = 'News (en)';
		return $values;
	};
	add_filter( 'spilno_render_values', $filter, 10, 4 );
	$html = $render( array( 'lang' => 'en' ) );
	$check( 'render_values: applied with lang', str_contains( $html, '>News (en)</h2>' ) && 'en' === ( $seen['lang'] ?? '' ) );
	$check( 'render_values: not seen by spilno_values()', 'News (en)' !== spilno_values( $page )['news']['title'] );
	remove_filter( 'spilno_render_values', $filter, 10 );
	$bad = static fn() => array( 'news' => array( 'title' => array( 'x' ) ) );
	add_filter( 'spilno_render_values', $bad );
	$check( 'render_values: malformed answer ignored', str_contains( $render(), '>Життя лікарні</h2>' ) );
	remove_filter( 'spilno_render_values', $bad );

	add_filter( 'spilno_enabled', '__return_false' );
	$check( 'render: spilno_enabled false → nothing', '' === $render() );
	remove_filter( 'spilno_enabled', '__return_false' );

	delete_option( 'spilno_test_slots' );
}

/**
 * Schema hash with every translatable label changed (as under another
 * locale): must equal the plain hash.
 *
 * @param string $key Template key.
 * @return string
 */
function spilno_test_hash_relabelled( $key ) {
	$relabel = static fn( $text ) => 'X ' . $text;
	add_filter( 'gettext', $relabel );
	_spilno_registry( true );
	$label = spilno_template( $key )['label'];
	$hash  = spilno_schema_hash( $key );
	remove_filter( 'gettext', $relabel );
	_spilno_registry( true );

	return str_starts_with( $label, 'X ' ) ? $hash : 'labels-not-translated';
}
