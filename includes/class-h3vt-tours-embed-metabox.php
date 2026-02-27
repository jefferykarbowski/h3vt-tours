<?php
/**
 * Embed code meta box for the tour edit screen.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers a sidebar meta box that displays embed codes for the current tour.
 */
class H3VT_Tours_Embed_Metabox {

	/**
	 * Constructor — hooks meta box registration.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
	}

	/**
	 * Register the embed codes meta box on the h3vt_tour post type.
	 */
	public function register() {
		add_meta_box(
			'h3vt_embed_codes',
			__( 'Embed This Tour', 'h3vt-tours' ),
			array( $this, 'render' ),
			'h3vt_tour',
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box content.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save this tour first to generate embed codes.', 'h3vt-tours' ) . '</p>';
			return;
		}

		$tour_url   = get_permalink( $post->ID );
		$shortcode  = '[h3vt_tour id="' . $post->ID . '"]';
		$embed_js   = H3VT_TOURS_URL . 'assets/js/h3vt-tours-embed.js';
		$script_tag = '<script src="' . esc_url( $embed_js ) . '" data-tour-id="' . esc_attr( $post->ID ) . '"></script>';
		?>
		<style>
			.h3vt-embed-section { margin-bottom: 12px; }
			.h3vt-embed-section:last-child { margin-bottom: 0; }
			.h3vt-embed-label { font-weight: 600; display: block; margin-bottom: 4px; font-size: 12px; }
			.h3vt-embed-help { color: #646970; font-size: 11px; margin: 4px 0 6px; line-height: 1.4; }
			.h3vt-embed-row { display: flex; gap: 4px; }
			.h3vt-embed-row input[type="text"] { flex: 1; font-size: 11px; padding: 4px 6px; }
			.h3vt-embed-copy { flex-shrink: 0; cursor: pointer; }
			.h3vt-embed-copy.copied { color: #00a32a; }
		</style>

		<div class="h3vt-embed-section">
			<span class="h3vt-embed-label"><?php esc_html_e( 'Direct URL', 'h3vt-tours' ); ?></span>
			<p class="h3vt-embed-help"><?php esc_html_e( 'Share this link directly or use in an iframe.', 'h3vt-tours' ); ?></p>
			<div class="h3vt-embed-row">
				<input type="text" readonly value="<?php echo esc_url( $tour_url ); ?>" onclick="this.select()">
				<button type="button" class="button h3vt-embed-copy" data-copy="<?php echo esc_url( $tour_url ); ?>">
					<?php esc_html_e( 'Copy', 'h3vt-tours' ); ?>
				</button>
			</div>
		</div>

		<div class="h3vt-embed-section">
			<span class="h3vt-embed-label"><?php esc_html_e( 'WordPress Shortcode', 'h3vt-tours' ); ?></span>
			<p class="h3vt-embed-help">
				<?php
				printf(
					/* translators: %s: download link */
					esc_html__( '%s for the client WordPress site, then paste this shortcode into any page or post:', 'h3vt-tours' ),
					'<a href="https://github.com/jefferykarbowski/h3vt-tours/releases/latest/download/h3vt-tours-embed.zip">' . esc_html__( 'Download the Embed Plugin', 'h3vt-tours' ) . '</a>'
				);
				?>
			</p>
			<div class="h3vt-embed-row">
				<input type="text" readonly value="<?php echo esc_attr( $shortcode ); ?>" onclick="this.select()">
				<button type="button" class="button h3vt-embed-copy" data-copy="<?php echo esc_attr( $shortcode ); ?>">
					<?php esc_html_e( 'Copy', 'h3vt-tours' ); ?>
				</button>
			</div>
		</div>

		<div class="h3vt-embed-section">
			<span class="h3vt-embed-label"><?php esc_html_e( 'JavaScript Embed', 'h3vt-tours' ); ?></span>
			<p class="h3vt-embed-help"><?php esc_html_e( 'Paste into any external website\'s HTML. The tour loads in-place — no iframe needed. Works cross-origin.', 'h3vt-tours' ); ?></p>
			<div class="h3vt-embed-row">
				<input type="text" readonly value="<?php echo esc_attr( $script_tag ); ?>" onclick="this.select()">
				<button type="button" class="button h3vt-embed-copy" data-copy="<?php echo esc_attr( $script_tag ); ?>">
					<?php esc_html_e( 'Copy', 'h3vt-tours' ); ?>
				</button>
			</div>
		</div>

		<hr style="margin: 12px 0;" />
		<div class="h3vt-embed-section">
			<span class="h3vt-embed-label"><?php esc_html_e( 'WordPress Client Plugin', 'h3vt-tours' ); ?></span>
			<p class="h3vt-embed-help"><?php esc_html_e( 'Install this lightweight plugin on client WordPress sites to embed tours via shortcode — no manual script tags needed.', 'h3vt-tours' ); ?></p>
			<a href="https://github.com/jefferykarbowski/h3vt-tours/releases/latest/download/h3vt-tours-embed.zip" target="_blank" rel="noopener noreferrer" class="button button-secondary" style="width:100%;text-align:center;">
				<?php esc_html_e( 'Download Client Plugin ↗', 'h3vt-tours' ); ?>
			</a>
		</div>

		<script>
		(function() {
			document.querySelectorAll('.h3vt-embed-copy').forEach(function(btn) {
				btn.addEventListener('click', function() {
					var text = this.getAttribute('data-copy');
					navigator.clipboard.writeText(text).then(function() {
						btn.textContent = '<?php echo esc_js( __( 'Copied!', 'h3vt-tours' ) ); ?>';
						btn.classList.add('copied');
						setTimeout(function() {
							btn.textContent = '<?php echo esc_js( __( 'Copy', 'h3vt-tours' ) ); ?>';
							btn.classList.remove('copied');
						}, 2000);
					});
				});
			});
		})();
		</script>
		<?php
	}
}
