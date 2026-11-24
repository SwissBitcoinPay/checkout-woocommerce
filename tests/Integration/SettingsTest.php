<?php
/**
 * The payment method's settings, and its key.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Sattleme\WooCommerce\Settings\GatewaySettings;
use Sattleme\WooCommerce\Settings\KeyStore;
use Sattleme\WooCommerce\Settings\MerchantInfo;
use Sattleme\WooCommerce\Support\Db;
use Sattleme\WooCommerce\Tests\Integration\Support\Sdk;
use Sattleme\WooCommerce\Tests\Support\Arrays;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\ChannelNotFoundException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\InvalidKeyException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\NetworkException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\RevokedException;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Exception\UnsupportedEnvironmentException;

/**
 * The integration key is write-only: checked with sattle.me when saved, kept in an option of its
 * own (never the gateway's settings, never autoloaded), never shown again, never sent to the
 * browser, never logged.
 */
final class SettingsTest extends TestCase {

	/** Another well-formed key. */
	private const NEW_KEY = 'sattleme_ck_-kKjB8mG0CLDp3tcdT76Nnq66ATU4wzzgS0xt7DCKdo.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA.cmVsYXkuZXhhbXBsZQ';

	public function set_up(): void {
		parent::set_up();
		self::errors( array() );
	}

	public function test_the_key_field_is_always_empty(): void {
		$html = $this->gateway()->generate_sattleme_key_html( 'key', $this->key_field() );

		self::assertStringContainsString( 'type="password" name="woocommerce_sattleme_key" id="woocommerce_sattleme_key" value=""', $html );
		self::assertStringContainsString( 'placeholder="Saved: enter a new key to replace it"', $html );
		self::assertStringNotContainsString( self::KEY, $html );
		self::assertStringNotContainsString( '17wserpSaW', $html );
	}

	public function test_without_a_key_the_field_says_what_to_enter(): void {
		$this->services()->keys->delete();

		$html = $this->gateway()->generate_sattleme_key_html( 'key', $this->key_field() );

		self::assertStringContainsString( 'placeholder="sattleme_ck_…"', $html );
	}

	public function test_the_settings_page_never_holds_the_key(): void {
		$this->services()->merchant_info->save( Sdk::merchant(), $this->clock()->now() );

		ob_start();
		$this->gateway()->admin_options();
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( '17wserpSaW', $html );
		self::assertStringContainsString( 'Connected to Satoshi’s &lt;Shop&gt;. Lightning payments from 1 to 10,000,000 sats; 25 deposit addresses for on-chain payments.', $html );
		self::assertStringContainsString( 'sattleme-logo.svg', $html );
		self::assertStringContainsString( 'There is no test mode', $html );
	}

