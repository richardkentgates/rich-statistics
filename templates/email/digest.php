<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{SITE_NAME}} — Analytics Digest</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1e293b;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;">
<tr><td align="center" style="padding:32px 16px;">

	<!-- Container -->
	<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.08);">

		<!-- Header -->
		<tr>
			<td style="background:linear-gradient(135deg,#6366f1,#4f46e5);padding:32px 36px;text-align:center;">
				<div style="font-size:28px;margin-bottom:8px;">📊</div>
				<h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">Analytics Digest</h1>
				<p style="margin:8px 0 0;color:rgba(255,255,255,0.8);font-size:14px;">
					<a href="{{SITE_URL}}" style="color:rgba(255,255,255,0.9);text-decoration:none;">{{SITE_NAME}}</a>
					&nbsp;&bull;&nbsp; {{PERIOD_LABEL}}
				</p>
			</td>
		</tr>

		<!-- KPI row -->
		<tr>
			<td style="padding:28px 36px 0;">
				<table width="100%" cellpadding="0" cellspacing="0">
				<tr>
					<td width="25%" style="text-align:center;padding:0 8px;">
						<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:6px;">Page Views</div>
						<div style="font-size:30px;font-weight:800;color:#6366f1;">{{PAGEVIEWS}}</div>
					</td>
					<td width="25%" style="text-align:center;padding:0 8px;border-left:1px solid #e2e8f0;">
						<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:6px;">Sessions</div>
						<div style="font-size:30px;font-weight:800;color:#1e293b;">{{SESSIONS}}</div>
					</td>
					<td width="25%" style="text-align:center;padding:0 8px;border-left:1px solid #e2e8f0;">
						<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:6px;">Avg. Time</div>
						<div style="font-size:30px;font-weight:800;color:#1e293b;">{{AVG_TIME}}</div>
					</td>
					<td width="25%" style="text-align:center;padding:0 8px;border-left:1px solid #e2e8f0;">
						<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:6px;">Bounce Rate</div>
						<div style="font-size:30px;font-weight:800;color:#1e293b;">{{BOUNCE_RATE}}</div>
					</td>
				</tr>
				</table>
			</td>
		</tr>

		<!-- Divider -->
		<tr><td style="padding:28px 36px 0;"><hr style="border:none;border-top:1px solid #e2e8f0;margin:0;"></td></tr>

		<!-- Top Pages -->
		<tr>
			<td style="padding:24px 36px 0;">
				<h2 style="margin:0 0 16px;font-size:16px;font-weight:700;color:#1e293b;">Top Pages</h2>
				<table width="100%" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;">
					<thead>
					<tr style="background:#f8fafc;">
						<th style="padding:10px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#94a3b8;">Page</th>
						<th style="padding:10px 12px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#94a3b8;">Views</th>
						<th style="padding:10px 12px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#94a3b8;">Avg. Time</th>
					</tr>
					</thead>
					<tbody>
					{{TOP_PAGES}}
					</tbody>
				</table>
			</td>
		</tr>

		<!-- CTA -->
		<tr>
			<td style="padding:28px 36px;text-align:center;">
				<a href="{{DASH_URL}}"
				   style="display:inline-block;padding:12px 28px;background:#6366f1;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;border-radius:8px;">
					View Full Dashboard →
				</a>
			</td>
		</tr>

		<!-- Footer -->
		<tr>
			<td style="background:#f8fafc;padding:20px 36px;border-top:1px solid #e2e8f0;text-align:center;">
				<p style="margin:0;font-size:12px;color:#94a3b8;">
					Sent by <strong>Rich Statistics</strong> for
					<a href="{{SITE_URL}}" style="color:#6366f1;text-decoration:none;">{{SITE_NAME}}</a>
					&nbsp;&bull;&nbsp; &copy; {{YEAR}}
				</p>
				<p style="margin:6px 0 0;font-size:11px;color:#cbd5e1;">
					No personal data was collected in generating this report.
				</p>
			</td>
		</tr>

	</table>
	<!-- /Container -->

</td></tr>
</table>

</body>
</html>
