<?php
/**
 * The plugin's readmes.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The plugin's page in WordPress.org's directory (readme.txt) and README.md: the plugin is
 * distributed only through that directory and installs from it; its page lists each external
 * service with its terms of use and privacy policy, and links its source, as the directory's
 * guidelines ask.
 */
final class ReadmeTest extends TestCase {

	public function test_the_plugin_installs_from_wordpress_org_s_directory(): void {
		$installation = self::section( self::read( 'readme.txt' ), '/^== Installation ==$/m', '/^== /m' );
		self::assertStringContainsString( '**Plugins → Add Plugin**, search for **sattle.me**', $installation );
		self::assertStringNotContainsString( 'Upload Plugin', $installation );

		$readme = self::read( 'README.md' );
		$guide  = self::section( $readme, '/^## Installation$/m', '/^## /m' );
		self::assertStringContainsString( "From WordPress.org's plugin directory", $guide );
		self::assertStringContainsString( 'wp plugin install sattleme-for-woocommerce --activate', $guide );

		$releases = self::section( $readme, '/^## Releases$/m', '/^## /m' );
		self::assertStringContainsString( 'no GitHub release', $releases );
		self::assertStringContainsString( "workflow run's artifact", $releases );
		self::assertDoesNotMatchRegularExpression( '#github\.com/[^/\s]+/[^/\s]+/releases#', $readme );
	}

	public function test_each_external_service_is_listed_with_its_terms_and_privacy_policy(): void {
		$readme   = self::read( 'readme.txt' );
		$services = self::section( $readme, '/^== External services ==$/m', '/^== /m', false );
		$words    = (string) preg_replace( '/\s+/', ' ', $services );
		self::assertStringContainsString( '**sattle.me** (https://sattle.me)', $words );
		self::assertStringContainsString( "**The merchant's Lightning address** (on breez.tips", $words );

		// Each host the SDK asks, wherever it names one: a rate source or an explorer.
		$hosts = array();
		foreach ( self::files( dirname( __DIR__, 2 ) . '/sdk/src' ) as $file ) {
			preg_match_all( '#[\'"]https://([a-z0-9.-]+)#', self::read( $file ), $found );
			$hosts = array_merge( $hosts, $found[1] );
		}
		$hosts = array_unique( $hosts );
		sort( $hosts );
		self::assertSame( array( 'api.blockcypher.com', 'api.coinbase.com', 'api.coingecko.com', 'api.kraken.com', 'api.opennode.com', 'mempool.space' ), $hosts );

		// Its service, its site, and links to its terms of use and its privacy policy.
		$items = preg_split( '/^\s*\* /m', $services );
		self::assertIsArray( $items );
		$items = array_map( static fn ( string $item ): string => (string) preg_replace( '/\s+/', ' ', $item ), $items );
		foreach ( $hosts as $host ) {
			$site   = 'https://' . preg_replace( '/^api\./', '', $host );
			$listed = array_values( array_filter( $items, static fn ( string $item ): bool => 1 === preg_match( '#\((' . preg_quote( $site, '#' ) . '|https://www\.' . preg_quote( substr( $site, 8 ), '#' ) . ')\)#', $item ) ) );
			self::assertNotSame( array(), $listed, $host );
			$linked = implode( ' ', $listed );
			self::assertMatchesRegularExpression( '#\bterms https://\S+#', $linked, $host . ': its terms of use' );
			self::assertMatchesRegularExpression( '#\bprivacy (policy|notice) https://\S+#', $linked, $host . ': its privacy policy' );
		}
		self::assertStringNotContainsString( 'are on their sites', $words );
	}

	public function test_the_source_and_how_its_dependencies_are_built_are_linked(): void {
		$source = self::section( self::read( 'readme.txt' ), '/^== Source code ==$/m', '/^== /m' );

		self::assertStringContainsString( 'https://github.com/sattleme/checkout-woocommerce', $source );
		self::assertStringContainsString( 'https://github.com/sattleme/checkout-php', $source );
		self::assertStringContainsString( 'The `vendor-prefixed/` folder holds its dependencies', $source );
		self::assertStringContainsString( '`tools/build.sh` installs them with Composer from the lockfile', $source );
		self::assertStringContainsString( 'Strauss 0.31.1', $source );
		self::assertStringContainsString( "\nVERSION=0.31.1\n", self::read( 'tools/strauss.sh' ) );
	}

	public function test_the_readme_is_within_wordpress_org_s_limit(): void {
		// WordPress.org's directory reads at most 10 KB of a readme.
		self::assertLessThan( 10 * 1024, strlen( self::read( 'readme.txt' ) ) );
	}

	/**
	 * The PHP files under $directory.
	 *
	 * @param string $directory A folder.
	 * @return list<string> Their paths, from the repository.
	 */
	private static function files( string $directory ): array {
		$files = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = substr( $file->getPathname(), strlen( dirname( __DIR__, 2 ) ) + 1 );
			}
		}
		self::assertNotSame( array(), $files );

		return $files;
	}

	/**
	 * The part of $text from the line $start matches to the next line $end matches, its spaces
	 * and line breaks as single spaces unless $words is false.
	 *
	 * @param string $text  The text.
	 * @param string $start Where the section starts (a pattern, multiline).
	 * @param string $end   Where the next one starts (a pattern, multiline).
	 * @param bool   $words False for its lines as they are.
	 */
	private static function section( string $text, string $start, string $end, bool $words = true ): string {
		self::assertSame( 1, preg_match( $start, $text, $found, PREG_OFFSET_CAPTURE ), $start );
		$from = $found[0][1] + strlen( $found[0][0] );
		$to   = 1 === preg_match( $end, $text, $next, PREG_OFFSET_CAPTURE, $from ) ? $next[0][1] : strlen( $text );

		$section = substr( $text, $from, $to - $from );

		// Its words, whatever their line breaks.
		return $words ? (string) preg_replace( '/\s+/', ' ', $section ) : $section;
	}

	/**
	 * A file of the repository.
	 *
	 * @param string $name Its path.
	 */
	private static function read( string $name ): string {
		$text = file_get_contents( dirname( __DIR__, 2 ) . '/' . $name );
		self::assertIsString( $text, $name );

		return $text;
	}
}
