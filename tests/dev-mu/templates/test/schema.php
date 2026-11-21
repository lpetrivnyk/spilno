<?php
/**
 * Test template of the harness (loaded only through SPILNO_DEV_TEMPLATES_DIR
 * on local / development). The option `spilno_test_schema_variant` changes it
 * to check the schema hash:
 *   - ''      — base;
 *   - 'label' — only a label changed (hash must stay);
 *   - 'max'   — a limit changed (hash must change);
 *   - 'v2'    — version 2 with an extra field (hash must change).
 *
 * @package SpilnoTest
 */

$variant = (string) get_option( 'spilno_test_schema_variant', '' );

$template = array(
	'label'    => 'Test page',
	'version'  => 'v2' === $variant ? 2 : 1,
	'sections' => array(
		'hero'   => array(
			'label'  => 'label' === $variant ? 'Hero (renamed)' : 'Hero',
			'fields' => array(
				'title' => array(
					'type'     => 'text',
					'label'    => 'Title',
					'max'      => 'max' === $variant ? 21 : 20,
					'required' => true,
				),
				'lede'  => array(
					'type'    => 'textarea',
					'label'   => 'Lede',
					'max'     => 30,
					'default' => 'Типовий лід',
				),
				'photo' => array(
					'type'  => 'image',
					'label' => 'Photo',
				),
			),
		),
		'cards'  => array(
			'label'  => 'Cards',
			'fields' => array(
				'title' => array(
					'type'  => 'text',
					'label' => 'Section title',
					'max'   => 120,
				),
				'items' => array(
					'type'   => 'group',
					'label'  => 'Card',
					'count'  => 2,
					'fields' => array(
						'title' => array(
							'type'     => 'text',
							'label'    => 'Card title',
							'max'      => 10,
							'required' => true,
							'default'  => 'Картка',
						),
						'text'  => array(
							'type'  => 'textarea',
							'label' => 'Card text',
							'max'   => 30,
						),
					),
				),
			),
		),
		'hidden' => array(
			'label'  => 'Hide blocks',
			'fields' => array(
				'cards' => array(
					'type'  => 'toggle',
					'label' => 'Hide cards',
				),
			),
		),
	),
	'slots'    => array( 'contacts' ),
);

if ( 'v2' === $variant ) {
	$template['sections']['hero']['fields']['note'] = array(
		'type'  => 'text',
		'label' => 'Note',
		'max'   => 40,
	);
}

return $template;
