<?php
/**
 * The SDK as the plugin embeds it.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Sattleme\WooCommerce\Tools\Sdk_Pin;
use Sattleme\WooCommerce\Vendor\Sattleme\Checkout\Client;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The plugin's prefixed copy of the SDK (vendor-prefixed/, by Strauss) is the pinned SDK, file
 * for file, but for its namespace; the plugin loads it, never another copy, and uses its public
 * API alone.
 */
final class EmbeddedSdkTest extends TestCase {

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/tools/sdk.php';
	}

	public function test_the_prefixed_copy_is_the_pinned_sdk_but_for_its_namespace(): void {
		$sdk      = dirname( __DIR__, 2 ) . '/sdk';
		$embedded = SATTLEME_TESTS_PLUGIN_DIR . '/vendor-prefixed/sattleme/checkout';
		$pin      = json_decode( (string) file_get_contents( $sdk . '/PIN.json' ), true );
		self::assertIsArray( $pin );
		self::assertIsArray( $pin['files'] );

		$compared = 0;
		foreach ( array_keys( $pin['files'] ) as $file ) {
			if ( 'composer.json' === $file && ! is_file( $embedded . '/' . $file ) ) {
				continue; // The plugin's archive holds no manifest of its dependencies.
			}
			if ( in_array( $file, Sdk_Pin::VECTORS, true ) ) {
				self::assertFileDoesNotExist( $embedded . '/' . $file, 'the tests\' vectors are no file of the plugin' );
				continue;
			}
			self::assertFileExists( $embedded . '/' . $file );
			$prefixed = (string) file_get_contents( $embedded . '/' . $file );
			$restored = str_replace(
				array( 'Sattleme\\WooCommerce\\Vendor\\Sattleme\\Checkout', 'Sattleme\\\\WooCommerce\\\\Vendor\\\\Sattleme\\\\Checkout' ),
				array( 'Sattleme\\Checkout', 'Sattleme\\\\Checkout' ),
				$prefixed
			);
			self::assertSame( file_get_contents( $sdk . '/' . $file ), $restored, $file );
			++$compared;
		}
		self::assertGreaterThan( 100, $compared );
	}

	public function test_the_plugin_loads_its_own_copy(): void {
		$file = ( new \ReflectionClass( Client::class ) )->getFileName();

		self::assertIsString( $file );
		self::assertStringStartsWith( realpath( SATTLEME_TESTS_PLUGIN_DIR . '/vendor-prefixed' ) . '/', (string) realpath( $file ) );
		$pin = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/sdk/PIN.json' ), true );
		self::assertIsArray( $pin );
		self::assertSame( $pin['version'], Client::VERSION, 'the pinned version' );
	}

	public function test_the_qr_code_library_is_prefixed_too(): void {
		self::assertTrue( class_exists( \Sattleme\WooCommerce\Vendor\BaconQrCode\Encoder\Encoder::class ) );
		self::assertFileExists( SATTLEME_TESTS_PLUGIN_DIR . '/vendor-prefixed/bacon/bacon-qr-code/src/Encoder/Encoder.php' );
	}

	public function test_the_plugin_uses_no_internal_class_of_the_sdk(): void {
		// What the plugin ships of its own: its main file and uninstall.php, src/ and templates/.
		$files = glob( SATTLEME_TESTS_PLUGIN_DIR . '/*.php' );
		self::assertIsArray( $files );
		foreach ( array( 'src', 'templates' ) as $dir ) {
			$tree = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( SATTLEME_TESTS_PLUGIN_DIR . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $tree as $file ) {
				if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}

		$internal = array();
		foreach ( $files as $file ) {
			// A name of the SDK's Internal namespace, in code or in a string.
			if ( 1 === preg_match( '/Checkout\\\\{1,2}Internal\\\\/', (string) file_get_contents( $file ) ) ) {
				$internal[] = substr( $file, strlen( SATTLEME_TESTS_PLUGIN_DIR ) + 1 );
			}
		}

		self::assertGreaterThan( 50, count( $files ) );
		self::assertSame( array(), $internal, 'the SDK\'s public API alone: its Internal classes are @internal, a new version may change them' );
	}
}
