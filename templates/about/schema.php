<?php
/**
 * Template «About the hospital»: schema.
 *
 * Plan §A4. Defaults are the design (Figma) texts verbatim, in Ukrainian:
 * data of the template, not translatable strings. An empty field means its
 * default. Link targets are slots, never fields: the site answers
 * `spilno_slot_link` (departments, patients, medics, vacancies, history,
 * director, leadership, reports, contacts); data comes from
 * `spilno_slot_data` (facts, news). Photos of the cards and the mosaic are
 * decorative; the main photo takes an optional alt text.
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$spilno_title   = static fn( $label, $fallback ) => array(
	'type'     => 'text',
	'label'    => $label,
	'max'      => 120,
	'required' => true,
	'default'  => $fallback,
);
$spilno_caption = static fn( $label, $fallback ) => array(
	'type'     => 'text',
	'label'    => $label,
	'max'      => 40,
	'required' => true,
	'default'  => $fallback,
);
$spilno_text    = static fn( $label, $fallback, $type = 'textarea', $max = 300 ) => array(
	'type'    => $type,
	'label'   => $label,
	'max'     => $max,
	'default' => $fallback,
);
$spilno_photo   = static fn( $label ) => array(
	'type'  => 'image',
	'label' => $label,
);

return array(
	'label'    => __( 'About the hospital', 'spilno' ),
	'version'  => 1,
	'sections' => array(
		'hero'       => array(
			'label'  => __( 'Page head', 'spilno' ),
			'fields' => array(
				'legal'  => $spilno_text( __( 'First sentence under the title (legal name)', 'spilno' ), 'КНП «Обласна клінічна лікарня» Івано-Франківської обласної ради.', 'textarea', 400 ),
				'action' => $spilno_caption( __( 'Button under the title', 'spilno' ), 'Наші відділення' ),
			),
		),
		'photo_main' => array(
			'label'  => __( 'Main photo', 'spilno' ),
			'fields' => array(
				'id'  => $spilno_photo( __( 'Main photo (1360 × 600)', 'spilno' ) ),
				'alt' => array(
					'type'  => 'text',
					'label' => __( 'Alternative text (empty — the photo is decorative)', 'spilno' ),
					'max'   => 300,
				),
			),
		),
		'directions' => array(
			'label'  => __( 'People who change medicine', 'spilno' ),
			'fields' => array(
				'title' => $spilno_title( __( 'Block title', 'spilno' ), 'Люди, які змінюють медицину' ),
				'cards' => array(
					'type'     => 'group',
					'label'    => __( 'Card', 'spilno' ),
					'count'    => 3,
					'defaults' => array(
						array(
							'title' => 'Пацієнтам',
							'text'  => 'Інформація про запис, послуги, права пацієнта та доступні ліки — все в одному місці.',
							'cta'   => 'Пацієнтам',
						),
						array(
							'title' => 'Ми вітаємо вас',
							'text'  => 'Кваліфікований медичний персонал і сучасне обладнання — запорука якісної допомоги кожному.',
							'cta'   => 'Медикам',
						),
						array(
							'title' => 'Змінюємо медицину',
							'text'  => 'Долучайтеся до команди лікарів, яка щодня допомагає людям і розвиває медицину регіону.',
							'cta'   => 'Вакансії',
						),
					),
					'fields'   => array(
						'title' => $spilno_title( __( 'Title', 'spilno' ), '' ),
						'text'  => $spilno_text( __( 'Text', 'spilno' ), '' ),
						'cta'   => $spilno_caption( __( 'Link caption', 'spilno' ), '' ),
						'photo' => $spilno_photo( __( 'Photo', 'spilno' ) ),
					),
				),
			),
		),
		'mosaic'     => array(
			'label'  => __( 'Open the door to modern medicine', 'spilno' ),
			'fields' => array(
				'title'        => $spilno_title( __( 'Block title', 'spilno' ), 'Відкрийте двері до сучасної медицини' ),
				'history_link' => $spilno_caption( __( 'Link to the history', 'spilno' ), 'Історія лікарні' ),
				'team'         => $spilno_title( __( 'Title of the staff card', 'spilno' ), 'Команда, якій довіряють' ),
				'links'        => array(
					'type'     => 'group',
					'label'    => __( 'Link', 'spilno' ),
					'count'    => 5,
					'defaults' => array(
						array( 'label' => 'Генеральний директор' ),
						array( 'label' => 'Керівництво лікарні' ),
						array( 'label' => 'Публічна звітність' ),
						array( 'label' => 'Вакансії' ),
						array( 'label' => 'Контакти' ),
					),
					'fields'   => array(
						'label' => $spilno_caption( __( 'Caption', 'spilno' ), '' ),
					),
				),
				'action'       => $spilno_caption( __( 'Button under the block', 'spilno' ), 'Усі відділення' ),
				'photo_short'  => $spilno_photo( __( 'Mosaic photo, low (322 × 254)', 'spilno' ) ),
				'photo_tall'   => $spilno_photo( __( 'Mosaic photo, tall (322 × 600)', 'spilno' ) ),
			),
		),
		'sections'   => array(
			'label'  => __( 'Documents, management, reporting', 'spilno' ),
			'fields' => array(
				'eyebrow' => $spilno_caption( __( 'Eyebrow', 'spilno' ), 'РОЗДІЛИ' ),
				'title'   => $spilno_title( __( 'Block title', 'spilno' ), 'Документи, керівництво, звітність' ),
				'lede'    => $spilno_text( __( 'Subtitle', 'spilno' ), 'Публічна інформація закладу — там, де її шукають перевіряючі, журналісти й пацієнти.', 'textarea', 400 ),
				'tiles'   => array(
					'type'     => 'group',
					'label'    => __( 'Tile', 'spilno' ),
					'count'    => 6,
					'defaults' => array(
						array(
							'title' => 'Генеральний директор',
							'text'  => 'Керівник закладу',
							'cta'   => 'Перейти →',
						),
						array(
							'title' => 'Керівництво лікарні',
							'text'  => 'Адміністрація, заступники, головні спеціалісти',
							'cta'   => 'Перейти →',
						),
						array(
							'title' => 'Публічна звітність',
							'text'  => 'Фінансові звіти, установчі документи, закупівлі',
							'cta'   => 'Документи →',
						),
						array(
							'title' => 'Історія лікарні',
							'text'  => 'Від 1949 року до сьогодні',
							'cta'   => 'Читати →',
						),
						array(
							'title' => 'Вакансії',
							'text'  => 'Відкриті позиції та умови роботи',
							'cta'   => 'Переглянути →',
						),
						array(
							'title' => 'Контакти',
							'text'  => 'Телефони, графік, адреса',
							'cta'   => 'Перейти →',
						),
					),
					'fields'   => array(
						'title' => $spilno_title( __( 'Title', 'spilno' ), '' ),
						'text'  => $spilno_text( __( 'Description', 'spilno' ), '', 'text' ),
						'cta'   => $spilno_caption( __( 'Link caption', 'spilno' ), '' ),
					),
				),
			),
		),
		'news'       => array(
			'label'  => __( 'Hospital life', 'spilno' ),
			'fields' => array(
				'title' => $spilno_title( __( 'Block title', 'spilno' ), 'Життя лікарні' ),
			),
		),
		'hidden'     => array(
			'label'  => __( 'Hide blocks', 'spilno' ),
			'fields' => array(
				'directions' => array(
					'type'  => 'toggle',
					'label' => __( 'Hide the block «People who change medicine»', 'spilno' ),
				),
				'mosaic'     => array(
					'type'  => 'toggle',
					'label' => __( 'Hide the mosaic with its button and badges', 'spilno' ),
				),
				'sections'   => array(
					'type'  => 'toggle',
					'label' => __( 'Hide the section tiles', 'spilno' ),
				),
				'news'       => array(
					'type'  => 'toggle',
					'label' => __( 'Hide the news', 'spilno' ),
				),
			),
		),
	),
	'slots'    => array( 'departments', 'patients', 'medics', 'vacancies', 'history', 'director', 'leadership', 'reports', 'contacts', 'facts', 'news' ),
);
