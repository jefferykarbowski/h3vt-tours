<?php
/**
 * Client analytics email.
 *
 * Same layout clients received from h3-tour-management: one table per tour,
 * then a red footer with the logo and a link to the full analytics page.
 *
 * Variables from H3VT_Tours_Client_Reports::build():
 *
 * @var array  $tours         Resolved tours, each with a 'stats' array keyed by period.
 * @var array  $periods       Period definitions (label, start, end).
 * @var string $analytics_url Client analytics page.
 * @var string $logo_url      Footer logo.
 * @var string $brand         Company name for the footer.
 *
 * @package H3VT_Tours
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$h3vt_cell = 'font-family: Arial, sans-serif;padding: 15px;font-size: 16px;';
$h3vt_head = 'padding: 15px;font-size: 16px;font-weight: bold;';
?>
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<meta name="x-apple-disable-message-reformatting">
	<title><?php echo esc_html( $subject ); ?></title>
	<!--[if mso]>
	<noscript>
		<xml>
			<o:OfficeDocumentSettings>
				<o:PixelsPerInch>96</o:PixelsPerInch>
			</o:OfficeDocumentSettings>
		</xml>
	</noscript>
	<![endif]-->
</head>
<body style="margin:0;padding:0;">
	<table role="presentation" style="width: 100%;border-collapse: collapse;border: 0;border-spacing: 0;background: #ffffff;font-family: Arial, sans-serif;">
		<tr>
			<td align="center" style="padding: 12px 15px;font-family: Arial, sans-serif;">
				<table role="presentation" style="width: 602px;border-collapse: collapse;border: 1px solid #cccccc;border-spacing: 0;text-align: left;font-family: Arial, sans-serif;">
					<?php foreach ( $tours as $h3vt_tour ) : ?>
					<tr>
						<td style="padding: 40px 30px 30px 30px;font-family: Arial, sans-serif;">
							<h1 style="font-size:28px;margin:0 0 30px 0;font-family:Arial,sans-serif;color:#153643;text-align:center;"><?php echo esc_html( $h3vt_tour['title'] ); ?></h1>
							<table class="styled-table" style="font-family: Arial, sans-serif;border-collapse: collapse;margin: 25px 0;font-size: 16px;min-width: 500px;box-shadow: 0 0 20px rgba(0, 0, 0, 0.15);width: 100%;color: #000;text-align: left;background-color: #f3f3f3;">
								<thead>
									<tr style="background-color: #000;color: #ffffff;">
										<th style="<?php echo esc_attr( $h3vt_head ); ?>"><?php esc_html_e( 'Period', 'h3vt-tours' ); ?></th>
										<th style="<?php echo esc_attr( $h3vt_head ); ?>"><?php esc_html_e( 'Total Tour Views', 'h3vt-tours' ); ?></th>
										<th style="<?php echo esc_attr( $h3vt_head ); ?>"><?php esc_html_e( 'New Users', 'h3vt-tours' ); ?></th>
										<th style="<?php echo esc_attr( $h3vt_head ); ?>"><?php esc_html_e( 'Repeat Users', 'h3vt-tours' ); ?></th>
										<th style="<?php echo esc_attr( $h3vt_head ); ?>"><?php esc_html_e( 'Avg Time on Tour', 'h3vt-tours' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									$h3vt_keys = array_keys( $periods );
									$h3vt_last = end( $h3vt_keys );
									foreach ( $periods as $h3vt_period => $h3vt_def ) :
										$h3vt_row = $h3vt_tour['stats'][ $h3vt_period ];
										?>
									<tr<?php echo $h3vt_period !== $h3vt_last ? ' style="border-bottom: 1px solid #dddddd;"' : ''; ?>>
										<td style="<?php echo esc_attr( $h3vt_cell ); ?>"><?php echo esc_html( $h3vt_def['label'] ); ?></td>
										<td style="<?php echo esc_attr( $h3vt_cell ); ?>"><?php echo esc_html( number_format_i18n( $h3vt_row['sessions'] ) ); ?></td>
										<td style="<?php echo esc_attr( $h3vt_cell ); ?>"><?php echo esc_html( number_format_i18n( $h3vt_row['new_users'] ) ); ?></td>
										<td style="<?php echo esc_attr( $h3vt_cell ); ?>"><?php echo esc_html( number_format_i18n( $h3vt_row['returning_users'] ) ); ?></td>
										<td style="<?php echo esc_attr( $h3vt_cell ); ?>"><?php echo esc_html( H3VT_Tours_Client_Reports::format_duration( $h3vt_row['avg_time'] ) ); ?></td>
									</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</td>
					</tr>
					<?php endforeach; ?>
					<tr>
						<td style="padding: 30px;background: #c1272d;font-family: Arial, sans-serif;">
							<table role="presentation" style="width:100%;border-collapse:collapse;border:0;border-spacing:0;font-size:9px;font-family:Arial,sans-serif;">
								<tr>
									<td style="padding: 12px 15px;width: 50%;font-family: Arial, sans-serif;" align="left">
										<p style="margin:0;font-size:14px;line-height:16px;font-family:Arial,sans-serif;color:#ffffff;">
											<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><img width="150" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $brand ); ?>" style="text-align:center" /></a><br>
											&reg; <?php echo esc_html( $brand . ' ' . current_time( 'Y' ) ); ?><br>
										</p>
									</td>
									<td style="padding: 12px 15px;width: 50%;font-family: Arial, sans-serif; text-align: right;" align="right">
										<a href="<?php echo esc_url( $analytics_url ); ?>" style="color:#ffffff;  text-align: right;"><?php esc_html_e( 'View More Analytics on our Site', 'h3vt-tours' ); ?></a>
									</td>
								</tr>
							</table>
						</td>
					</tr>
				</table>
			</td>
		</tr>
	</table>
</body>
</html>
