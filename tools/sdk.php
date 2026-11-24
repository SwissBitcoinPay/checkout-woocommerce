<?php
/**
 * The pinned copy of the PHP SDK (sattleme/checkout) in sdk/: the files its package builds from,
 * unchanged, and sdk/PIN.json saying which commit of https://github.com/sattleme/checkout-php
 * they come from, with the SHA-256 of each.
 *
 * Commands:
 *
 *   php tools/sdk.php check
 *       Whether sdk/ is PIN.json's, file for file (tests/Unit/SdkPinTest.php runs it too).
 *   php tools/sdk.php import <checkout-php clone> [<commit>]
 *       Replaces sdk/ with the SDK's files at <commit> (by default the clone's HEAD), as git
 *       holds them at that commit (never the clone's working tree), writes PIN.json, sets the
 *       SDK's version in composer.json, then has Composer lock it (composer update
 *       sattleme/checkout), install it again and prefix it (composer reinstall
 *       sattleme/checkout, and its post-install-cmd); COMPOSER_BIN names Composer, else
 *       "composer".
 *       The files are those of the SDK's package at that commit (what `git archive` gives, its
 *       .gitattributes's export-ignore applied: what Composer installs), but its Markdown
 *       documentation: today composer.json, LICENSE and src/; and the test vectors of
 *       Sdk_Pin::VECTORS, which its package does not hold.
 *
 * Run it with the PHP CLI, from anywhere: it never loads WordPress.
 *
 * @package Sattleme\WooCommerce
 */

declare(strict_types=1);

namespace Sattleme\WooCommerce\Tools;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

/**
 * The pinned copy's repository and paths.
 */
final class Sdk_Pin {

	public const REPOSITORY = 'https://github.com/sattleme/checkout-php';

	public const PIN = 'PIN.json';

	/** The SDK's package, as its composer.json names it. */
	public const PACKAGE = 'sattleme/checkout';

	/**
	 * Files of the SDK's repository that its package does not hold, copied with it for the
	 * plugin's tests: the specification's prices (SPEC.md §25 and §4's currencyDigits, which
	 * tests/Unit/PricesVectorsTest.php runs through Prices), and the pin of the specification
	 * they come from. Composer installs them with the package; Strauss leaves them out of the
	 * prefixed copy (composer.json), so the plugin's ZIP never holds them.
	 */
	public const VECTORS = array(
		'spec/PIN.json',
		'spec/vectors/fiat.json',
	);

