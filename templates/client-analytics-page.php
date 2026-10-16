<?php
/**
 * Client analytics page ([tour_analytics_display]).
 *
 * Markup and styles carried over from h3-tour-management so the page looks
 * the same to clients.
 *
 * Variables from H3VT_Tours_Client_Page::render():
 *
 * @var array       $tours    The viewer's resolved tours.
 * @var string      $selected Selected tour key, or 'all'.
 * @var string      $range    Selected range key.
 * @var string      $title    Heading for the selection.
 * @var array|null  $data     Dashboard stats, or null on error.
 * @var string|null $error    Error message to show instead of stats.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$h3vt_ranges = array(
	'7days'   => __( 'Last 7 Days', 'h3vt-tours' ),
	'30days'  => __( 'Last 30 Days', 'h3vt-tours' ),
	'90days'  => __( 'Last 90 Days', 'h3vt-tours' ),
	'365days' => __( 'Last Year', 'h3vt-tours' ),
);
?>
<style>
	.analytics-container { background: #f8f9fa; padding: 20px; max-width: 1200px; margin: 0 auto; }
	.analytics-controls { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
	.analytics-controls .controls-row { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
	.analytics-controls .control-group { display: flex; flex-direction: column; gap: 5px; }
	.analytics-controls .control-group label { font-size: 12px; color: #666; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
	.analytics-controls .control-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; background: white; min-width: 200px; }
	.global-analytics { background: white; padding: 30px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
	.global-analytics h2 { font-size: 24px; margin: 0 0 30px 0; color: #333; }
	.metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 30px; margin-bottom: 40px; }
	.metric-card { text-align: center; }
	.metric-value { font-size: 48px; font-weight: 700; color: #00bcd4; line-height: 1; margin-bottom: 10px; }
	.metric-label { font-size: 14px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }
	.enhanced-analytics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; margin: 40px 0; }
	.analytics-section, .states-section { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
	.analytics-section h3, .states-section h3 { font-size: 18px; margin: 0 0 20px 0; color: #333; border-bottom: 2px solid #00bcd4; padding-bottom: 10px; }
	.device-chart-container { display: flex; align-items: center; gap: 30px; flex-wrap: wrap; }
	.device-chart-container canvas { max-width: 200px; max-height: 200px; }
	.device-legend { flex: 1; min-width: 200px; }
	.legend-item { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; padding: 8px; border-radius: 4px; transition: background-color 0.3s; }
	.legend-item:hover { background-color: #f8f9fa; }
	.legend-color { width: 16px; height: 16px; border-radius: 50%; flex-shrink: 0; }
	.legend-label { font-weight: 600; color: #333; min-width: 60px; text-transform: capitalize; }
	.legend-percent { font-weight: 700; color: #00bcd4; min-width: 40px; }
	.legend-users { color: #666; font-size: 12px; }
	.referral-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #f0f0f0; }
	.referral-item:last-child { border-bottom: none; }
	.referral-source { font-weight: 600; color: #333; }
	.referral-stats { display: flex; gap: 10px; align-items: center; }
	.referral-users { color: #00bcd4; font-weight: 600; }
	.referral-percent { color: #666; font-size: 12px; }
	.states-container { max-height: 400px; overflow-y: auto; border: 1px solid #f0f0f0; border-radius: 8px; }
	.state-item { display: flex; align-items: center; padding: 15px 20px; border-bottom: 1px solid #f8f9fa; transition: background-color 0.2s ease; }
	.state-item:hover { background-color: #f8f9fa; }
	.state-item:last-child { border-bottom: none; }
	.state-info { flex: 1; display: flex; justify-content: space-between; align-items: center; gap: 20px; }
	.state-name { font-weight: 600; color: #333; min-width: 120px; font-size: 14px; }
	.state-metrics { display: flex; gap: 30px; align-items: center; min-width: 200px; }
	.state-metric { text-align: center; }
	.state-metric-value { font-weight: 700; color: #00bcd4; font-size: 16px; display: block; }
	.state-metric-label { font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 2px; }
	.state-bar-container { flex: 1; display: flex; align-items: center; gap: 12px; min-width: 150px; }
	.state-bar { flex: 1; height: 8px; background: #f0f0f0; border-radius: 4px; overflow: hidden; position: relative; }
	.state-bar-fill { height: 100%; background: linear-gradient(90deg, #00bcd4, #4dd0e1); border-radius: 4px; }
	.state-percent { font-weight: 600; color: #00bcd4; font-size: 13px; min-width: 45px; text-align: right; }
	.analytics-error { background: #fff3cd; color: #856404; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #ffeaa7; }
	.no-data { text-align: center; padding: 40px; color: #999; font-style: italic; }
	@media (max-width: 768px) {
		.analytics-controls .controls-row { flex-direction: column; align-items: stretch; }
		.analytics-controls .control-group select { width: 100%; }
		.metrics-grid { grid-template-columns: 1fr; gap: 20px; }
		.metric-value { font-size: 36px; }
		.state-item, .state-info { flex-direction: column; align-items: stretch; gap: 12px; }
		.state-metrics { justify-content: space-around; gap: 20px; }
		.state-bar-container { min-width: auto; }
	}
</style>

<div class="analytics-container">
	<div class="analytics-controls">
		<form method="get">
			<div class="controls-row">
				<div class="control-group">
					<label for="h3vt-tour-select"><?php esc_html_e( 'Tour', 'h3vt-tours' ); ?></label>
					<select name="tour" id="h3vt-tour-select" onchange="this.form.submit()">
						<option value="all" <?php selected( $selected, 'all' ); ?>><?php esc_html_e( 'All Tours', 'h3vt-tours' ); ?></option>
						<?php foreach ( $tours as $h3vt_tour ) : ?>
							<option value="<?php echo esc_attr( $h3vt_tour['key'] ); ?>" <?php selected( $selected, $h3vt_tour['key'] ); ?>><?php echo esc_html( $h3vt_tour['title'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="control-group">
					<label for="h3vt-range-select"><?php esc_html_e( 'Date Range', 'h3vt-tours' ); ?></label>
					<select name="range" id="h3vt-range-select" onchange="this.form.submit()">
						<?php foreach ( $h3vt_ranges as $h3vt_value => $h3vt_label ) : ?>
							<option value="<?php echo esc_attr( $h3vt_value ); ?>" <?php selected( $range, $h3vt_value ); ?>><?php echo esc_html( $h3vt_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<noscript><p><button type="submit"><?php esc_html_e( 'Show', 'h3vt-tours' ); ?></button></p></noscript>
		</form>
	</div>

	<div class="global-analytics">
		<h2>
			<?php
			/* translators: %s: tour name, or "All Tours" */
			echo esc_html( sprintf( __( '%s Analytics', 'h3vt-tours' ), $title ) );
			?>
		</h2>

		<?php if ( $error ) : ?>
			<div class="analytics-error"><?php echo esc_html( $error ); ?></div>
		<?php else : ?>
			<?php $h3vt_m = $data['metrics']; ?>
			<div class="metrics-grid">
				<div class="metric-card">
					<div class="metric-value"><?php echo esc_html( number_format_i18n( $h3vt_m['sessions'] ) ); ?></div>
					<div class="metric-label"><?php esc_html_e( 'Total Visits', 'h3vt-tours' ); ?></div>
				</div>
				<div class="metric-card">
					<div class="metric-value"><?php echo esc_html( number_format_i18n( $h3vt_m['newUsers'] ) ); ?></div>
					<div class="metric-label"><?php esc_html_e( 'New Users', 'h3vt-tours' ); ?></div>
				</div>
				<div class="metric-card">
					<div class="metric-value"><?php echo esc_html( number_format_i18n( $h3vt_m['returningUsers'] ) ); ?></div>
					<div class="metric-label"><?php esc_html_e( 'Returning Users', 'h3vt-tours' ); ?></div>
				</div>
				<div class="metric-card">
					<div class="metric-value"><?php echo esc_html( H3VT_Tours_Client_Reports::format_duration( $h3vt_m['avgSessionDuration'] ) ); ?></div>
					<div class="metric-label"><?php esc_html_e( 'Avg. Duration', 'h3vt-tours' ); ?></div>
				</div>
				<div class="metric-card">
					<div class="metric-value"><?php echo esc_html( number_format_i18n( $h3vt_m['bounceRate'] * 100, 1 ) ); ?>%</div>
					<div class="metric-label"><?php esc_html_e( 'Bounce Rate', 'h3vt-tours' ); ?></div>
				</div>
				<div class="metric-card">
					<div class="metric-value"><?php echo esc_html( number_format_i18n( $h3vt_m['screenPageViewsPerSession'], 1 ) ); ?></div>
					<div class="metric-label"><?php esc_html_e( 'Pages/Session', 'h3vt-tours' ); ?></div>
				</div>
			</div>

			<div class="enhanced-analytics-grid">
				<div class="analytics-section">
					<h3><?php esc_html_e( 'Device Types', 'h3vt-tours' ); ?></h3>
					<?php if ( $data['devices'] ) : ?>
						<div class="device-chart-container">
							<canvas id="h3vt-device-chart" width="300" height="300" role="img" aria-label="<?php esc_attr_e( 'Visitors by device type', 'h3vt-tours' ); ?>"></canvas>
							<div class="device-legend">
								<?php foreach ( $data['devices'] as $h3vt_i => $h3vt_device ) : ?>
									<div class="legend-item">
										<span class="legend-color" style="background-color: <?php echo esc_attr( H3VT_Tours_Client_Page::chart_color( $h3vt_i ) ); ?>"></span>
										<span class="legend-label"><?php echo esc_html( $h3vt_device['name'] ); ?></span>
										<span class="legend-percent"><?php echo esc_html( number_format_i18n( $h3vt_device['percent'], 1 ) ); ?>%</span>
										<span class="legend-users">
											<?php
											/* translators: %s: number of users */
											echo esc_html( sprintf( _n( '(%s user)', '(%s users)', $h3vt_device['users'], 'h3vt-tours' ), number_format_i18n( $h3vt_device['users'] ) ) );
											?>
										</span>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php else : ?>
						<div class="no-data"><?php esc_html_e( 'No device data for this period.', 'h3vt-tours' ); ?></div>
					<?php endif; ?>
				</div>

				<div class="analytics-section">
					<h3><?php esc_html_e( 'Traffic Sources', 'h3vt-tours' ); ?></h3>
					<?php if ( $data['referrals'] ) : ?>
						<div class="referral-list">
							<?php foreach ( $data['referrals'] as $h3vt_ref ) : ?>
								<div class="referral-item">
									<div class="referral-source"><?php echo esc_html( $h3vt_ref['source'] ); ?></div>
									<div class="referral-stats">
										<span class="referral-users">
											<?php
											/* translators: %s: number of users */
											echo esc_html( sprintf( _n( '%s user', '%s users', $h3vt_ref['users'], 'h3vt-tours' ), number_format_i18n( $h3vt_ref['users'] ) ) );
											?>
										</span>
										<span class="referral-percent">(<?php echo esc_html( number_format_i18n( $h3vt_ref['percent'], 1 ) ); ?>%)</span>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<div class="no-data"><?php esc_html_e( 'No traffic source data for this period.', 'h3vt-tours' ); ?></div>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $data['states'] ) : ?>
				<?php $h3vt_max = max( array_column( $data['states'], 'users' ) ); ?>
				<div class="states-section">
					<h3><?php esc_html_e( 'Visitors by State', 'h3vt-tours' ); ?></h3>
					<div class="states-container">
						<?php foreach ( $data['states'] as $h3vt_state ) : ?>
							<div class="state-item">
								<div class="state-info">
									<div class="state-name"><?php echo esc_html( $h3vt_state['name'] ); ?></div>
									<div class="state-metrics">
										<div class="state-metric">
											<span class="state-metric-value"><?php echo esc_html( number_format_i18n( $h3vt_state['users'] ) ); ?></span>
											<div class="state-metric-label"><?php esc_html_e( 'Users', 'h3vt-tours' ); ?></div>
										</div>
										<div class="state-metric">
											<span class="state-metric-value"><?php echo esc_html( number_format_i18n( $h3vt_state['sessions'] ) ); ?></span>
											<div class="state-metric-label"><?php esc_html_e( 'Sessions', 'h3vt-tours' ); ?></div>
										</div>
									</div>
									<div class="state-bar-container">
										<div class="state-bar">
											<div class="state-bar-fill" style="width: <?php echo esc_attr( $h3vt_max > 0 ? round( $h3vt_state['users'] / $h3vt_max * 100, 1 ) : 0 ); ?>%;"></div>
										</div>
										<span class="state-percent"><?php echo esc_html( number_format_i18n( $h3vt_state['percent'], 1 ) ); ?>%</span>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>

