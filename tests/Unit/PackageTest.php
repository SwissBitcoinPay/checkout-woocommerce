<?php
/**
 * The check of the plugin's archive.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tools/package.php check, as tools/build.sh runs it: the archive holds the plugin's
 * composer.json and composer.lock at its root, which say where the dependencies it embeds come
 * from (WordPress.org's reviewers ask for them), and no other manifest.
 */
final class PackageTest extends TestCase {

	/**
	 * A folder of its own, removed after the test.
	 *
	 * @var string
	 */
	private string $directory = '';

	protected function set_up(): void {
		parent::set_up();
		$this->directory = sys_get_temp_dir() . '/sattleme-package-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->directory ) );
	}

	protected function tear_down(): void {
		$files = glob( $this->directory . '/*' );
		foreach ( false === $files ? array() : $files as $file ) {
			unlink( $file );
		}
		rmdir( $this->directory );
		parent::tear_down();
	}

	public function test_the_archive_holds_the_plugin_s_composer_manifests(): void {
		$archive = $this->archive( self::plugin() );

		self::assertSame( array( 0, '' ), self::check( $archive ) );
	}

	public function test_an_archive_without_them_is_refused(): void {
		$files = self::plugin();
		unset( $files['composer.json'], $files['composer.lock'] );

		self::assertSame( array( 1, "composer.json is missing\ncomposer.lock is missing\n" ), self::check( $this->archive( $files ) ) );
	}

	public function test_a_dependency_s_manifest_is_refused(): void {
		$files = self::plugin() + array( 'vendor-prefixed/dasprid/enum/composer.json' => "{}\n" );

		self::assertSame( array( 1, "vendor-prefixed/dasprid/enum/composer.json is not the plugin's\n" ), self::check( $this->archive( $files ) ) );
	}

	/**
	 * The files of a plugin's archive that passes the check, at version 1.0.0.
	 *
	 * @return array<string, string> Each file's content, by its path in the plugin's folder.
	 */
	private static function plugin(): array {
		$files = array(
			'sattleme-for-woocommerce.php' => "<?php\n/**\n * Version: 1.0.0\n */\ndefine( 'SATTLEME_FOR_WOOCOMMERCE_VERSION', '1.0.0' );\n",
			'readme.txt'                   => "=== sattle.me for WooCommerce ===\nStable tag: 1.0.0\n",
			'LICENSE'                      => "MIT\n",
			'composer.json'                => "{}\n",
			'composer.lock'                => "{}\n",
		);
		$files['languages/sattleme-for-woocommerce-fr_FR.mo'] = '';
		foreach ( array( 'uninstall.php', 'src/autoload.php', 'vendor-prefixed/autoload.php', 'vendor-prefixed/sattleme/checkout/src/Client.php', 'vendor-prefixed/bacon/bacon-qr-code/src/Encoder/Encoder.php' ) as $php ) {
			$files[ $php ] = "<?php\n";
		}
		foreach ( array( 'assets/js/payment-page.js', 'assets/js/blocks.js' ) as $script ) {
			$files[ $script ] = "'use strict';\n";
		}

		return $files;
	}

	/**
	 * An archive of $files, in the plugin's folder.
	 *
	 * @param array<string, string> $files Each file's content, by its path in the plugin's folder.
	 * @return string The archive's path.
	 */
	private function archive( array $files ): string {
		$path = $this->directory . '/plugin.zip';
		$zip  = new \ZipArchive();
		self::assertTrue( $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
		foreach ( $files as $name => $content ) {
			self::assertTrue( $zip->addFromString( 'sattleme-for-woocommerce/' . $name, $content ) );
		}
		self::assertTrue( $zip->close() );

		return $path;
	}

	/**
	 * Runs tools/package.php check on $archive, at version 1.0.0.
	 *
	 * @param string $archive The archive.
	 * @return array{int, string} Its exit status and what it wrote on its standard error.
	 */
	private static function check( string $archive ): array {
		$process = proc_open(
			array( PHP_BINARY, dirname( __DIR__, 2 ) . '/tools/package.php', 'check', $archive, '1.0.0' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		self::assertIsResource( $process );
		stream_get_contents( $pipes[1] );
		$errors = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array( proc_close( $process ), $errors );
	}
}
