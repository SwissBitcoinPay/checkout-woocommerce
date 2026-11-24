<?php
/**
 * The payment page's content, below WooCommerce's summary of the order.
 *
 * Every value is escaped here.
 *
 * @package Sattleme\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * What the page shows (PaymentPage::view()).
 *
 * @var \Sattleme\WooCommerce\Page\PaymentView $sattleme
 */
$sattleme_phase = $sattleme->phase;
?>
<section id="sattleme-payment" class="sattleme-payment"
	data-status-url="<?php echo esc_url( $sattleme->status_url ); ?>"
	data-version="<?php echo esc_attr( $sattleme->version ); ?>"
	data-seconds-left="<?php echo esc_attr( (string) $sattleme->seconds_left ); ?>"
	<?php if ( null !== $sattleme->poll_interval ) : ?>
	data-poll-interval="<?php echo esc_attr( (string) $sattleme->poll_interval ); ?>"
	<?php endif; ?>
	aria-live="polite">
	<h2 class="sattleme-payment__title"><?php esc_html_e( 'Pay with Bitcoin', 'sattleme-for-woocommerce' ); ?></h2>

	<?php if ( 'open' === $sattleme_phase || 'underpaid' === $sattleme_phase ) : ?>
		<div class="sattleme-payment__body">
			<div class="sattleme-payment__code">
				<?php if ( null !== $sattleme->uri ) : ?>
					<?php if ( null !== $sattleme->qr ) : ?>
						<a class="sattleme-payment__qr" href="<?php echo esc_url( $sattleme->uri, array( 'bitcoin', 'lightning' ) ); ?>">
							<?php echo wp_kses( $sattleme->qr, \Sattleme\WooCommerce\Page\Qr::ALLOWED_HTML ); ?>
						</a>
						<p class="sattleme-payment__hint"><?php esc_html_e( 'Scan this QR code with your Bitcoin wallet.', 'sattleme-for-woocommerce' ); ?></p>
					<?php endif; ?>
					<p>
						<a class="button wp-element-button sattleme-payment__open" href="<?php echo esc_url( $sattleme->uri, array( 'bitcoin', 'lightning' ) ); ?>"><?php esc_html_e( 'Open in my wallet', 'sattleme-for-woocommerce' ); ?></a>
					</p>
				<?php endif; ?>
			</div>
			<div class="sattleme-payment__details">
				<?php if ( 'underpaid' === $sattleme_phase ) : ?>
					<p class="sattleme-payment__notice woocommerce-info">
						<?php
						printf(
							/* translators: 1: sats received, 2: sats left to pay. */
							esc_html__( 'We received %1$s sats, less than the amount of this order. Please pay the remaining %2$s sats.', 'sattleme-for-woocommerce' ),
							esc_html( $sattleme->received_sats ),
							esc_html( $sattleme->to_pay_sats )
						);
						?>
					</p>
					<?php if ( null === $sattleme->uri ) : ?>
						<p><?php esc_html_e( 'An invoice for the remaining amount is being prepared: it appears here in a moment.', 'sattleme-for-woocommerce' ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
				<dl class="sattleme-payment__facts">
					<dt><?php echo 'underpaid' === $sattleme_phase ? esc_html__( 'Remaining amount', 'sattleme-for-woocommerce' ) : esc_html__( 'Amount', 'sattleme-for-woocommerce' ); ?></dt>
					<dd>
						<strong><?php echo esc_html( $sattleme->to_pay_btc ); ?> BTC</strong>
						<?php
						/* translators: %s: an amount in sats. */
						printf( esc_html__( '(%s sats)', 'sattleme-for-woocommerce' ), esc_html( $sattleme->to_pay_sats ) );
						?>
						<?php if ( 'open' === $sattleme_phase ) : ?>
							<br><span class="sattleme-payment__fiat"><?php echo wp_kses_post( $sattleme->price ); ?></span>
						<?php endif; ?>
					</dd>
					<dt><?php esc_html_e( 'Time left to pay', 'sattleme-for-woocommerce' ); ?></dt>
					<dd>
						<span id="sattleme-countdown" class="sattleme-payment__countdown"><?php echo esc_html( $sattleme->time_left ); ?></span>
						<?php if ( '' !== $sattleme->until ) : ?>
							<span class="sattleme-payment__until">
								<?php
								/* translators: %s: a time of day. */
								printf( esc_html__( '(until %s)', 'sattleme-for-woocommerce' ), esc_html( $sattleme->until ) );
								?>
							</span>
						<?php endif; ?>
					</dd>
				</dl>
				<?php if ( null !== $sattleme->lightning ) : ?>
					<p class="sattleme-payment__label"><strong><?php esc_html_e( 'Lightning invoice', 'sattleme-for-woocommerce' ); ?></strong></p>
					<p class="sattleme-payment__text" id="sattleme-lightning"><?php echo esc_html( $sattleme->lightning ); ?></p>
					<p>
						<button type="button" class="button wp-element-button sattleme-payment__copy" hidden
							data-sattleme-copy="sattleme-lightning"
							data-copied="<?php esc_attr_e( 'Copied', 'sattleme-for-woocommerce' ); ?>"><?php esc_html_e( 'Copy the Lightning invoice', 'sattleme-for-woocommerce' ); ?></button>
					</p>
				<?php endif; ?>
				<?php if ( null !== $sattleme->address ) : ?>
					<p class="sattleme-payment__label"><strong><?php esc_html_e( 'Bitcoin address', 'sattleme-for-woocommerce' ); ?></strong></p>
					<p class="sattleme-payment__text" id="sattleme-address"><?php echo esc_html( $sattleme->address ); ?></p>
					<p>
						<button type="button" class="button wp-element-button sattleme-payment__copy" hidden
							data-sattleme-copy="sattleme-address"
							data-copied="<?php esc_attr_e( 'Copied', 'sattleme-for-woocommerce' ); ?>"><?php esc_html_e( 'Copy the address', 'sattleme-for-woocommerce' ); ?></button>
					</p>
					<p>
						<?php
						/* translators: %s: an amount in BTC. */
						printf( esc_html__( 'To pay on-chain, send exactly %s BTC to this address.', 'sattleme-for-woocommerce' ), esc_html( $sattleme->to_pay_btc ) );
						?>
					</p>
				<?php endif; ?>
				<p class="sattleme-payment__hint">
					<?php esc_html_e( 'This page updates on its own as soon as your payment arrives.', 'sattleme-for-woocommerce' ); ?>
					<?php if ( $sattleme->has_onchain ) : ?>
						<?php esc_html_e( 'A payment on-chain is confirmed by the Bitcoin network in about 30 minutes: once it has arrived, you can close this page.', 'sattleme-for-woocommerce' ); ?>
					<?php endif; ?>
				</p>
			</div>
		</div>
	<?php elseif ( 'unconfirmed' === $sattleme_phase ) : ?>
		<p class="woocommerce-info"><?php esc_html_e( 'Your payment has arrived: it is being confirmed.', 'sattleme-for-woocommerce' ); ?></p>
		<p>
			<?php
			/* translators: %s: the confirmations so far, out of 3. */
			printf( esc_html__( 'It is being confirmed by the Bitcoin network: %s of 3 confirmations. This usually takes about 30 minutes, after which your order is marked as paid.', 'sattleme-for-woocommerce' ), esc_html( number_format_i18n( $sattleme->confirmations ) ) );
			?>
		</p>
		<p class="sattleme-payment__hint"><?php esc_html_e( 'You can close this page: your order’s page shows its status.', 'sattleme-for-woocommerce' ); ?></p>
	<?php elseif ( 'checking' === $sattleme_phase ) : ?>
		<p><?php esc_html_e( 'Your Bitcoin invoice is being checked: this page updates on its own in a moment.', 'sattleme-for-woocommerce' ); ?></p>
	<?php elseif ( 'ended' === $sattleme_phase ) : ?>
		<p><?php esc_html_e( 'The time to pay this invoice is over.', 'sattleme-for-woocommerce' ); ?></p>
		<p class="sattleme-payment__hint"><?php esc_html_e( 'If you paid it in time, your payment is being checked: this page updates on its own.', 'sattleme-for-woocommerce' ); ?></p>
	<?php elseif ( 'expired' === $sattleme_phase ) : ?>
		<?php if ( $sattleme->has_received ) : ?>
			<p class="woocommerce-error"><?php echo esc_html( \Sattleme\WooCommerce\Page\PaymentPage::underpaid_text( $sattleme->received_sats, $sattleme->sats ) ); ?></p>
		<?php else : ?>
			<p class="woocommerce-info"><?php esc_html_e( 'This invoice expired without being paid.', 'sattleme-for-woocommerce' ); ?></p>
		<?php endif; ?>
		<?php if ( null !== $sattleme->pay_again_url ) : ?>
			<p><a class="button wp-element-button" href="<?php echo esc_url( $sattleme->pay_again_url ); ?>"><?php esc_html_e( 'Pay again', 'sattleme-for-woocommerce' ); ?></a></p>
		<?php endif; ?>
	<?php elseif ( 'paid' === $sattleme_phase ) : ?>
		<p class="woocommerce-message"><?php esc_html_e( 'Your payment has been received. Thank you!', 'sattleme-for-woocommerce' ); ?></p>
	<?php elseif ( 'received' === $sattleme_phase ) : ?>
		<p class="woocommerce-info"><?php echo esc_html( \Sattleme\WooCommerce\Page\PaymentPage::received_text() ); ?></p>
	<?php elseif ( 'canceled' === $sattleme_phase ) : ?>
		<p class="woocommerce-info"><?php esc_html_e( 'This order no longer takes this payment. Your order’s page shows its status.', 'sattleme-for-woocommerce' ); ?></p>
	<?php else : ?>
		<p class="woocommerce-info"><?php esc_html_e( 'No Bitcoin invoice could be created for this order yet.', 'sattleme-for-woocommerce' ); ?></p>
		<?php if ( null !== $sattleme->pay_again_url ) : ?>
			<p><a class="button wp-element-button" href="<?php echo esc_url( $sattleme->pay_again_url ); ?>"><?php esc_html_e( 'Try again', 'sattleme-for-woocommerce' ); ?></a></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( null !== $sattleme->poll_interval ) : ?>
		<noscript>
			<p><a class="button wp-element-button" href="<?php echo esc_url( $sattleme->check_url ); ?>"><?php esc_html_e( 'Check the payment', 'sattleme-for-woocommerce' ); ?></a></p>
		</noscript>
	<?php endif; ?>
</section>
