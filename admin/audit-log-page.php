<?php
/**
 * Audit log admin page template.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template included inside Haydi_Plugin::render_audit_log_page(); variables are method-scoped, never global.
defined( 'ABSPATH' ) || exit;
if ( ! haydi_current_user_can_access() ) {
	wp_die( esc_html__( 'You do not have permission to view this page.', 'haydi' ) );
}

$logger    = new Haydi_Audit_Logger();
$log       = $logger->get_log();
$wpc_per   = 20;
$total     = count( $log );
$total_pgs = max( 1, (int) ceil( $total / $wpc_per ) );
// Read-only pagination, no state mutation, so a nonce is unnecessary.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$wpc_page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
$wpc_page = min( $wpc_page, $total_pgs );
$page_log = array_slice( $log, ( $wpc_page - 1 ) * $wpc_per, $wpc_per );
?>
<div class="wrap wpc-wrap">
	<h1><?php esc_html_e( 'Haydi Audit Log', 'haydi' ); ?></h1>

	<div class="wpc-audit-section">
		<div class="wpc-audit-header">
			<button id="wpc-btn-clear-log" class="button button-small">
				<?php esc_html_e( 'Clear log', 'haydi' ); ?>
			</button>
		</div>

		<table class="wp-list-table widefat striped fixed wpc-audit-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'haydi' ); ?></th>
					<th><?php esc_html_e( 'User', 'haydi' ); ?></th>
					<th><?php esc_html_e( 'Action', 'haydi' ); ?></th>
					<th><?php esc_html_e( 'Path', 'haydi' ); ?></th>
					<th><?php esc_html_e( 'Details', 'haydi' ); ?></th>
				</tr>
			</thead>
			<tbody id="wpc-audit-tbody">
				<?php if ( empty( $page_log ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No log entries yet.', 'haydi' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $page_log as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $entry['time'] ) ); ?></td>
						<td><?php echo esc_html( $entry['user'] ); ?></td>
						<td><code><?php echo esc_html( $entry['action'] ); ?></code></td>
						<td class="wpc-audit-path"><?php echo esc_html( $entry['path'] ); ?></td>
						<td><?php echo esc_html( $entry['details'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $total_pgs > 1 ) : ?>
			<?php
			$first_url = $wpc_page > 1 ? add_query_arg( 'paged', 1 ) : '';
			$prev_url  = $wpc_page > 1 ? add_query_arg( 'paged', $wpc_page - 1 ) : '';
			$next_url  = $wpc_page < $total_pgs ? add_query_arg( 'paged', $wpc_page + 1 ) : '';
			$last_url  = $wpc_page < $total_pgs ? add_query_arg( 'paged', $total_pgs ) : '';
			?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of audit log entries */
							_n( '%s item', '%s items', $total, 'haydi' ),
							number_format_i18n( $total )
						)
					);
					?>
				</span>
				<span class="pagination-links">
					<?php if ( $first_url ) : ?>
						<a class="first-page button" href="<?php echo esc_url( $first_url ); ?>">
							<span class="screen-reader-text"><?php esc_html_e( 'First page', 'haydi' ); ?></span>
							<span aria-hidden="true">&laquo;</span>
						</a>
					<?php else : ?>
						<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>
					<?php endif; ?>

					<?php if ( $prev_url ) : ?>
						<a class="prev-page button" href="<?php echo esc_url( $prev_url ); ?>">
							<span class="screen-reader-text"><?php esc_html_e( 'Previous page', 'haydi' ); ?></span>
							<span aria-hidden="true">&lsaquo;</span>
						</a>
					<?php else : ?>
						<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
					<?php endif; ?>

					<span class="paging-input">
						<span class="tablenav-paging-text">
							<?php echo esc_html( number_format_i18n( $wpc_page ) ); ?>
							<?php esc_html_e( 'of', 'haydi' ); ?>
							<span class="total-pages"><?php echo esc_html( number_format_i18n( $total_pgs ) ); ?></span>
						</span>
					</span>

					<?php if ( $next_url ) : ?>
						<a class="next-page button" href="<?php echo esc_url( $next_url ); ?>">
							<span class="screen-reader-text"><?php esc_html_e( 'Next page', 'haydi' ); ?></span>
							<span aria-hidden="true">&rsaquo;</span>
						</a>
					<?php else : ?>
						<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
					<?php endif; ?>

					<?php if ( $last_url ) : ?>
						<a class="last-page button" href="<?php echo esc_url( $last_url ); ?>">
							<span class="screen-reader-text"><?php esc_html_e( 'Last page', 'haydi' ); ?></span>
							<span aria-hidden="true">&raquo;</span>
						</a>
					<?php else : ?>
						<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>
					<?php endif; ?>
				</span>
			</div>
		</div>
		<?php endif; ?>
	</div>
</div>
