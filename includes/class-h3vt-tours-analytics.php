<?php
/**
 * Reporting-property Google Analytics tag for tour pages.
 *
 * The site-wide GA property tracks general site traffic, but the client tour
 * analytics emails (sent by the h3-tour-management plugin) read a separate
 * GA4 "reporting" property whose measurement id lives in the shared
 * `h3tm_ga_measurement_id` option. Legacy /h3panos/ tours already report into
 * that property; this makes the new /tour/ CPT tours do the same so they show
 * up in the client reports alongside the legacy tours.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits the reporting-property gtag snippet on single tour pages.
 */
class H3VT_Tours_Analytics {

	/**
	 * Fallback measurement id when the shared option is unset.
	 */
	const DEFAULT_MEASUREMENT_ID = 'G-08Q1M637NJ';

	/**
	 * Hook into the tour template head.
	 */
	public function __construct() {
		add_action( 'wp_head', array( $this, 'print_reporting_tag' ), 20 );
	}

	/**
	 * Resolve the reporting-property measurement id.
	 *
	 * Shares the h3-tour-management option so both plugins report into the same
	 * GA4 property. Filterable via `h3vt_tours_ga_measurement_id`.
	 *
	 * @return string The measurement id, or empty string to disable.
	 */
	private function get_measurement_id() {
		$id = get_option( 'h3tm_ga_measurement_id', self::DEFAULT_MEASUREMENT_ID );

		if ( empty( $id ) ) {
			$id = self::DEFAULT_MEASUREMENT_ID;
		}

		return (string) apply_filters( 'h3vt_tours_ga_measurement_id', $id );
	}

	/**
	 * Print the GA4 gtag snippet for the reporting property on single tour pages.
	 *
	 * The default pagePath GA records is the real URL (/tour/{slug}/), which is
	 * exactly what the client analytics email filters on — no page_path override
	 * is needed here.
	 */
	public function print_reporting_tag() {
		if ( ! is_singular( 'h3vt_tour' ) ) {
			return;
		}

		$measurement_id = $this->get_measurement_id();
		if ( '' === $measurement_id ) {
			return;
		}

		$src    = esc_url( 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $measurement_id ) );
		$mid_js = esc_js( $measurement_id );
		?>
<!-- H3VT Tours reporting-property analytics -->
<script async src="<?php echo $src; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() applied above. ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '<?php echo $mid_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js() applied above. ?>');
</script>
<?php
	}
}
