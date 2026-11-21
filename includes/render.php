<?php
/**
 * Rendering of a page and the markup atoms of the templates.
 *
 * The plugin draws from the page head to the last band; the site's shell
 * gives header, breadcrumbs, `<main>` and footer. The atoms below (button,
 * pill, tile, link with an arrow, cards, mosaic, figures) are the plugin's own
 * copies of the design-system atoms, with `spilno-*` classes on the theme roles
 * `var(--ds-*)`: the two themes differ in their own atoms.
 *
 * Empty data is never rendered: an element without text is left out, a link
 * or a button without a target is not a link (a button is dropped, a card or
 * tile becomes plain text), a section without content is left out together
 * with its title.
 *
 * @package Spilno
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a page of a spilno template (echoes).
 *
 * Reads the stored original (`spilno_values()`), passes it through the filter
 * `spilno_render_values` (the only point where a translation may replace it;
 * never called by save, form, sync or the publication gate), fills empty
 * fields with the template defaults and includes `templates/{key}/render.php`.
 * The style sheet is enqueued here: printed right before the page when the
 * document head is already out.
 *
 * @param int   $record_id Record ID.
 * @param array $context {
 *     @type string $template Template of a page without stored values yet (the
 *                            site's record says which; defaults are rendered).
 *     @type string $lang   Language of the request (for `spilno_render_values`).
 *     @type string $before The site's own markup (already escaped) to print
 *                          inside the page root, above the page head — the
 *                          breadcrumbs, so they sit on the page backdrop.
 * }
 * @return void
 */
function spilno_render( $record_id, $context = array() ) {
	$record_id = (int) $record_id;
	$context   = is_array( $context ) ? $context : array();

	if ( ! spilno_enabled() ) {
		return;
	}

	$state      = spilno_page_state( $record_id );
	$template   = '' !== $state['template'] ? $state['template'] : ( is_string( $context['template'] ?? null ) ? $context['template'] : '' );
	$definition = spilno_template( $template );

	if ( null === $definition || ! is_file( $definition['dir'] . '/render.php' ) ) {
		return;
	}

	$values = $state['has_values'] && $state['template'] === $template ? spilno_values( $record_id ) : _spilno_field_empty( $definition['root'] );

	/**
	 * Values of a page right before rendering (translations).
	 *
	 * @param array  $values    The original values (normalized, no defaults).
	 * @param int    $record_id Record ID.
	 * @param string $template  Template key.
	 * @param array  $context   `lang`, `site`.
	 */
	$filtered = apply_filters(
		'spilno_render_values',
		$values,
		$record_id,
		$template,
		array(
			'lang' => is_string( $context['lang'] ?? null ) ? $context['lang'] : determine_locale(),
			'site' => _spilno_site_id(),
		)
	);

	$errors = array();
	_spilno_field_validate( $definition['root'], $filtered, '', $errors );
	$values = $errors ? $values : $filtered;

	_spilno_enqueue_style();

	$spilno = array(
		'record_id' => $record_id,
		'template'  => $template,
		'values'    => _spilno_with_defaults( $definition['root'], $values ),
		'before'    => is_string( $context['before'] ?? null ) ? $context['before'] : '',
	);

	( static function ( $spilno, $file ) {
		include $file;
	} )( $spilno, $definition['dir'] . '/render.php' );
}

/**
 * Registers and enqueues the style sheet; prints it at once when the document
 * head is already out (an in-body stylesheet still blocks rendering of what
 * follows, so the page never flashes unstyled).
 *
 * @return void
 */
function _spilno_enqueue_style() {
	$file = SPILNO_PATH . 'assets/spilno.css';

	if ( ! wp_style_is( 'spilno', 'registered' ) ) {
		wp_register_style( 'spilno', plugins_url( 'assets/spilno.css', SPILNO_FILE ), array(), SPILNO_VERSION . '.' . (int) filemtime( $file ) );
	}

	if ( did_action( 'wp_head' ) && ! wp_style_is( 'spilno', 'done' ) ) {
		wp_print_styles( 'spilno' );
		return;
	}

	wp_enqueue_style( 'spilno' );
}

/**
 * Values with every empty text field replaced by its default (render only).
 *
 * @param array $field Normalized field.
 * @param mixed $value Typed value.
 * @return mixed
 */
