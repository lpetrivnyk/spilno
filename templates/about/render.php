<?php
/**
 * Template «About the hospital»: markup.
 *
 * Included by spilno_render() with `$spilno`: `record_id`, `template`,
 * `values` (defaults already filled in), `before` (the site's breadcrumbs).
 *
 * Order and rules follow the reference (the page as the first site renders
 * it): a block or an element without data is not rendered, a heading never
 * stands without its content. Figures come from the data slot `facts` (phase
 * 1: the site gives only facts it may show; null — none): without them the
 * sentences of the lede, the cards of figures, «since {year}», the staff card,
 * the badges and the description of the «History» tile drop out. News — the
 * data slot `news` (null — no block).
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) || ! isset( $spilno ) ) {
	exit;
}

$spilno_v     = $spilno['values'];
$spilno_facts = _spilno_slot_facts( 'about' );
$spilno_fact  = static fn( $key ) => $spilno_facts[ $key ] ?? null;
$spilno_link  = static fn( $slot ) => _spilno_slot_link( $slot, 'about' );

// ─── Lede: the legal name + sentences resting on figures ───────────────────
$spilno_lede = array( $spilno_v['hero']['legal'] );

if ( null !== $spilno_fact( 'accreditation' ) ) {
	$spilno_lede[] = __( 'An institution of the highest accreditation category.', 'spilno' );
}

if ( null !== $spilno_fact( 'specialties' ) ) {
	/* translators: %s: number of specialties. */
	$spilno_lede[] = sprintf( __( 'Provides highly qualified specialised inpatient and consultative medical care in %s specialties.', 'spilno' ), $spilno_fact( 'specialties' )['value'] );
}

$spilno_lede = trim( implode( ' ', array_filter( array_map( 'trim', $spilno_lede ) ) ) );

// ─── Cards of directions ───────────────────────────────────────────────────
$spilno_cards = array();

if ( ! $spilno_v['hidden']['directions'] ) {
	foreach ( array( 'patients', 'medics', 'vacancies' ) as $spilno_i => $spilno_slot ) {
		$spilno_card    = $spilno_v['directions']['cards'][ $spilno_i ];
		$spilno_cards[] = _spilno_ui_card(
			array(
				'title' => $spilno_card['title'],
				'meta'  => $spilno_card['text'],
				'cta'   => $spilno_card['cta'],
				'url'   => $spilno_link( $spilno_slot ),
				'media' => _spilno_photo( $spilno_card['photo'], '(min-width: 1440px) 437px, (min-width: 768px) calc(50vw - 40px), calc(100vw - 32px)' ),
			)
		);
	}
}

$spilno_cards = _spilno_ui_card_grid( $spilno_cards );

// ─── Mosaic ────────────────────────────────────────────────────────────────
$spilno_mosaic = '';
$spilno_action = '';
$spilno_pills  = array();

