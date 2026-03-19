<?php
/**
 * Admin page: Maintenance
 *
 * Lists every distinct page path recorded across rsa_events, rsa_clicks, and
 * rsa_heatmap.  Administrators can see which paths are still live on the site
 * and purge data for orphaned or bogus paths.
 *
 * @package RichStatistics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$rows = RSA_Analytics::get_all_tracked_pages();

// Data retention setting (for context note)
$retention_days = (int) get_option( 'rsa_data_retention_days', 365 );

$status_labels = [
	'live'      => [ 'label' => __( 'Live',      'rich-statistics' ), 'class' => 'rsa-badge-green' ],
	'unmatched' => [ 'label' => __( 'Unmatched', 'rich-statistics' ), 'class' => 'rsa-badge-gray'  ],
];

$nonce = wp_create_nonce( 'wp_rest' );
?>
<?php RSA_Admin::page_header( __( 'Maintenance', 'rich-statistics' ) ); ?>

<div class="rsa-section">
	<div class="rsa-chart-card">
		<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px">
			<div>
				<h2 style="margin:0 0 4px"><?php esc_html_e( 'Tracked Pages', 'rich-statistics' ); ?></h2>
				<p class="rsa-field-hint" style="margin:0">
					<?php
					printf(
						/* translators: %d = number of days */
						esc_html__( 'All distinct page paths recorded in the database. Live = published page or post. Unmatched = deleted, never existed, or outside current keep-data setting (%d days). Purge removes all events, clicks and heatmap data for that path.', 'rich-statistics' ),
						$retention_days
					);
					?>
				</p>
			</div>
			<button id="rsa-purge-orphaned"
				class="rsa-btn rsa-btn-secondary"
				style="white-space:nowrap"
				data-confirm="<?php esc_attr_e( 'Purge data for all Unmatched paths? This cannot be undone.', 'rich-statistics' ); ?>">
				<?php esc_html_e( 'Purge All Unmatched', 'rich-statistics' ); ?>
			</button>
		</div>

		<?php if ( empty( $rows ) ) : ?>
			<p><?php esc_html_e( 'No tracked pages found.', 'rich-statistics' ); ?></p>
		<?php else : ?>
		<div style="overflow-x:auto">
			<table class="rsa-table" id="rsa-maintenance-table" style="width:100%;border-collapse:collapse">
				<thead>
					<tr>
						<th style="text-align:left;padding:10px 12px"><?php esc_html_e( 'Page Path',   'rich-statistics' ); ?></th>
						<th style="text-align:right;padding:10px 12px"><?php esc_html_e( 'Pageviews',  'rich-statistics' ); ?></th>
						<th style="text-align:right;padding:10px 12px"><?php esc_html_e( 'Clicks',     'rich-statistics' ); ?></th>
						<th style="text-align:right;padding:10px 12px"><?php esc_html_e( 'Heatmap Pts','rich-statistics' ); ?></th>
						<th style="text-align:center;padding:10px 12px"><?php esc_html_e( 'Status',    'rich-statistics' ); ?></th>
						<th style="text-align:center;padding:10px 12px"><?php esc_html_e( 'Actions',   'rich-statistics' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) :
						$status_info = $status_labels[ $row['status'] ] ?? $status_labels['unmatched'];
					?>
					<tr data-page="<?php echo esc_attr( $row['page'] ); ?>"
						data-status="<?php echo esc_attr( $row['status'] ); ?>">
						<td style="padding:10px 12px;font-family:monospace;word-break:break-all"><?php echo esc_html( $row['page'] ); ?></td>
						<td style="padding:10px 12px;text-align:right"><?php echo esc_html( number_format_i18n( $row['events'] ) ); ?></td>
						<td style="padding:10px 12px;text-align:right"><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
						<td style="padding:10px 12px;text-align:right"><?php echo esc_html( number_format_i18n( $row['heatmap'] ) ); ?></td>
						<td style="padding:10px 12px;text-align:center">
							<span class="rsa-badge <?php echo esc_attr( $status_info['class'] ); ?>">
								<?php echo esc_html( $status_info['label'] ); ?>
							</span>
						</td>
						<td style="padding:10px 12px;text-align:center">
							<button class="rsa-btn rsa-btn-danger rsa-purge-row"
								data-page="<?php echo esc_attr( $row['page'] ); ?>"
								data-confirm="<?php
									// translators: %s = page path
									echo esc_attr( sprintf( __( 'Purge all data for %s? This cannot be undone.', 'rich-statistics' ), $row['page'] ) );
								?>">
								<?php esc_html_e( 'Purge', 'rich-statistics' ); ?>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>

		<p id="rsa-maintenance-msg" style="margin-top:12px;min-height:1.4em" aria-live="polite"></p>
	</div>