function _spilno_with_defaults( array $field, $value ) {
	if ( 'group' !== $field['type'] ) {
		return in_array( $field['type'], array( 'text', 'textarea' ), true ) && '' === $value ? $field['default'] : $value;
	}

	$out = array();

	if ( $field['count'] > 0 ) {
		for ( $i = 0; $i < $field['count']; $i++ ) {
			$out[] = _spilno_with_defaults( _spilno_list_item( $field, $i ), $value[ $i ] ?? null );
		}

		return $out;
	}

	foreach ( $field['fields'] as $key => $child ) {
		$out[ $key ] = _spilno_with_defaults( $child, $value[ $key ] ?? null );
	}

	return $out;
}

/**
 * Inline SVG icon from `assets/icons/{name}.svg`.
 *
 * @param string $name  Icon file name without extension.
 * @param string $extra Extra class.
 * @return string
 */
function _spilno_icon( $name, $extra = '' ) {
	static $cache = array();

	if ( ! preg_match( '/^[a-z0-9-]+$/', $name ) ) {
		return '';
	}

	if ( ! isset( $cache[ $name ] ) ) {
		$file           = SPILNO_PATH . 'assets/icons/' . $name . '.svg';
		$svg            = is_file( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file of the plugin.
		$svg            = (string) preg_replace( '/^(?:\s|<\?xml[^>]*\?>|<!DOCTYPE[^>]*>|<!--.*?-->)+/s', '', $svg );
		$cache[ $name ] = str_starts_with( $svg, '<svg' ) ? trim( $svg ) : '';
	}

	if ( '' === $cache[ $name ] ) {
		return '';
	}

	return (string) preg_replace( '/^<svg/', '<svg class="' . esc_attr( trim( 'spilno-icon ' . $extra ) ) . '" aria-hidden="true" focusable="false"', $cache[ $name ], 1 );
}

/**
 * Photo of a field: the image, a placeholder or nothing.
 *
 * @param int    $id    Attachment ID (0 — none).
 * @param string $sizes `sizes` attribute.
 * @param string $alt   Alternative text ('' — decorative).
 * @param array  $attrs Extra attributes of the `<img>`.
 * @return string|null HTML of the image; '' — a placeholder (outside
 *                     production, `spilno_is_preview`); null — no photo at all.
 */
function _spilno_photo( $id, $sizes, $alt = '', array $attrs = array() ) {
	$id = (int) $id;

	if ( $id > 0 && wp_attachment_is_image( $id ) ) {
		$html = wp_get_attachment_image(
			$id,
			'full',
			false,
			array_merge(
				array(
					'alt'      => $alt,
					'sizes'    => $sizes,
					'loading'  => 'lazy',
					'decoding' => 'async',
					'class'    => '',
				),
				$attrs
			)
		);

		if ( '' !== $html ) {
			return $html;
		}
	}

	return _spilno_is_preview() ? '' : null;
}

/**
 * A button-link; '' without a label or a target.
 *
 * @param string $label   Label.
 * @param string $url     Target.
 * @param string $variant `primary`.
 * @param string $size    `lg`, `md` or `sm`.
 * @return string
 */
function _spilno_ui_button( $label, $url, $variant = 'primary', $size = 'lg' ) {
	$label = trim( (string) $label );
	$url   = esc_url( (string) $url );

	if ( '' === $label || '' === $url ) {
		return '';
	}

	return '<a class="spilno-btn spilno-btn--' . esc_attr( $variant ) . ' spilno-btn--' . esc_attr( $size ) . '" href="' . $url . '"><span class="spilno-btn__label">' . esc_html( $label ) . '</span></a>';
}

/**
 * A link with an arrow; '' without a label or a target.
 *
 * @param string $label Label.
 * @param string $url   Target.
 * @return string
 */
function _spilno_ui_link_arrow( $label, $url ) {
	$label = trim( (string) $label );
	$url   = esc_url( (string) $url );

	if ( '' === $label || '' === $url ) {
		return '';
	}

	return '<a class="spilno-link-arrow" href="' . $url . '">' . esc_html( $label ) . '<span class="spilno-link-arrow__arrow" aria-hidden="true"> ↗</span></a>';
}

/**
 * A pill; '' without a label.
 *
 * @param string $label Label.
 * @return string
 */
function _spilno_ui_pill( $label ) {
	$label = trim( (string) $label );

	return '' === $label ? '' : '<span class="spilno-pill">' . esc_html( $label ) . '</span>';
}

/**
 * Opens a section band.
 *
 * @param string $surface    `paper`, `card` or `inverse`.
 * @param string $gap        `template` or `wide`.
 * @param string $labelledby ID of its title ('' — none).
 * @return string
 */
function _spilno_ui_section_open( $surface, $gap, $labelledby ) {
	$class = 'spilno-band spilno-section spilno-section--' . $surface . ' spilno-section--template spilno-section--gap-' . $gap . ( 'inverse' === $surface ? ' spilno-on-inverse' : '' );

	return '<section class="' . esc_attr( $class ) . '"' . ( '' !== $labelledby ? ' aria-labelledby="' . esc_attr( $labelledby ) . '"' : '' ) . '><div class="spilno-band__wrap spilno-section__wrap">';
}

/**
 * Head of a section; '' without a title.
 *
 * @param array $args `title`, `id`, `eyebrow`, `lede`, `center`.
 * @return string
 */
function _spilno_ui_section_head( array $args ) {
	$title = trim( (string) ( $args['title'] ?? '' ) );

	if ( '' === $title ) {
		return '';
	}

	$eyebrow = trim( (string) ( $args['eyebrow'] ?? '' ) );
	$lede    = trim( (string) ( $args['lede'] ?? '' ) );
	$html    = '<div class="spilno-section-head' . ( empty( $args['center'] ) ? '' : ' spilno-section-head--center' ) . '"><div class="spilno-section-head__text">';
	$html   .= '' !== $eyebrow ? '<p class="spilno-section-head__eyebrow">' . esc_html( $eyebrow ) . '</p>' : '';
	$html   .= '<h2 class="spilno-section-head__title" id="' . esc_attr( (string) $args['id'] ) . '">' . esc_html( $title ) . '</h2>';
	$html   .= '' !== $lede ? '<p class="spilno-section-head__lede">' . esc_html( $lede ) . '</p>' : '';

	return $html . '</div></div>';
}

/**
 * A card with an optional photo; '' without a title.
 *
 * @param array $args `title`, `meta`, `cta`, `url`, `media` (string — HTML of
 *                    the image, '' — placeholder; null — no media slot).
 * @return string
 */
function _spilno_ui_card( array $args ) {
	$title = trim( (string) ( $args['title'] ?? '' ) );

	if ( '' === $title ) {
		return '';
	}

	$url   = esc_url( (string) ( $args['url'] ?? '' ) );
	$meta  = trim( (string) ( $args['meta'] ?? '' ) );
	$cta   = trim( (string) ( $args['cta'] ?? '' ) );
	$media = $args['media'] ?? null;
	$html  = '<article class="spilno-news-card' . ( '' !== $url ? ' spilno-news-card--link' : '' ) . ( null !== $media ? ' spilno-news-card--media' : '' ) . '">';
	$html .= '<div class="spilno-news-card__body"><div class="spilno-news-card__heading">';
	$html .= '<h3 class="spilno-news-card__title">' . ( '' !== $url ? '<a class="spilno-news-card__link" href="' . $url . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</h3>';
	$html .= '' !== $meta ? '<p class="spilno-news-card__meta">' . esc_html( $meta ) . '</p>' : '';
	$html .= '</div>';
	$html .= '' !== $url && '' !== $cta ? '<span class="spilno-news-card__more" aria-hidden="true">' . esc_html( $cta ) . '<span class="spilno-news-card__arrow">→</span></span>' : '';
	$html .= '</div>';

	if ( is_string( $media ) ) {
		$html .= '' === $media ? '<div class="spilno-news-card__media" aria-hidden="true"></div>' : '<div class="spilno-news-card__media">' . $media . '</div>';
	}

	return $html . '</article>';
}

/**
 * A grid of cards; '' when none.
 *
 * @param string[] $cards Rendered cards.
 * @return string
 */
function _spilno_ui_card_grid( array $cards ) {
	$cards = array_values( array_filter( $cards, static fn( $card ) => '' !== $card ) );

	if ( ! $cards ) {
		return '';
	}

	return '<ul class="spilno-card-grid" role="list"><li class="spilno-card-grid__item">' . implode( '</li><li class="spilno-card-grid__item">', $cards ) . '</li></ul>';
}

/**
 * A tile; '' without a title. Without a target — plain text (no cta).
 *
 * @param array $args `title`, `text`, `cta`, `url`, `icon`.
 * @return string
 */
function _spilno_ui_tile( array $args ) {
	$title = trim( (string) ( $args['title'] ?? '' ) );

	if ( '' === $title ) {
		return '';
	}

	$url   = esc_url( (string) ( $args['url'] ?? '' ) );
	$text  = trim( (string) ( $args['text'] ?? '' ) );
	$cta   = trim( (string) ( $args['cta'] ?? '' ) );
	$html  = '<div class="spilno-tile spilno-tile--' . ( '' === $url ? 'text' : 'link' ) . '"><div class="spilno-tile__body">';
	$html .= _spilno_icon( (string) ( $args['icon'] ?? '' ), 'spilno-tile__icon' );
	$html .= '<h3 class="spilno-tile__title">' . ( '' === $url ? esc_html( $title ) : '<a class="spilno-tile__target" href="' . $url . '">' . esc_html( $title ) . '</a>' ) . '</h3>';
	$html .= '' !== $text ? '<p class="spilno-tile__text">' . esc_html( $text ) . '</p>' : '';
	$html .= '</div>';
	$html .= '' !== $url && '' !== $cta ? '<span class="spilno-tile__cta" aria-hidden="true">' . esc_html( $cta ) . '</span>' : '';

	return $html . '</div>';
}

/**
 * Figures as stat cards; '' when none.
 *
 * @param array<int, array{value: string, label: string}> $stats Figures.
 * @return string
 */
function _spilno_ui_stat_grid( array $stats ) {
	$items = array();

	foreach ( $stats as $stat ) {
		$items[] = '<li class="spilno-stat-grid__item"><p class="spilno-stat-card"><span class="spilno-stat-card__value">' . esc_html( $stat['value'] ) . '</span> <span class="spilno-stat-card__label">' . esc_html( $stat['label'] ) . '</span></p></li>';
	}

	return $items ? '<ul class="spilno-stat-grid" role="list">' . implode( '', $items ) . '</ul>' : '';
}

/**
 * The mosaic: columns of items; empty items and columns are left out.
 *
 * Items: `{type: photo, media: string|null}`, `{type: stat, value, label,
 * icon}`, `{type: card, title?, lines?, link?: [label, url], links?: [[label,
 * url], …]}`.
 *
 * @param array<int, array<int, array>> $columns Columns.
 * @return string
 */
function _spilno_ui_bento( array $columns ) {
	$rendered = array();

	foreach ( $columns as $column ) {
		$items = array_values( array_filter( array_map( '_spilno_ui_bento_item', $column ), static fn( $item ) => '' !== $item ) );

		if ( $items ) {
			$rendered[] = '<div class="spilno-bento__col">' . implode( '', $items ) . '</div>';
		}
	}

	if ( ! $rendered ) {
		return '';
	}

	return '<div class="spilno-bento spilno-bento--cols-' . count( $rendered ) . '">' . implode( '', $rendered ) . '</div>';
}

/**
 * One item of the mosaic.
 *
 * @param array $item Item.
 * @return string
 */
function _spilno_ui_bento_item( array $item ) {
	switch ( $item['type'] ?? '' ) {
		case 'photo':
			return is_string( $item['media'] ?? null ) ? '<div class="spilno-bento__photo" aria-hidden="true">' . $item['media'] . '</div>' : '';
		case 'stat':
			if ( '' === ( $item['value'] ?? '' ) || '' === ( $item['label'] ?? '' ) ) {
				return '';
			}

			return '<div class="spilno-bento__card spilno-bento__card--stat spilno-on-inverse"><p class="spilno-bento__stat"><span class="spilno-bento__value">' . esc_html( $item['value'] ) . '</span> <span class="spilno-bento__label">' . esc_html( $item['label'] ) . '</span></p>' . _spilno_icon( (string) $item['icon'], 'spilno-bento__icon' ) . '</div>';
		case 'card':
			$title = trim( (string) ( $item['title'] ?? '' ) );
			$lines = array_filter( array_map( 'trim', (array) ( $item['lines'] ?? array() ) ) );
			$link  = isset( $item['link'] ) ? _spilno_ui_link_arrow( $item['link'][0], $item['link'][1] ) : '';
			$links = array_filter(
				array_map(
					static fn( $one ) => _spilno_ui_link_arrow( $one[0], $one[1] ),
					(array) ( $item['links'] ?? array() )
				)
			);

			if ( '' === $title && ! $lines && ! $links && '' === $link ) {
				return '';
			}

			$html = '<div class="spilno-bento__card spilno-bento__card--paper' . ( $lines ? ' spilno-bento__card--top' : '' ) . '">';

			if ( '' !== $title || $lines ) {
				$html .= '<div class="spilno-bento__text">';
				$html .= '' !== $title ? '<h3 class="spilno-bento__title">' . esc_html( $title ) . '</h3>' : '';
				$html .= $lines ? '<ul class="spilno-bento__lines" role="list"><li>' . implode( '</li><li>', array_map( 'esc_html', $lines ) ) . '</li></ul>' : '';
				$html .= '</div>';
			}

			$html .= $link;
			$html .= $links ? '<ul class="spilno-bento__links" role="list"><li>' . implode( '</li><li>', $links ) . '</li></ul>' : '';

			return $html . '</div>';
	}

	return '';
}