if ( ! $spilno_v['hidden']['mosaic'] ) {
	$spilno_m     = $spilno_v['mosaic'];
	$spilno_sizes = '(min-width: 1440px) 322px, (min-width: 768px) calc(50vw - 40px), calc(100vw - 32px)';
	$spilno_lines = _spilno_facts_staff_lines( $spilno_facts );

	$spilno_stat = static fn( $key, $icon ) => null === $spilno_fact( $key ) ? array() : array(
		'type'  => 'stat',
		'value' => $spilno_fact( $key )['value'],
		'label' => $spilno_fact( $key )['label'],
		'icon'  => $icon,
	);

	$spilno_links = array();
	foreach ( array( 'director', 'leadership', 'reports', 'vacancies', 'contacts' ) as $spilno_i => $spilno_slot ) {
		$spilno_links[] = array( $spilno_m['links'][ $spilno_i ]['label'], $spilno_link( $spilno_slot ) );
	}

	$spilno_mosaic = _spilno_ui_bento(
		array(
			array(
				null === $spilno_fact( 'founded' ) ? array() : array(
					'type'  => 'card',
					/* translators: %s: the year the hospital was founded. */
					'title' => sprintf( __( 'Caring for health since %s', 'spilno' ), $spilno_fact( 'founded' )['value'] ),
					'link'  => array( $spilno_m['history_link'], $spilno_link( 'history' ) ),
				),
				array(
					'type'  => 'photo',
					'media' => _spilno_photo( $spilno_m['photo_short'], $spilno_sizes ),
				),
			),
			array(
				$spilno_stat( 'beds', 'hospital-48' ),
				$spilno_lines ? array(
					'type'  => 'card',
					'title' => $spilno_m['team'],
					'lines' => $spilno_lines,
				) : array(),
			),
			array(
				array(
					'type'  => 'photo',
					'media' => _spilno_photo( $spilno_m['photo_tall'], $spilno_sizes ),
				),
			),
			array(
				$spilno_stat( 'specialties', 'doctor-48' ),
				array(
					'type'  => 'card',
					'links' => $spilno_links,
				),
			),
		)
	);

	$spilno_action = _spilno_ui_button( $spilno_m['action'], $spilno_link( 'departments' ) );

	foreach ( array( 'accreditation', 'license', 'nhsu' ) as $spilno_key ) {
		if ( null !== $spilno_fact( $spilno_key ) ) {
			$spilno_pills[] = _spilno_ui_pill( $spilno_fact( $spilno_key )['value'] );
		}
	}
}

// ─── Figures and tiles ─────────────────────────────────────────────────────
$spilno_stats = array();

foreach ( array( 'doctors', 'nurses', 'junior_staff', 'other_staff' ) as $spilno_key ) {
	if ( null !== $spilno_fact( $spilno_key ) && '' !== $spilno_fact( $spilno_key )['label'] ) {
		$spilno_stats[] = $spilno_fact( $spilno_key );
	}
}

$spilno_stats = _spilno_ui_stat_grid( $spilno_stats );
$spilno_tiles = array();

if ( ! $spilno_v['hidden']['sections'] ) {
	$spilno_icons = array( 'award-32', 'doctor-32', 'document-32', 'clock-32', 'partnership-32', 'phone-call-32' );

	foreach ( array( 'director', 'leadership', 'reports', 'history', 'vacancies', 'contacts' ) as $spilno_i => $spilno_slot ) {
		$spilno_tile    = $spilno_v['sections']['tiles'][ $spilno_i ];
		$spilno_tiles[] = _spilno_ui_tile(
			array(
				'title' => $spilno_tile['title'],
				// The description of «History» names the founding year — a figure.
				'text'  => 'history' === $spilno_slot && null === $spilno_fact( 'founded' ) ? '' : $spilno_tile['text'],
				'cta'   => $spilno_tile['cta'],
				'url'   => $spilno_link( $spilno_slot ),
				'icon'  => $spilno_icons[ $spilno_i ],
			)
		);
	}
}

$spilno_tiles = array_values( array_filter( $spilno_tiles ) );

// ─── News ──────────────────────────────────────────────────────────────────
$spilno_news = array();

if ( ! $spilno_v['hidden']['news'] ) {
	foreach ( _spilno_slot_news( 'about', 3 ) as $spilno_item ) {
		$spilno_news[] = _spilno_ui_card( $spilno_item + array( 'cta' => __( 'Read', 'spilno' ) ) );
	}
}

$spilno_news = _spilno_ui_card_grid( $spilno_news );

