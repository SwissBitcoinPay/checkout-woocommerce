<?php
/**
 * The order's Bitcoin payments, on its edit screen.
 *
 * Every value is escaped here.
 *
 * @package Sattleme\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * What the box shows (OrderBox::render()).
 *
 * @var \Sattleme\WooCommerce\Admin\OrderBoxView $sattleme
 */
$sattleme_rows = $sattleme->rows;
?>
<div class="sattleme-order-box">
	<?php if ( array() === $sattleme_rows ) : ?>
		<p><?php esc_html_e( 'No Bitcoin invoice was made for this order.', 'sattleme-for-woocommerce' ); ?></p>
	<?php endif; ?>
	<?php foreach ( $sattleme_rows as $sattleme_row ) : ?>
		<table class="widefat striped" style="margin-bottom:1em">
			<tbody>
				<tr><th scope="row"><?php esc_html_e( 'Reference', 'sattleme-for-woocommerce' ); ?></th><td><code><?php echo esc_html( $sattleme_row->ref ); ?></code></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Status', 'sattleme-for-woocommerce' ); ?></th><td><?php echo esc_html( $sattleme_row->state ); ?></td></tr>
				<?php if ( $sattleme_row->has_invoice ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Amount', 'sattleme-for-woocommerce' ); ?></th><td>
						<?php echo esc_html( $sattleme_row->btc ); ?> BTC
						<?php
						/* translators: %s: an amount in sats. */
						printf( esc_html__( '(%s sats)', 'sattleme-for-woocommerce' ), esc_html( $sattleme_row->sats ) );
						?>
						<?php if ( null !== $sattleme_row->fiat ) : ?>
							— <?php echo esc_html( $sattleme_row->fiat ); ?>
						<?php endif; ?>
					</td></tr>
					<?php if ( null !== $sattleme_row->rate ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Exchange rate', 'sattleme-for-woocommerce' ); ?></th><td>
							<?php echo esc_html( $sattleme_row->rate ); ?>
							<?php if ( null !== $sattleme_row->rate_source ) : ?>
								<span class="description">(<?php echo esc_html( $sattleme_row->rate_source ); ?><?php echo null !== $sattleme_row->rate_time ? ', ' . esc_html( $sattleme_row->rate_time ) : ''; ?>)</span>
							<?php endif; ?>
						</td></tr>
					<?php endif; ?>
					<tr><th scope="row"><?php esc_html_e( 'Payable', 'sattleme-for-woocommerce' ); ?></th><td>
						<?php
						$sattleme_methods = array();
						if ( $sattleme_row->lightning ) {
							$sattleme_methods[] = __( 'in Lightning', 'sattleme-for-woocommerce' );
						}
						if ( $sattleme_row->onchain ) {
							$sattleme_methods[] = __( 'on-chain', 'sattleme-for-woocommerce' );
						}
						echo esc_html( implode( ', ', $sattleme_methods ) );
						?>
					</td></tr>
					<?php if ( null !== $sattleme_row->payment_hash ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Payment hash', 'sattleme-for-woocommerce' ); ?></th><td><code><?php echo esc_html( $sattleme_row->payment_hash ); ?></code></td></tr>
					<?php endif; ?>
					<?php if ( null !== $sattleme_row->address ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Bitcoin address', 'sattleme-for-woocommerce' ); ?></th><td>
							<?php if ( null !== $sattleme_row->address_link ) : ?>
								<a href="<?php echo esc_url( $sattleme_row->address_link ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( $sattleme_row->address ); ?></code></a>
							<?php else : ?>
								<code><?php echo esc_html( $sattleme_row->address ); ?></code>
							<?php endif; ?>
						</td></tr>
					<?php endif; ?>
					<tr><th scope="row"><?php esc_html_e( 'Created', 'sattleme-for-woocommerce' ); ?></th><td><?php echo esc_html( $sattleme_row->created ); ?></td></tr>
					<?php if ( null !== $sattleme_row->expires ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Payable until', 'sattleme-for-woocommerce' ); ?></th><td><?php echo esc_html( $sattleme_row->expires ); ?></td></tr>
					<?php endif; ?>
					<?php if ( null !== $sattleme_row->received ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Received', 'sattleme-for-woocommerce' ); ?></th><td>
							<?php
							/* translators: %s: an amount in sats. */
							printf( esc_html__( '%s sats', 'sattleme-for-woocommerce' ), esc_html( $sattleme_row->received ) );
							?>
						</td></tr>
					<?php endif; ?>
					<?php if ( null !== $sattleme_row->confirmations ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Confirmations', 'sattleme-for-woocommerce' ); ?></th><td><?php echo esc_html( (string) $sattleme_row->confirmations ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $sattleme_row->txids as $sattleme_index => $sattleme_txid ) : ?>
						<tr><th scope="row"><?php echo 0 === $sattleme_index ? esc_html__( 'Transactions', 'sattleme-for-woocommerce' ) : ''; ?></th><td><a href="<?php echo esc_url( $sattleme_txid[1] ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( $sattleme_txid[0] ); ?></code></a></td></tr>
					<?php endforeach; ?>
					<?php if ( null !== $sattleme_row->txids_link ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Transactions', 'sattleme-for-woocommerce' ); ?></th><td><a href="<?php echo esc_url( $sattleme_row->txids_link ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'On the address’s page (mempool.space)', 'sattleme-for-woocommerce' ); ?></a></td></tr>
					<?php endif; ?>
					<?php foreach ( $sattleme_row->preimages as $sattleme_index => $sattleme_preimage ) : ?>
						<tr><th scope="row"><?php echo 0 === $sattleme_index ? esc_html__( 'Proof of payment', 'sattleme-for-woocommerce' ) : ''; ?></th><td>
							<code><?php echo esc_html( $sattleme_preimage[0] ); ?></code><br>
							<span class="description">
								<?php
								/* translators: %s: the payment hash (hex) the preimage proves, its SHA-256. */
								printf( esc_html__( 'Preimage of the payment hash %s', 'sattleme-for-woocommerce' ), '<code>' . esc_html( $sattleme_preimage[1] ) . '</code>' );
								?>
							</span>
						</td></tr>
					<?php endforeach; ?>
					<tr><th scope="row"><?php esc_html_e( 'Last check', 'sattleme-for-woocommerce' ); ?></th><td>
						<?php echo null !== $sattleme_row->checked ? esc_html( $sattleme_row->checked ) : '–'; ?>
						<?php if ( '' !== $sattleme_row->error ) : ?>
							<br><span style="color:#b32d2e"><?php echo esc_html( $sattleme_row->error ); ?></span>
						<?php endif; ?>
					</td></tr>
					<?php if ( null !== $sattleme_row->next_check ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Next check', 'sattleme-for-woocommerce' ); ?></th><td><?php echo esc_html( $sattleme_row->next_check ); ?></td></tr>
					<?php endif; ?>
					<?php if ( $sattleme_row->shredded ) : ?>
						<tr><th scope="row"><?php esc_html_e( 'Personal data', 'sattleme-for-woocommerce' ); ?></th><td><?php esc_html_e( 'Removed: the address and the transactions are erased once the payment is final.', 'sattleme-for-woocommerce' ); ?></td></tr>
					<?php endif; ?>
				<?php elseif ( '' !== $sattleme_row->error ) : ?>
					<tr><th scope="row"><?php esc_html_e( 'Error', 'sattleme-for-woocommerce' ); ?></th><td><?php echo esc_html( $sattleme_row->error ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
	<?php endforeach; ?>
</div>
