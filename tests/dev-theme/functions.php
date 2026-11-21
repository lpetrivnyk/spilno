<?php
/**
 * Test theme shell of the spilno-dev stand. Not part of the plugin.
 *
 * <html> attributes come from the same cookies both sites use: ds_theme
 * (normal|high|dark|mono), ds_text_scale (100|125|150), ds_links
 * (underline), ds_motion (reduce).
 *
 * @package SpilnoDevShell
 */

add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style( 'spilno-dev-shell', get_stylesheet_directory_uri() . '/shell.css', array(), (string) filemtime( __DIR__ . '/shell.css' ) );
	}
);

add_filter(
	'language_attributes',
	static function ( $output ) {
		$cookie = static fn( $name, $allowed ) => isset( $_COOKIE[ $name ] ) && in_array( $_COOKIE[ $name ], $allowed, true ) ? $_COOKIE[ $name ] : '';

		$theme = $cookie( 'ds_theme', array( 'high', 'dark', 'mono' ) );
		$scale = $cookie( 'ds_text_scale', array( '125', '150' ) );

		$output .= '' !== $theme ? ' data-theme="' . $theme . '"' : '';
		$output .= '' !== $scale ? ' data-text-scale="' . $scale . '"' : '';
		$output .= 'underline' === $cookie( 'ds_links', array( 'underline' ) ) ? ' data-links="underline"' : '';
		$output .= 'reduce' === $cookie( 'ds_motion', array( 'reduce' ) ) ? ' data-motion="reduce"' : '';

		return $output;
	}
);

add_theme_support( 'title-tag' );

/**
 * Breadcrumbs of the shell (the markup of the theme atom).
 *
 * @return string
 */
function spilno_dev_shell_crumbs() {
	return '<div class="ds-band ds-crumbs"><div class="ds-band__wrap"><nav aria-label="Хлібні крихти"><ol class="ds-crumbs__list">'
		. '<li class="ds-crumbs__item"><a class="ds-crumb" href="' . esc_url( home_url( '/' ) ) . '">Головна</a></li>'
		. '<li class="ds-crumbs__item"><span class="ds-crumb ds-crumb--current" aria-current="page">' . esc_html( get_the_title() ) . '</span></li>'
		. '</ol></nav></div></div>';
}

// The site language: Ukrainian (the plugin's spilno-uk.mo), without a core
// language pack on the stand (WPLANG would refuse a locale that is not installed).
add_filter( 'locale', static fn() => 'uk' );
