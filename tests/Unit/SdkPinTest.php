<?php
/**
 * The pinned copy of the SDK.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Sattleme\WooCommerce\Tests\Support\Arrays;
use Sattleme\WooCommerce\Tools\Sdk_Pin;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The directory sdk/ is the SDK's files at the commit PIN.json names, file for file (tools/sdk.php
 * check), and the check finds any change; the plugin requires and locks that version; the import
 * takes the SDK's package and the vectors the plugin's tests run at any commit of a clone
 * (tools/sdk.php import).
 */
final class SdkPinTest extends TestCase {

	/**
	 * A copy of sdk/ the tests change.
	 *
	 * @var string
	 */
	private string $copy = '';

	/**
	 * The git repositories the tests made.
	 *
	 * @var list<string>
	 */
	private array $clones = array();

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once dirname( __DIR__, 2 ) . '/tools/sdk.php';
	}

	protected function tear_down(): void {
		foreach ( array_merge( array( $this->copy ), $this->clones ) as $dir ) {
			if ( '' !== $dir && is_dir( $dir ) ) {
				self::remove( $dir );
			}
		}
		parent::tear_down();
	}

	public function test_sdk_is_the_pinned_commit(): void {
		self::assertSame( array(), Sdk_Pin::problems( self::sdk() ) );
	}

	public function test_the_pin_names_the_sdk_s_repository_and_version(): void {
		$pin = self::pin( self::sdk() );

		self::assertSame( 'https://github.com/sattleme/checkout-php', $pin['repository'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{40}$/D', $pin['commit'] );
		self::assertSame( self::client_version( self::sdk() ), $pin['version'], 'the version the SDK declares' );
		self::assertArrayHasKey( 'composer.json', $pin['files'] );
		self::assertArrayHasKey( 'LICENSE', $pin['files'] );
		self::assertArrayHasKey( 'src/Client.php', $pin['files'] );
	}

	public function test_the_plugin_requires_and_locks_the_pinned_version(): void {
		$pin      = self::pin( self::sdk() );
		$manifest = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/composer.json' ), true );
		$lock     = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/composer.lock' ), true );
		$packages = Arrays::at( $lock, 'packages' );
		self::assertIsArray( $packages );

		self::assertSame( $pin['version'], Arrays::at( $manifest, 'require', 'sattleme/checkout' ), 'exactly the pinned version' );
		self::assertSame( 'sdk', Arrays::at( $manifest, 'repositories', 0, 'url' ) );
		self::assertSame( $pin['version'], Arrays::at( $manifest, 'repositories', 0, 'options', 'versions', 'sattleme/checkout' ) );
		$locked = array_values( array_filter( $packages, static fn ( mixed $package ): bool => 'sattleme/checkout' === Arrays::at( $package, 'name' ) ) );
		self::assertCount( 1, $locked );
		self::assertSame( $pin['version'], Arrays::at( $locked[0], 'version' ) );
		self::assertSame( 'path', Arrays::at( $locked[0], 'dist', 'type' ) );
		self::assertSame( 'sdk', Arrays::at( $locked[0], 'dist', 'url' ) );
	}

	public function test_an_import_takes_the_sdk_s_package_at_any_commit(): void {
		$clone = $this->clone_of_the_sdk();
		$first = self::git( $clone, 'rev-parse', 'HEAD' );
		// A later commit: a new version, a file gone, a resource of the package added, a path
		// too long for a tar header's name (in its prefix) and a name too long for any (in a pax
		// header).
		$long    = 'src/' . str_repeat( 'Deep/', 22 ) . 'Leaf.php';
		$longest = 'resources/' . str_repeat( 'r', 120 ) . '.json';
		self::write( $clone, 'src/Client.php', "<?php\nfinal class Client {\n\tpublic const VERSION = '0.2.0';\n}\n" );
		self::write( $clone, $long, "<?php\n" );
		self::write( $clone, 'resources/currencies.json', "{}\n" );
		self::write( $clone, $longest, "[]\n" );
		self::git( $clone, 'rm', '-q', 'src/Old.php' );
		self::git( $clone, 'add', '-A' );
		self::git( $clone, '-c', 'user.name=t', '-c', 'user.email=t@example.com', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'second' );
		$second = self::git( $clone, 'rev-parse', 'HEAD' );
		self::git( $clone, '-c', 'advice.detachedHead=false', 'checkout', '-q', $first );
		$copy = $this->copy();

		$pin = Sdk_Pin::import( $clone, $copy, $second );

		self::assertSame( $second, $pin['commit'] );
		self::assertSame( '0.2.0', $pin['version'] );
		self::assertSame( array( 'LICENSE', 'composer.json', 'resources/currencies.json', $longest, 'spec/PIN.json', 'spec/vectors/fiat.json', 'src/Client.php', $long ), array_keys( $pin['files'] ) );
		self::assertSame( array( 'LICENSE', 'PIN.json', 'composer.json', 'resources/currencies.json', $longest, 'spec/PIN.json', 'spec/vectors/fiat.json', 'src/Client.php', $long ), Sdk_Pin::files_under( $copy ) );
		self::assertSame( "[]\n", file_get_contents( $copy . '/' . $longest ) );
		self::assertSame( "{\"cases\": [], \"digits\": []}\n", file_get_contents( $copy . '/spec/vectors/fiat.json' ), 'the vectors, which the package does not hold' );
		self::assertSame( array(), Sdk_Pin::problems( $copy ) );
		self::assertSame( "<?php\nfinal class Client {\n\tpublic const VERSION = '0.2.0';\n}\n", file_get_contents( $copy . '/src/Client.php' ), 'the commit\'s, not the working tree\'s' );

		$pin = Sdk_Pin::import( $clone, $copy );

		self::assertSame( $first, $pin['commit'], 'by default, the clone\'s HEAD' );
		self::assertSame( '0.1.0', $pin['version'] );
		self::assertSame( array( 'LICENSE', 'PIN.json', 'composer.json', 'spec/PIN.json', 'spec/vectors/fiat.json', 'src/Client.php', 'src/Old.php' ), Sdk_Pin::files_under( $copy ), 'nothing of the other commit left, nothing else of spec/' );
		self::assertSame( "Copyright\r\n", file_get_contents( $copy . '/LICENSE' ), 'its bytes as committed' );
		self::assertSame( array(), Sdk_Pin::problems( $copy ) );
	}

	public function test_an_import_refuses_what_is_not_the_sdk(): void {
		$clone = $this->clone_of_the_sdk();
		$copy  = $this->copy();

		foreach ( array( 'no-such-commit', '--output=x', '' ) as $commit ) {
			try {
				Sdk_Pin::import( $clone, $copy, $commit );
				self::fail( "Imported {$commit}" );
			} catch ( \RuntimeException $e ) {
				self::assertNotSame( '', $e->getMessage() );
			}
		}
		self::write( $clone, 'composer.json', "{\"name\": \"someone/else\"}\n" );
		self::git( $clone, '-c', 'user.name=t', '-c', 'user.email=t@example.com', '-c', 'commit.gpgsign=false', 'commit', '-q', '-am', 'another package' );
		try {
			Sdk_Pin::import( $clone, $copy );
			self::fail( 'Imported another package' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'is not sattleme/checkout', $e->getMessage() );
		}
		self::assertSame( array(), Sdk_Pin::problems( $copy ), 'the copy as it was' );
	}

	public function test_an_import_refuses_a_commit_without_the_vectors(): void {
		$clone = $this->clone_of_the_sdk();
		$copy  = $this->copy();
		self::git( $clone, 'rm', '-q', 'spec/vectors/fiat.json' );
		self::git( $clone, '-c', 'user.name=t', '-c', 'user.email=t@example.com', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'no vectors' );

		try {
			Sdk_Pin::import( $clone, $copy );
			self::fail( 'Imported a commit without the vectors' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'has no spec/vectors/fiat.json', $e->getMessage() );
		}
		self::assertSame( array(), Sdk_Pin::problems( $copy ), 'the copy as it was' );
	}

	public function test_the_version_is_set_where_the_plugin_requires_it(): void {
		$this->copy = sys_get_temp_dir() . '/sattleme-manifest-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->copy );
		$manifest = $this->copy . '/composer.json';
		copy( dirname( __DIR__, 2 ) . '/composer.json', $manifest );
		$before = (string) file_get_contents( $manifest );

		self::assertTrue( Sdk_Pin::set_version( $manifest, '0.2.0' ) );
		self::assertFalse( Sdk_Pin::set_version( $manifest, '0.2.0' ) );

		$after = json_decode( (string) file_get_contents( $manifest ), true );
		self::assertSame( '0.2.0', Arrays::at( $after, 'require', 'sattleme/checkout' ) );
		self::assertSame( '0.2.0', Arrays::at( $after, 'repositories', 0, 'options', 'versions', 'sattleme/checkout' ) );
		self::assertSame( str_replace( '"sattleme/checkout": "' . self::pin( self::sdk() )['version'] . '"', '"sattleme/checkout": "0.2.0"', $before ), file_get_contents( $manifest ), 'nothing else changed' );
	}

	public function test_a_changed_file_is_found(): void {
		$copy = $this->copy();
		file_put_contents( $copy . '/src/Client.php', "\n", FILE_APPEND );

		self::assertSame( array( 'src/Client.php differs from PIN.json' ), Sdk_Pin::problems( $copy ) );
	}

	public function test_a_missing_file_is_found(): void {
		$copy = $this->copy();
		unlink( $copy . '/LICENSE' );

		self::assertSame( array( 'LICENSE is missing' ), Sdk_Pin::problems( $copy ) );
	}

	public function test_a_file_added_is_found(): void {
		$copy = $this->copy();
		file_put_contents( $copy . '/src/Extra.php', "<?php\n" );

		self::assertSame( array( 'src/Extra.php is not in PIN.json' ), Sdk_Pin::problems( $copy ) );
	}

	public function test_a_pin_changed_by_hand_is_found(): void {
		$copy = $this->copy();
		$pin  = json_decode( (string) file_get_contents( $copy . '/PIN.json' ), true );
		self::assertIsArray( $pin );
		$pin['repository'] = 'https://example.com/checkout-php';
		$pin['commit']     = 'main';
		file_put_contents( $copy . '/PIN.json', json_encode( $pin ) );

		$problems = Sdk_Pin::problems( $copy );

		self::assertContains( 'PIN.json: repository is not https://github.com/sattleme/checkout-php', $problems );
		self::assertContains( 'PIN.json: commit is not a commit', $problems );
	}

	/**
	 * The PIN.json of $copy.
	 *
	 * @param string $copy A pinned copy.
	 * @return array{repository: string, commit: string, version: string, files: array<string, string>}
	 * @throws \UnexpectedValueException When it is not a PIN.json.
	 */
	private static function pin( string $copy ): array {
		$pin        = json_decode( (string) file_get_contents( $copy . '/PIN.json' ), true );
		$repository = Arrays::at( $pin, 'repository' );
		$commit     = Arrays::at( $pin, 'commit' );
		$version    = Arrays::at( $pin, 'version' );
		$listed     = Arrays::at( $pin, 'files' );
		if ( ! is_string( $repository ) || ! is_string( $commit ) || ! is_string( $version ) || ! is_array( $listed ) ) {
			throw new \UnexpectedValueException( 'Not a PIN.json' );
		}
		$files = array();
		foreach ( $listed as $file => $sha256 ) {
			if ( ! is_string( $file ) || ! is_string( $sha256 ) ) {
				throw new \UnexpectedValueException( 'Not a PIN.json' );
			}
			$files[ $file ] = $sha256;
		}

		return array(
			'repository' => $repository,
			'commit'     => $commit,
			'version'    => $version,
			'files'      => $files,
		);
	}

	/**
	 * The version $copy's Client declares.
	 *
	 * @param string $copy A pinned copy.
	 */
	private static function client_version( string $copy ): string {
		$found = preg_match( "/public const VERSION = '([0-9.]+)';/", (string) file_get_contents( $copy . '/src/Client.php' ), $match );

		return 1 === $found ? $match[1] : '';
	}

	/**
	 * A git repository shaped as checkout-php, at version 0.1.0: its package's files, its
	 * documentation, and what its .gitattributes keeps out of its package (its tests, its pinned
	 * specification with the vectors the plugin takes).
	 */
	private function clone_of_the_sdk(): string {
		$clone          = sys_get_temp_dir() . '/sattleme-clone-' . bin2hex( random_bytes( 6 ) );
		$this->clones[] = $clone;
		mkdir( $clone );
		self::git( $clone, 'init', '-q' );
		self::write( $clone, 'composer.json', "{\"name\": \"sattleme/checkout\"}\n" );
		self::write( $clone, 'LICENSE', "Copyright\r\n" );
		self::write( $clone, 'README.md', "# The SDK\n" );
		self::write( $clone, 'CHANGELOG.md', "## 0.1.0\n" );
		self::write( $clone, 'src/Client.php', "<?php\nfinal class Client {\n\tpublic const VERSION = '0.1.0';\n}\n" );
		self::write( $clone, 'src/Old.php', "<?php\n" );
		self::write( $clone, 'tests/ClientTest.php', "<?php\n" );
		self::write( $clone, 'spec/SPEC.md', "# Spec\n" );
		self::write( $clone, 'spec/PIN.json', "{}\n" );
		self::write( $clone, 'spec/vectors/fiat.json', "{\"cases\": [], \"digits\": []}\n" );
		self::write( $clone, '.gitattributes', "* -text\n/tests export-ignore\n/spec export-ignore\n/.gitattributes export-ignore\n" );
		self::git( $clone, 'add', '-A' );
		self::git( $clone, '-c', 'user.name=t', '-c', 'user.email=t@example.com', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'first' );

		return $clone;
	}

	/**
	 * Writes $content to $file of $dir.
	 *
	 * @param string $dir     The directory.
	 * @param string $file    The file, in it.
	 * @param string $content Its content.
	 */
	private static function write( string $dir, string $file, string $content ): void {
		if ( ! is_dir( dirname( $dir . '/' . $file ) ) ) {
			mkdir( dirname( $dir . '/' . $file ), 0777, true );
		}
		file_put_contents( $dir . '/' . $file, $content );
	}

	/**
	 * Runs git in $dir: its output, trimmed.
	 *
	 * @param string $dir     The repository.
	 * @param string ...$args Its arguments.
	 * @throws \RuntimeException When git fails.
	 */
	private static function git( string $dir, string ...$args ): string {
		$process = proc_open(
			array( 'git', '-C', $dir, ...array_values( $args ) ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Cannot run git' );
		}
		$output = (string) stream_get_contents( $pipes[1] );
		$error  = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		if ( 0 !== proc_close( $process ) ) {
			throw new \RuntimeException( 'git ' . implode( ' ', $args ) . ': ' . $error );
		}

		return trim( $output );
	}

	/**
	 * The repository's sdk/.
	 */
	private static function sdk(): string {
		return dirname( __DIR__, 2 ) . '/sdk';
	}

	/**
	 * A copy of sdk/ in a temporary directory.
	 */
	private function copy(): string {
		$this->copy = sys_get_temp_dir() . '/sattleme-sdk-' . bin2hex( random_bytes( 6 ) );
		foreach ( Sdk_Pin::files_under( self::sdk() ) as $file ) {
			$target = $this->copy . '/' . $file;
			if ( ! is_dir( dirname( $target ) ) ) {
				mkdir( dirname( $target ), 0777, true );
			}
			copy( self::sdk() . '/' . $file, $target );
		}

		return $this->copy;
	}

	/**
	 * Removes $dir.
	 *
	 * @param string $dir The directory.
	 */
	private static function remove( string $dir ): void {
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			if ( $file instanceof \SplFileInfo ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
		}
		rmdir( $dir );
	}
}