	/**
	 * What differs between $copy and its PIN.json: empty when the copy is the pinned one.
	 *
	 * @param string $copy The pinned copy (sdk/).
	 * @return list<string>
	 */
	public static function problems( string $copy ): array {
		$pin_path = $copy . '/' . self::PIN;
		if ( ! is_file( $pin_path ) ) {
			return array( self::PIN . ' is missing' );
		}
		$pin = json_decode( (string) file_get_contents( $pin_path ), true );
		if ( ! is_array( $pin ) ) {
			return array( self::PIN . ' is not JSON' );
		}
		$problems = array();
		if ( array( 'repository', 'commit', 'version', 'files' ) !== array_keys( $pin ) ) {
			$problems[] = self::PIN . ': its members are repository, commit, version and files, in that order';
		}
		if ( ( $pin['repository'] ?? null ) !== self::REPOSITORY ) {
			$problems[] = self::PIN . ': repository is not ' . self::REPOSITORY;
		}
		if ( ! is_string( $pin['commit'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{40}$/D', $pin['commit'] ) ) {
			$problems[] = self::PIN . ': commit is not a commit';
		}
		if ( ! is_string( $pin['version'] ?? null ) || 1 !== preg_match( '/^\d+\.\d+\.\d+$/D', $pin['version'] ) ) {
			$problems[] = self::PIN . ': version is not a version';
		}
		$files = $pin['files'] ?? null;
		if ( ! is_array( $files ) || array() === $files ) {
			return array_merge( $problems, array( self::PIN . ': files is empty' ) );
		}
		foreach ( $files as $file => $hash ) {
			$path = $copy . '/' . $file;
			if ( ! is_file( $path ) ) {
				$problems[] = "{$file} is missing";
			} elseif ( hash_file( 'sha256', $path ) !== $hash ) {
				$problems[] = "{$file} differs from " . self::PIN;
			}
		}
		foreach ( self::files_under( $copy ) as $file ) {
			if ( self::PIN !== $file && ! array_key_exists( $file, $files ) ) {
				$problems[] = "{$file} is not in " . self::PIN;
			}
		}
		$listed = array_keys( $files );
		$sorted = $listed;
		sort( $sorted, SORT_STRING );
		if ( $listed !== $sorted ) {
			$problems[] = self::PIN . ': files are not sorted';
		}

		return $problems;
	}

	/**
	 * Replaces $copy with the SDK's files at $commit of the clone $source (by default its HEAD),
	 * its package's and VECTORS, as git holds them (never the working tree), and writes PIN.json.
	 *
	 * @param string      $source A clone of checkout-php.
	 * @param string      $copy   The pinned copy (sdk/).
	 * @param string|null $commit The commit (a hash, a tag, a branch), null for HEAD.
	 * @return array{repository: string, commit: string, version: string, files: array<array-key, string>}
	 * @throws \RuntimeException When the commit is not the SDK's, has no file of VECTORS, or a file cannot be written.
	 */
	public static function import( string $source, string $copy, ?string $commit = null ): array {
		$asked = $commit ?? 'HEAD';
		if ( '' === $asked || str_starts_with( $asked, '-' ) ) {
			throw new \RuntimeException( "Not a commit: {$asked}" );
		}
		$commit = self::git( $source, array( 'rev-parse', '--verify', '--quiet', $asked . '^{commit}' ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/D', $commit ) ) {
			throw new \RuntimeException( "Not a commit of {$source}: {$asked}" );
		}
		$manifest = json_decode( self::git( $source, array( 'show', "{$commit}:composer.json" ) ), true );
		if ( ! is_array( $manifest ) || self::PACKAGE !== ( $manifest['name'] ?? null ) ) {
			throw new \RuntimeException( "{$source} at {$commit} is not " . self::PACKAGE );
		}
		// The version the SDK declares (Client::VERSION): its composer.json has none.
		$client = self::git( $source, array( 'show', "{$commit}:src/Client.php" ) );
		if ( 1 !== preg_match( "/public const VERSION = '(\\d+\\.\\d+\\.\\d+)';/", $client, $match ) ) {
			throw new \RuntimeException( 'No Client::VERSION in src/Client.php' );
		}
		$wanted = array();
		foreach ( self::archived( $source, $commit ) as $file ) {
			// Its documentation is no file the package builds from.
			if ( 1 !== preg_match( '#^[^/]+\.md$#Di', $file ) ) {
				$wanted[] = $file;
			}
		}
		if ( ! in_array( 'composer.json', $wanted, true ) || ! in_array( 'src/Client.php', $wanted, true ) ) {
			throw new \RuntimeException( "The package of {$commit} has no composer.json or no src/Client.php" );
		}
		$wanted = array_values( array_unique( array_merge( $wanted, self::VECTORS ) ) );
		sort( $wanted, SORT_STRING );
		// Every blob as committed (never the working tree, never a line ending converted), read
		// before the copy is replaced: a commit without one of them leaves the copy as it was.
		$contents = array();
		foreach ( $wanted as $file ) {
			try {
				$contents[ $file ] = self::git( $source, array( 'cat-file', 'blob', "{$commit}:{$file}" ), false );
			} catch ( \RuntimeException $e ) {
				throw new \RuntimeException( "{$commit} has no {$file}", 0, $e );
			}
		}
		self::remove( $copy );
		$files = array();
		foreach ( $contents as $file => $content ) {
			$target = $copy . '/' . $file;
			if ( ! is_dir( dirname( $target ) ) && ! mkdir( dirname( $target ), 0777, true ) ) {
				throw new \RuntimeException( "Cannot create the directory of {$target}" );
			}
			if ( false === file_put_contents( $target, $content ) ) {
				throw new \RuntimeException( "Cannot write {$target}" );
			}
			$files[ $file ] = hash( 'sha256', $content );
		}
		$pin = array(
			'repository' => self::REPOSITORY,
			'commit'     => $commit,
			'version'    => $match[1],
			'files'      => $files,
		);
		if ( false === file_put_contents( $copy . '/' . self::PIN, json_encode( $pin, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" ) ) {
			throw new \RuntimeException( 'Cannot write ' . self::PIN );
		}

		return $pin;
	}

	/**
	 * Sets the SDK's version in the plugin's composer.json $manifest: the version its path
	 * repository gives sdk/, and the exact version the plugin requires. Whether it changed.
	 *
	 * @param string $manifest The plugin's composer.json.
	 * @param string $version  The SDK's version.
	 * @throws \RuntimeException When the manifest does not name the SDK twice, as expected.
	 */
	public static function set_version( string $manifest, string $version ): bool {
		$json = (string) file_get_contents( $manifest );
		$new  = (string) preg_replace( '#("' . preg_quote( self::PACKAGE, '#' ) . '": ")\d+\.\d+\.\d+(")#', '${1}' . $version . '${2}', $json, -1, $count );
		if ( 2 !== $count || array( $version, $version ) !== self::versions_in( $new ) ) {
			throw new \RuntimeException( "{$manifest} does not require " . self::PACKAGE . ' from its path repository sdk/ at an exact version' );
		}
		if ( $new === $json ) {
			return false;
		}
		if ( false === file_put_contents( $manifest, $new ) ) {
			throw new \RuntimeException( "Cannot write {$manifest}" );
		}

		return true;
	}

	/**
	 * The SDK's versions in the plugin's composer.json $json: the one it requires, and the one
	 * its path repository (sdk/) gives the package. Nulls where it names none.
	 *
	 * @param string $json The plugin's composer.json.
	 * @return array{0: mixed, 1: mixed}
	 */
	private static function versions_in( string $json ): array {
		$manifest   = json_decode( $json, true );
		$require    = is_array( $manifest ) && is_array( $manifest['require'] ?? null ) ? $manifest['require'] : array();
		$repository = is_array( $manifest ) && is_array( $manifest['repositories'] ?? null ) && is_array( $manifest['repositories'][0] ?? null ) ? $manifest['repositories'][0] : array();
		$options    = is_array( $repository['options'] ?? null ) ? $repository['options'] : array();
		$versions   = is_array( $options['versions'] ?? null ) ? $options['versions'] : array();
		$from_sdk   = 'sdk' === ( $repository['url'] ?? null );

		return array( $require[ self::PACKAGE ] ?? null, $from_sdk ? ( $versions[ self::PACKAGE ] ?? null ) : null );
	}

	/**
	 * The files of the SDK's package at $commit: those `git archive` gives (export-ignore
	 * applied, from the commit's own .gitattributes), sorted.
	 *
	 * @param string $source A clone of checkout-php.
	 * @param string $commit The commit.
	 * @return list<string>
	 * @throws \RuntimeException When the archive holds what is not a file nor a directory.
	 */
	private static function archived( string $source, string $commit ): array {
		$tar    = self::git( $source, array( 'archive', '--format=tar', $commit ), false );
		$length = strlen( $tar );
		$files  = array();
		$offset = 0;
		$path   = null;
		while ( $offset + 512 <= $length ) {
			$header = substr( $tar, $offset, 512 );
			if ( '' === trim( $header, "\0" ) ) {
				break; // The end of the archive.
			}
			$size    = (int) octdec( trim( substr( $header, 124, 12 ), "\0 " ) );
			$type    = $header[156];
			$data    = substr( $tar, $offset + 512, $size );
			$offset += 512 + (int) ( ceil( $size / 512 ) * 512 );
			if ( 'g' === $type ) {
				continue; // git's global header: the commit's id.
			}
			if ( 'x' === $type ) {
				// A pax header: the path of the next entry, when it is too long for its own.
				$path = 1 === preg_match( '/^\d+ path=(.*)$/m', $data, $long ) ? $long[1] : null;
				continue;
			}
			$prefix = rtrim( substr( $header, 345, 155 ), "\0" );
			$name   = rtrim( substr( $header, 0, 100 ), "\0" );
			$name   = $path ?? ( '' === $prefix ? $name : $prefix . '/' . $name );
			$path   = null;
			if ( '0' === $type || "\0" === $type ) {
				$files[] = $name;
			} elseif ( '5' !== $type ) {
				throw new \RuntimeException( "{$name} is not a file nor a directory" );
			}
		}
		sort( $files, SORT_STRING );

		return $files;
	}

	/**
	 * Every file under $dir, as paths relative to it with "/", sorted.
	 *
	 * @param string $dir The directory.
	 * @return list<string>
	 */
	public static function files_under( string $dir ): array {
		$found = array();
		if ( ! is_dir( $dir ) ) {
			return $found;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && $file->isFile() ) {
				$found[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) + 1 ) );
			}
		}
		sort( $found, SORT_STRING );

		return $found;
	}

	/**
	 * Runs git in $repository: its output, trimmed unless $trim is false.
	 *
	 * @param string             $repository The repository.
	 * @param array<int, string> $args       Its arguments.
	 * @param bool               $trim       Whether to trim the output.
	 * @return string
	 * @throws \RuntimeException When git fails.
	 */
	private static function git( string $repository, array $args, bool $trim = true ): string {
		$command = array_merge( array( 'git', '-C', $repository ), $args );
		$process = proc_open(
			$command,
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
			throw new \RuntimeException( 'git ' . implode( ' ', $args ) . ' failed: ' . trim( $error ) );
		}

		return $trim ? trim( $output ) : $output;
	}

	/**
	 * Removes $dir and everything under it.
	 *
	 * @param string $dir The directory.
	 */
	private static function remove( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
		}
		rmdir( $dir );
	}
}

// Loaded by the tests for its class alone.
if ( isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$root    = dirname( __DIR__ );
	$copy    = $root . '/sdk';
	$command = $argv[1] ?? '';
	if ( 'check' === $command && 2 === $argc ) {
		$problems = Sdk_Pin::problems( $copy );
		foreach ( $problems as $problem ) {
			fwrite( STDERR, $problem . "\n" );
		}
		if ( array() === $problems ) {
			echo "sdk/ is PIN.json's\n";
		}
		exit( array() === $problems ? 0 : 1 );
	}
	if ( 'import' === $command && ( 3 === $argc || 4 === $argc ) ) {
		try {
			$pin = Sdk_Pin::import( $argv[2], $copy, $argv[3] ?? null );
			Sdk_Pin::set_version( $root . '/composer.json', $pin['version'] );
		} catch ( \RuntimeException $e ) {
			fwrite( STDERR, $e->getMessage() . "\n" );
			exit( 1 );
		}
		echo "sdk/ is sattleme/checkout {$pin['version']} at {$pin['commit']} (" . count( $pin['files'] ) . " files)\n";
		// The lock (the SDK's version and manifest), then the installed copy, from sdk/, and its
		// prefixed copy (the post-install-cmd). Composer copies a path package's files when it
		// installs it, never on an update that changes neither its version nor its manifest: an
		// SDK fixed at the same version would stay as it was installed, hence the reinstall.
		$composer = getenv( 'COMPOSER_BIN' );
		$composer = is_string( $composer ) && '' !== $composer ? $composer : 'composer';
		foreach ( array( array( 'update', Sdk_Pin::PACKAGE, '--no-scripts' ), array( 'reinstall', Sdk_Pin::PACKAGE ) ) as $args ) {
			$process = proc_open( array_merge( array( $composer ), $args, array( '--no-interaction', '--no-progress' ) ), array(), $pipes, $root );
			$exit    = is_resource( $process ) ? proc_close( $process ) : 1;
			if ( 0 !== $exit ) {
				fwrite( STDERR, 'composer ' . implode( ' ', $args ) . " failed: run it, then the checks\n" );
				exit( 1 );
			}
		}
		echo "composer.lock and vendor-prefixed/ hold it: now run the checks and the tests (README.md, Development)\n";
		exit( 0 );
	}
	fwrite( STDERR, "Usage: php tools/sdk.php check | import <checkout-php clone> [<commit>]\n" );
	exit( 2 );
}
