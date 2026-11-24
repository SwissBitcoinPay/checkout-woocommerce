<?php
/**
 * What the admin tells the shop's managers.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Sattleme\WooCommerce\Admin\Notices;
use Sattleme\WooCommerce\Requirements;

/**
 * The admin says what keeps Bitcoin from being offered: what the server lacks, a missing key, a
 * currency the SDK does not convert; and that a test runs the site when the testing constant is
 * defined.
 */
final class NoticesTest extends TestCase {

	public function test_this_server_has_what_the_plugin_needs(): void {
		self::assertSame( array(), Requirements::missing() );
	}

	public function test_a_test_says_so(): void {
		self::assertContains(
			array(
				'type' => 'warning',
				'text' => 'a test runs this site (the testing constant is defined): payments are not real, never use it for a shop.',
			),
			Notices::messages()
		);
	}

	public function test_a_missing_key(): void {
		$this->services()->keys->delete();

		self::assertContains( 'Bitcoin payments are not offered until you enter your integration key (WooCommerce → Settings → Payments → sattle.me).', array_column( Notices::messages(), 'text' ) );
	}

	public function test_a_currency_the_sdk_does_not_convert(): void {
		update_option( 'woocommerce_currency', 'BTC' );

		self::assertContains( 'Bitcoin payments are not offered: the store’s currency, BTC, is not one sattle.me converts.', array_column( Notices::messages(), 'text' ) );
	}

	public function test_what_a_server_may_lack_in_words(): void {
		self::assertSame( 'WooCommerce 11.1 or later', Notices::describe( Requirements::WOOCOMMERCE ) );
		self::assertSame( 'a 64-bit PHP', Notices::describe( Requirements::BITS ) );
		self::assertSame( 'OpenSSL with the secp256k1 curve', Notices::describe( Requirements::CURVE ) );
		self::assertSame( 'OpenSSL with the ChaCha20 cipher', Notices::describe( Requirements::CIPHER ) );
		self::assertSame( 'the PHP extension bcmath', Notices::describe( 'ext-bcmath' ) );
	}

	public function test_only_the_shop_s_managers_see_them(): void {
		wp_set_current_user( self::user( 'subscriber' ) );
		ob_start();
		Notices::render();
		self::assertSame( '', ob_get_clean() );

		wp_set_current_user( self::user( 'shop_manager' ) );
		ob_start();
		Notices::render();
		$html = (string) ob_get_clean();
		self::assertStringContainsString( '<div class="notice notice-warning"><p><strong>sattle.me for WooCommerce:</strong> a test runs this site', $html );
	}
}
