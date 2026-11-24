<?php
/**
 * The plugin's archive (tools/build.sh): made reproducibly, and checked.
 *
 * Usage:
 *   php tools/package.php zip <directory> <archive> <Unix time>
 *       Zips the folders of <directory>: files and folders sorted, all dated <Unix time>, files
 *       0644 and folders 0755, deflated.
 *   php tools/package.php check <archive> <version>
 *       Fails unless the archive is the plugin's alone: one folder sattleme-for-woocommerce/,
 *       the allowlisted files only, the prefixed dependencies present with the plugin's
 *       composer.json and composer.lock (theirs left out), the version the same in the main
 *       file and the readme, every PHP file parsing.
 *
 * @package Sattleme\WooCommerce
 */

declare(strict_types=1);

namespace Sattleme\WooCommerce\Tools;

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

/**
 * Makes and checks the archive.
 */
final class Package {

	/** The folder WordPress installs the plugin in. */
	public const SLUG = 'sattleme-for-woocommerce';

	/** What the archive may hold, under its folder: files, or folders ending in "/". */
	public const ALLOWED = array(
		'sattleme-for-woocommerce.php',
		'uninstall.php',
		'readme.txt',
		'LICENSE',
		'wpml-config.xml',
		'composer.json',
		'composer.lock',
		'src/',
		'templates/',
		'assets/',
		'languages/',
		'vendor-prefixed/',
	);

	/** What it must hold. */
	public const REQUIRED = array(
		'sattleme-for-woocommerce.php',
		'uninstall.php',
		'readme.txt',
		'LICENSE',
		'composer.json',
		'composer.lock',
		'src/autoload.php',
		'vendor-prefixed/autoload.php',
		'vendor-prefixed/sattleme/checkout/src/Client.php',
		'vendor-prefixed/bacon/bacon-qr-code/src/Encoder/Encoder.php',
		'assets/js/payment-page.js',
		'assets/js/blocks.js',
		'languages/sattleme-for-woocommerce-fr_FR.mo',
	);

	/**
	 * What it never holds, wherever it is: Composer's manifests only at its root, the plugin's
	 * (WordPress.org's reviewers read them to know where the dependencies come from).
	 */
	public const FORBIDDEN = '#(^|/)(\.[^/]+|tests?|tools|node_modules|phpunit[^/]*|phpcs[^/]*|phpstan[^/]*|PIN\.json|.*\.md)(/|$)|/composer\.(json|lock)$#i';

	/**
	 * Zips the folders of $directory into $archive, every entry dated $time.
	 *
	 * @param string $directory The folder holding the plugin's folder.
	 * @param string $archive   The archive.
	 * @param int    $time      The date of every entry.
	 * @throws \RuntimeException When the archive cannot be written.
	 */
	public static function zip( string $directory, string $archive, int $time ): void {
		$entries = array();
		$files   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $files as $file ) {
			if ( $file instanceof \SplFileInfo ) {
				$name             = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $directory ) + 1 ) );
				$entries[ $name ] = $file->isDir();
			}
		}
		ksort( $entries, SORT_STRING );
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( "Cannot write {$archive}" );
		}
		foreach ( $entries as $name => $is_dir ) {
			if ( $is_dir ) {
				$zip->addEmptyDir( $name );
				$entry = $name . '/';
				$mode  = 040755;
			} else {
				$zip->addFile( $directory . '/' . $name, $name );
				$zip->setCompressionName( $name, \ZipArchive::CM_DEFLATE, 9 );
				$entry = $name;
				$mode  = 0100644;
			}
			$zip->setMtimeName( $entry, $time );
			$zip->setExternalAttributesName( $entry, \ZipArchive::OPSYS_UNIX, $mode << 16 );
		}
		if ( ! $zip->close() ) {
			throw new \RuntimeException( "Cannot write {$archive}" );
		}
	}

	/**
	 * What is wrong with $archive: empty when it is the plugin's alone, at $version.
	 *
	 * @param string $archive The archive.
	 * @param string $version The version.
	 * @return list<string>
	 */
	public static function problems( string $archive, string $version ): array {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $archive, \ZipArchive::RDONLY ) ) {
			return array( "{$archive} is not a ZIP archive" );
		}
		$problems = array();
		$names    = array();
		for ( $i = 0, $count = $zip->count(); $i < $count; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( str_ends_with( $name, '/' ) ) {
				continue;
			}
			if ( ! str_starts_with( $name, self::SLUG . '/' ) ) {
				$problems[] = "{$name} is outside " . self::SLUG . '/';
				continue;
			}
			$relative = substr( $name, strlen( self::SLUG ) + 1 );
			$names[]  = $relative;
			$allowed  = false;
			foreach ( self::ALLOWED as $prefix ) {
				$allowed = $allowed || $relative === $prefix || ( str_ends_with( $prefix, '/' ) && str_starts_with( $relative, $prefix ) );
			}
			if ( ! $allowed || 1 === preg_match( self::FORBIDDEN, $relative ) ) {
				$problems[] = "{$relative} is not the plugin's";
			}
			if ( str_ends_with( $relative, '.php' ) ) {
				$problem = self::lint( (string) $zip->getFromIndex( $i ), $relative );
				if ( null !== $problem ) {
					$problems[] = $problem;
				}
			}
		}
		foreach ( self::REQUIRED as $required ) {
			if ( ! in_array( $required, $names, true ) ) {
				$problems[] = "{$required} is missing";
			}
		}
		$main   = (string) $zip->getFromName( self::SLUG . '/sattleme-for-woocommerce.php' );
		$readme = (string) $zip->getFromName( self::SLUG . '/readme.txt' );
		if ( 1 !== preg_match( '/^ \* Version: ' . preg_quote( $version, '/' ) . '$/m', $main ) || ! str_contains( $main, "define( 'SATTLEME_FOR_WOOCOMMERCE_VERSION', '{$version}' );" ) ) {
			$problems[] = "The main file is not version {$version}";
		}
		if ( 1 !== preg_match( '/^Stable tag: ' . preg_quote( $version, '/' ) . '$/m', $readme ) ) {
			$problems[] = "readme.txt's stable tag is not {$version}";
		}
		$zip->close();

		return $problems;
	}

	/**
	 * Whether $code parses (php -l): null when it does.
	 *
	 * @param string $code The file's code.
	 * @param string $name Its name.
	 */
	private static function lint( string $code, string $name ): ?string {
		$process = proc_open(
			array( PHP_BINARY, '-l' ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			return "{$name}: cannot run php -l";
		}
		fwrite( $pipes[0], $code );
		fclose( $pipes[0] );
		$output = (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return 0 === proc_close( $process ) ? null : "{$name} does not parse: " . trim( $output );
	}
}

$command = $argv[1] ?? '';
if ( 'zip' === $command && 5 === $argc ) {
	Package::zip( rtrim( $argv[2], '/' ), $argv[3], (int) $argv[4] );
	exit( 0 );
}
if ( 'check' === $command && 4 === $argc ) {
	$problems = Package::problems( $argv[2], $argv[3] );
	foreach ( $problems as $problem ) {
		fwrite( STDERR, $problem . "\n" );
	}
	exit( array() === $problems ? 0 : 1 );
}
fwrite( STDERR, "Usage: php tools/package.php zip <directory> <archive> <Unix time> | check <archive> <version>\n" );
exit( 2 );
