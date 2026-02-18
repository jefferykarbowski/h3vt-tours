<?php
/**
 * Full-screen single tour template — no theme chrome.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( get_the_title() ); ?> | Virtual Tour</title>
<?php wp_head(); ?>
</head>
<body class="h3vt-tour-page" style="margin:0;padding:0;overflow:hidden;background:#000">
<?php
echo H3VT_Tours_Renderer::render( get_the_ID(), 'template' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer handles escaping internally.
?>
<?php wp_footer(); ?>
</body>
</html>
