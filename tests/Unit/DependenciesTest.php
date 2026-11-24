<?php
/**
 * The plugin's dependencies.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Every dependency is pinned to an exact version in composer.json, the version composer.lock
 * holds; and every package the plugin's archive embeds (the lockfile's packages, not the
 * development ones) is one composer.json requires by name: no version reaches a shop that the
 * manifest does not name.
 */
final class DependenciesTest extends TestCase {

	public function test_every_dependency_is_pinned_exactly_at_its_locked_version(): void {
		$locked = array_merge( self::locked( 'packages' ), self::locked( 'packages-dev' ) );

		$pinned = 0;
		foreach ( array( 'require', 'require-dev' ) as $section ) {
			foreach ( self::required( $section ) as $name => $constraint ) {
				self::assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/D', $constraint, "{$section} {$name}: an exact version" );
				self::assertSame( $constraint, $locked[ $name ] ?? null, "{$name}: locked at the version composer.json pins" );
				++$pinned;
			}
		}
		self::assertGreaterThan( 10, $pinned );
	}

	public function test_the_archive_embeds_only_packages_required_by_name(): void {
		$embedded = array_keys( self::locked( 'packages' ) );
		$required = array_keys( self::required( 'require' ) );
		sort( $embedded );
		sort( $required );

		self::assertSame( array( 'bacon/bacon-qr-code', 'dasprid/enum', 'sattleme/checkout' ), $embedded );
		self::assertSame( $embedded, $required );
	}

	/**
	 * The packages composer.json requires in a section, PHP and its extensions aside.
	 *
	 * @param string $section require or require-dev.
	 * @return array<string, string> Each package's constraint, by name.
	 */
	private static function required( string $section ): array {
		$packages = self::json( 'composer.json' )[ $section ] ?? null;
		self::assertIsArray( $packages );

		$required = array();
		foreach ( $packages as $name => $constraint ) {
			self::assertIsString( $name );
			self::assertIsString( $constraint );
			if ( 'php' !== $name && ! str_starts_with( $name, 'ext-' ) ) {
				$required[ $name ] = $constraint;
			}
		}
		return $required;
	}

	/**
	 * The packages composer.lock holds in a section.
	 *
	 * @param string $section packages or packages-dev.
	 * @return array<string, string> Each package's version, by name, without its tag's "v".
	 */
	private static function locked( string $section ): array {
		$packages = self::json( 'composer.lock' )[ $section ] ?? null;
		self::assertIsArray( $packages );

		$locked = array();
		foreach ( $packages as $package ) {
			self::assertIsArray( $package );
			self::assertIsString( $package['name'] ?? null );
			self::assertIsString( $package['version'] ?? null );
			$locked[ $package['name'] ] = (string) preg_replace( '/^v(?=\d)/', '', $package['version'] );
		}
		return $locked;
	}

	/**
	 * A JSON file of the repository, decoded.
	 *
	 * @param string $file Its path in the repository.
	 * @return array<mixed>
	 */
	private static function json( string $file ): array {
		$data = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file ), true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $data );
		return $data;
	}
}
