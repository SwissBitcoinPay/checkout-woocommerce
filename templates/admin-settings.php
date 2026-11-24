<?php
/**
 * The head of the payment method's settings page: the logo, and the integration it connects to.
 *
 * Every value is escaped here.
 *
 * @package Sattleme\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * What the head shows (Gateway::admin_options()).
 *
 * @var \Sattleme\WooCommerce\Admin\SettingsView $sattleme
 */
$sattleme_merchant = $sattleme->merchant;
?>
<div class="sattleme-settings">
	<p><img src="<?php echo esc_url( $sattleme->logo ); ?>" alt="sattle.me" width="160" height="28" style="height:auto;max-width:160px;"></p>
	<?php if ( ! $sattleme->supports ) : ?>
		<div class="notice notice-warning inline"><p>
			<?php
			/* translators: %s: the store's currency code. */
			printf( esc_html__( 'The store’s currency, %s, is not one sattle.me converts: Bitcoin is not offered at checkout.', 'sattleme-for-woocommerce' ), esc_html( $sattleme->currency ) );
			?>
		</p></div>
	<?php endif; ?>
	<?php if ( $sattleme->has_key && null !== $sattleme_merchant ) : ?>
		<div class="notice notice-success inline"><p>
			<?php
			printf(
				esc_html(
					/* translators: 1: the merchant's business name, 2: least sats, 3: most sats, 4: the number of deposit addresses, 5: the date and time of the check. */
					_n( 'Connected to %1$s. Lightning payments from %2$s to %3$s sats; %4$s deposit address for on-chain payments. Checked on %5$s.', 'Connected to %1$s. Lightning payments from %2$s to %3$s sats; %4$s deposit addresses for on-chain payments. Checked on %5$s.', $sattleme_merchant['onchainAddresses'], 'sattleme-for-woocommerce' )
				),
				esc_html( $sattleme_merchant['businessName'] ),
				esc_html( \Sattleme\WooCommerce\Page\Amounts::sats( $sattleme_merchant['minSats'] ) ),
				esc_html( \Sattleme\WooCommerce\Page\Amounts::sats( $sattleme_merchant['maxSats'] ) ),
				esc_html( number_format_i18n( $sattleme_merchant['onchainAddresses'] ) ),
				esc_html( \Sattleme\WooCommerce\Support\Dates::date_time( $sattleme_merchant['checkedAt'] ) )
			);
			?>
		</p></div>
	<?php elseif ( ! $sattleme->has_key ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'Enter the secret key of an integration you created for this shop in the sattle.me app.', 'sattleme-for-woocommerce' ); ?></p></div>
	<?php endif; ?>
	<p><?php esc_html_e( 'There is no test mode: payments are real bitcoin payments, to your wallet.', 'sattleme-for-woocommerce' ); ?></p>
</div>
