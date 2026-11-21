<?php
/**
 * Test theme shell: every request. A page with the meta
 * `_spilno_test_template` is a spilno page; anything else shows its content.
 *
 * @package SpilnoDevShell
 */

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<header class="shell-header"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Shell header</a></header>
<main id="main">
<?php
while ( have_posts() ) {
	the_post();

	$spilno_dev_template = (string) get_post_meta( get_the_ID(), '_spilno_test_template', true );

	if ( is_page() && '' !== $spilno_dev_template && function_exists( 'spilno_render' ) ) {
		spilno_render(
			get_the_ID(),
			array(
				'template' => $spilno_dev_template,
				'before'   => spilno_dev_shell_crumbs(),
			)
		);
	} else {
		the_title( '<h1>', '</h1>' );
		the_content();
	}
}
?>
</main>
<footer class="shell-footer"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Shell footer</a></footer>
<?php wp_footer(); ?>
</body>
</html>