</div>

<style>
.rsa-badge { display:inline-block;padding:2px 10px;border-radius:999px;font-size:.8em;font-weight:600;letter-spacing:.02em }
.rsa-badge-green  { background:#d1fae5;color:#065f46 }
.rsa-badge-yellow { background:#fef3c7;color:#92400e }
.rsa-badge-gray   { background:#f1f5f9;color:#475569 }
.rsa-btn-danger { background:#fef2f2;color:#b91c1c;border-color:#fca5a5 }
.rsa-btn-danger:hover { background:#fee2e2 }
</style>

<script>
( function () {
	var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
	var apiBase = <?php echo wp_json_encode( rest_url( 'rsa/v1' ) ); ?>;
	var msgEl   = document.getElementById( 'rsa-maintenance-msg' );

	function showMsg( text, isError ) {
		msgEl.textContent = text;
		msgEl.style.color = isError ? '#b91c1c' : '#065f46';
	}

	function purge( page, btn ) {
		btn.disabled = true;
		btn.textContent = <?php echo wp_json_encode( __( 'Purging\u2026', 'rich-statistics' ) ); ?>;

		fetch( apiBase + '/purge-page', {
			method:  'POST',
			headers: {
				'Content-Type':    'application/json',
				'X-WP-Nonce':      nonce,
			},
			body: JSON.stringify( { page: page } ),
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( data ) {
			if ( data && typeof data.deleted !== 'undefined' ) {
				showMsg(
					<?php echo wp_json_encode( __( 'Purged', 'rich-statistics' ) ); ?> + ' \u201c' + page + '\u201d \u2014 ' +
					data.deleted + ' ' + <?php echo wp_json_encode( __( 'records deleted.', 'rich-statistics' ) ); ?>,
					false
				);
				// Remove row from table
				var row = btn.closest( 'tr' );
				if ( row ) { row.remove(); }
			} else {
				throw new Error( data && data.message ? data.message : 'Unknown error' );
			}
		} )
		.catch( function ( err ) {
			showMsg( <?php echo wp_json_encode( __( 'Error:', 'rich-statistics' ) ); ?> + ' ' + err.message, true );
			btn.disabled = false;
			btn.textContent = <?php echo wp_json_encode( __( 'Purge', 'rich-statistics' ) ); ?>;
		} );
	}

	// Per-row purge buttons
	document.querySelectorAll( '.rsa-purge-row' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var page    = btn.getAttribute( 'data-page' );
			var confirm_msg = btn.getAttribute( 'data-confirm' );
			// eslint-disable-next-line no-alert
			if ( window.confirm( confirm_msg ) ) {
				purge( page, btn );
			}
		} );
	} );

	// Bulk "Purge All Unmatched"
	var bulkBtn = document.getElementById( 'rsa-purge-orphaned' );
	if ( bulkBtn ) {
		bulkBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( bulkBtn.getAttribute( 'data-confirm' ) ) ) { return; }

			var rows = document.querySelectorAll( '#rsa-maintenance-table tbody tr[data-status="unmatched"]' );
			if ( ! rows.length ) {
				showMsg( <?php echo wp_json_encode( __( 'No unmatched paths to purge.', 'rich-statistics' ) ); ?>, false );
				return;
			}

			bulkBtn.disabled = true;
			var pending = rows.length;
			var totalDeleted = 0;

			rows.forEach( function ( row ) {
				var page = row.getAttribute( 'data-page' );
				fetch( apiBase + '/purge-page', {
					method:  'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify( { page: page } ),
				} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					totalDeleted += ( data && data.deleted ) ? data.deleted : 0;
					row.remove();
				} )
				.finally( function () {
					pending--;
					if ( pending === 0 ) {
						bulkBtn.disabled = false;
						showMsg(
							<?php echo wp_json_encode( __( 'Bulk purge complete.', 'rich-statistics' ) ); ?> + ' ' +
							totalDeleted + ' ' + <?php echo wp_json_encode( __( 'total records deleted.', 'rich-statistics' ) ); ?>,
							false
						);
					}
				} );
			} );
		} );
	}
}() );
</script>

<?php RSA_Admin::page_footer(); ?>