// ─── Output ────────────────────────────────────────────────────────────────
$spilno_hero_action = _spilno_ui_button( $spilno_v['hero']['action'], $spilno_link( 'departments' ) );
$spilno_main_photo  = _spilno_photo(
	$spilno_v['photo_main']['id'],
	'(min-width: 1440px) 1360px, calc(100vw - 32px)',
	$spilno_v['photo_main']['alt'],
	array(
		'loading'       => 'eager',
		'fetchpriority' => 'high',
	)
);
$spilno_main_alt    = is_string( $spilno_main_photo ) && '' !== $spilno_main_photo && '' !== $spilno_v['photo_main']['alt'];
?>
<div class="spilno spilno--about">
	<div class="spilno-backdrop" aria-hidden="true"></div>
	<?php echo $spilno['before']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the site's own escaped markup. ?>
	<div class="spilno-band spilno-page-head-band spilno-page-head-band--hero">
		<div class="spilno-band__wrap">
			<div class="spilno-page-head spilno-page-head--hero">
				<h1 class="spilno-page-head__title"><?php echo esc_html( get_the_title( $spilno['record_id'] ) ); ?></h1>
				<?php if ( '' !== $spilno_lede ) : ?>
					<p class="spilno-page-head__lede"><?php echo esc_html( $spilno_lede ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $spilno_hero_action ) : ?>
					<div class="spilno-page-head__actions"><?php echo $spilno_hero_action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the atom. ?></div>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php if ( null !== $spilno_main_photo ) : ?>
		<div class="spilno-band spilno-photo-band">
			<div class="spilno-band__wrap">
				<div class="spilno-photo-band__media"<?php echo $spilno_main_alt ? '' : ' aria-hidden="true"'; ?>>
					<div class="spilno-photo-band__frame"><?php echo $spilno_main_photo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image(). ?></div>
				</div>
			</div>
		</div>
	<?php endif; ?>
	<?php
	if ( '' !== $spilno_cards ) {
		echo _spilno_ui_section_open( 'inverse', 'template', 'spilno-about-directions' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the atom.
		echo _spilno_ui_section_head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the atom.
			array(
				'title'  => $spilno_v['directions']['title'],
				'id'     => 'spilno-about-directions',
				'center' => true,
			)
		);
		echo $spilno_cards . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the atoms.
	}

	if ( '' !== $spilno_mosaic || '' !== $spilno_action || $spilno_pills ) {
		echo _spilno_ui_section_open( 'card', 'wide', 'spilno-about-mosaic' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the atom.
		echo _spilno_ui_section_head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the atom.
			array(
				'title'  => $spilno_v['mosaic']['title'],
				'id'     => 'spilno-about-mosaic',
				'center' => true,
			)
		);
		echo $spilno_mosaic; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the atoms.

		if ( '' !== $spilno_action ) {
			echo '<div class="spilno-section__actions">' . $spilno_action . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the atom.
		}

		if ( $spilno_pills ) {
			echo '<ul class="spilno-pill-list" role="list"><li>' . implode( '</li><li>', $spilno_pills ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the atom.
		}

		echo '</div></section>';
	}

	if ( '' !== $spilno_stats || $spilno_tiles ) {
		echo _spilno_ui_section_open( 'paper', 'template', $spilno_tiles ? 'spilno-about-sections' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the atom.
		echo $spilno_stats; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the atom.

		if ( $spilno_tiles ) {
			echo '<div class="spilno-section__group">';
			echo _spilno_ui_section_head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the atom.
				array(
					'eyebrow' => $spilno_v['sections']['eyebrow'],
					'title'   => $spilno_v['sections']['title'],
					'lede'    => $spilno_v['sections']['lede'],
					'id'      => 'spilno-about-sections',
				)
			);
			echo '<ul class="spilno-tile-grid" role="list"><li>' . implode( '</li><li>', $spilno_tiles ) . '</li></ul></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the atom.
		}

		echo '</div></section>';
	}

	if ( '' !== $spilno_news ) {
		echo _spilno_ui_section_open( 'paper', 'template', 'spilno-about-news' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the atom.
		echo _spilno_ui_section_head( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the atom.
			array(
				'title'  => $spilno_v['news']['title'],
				'id'     => 'spilno-about-news',
				'center' => true,
			)
		);
		echo $spilno_news . '</div></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the atoms.
	}
	?>
</div>
