<?php
/**
 * The plugin's translations.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration;

use Sattleme\WooCommerce\Plugin;

/**
 * English in the sources; French, German (informal and formal, of Germany and of Switzerland)
 * and Italian in languages/, loaded by WordPress for the site's or the user's language.
 */
final class TranslationsTest extends TestCase {

	private const DOMAIN = 'sattleme-for-woocommerce';

	/**
	 * The locale the plugin's translations are loaded in, if any.
	 *
	 * @var string|null
	 */
	private ?string $locale = null;

	public function tear_down(): void {
		remove_all_filters( 'determine_locale' );
		unload_textdomain( self::DOMAIN, true );
		parent::tear_down();
	}

	/**
	 * @dataProvider locales
	 *
	 * @param string $locale   The locale.
	 * @param string $pay      "Pay with Bitcoin" in it.
	 * @param string $received "Your payment has been received. Thank you!" in it.
	 */
	public function test_the_plugin_speaks_the_language( string $locale, string $pay, string $received ): void {
		$this->load( $locale );

		self::assertSame( $pay, __( 'Pay with Bitcoin', self::DOMAIN ) );
		self::assertSame( $received, __( 'Your payment has been received. Thank you!', self::DOMAIN ) );
	}

	/**
	 * The locales, and two strings in each.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function locales(): array {
		return array(
			'French'                    => array( 'fr_FR', 'Payer en bitcoin', "Votre paiement a bien été reçu. Merci\u{00a0}!" ),
			'German'                    => array( 'de_DE', 'Mit Bitcoin bezahlen', 'Deine Zahlung ist eingegangen. Danke!' ),
			'German, formal'            => array( 'de_DE_formal', 'Mit Bitcoin bezahlen', 'Ihre Zahlung ist eingegangen. Vielen Dank!' ),
			'German of Switzerland'     => array( 'de_CH', 'Mit Bitcoin bezahlen', 'Ihre Zahlung ist eingegangen. Vielen Dank!' ),
			'German of Switzerland, du' => array( 'de_CH_informal', 'Mit Bitcoin bezahlen', 'Deine Zahlung ist eingegangen. Danke!' ),
			'Italian'                   => array( 'it_IT', 'Paga in bitcoin', 'Il tuo pagamento è stato ricevuto. Grazie!' ),
		);
	}

	public function test_the_german_of_switzerland_writes_no_eszett(): void {
		$this->load( 'de_CH' );

		self::assertSame( 'Sie können diese Seite schliessen: Die Seite Ihrer Bestellung zeigt ihren Status.', __( 'You can close this page: your order’s page shows its status.', self::DOMAIN ) );
	}

	public function test_the_payment_page_is_in_the_customer_s_language(): void {
		$order = $this->order();
		$this->invoice_for( $order );
		$this->load( 'fr_FR' );

		ob_start();
		do_action( 'woocommerce_receipt_sattleme', $order->get_id() );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Payer en bitcoin', $html );
		self::assertStringContainsString( 'Ouvrir dans mon portefeuille', $html );
		self::assertStringContainsString( 'Copier l’adresse', $html );
	}

	public function test_every_string_is_translated_with_its_placeholders(): void {
		$pot = $this->strings( SATTLEME_TESTS_PLUGIN_DIR . '/languages/' . self::DOMAIN . '.pot' );
		self::assertGreaterThan( 150, count( $pot ) );

		foreach ( array_column( self::locales(), 0 ) as $locale ) {
			$po = $this->strings( SATTLEME_TESTS_PLUGIN_DIR . '/languages/' . self::DOMAIN . '-' . $locale . '.po' );
			self::assertSame( array_keys( $pot ), array_keys( $po ), $locale );
			foreach ( $po as $msgid => $msgstr ) {
				self::assertNotSame( '', $msgstr, $locale . ': ' . $msgid );
				self::assertSame( self::placeholders( $msgid ), self::placeholders( $msgstr ), $locale . ': ' . $msgid );
			}
			self::assertFileExists( SATTLEME_TESTS_PLUGIN_DIR . '/languages/' . self::DOMAIN . '-' . $locale . '.mo' );
			self::assertFileExists( SATTLEME_TESTS_PLUGIN_DIR . '/languages/' . self::DOMAIN . '-' . $locale . '.l10n.php' );
		}
	}

	/**
	 * Has WordPress speak $locale: the plugin's translations are loaded at their first string,
	 * from the path the plugin gave at init (WordPress's just-in-time loading).
	 *
	 * @param string $locale The locale.
	 */
	private function load( string $locale ): void {
		$this->locale = $locale;
		add_filter( 'determine_locale', fn () => $this->locale );
		unload_textdomain( self::DOMAIN, true );
		Plugin::instance()->init();
		__( 'Bitcoin', self::DOMAIN );
		self::assertTrue( is_textdomain_loaded( self::DOMAIN ), $locale );
	}

	/**
	 * The strings of a .po or .pot file: msgid → msgstr.
	 *
	 * @param string $file The file.
	 * @return array<string, string>
	 */
	private function strings( string $file ): array {
		$text = (string) file_get_contents( $file );
		preg_match_all( '/^msgid "((?:[^"\\\\]|\\\\.)*)"\nmsgstr "((?:[^"\\\\]|\\\\.)*)"$/m', $text, $matches, PREG_SET_ORDER );
		$strings = array();
		foreach ( $matches as $match ) {
			if ( '' !== $match[1] ) {
				$strings[ stripcslashes( $match[1] ) ] = stripcslashes( $match[2] );
			}
		}
		ksort( $strings );

		return $strings;
	}

	/**
	 * The placeholders of a string, sorted.
	 *
	 * @param string $text The string.
	 * @return list<string>
	 */
	private static function placeholders( string $text ): array {
		preg_match_all( '/%(?:\d+\$)?[sd]/', $text, $matches );
		$found = $matches[0];
		sort( $found );

		return $found;
	}
}