	public function test_one_deposit_address_is_said_in_the_singular(): void {
		$this->services()->merchant_info->save( new \Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Merchant( 'Shop', '1', '1000', 1 ), $this->clock()->now() );

		ob_start();
		$this->gateway()->admin_options();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '; 1 deposit address for on-chain payments.', $html );
	}

	public function test_a_key_is_checked_then_saved_apart(): void {
		$this->save( array( 'key' => self::NEW_KEY ) );

		self::assertSame( array(), self::errors() );
		self::assertSame( self::NEW_KEY, $this->services()->keys->key() );
		self::assertSame( 1, $this->sdk()->merchant_calls, 'checked with sattle.me' );
		$merchant = $this->services()->merchant_info->get();
		self::assertNotNull( $merchant );
		self::assertSame( 'Satoshi’s <Shop>', $merchant['businessName'] );
		$settings = get_option( GatewaySettings::OPTION );
		self::assertIsArray( $settings );
		self::assertSame( '', $settings['key'] );
		self::assertStringNotContainsString( 'AAAAAAAAAAAA', (string) wp_json_encode( $settings ) );
	}

	public function test_the_key_is_never_on_wordpress_s_options_page(): void {
		$raw = Db::wpdb()->get_var( Db::wpdb()->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', Db::wpdb()->options, KeyStore::OPTION ) );

		self::assertIsString( $raw );
		// wp-admin/options.php writes a string option into an input's value; a serialized array
		// it shows as "SERIALIZED DATA".
		self::assertTrue( is_serialized( $raw ) && ! is_serialized_string( $raw ), 'not a string: ' . substr( $raw, 0, 24 ) );
		self::assertSame( self::KEY, $this->services()->keys->key() );
	}

	public function test_the_key_is_never_autoloaded(): void {
		$this->save( array( 'key' => self::NEW_KEY ) );
		wp_cache_delete( 'alloptions', 'options' );

		self::assertArrayNotHasKey( KeyStore::OPTION, wp_load_alloptions() );
		self::assertArrayNotHasKey( MerchantInfo::OPTION, wp_load_alloptions() );
	}

	public function test_an_empty_field_keeps_the_key(): void {
		$this->save( array( 'key' => '' ) );

		self::assertSame( self::KEY, $this->services()->keys->key() );
		self::assertSame( 0, $this->sdk()->merchant_calls );
	}

	/**
	 * @dataProvider refusals
	 *
	 * @param \Throwable $refusal Why the SDK refuses it.
	 * @param bool       $client  Whether it is the client that refuses it (else merchant()).
	 * @param string     $message What the merchant is told.
	 */
	public function test_a_key_refused_is_not_saved( \Throwable $refusal, bool $client, string $message ): void {
		if ( $client ) {
			$this->clients()->refusal = $refusal;
		} else {
			$this->sdk()->merchants = array( $refusal );
		}

		$this->save( array( 'key' => self::NEW_KEY ) );

		self::assertSame( array( $message ), self::errors() );
		self::assertSame( self::KEY, $this->services()->keys->key() );
		self::assertStringNotContainsString( 'AAAAAAAAAAAA', $this->log()->text() );
		self::assertStringNotContainsString( 'AAAAAAAAAAAA', $message );
	}

	/**
	 * Every refusal, and what the merchant is told.
	 *
	 * @return array<string, array{\Throwable, bool, string}>
	 */
	public static function refusals(): array {
		return array(
			'not a key'         => array( new InvalidKeyException(), true, 'This is not the secret key of a sattle.me integration (sattleme_ck_…).' ),
			'the server\'s PHP' => array( new UnsupportedEnvironmentException( 'ext-gmp is missing' ), true, 'The server’s PHP lacks what sattle.me needs: ext-gmp is missing' ),
			'a removed one'     => array( new RevokedException(), false, 'This integration was removed in the sattle.me app. Create a new one and enter its key.' ),
			'a newer format'    => array( new ChannelNotFoundException( true ), false, 'This integration needs a newer version of sattle.me for WooCommerce.' ),
			'not found'         => array( new ChannelNotFoundException(), false, 'This integration could not be found. Please check the key, or try again in a moment.' ),
			'sattle.me silent'  => array( new NetworkException(), false, 'sattle.me could not be reached to check this key. Please try again in a moment.' ),
			'anything else'     => array( new \RuntimeException( 'boom' ), false, 'sattle.me could not be reached to check this key. Please try again in a moment.' ),
		);
	}

	public function test_the_time_to_pay_is_one_to_sixty_minutes(): void {
		$this->save( array( 'invoice_minutes' => '30' ) );
		self::assertSame( 30, $this->services()->settings->minutes() );

		foreach ( array( '0', '61', 'abc', '-5', '1.5' ) as $wrong ) {
			self::errors( array() );
			$this->save( array( 'invoice_minutes' => $wrong ) );
			self::assertSame( 30, $this->services()->settings->minutes(), $wrong );
			self::assertSame( array( 'The time to pay is a number of minutes from 1 to 60.' ), self::errors() );
		}
	}

	public function test_the_title_and_description_have_translated_defaults(): void {
		self::assertSame( 'Bitcoin', $this->gateway()->get_title() );
		self::assertSame( GatewaySettings::default_description(), $this->gateway()->get_description() );

		$this->save(
			array(
				'title'       => 'Bitcoin ⚡',
				'description' => 'Pay from your wallet.',
			)
		);

		self::assertSame( 'Bitcoin ⚡', $this->gateway()->get_title() );
		self::assertSame( 'Pay from your wallet.', $this->gateway()->get_description() );
	}

	public function test_woocommerce_s_rest_api_never_returns_the_key(): void {
		wp_set_current_user( self::user( 'administrator' ) );

		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/wc/v3/payment_gateways/sattleme' ) );

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertSame( '', Arrays::at( $data, 'settings', 'key', 'value' ) );
		self::assertStringNotContainsString( '17wserpSaW', (string) wp_json_encode( $data ) );
	}

	public function test_a_key_written_through_the_rest_api_is_never_stored(): void {
		wp_set_current_user( self::user( 'administrator' ) );
		$request = new \WP_REST_Request( 'PUT', '/wc/v3/payment_gateways/sattleme' );
		$request->set_body_params( array( 'settings' => array( 'key' => self::NEW_KEY ) ) );

		$response = rest_get_server()->dispatch( $request );

		self::assertSame( 200, $response->get_status() );
		$settings = get_option( GatewaySettings::OPTION );
		self::assertIsArray( $settings );
		self::assertSame( '', $settings['key'] );
		self::assertSame( self::KEY, $this->services()->keys->key() );
		self::assertStringNotContainsString( 'AAAAAAAAAAAA', (string) wp_json_encode( $response->get_data() ) );
	}

	public function test_the_plugins_screen_links_to_the_settings(): void {
		$links = \Sattleme\WooCommerce\Plugin::instance()->action_links( array( '<a href="#">Deactivate</a>' ) );

		self::assertCount( 2, $links );
		self::assertIsString( $links[0] ?? null );
		self::assertStringContainsString( 'page=wc-settings&#038;tab=checkout&#038;section=sattleme', $links[0] );
	}

	/**
	 * The key's field, as the settings page has it.
	 *
	 * @return array<mixed>
	 */
	private function key_field(): array {
		$field = $this->gateway()->get_form_fields()['key'] ?? null;
		self::assertIsArray( $field );

		return $field;
	}

	/**
	 * Saves the settings form with $fields (the others as they are), as the settings page posts it.
	 *
	 * @param array<string, string> $fields The fields, by key.
	 */
	private function save( array $fields ): void {
		$gateway = $this->gateway();
		$post    = array();
		foreach ( $gateway->get_form_fields() as $key => $field ) {
			if ( 'checkbox' === Arrays::at( $field, 'type' ) ) {
				if ( 'yes' === $gateway->get_option( $key ) ) {
					$post[ $gateway->get_field_key( $key ) ] = '1';
				}
				continue;
			}
			$post[ $gateway->get_field_key( $key ) ] = $fields[ $key ] ?? ( 'key' === $key ? '' : $gateway->get_option( $key ) );
		}
		$gateway->set_post_data( $post );
		$gateway->process_admin_options();
	}

	/**
	 * The errors the settings page shows (WooCommerce keeps them in a private property), after
	 * setting them to $errors when given.
	 *
	 * @param list<string>|null $errors The errors to start from.
	 * @return list<string>
	 */
	private static function errors( ?array $errors = null ): array {
		$property = new \ReflectionProperty( \WC_Admin_Settings::class, 'errors' );
		if ( null !== $errors ) {
			$property->setValue( null, $errors );
		}
		$value = $property->getValue();

		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	/**
	 * The SDK's clients.
	 */
	private function clients(): \Sattleme\WooCommerce\Tests\Integration\Support\FakeClients {
		return \Sattleme\WooCommerce\Tests\Integration\Support\Fakes::clients();
	}
}