<?php if ( ! $error && $data['devices'] ) : ?>
<script>
(function () {
	var devices = <?php echo wp_json_encode( array_map( 'floatval', array_column( $data['devices'], 'percent' ) ) ); ?>;
	var colors = <?php echo wp_json_encode( array_map( array( 'H3VT_Tours_Client_Page', 'chart_color' ), array_keys( $data['devices'] ) ) ); ?>;

	function draw() {
		var canvas = document.getElementById('h3vt-device-chart');
		if (!canvas || !devices.length) {
			return;
		}
		var ctx = canvas.getContext('2d');
		var cx = canvas.width / 2, cy = canvas.height / 2;
		var radius = Math.min(cx, cy) - 10;
		var total = devices.reduce(function (sum, value) { return sum + value; }, 0);
		var angle = -Math.PI / 2;

		ctx.clearRect(0, 0, canvas.width, canvas.height);
		devices.forEach(function (value, i) {
			var slice = total > 0 ? value / total * 2 * Math.PI : 0;
			ctx.beginPath();
			ctx.moveTo(cx, cy);
			ctx.arc(cx, cy, radius, angle, angle + slice);
			ctx.closePath();
			ctx.fillStyle = colors[i];
			ctx.fill();
			ctx.strokeStyle = '#fff';
			ctx.lineWidth = 2;
			ctx.stroke();
			angle += slice;
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', draw);
	} else {
		draw();
	}
})();
</script>
<?php endif; ?>
