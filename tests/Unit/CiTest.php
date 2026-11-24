<?php
/**
 * What the CI runs.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Unit;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The workflows and the tools they run (tools/**.sh): they download only from the hosts listed
 * here (the release's own hosts, never a mirror), each download tried again when it fails, a step fails when one of
 * its commands does, and the PHP version a workflow installs is never in PHP, the binary the
 * tools run.
 */
final class CiTest extends TestCase {

	/**
	 * The hosts the CI downloads from: each release's own (MariaDB's archive, static-php-cli's
	 * builds, WordPress.org, Composer's, GitHub's).
	 */
	private const REACHED = array(
		'archive.mariadb.org',
		'dl.static-php.dev',
		'downloads.wordpress.org',
		'getcomposer.org',
		'github.com',
		'wordpress.org',
	);

	public function test_the_ci_downloads_only_from_hosts_its_runners_reach(): void {
		$hosts = array();
		foreach ( self::lines() as $where => $line ) {
			if ( 1 !== preg_match( '/\bcurl\b|\bgit\b.*\bfetch\b|\bURL="|_URL: /', $line ) ) {
				continue;
			}
			preg_match_all( '#https://([A-Za-z0-9.-]+)#', $line, $found );
			foreach ( $found[1] as $host ) {
				$hosts[ $host ] = $where;
			}
		}

		self::assertNotSame( array(), $hosts );
		foreach ( $hosts as $host => $where ) {
			self::assertContains( $host, self::REACHED, $where );
		}
		self::assertArrayHasKey( 'archive.mariadb.org', $hosts, 'MariaDB, from its official archive' );
	}

	public function test_no_workflow_sets_php_which_the_tools_run_as_php(): void {
		$versions = 0;
		foreach ( self::lines() as $where => $line ) {
			if ( ! str_starts_with( $where, '.github/' ) ) {
				continue;
			}
			// tools/e2e/run.sh and docker.sh run "$PHP" (PHP="${PHP:-php}"): a version there is no PHP.
			self::assertDoesNotMatchRegularExpression( '/^\s*PHP:|\$\{\{ env\.PHP \}\}|\$PHP\b(?!_)/', $line, $where );
			$versions += substr_count( $line, 'PHP_VERSION' );
		}
		self::assertGreaterThan( 10, $versions );
	}

	public function test_every_download_is_tried_again_when_it_fails(): void {
		$downloads = 0;
		foreach ( self::lines() as $where => $line ) {
			// curl -o <file>: a download (not a check of a local server, -o /dev/null).
			if ( 1 === preg_match( '/\bcurl\b.* -o (?!\/dev\/null)/', $line ) ) {
				self::assertStringContainsString( ' --retry ', $line, $where );
				self::assertStringContainsString( ' --retry-all-errors ', $line, $where . ': a connection reset too' );
				++$downloads;
			}
		}
		self::assertGreaterThan( 8, $downloads );
	}

	public function test_no_step_hides_a_failing_command_in_what_it_writes_for_the_next(): void {
		$writes = 0;
		foreach ( self::lines() as $where => $line ) {
			if ( 1 === preg_match( '/>> "\$GITHUB_(ENV|PATH|OUTPUT)"/', $line ) ) {
				// echo "X=$(command)": the command's failure fails nothing, X is written empty.
				self::assertStringNotContainsString( '$(', $line, $where . ': assign first, then echo' );
				++$writes;
			}
		}
		self::assertGreaterThan( 5, $writes );
	}

	public function test_the_release_is_kept_as_the_workflow_s_artifact_never_a_github_release(): void {
		$release = array_filter( self::lines(), static fn ( string $where ): bool => str_starts_with( $where, '.github/workflows/release.yml:' ), ARRAY_FILTER_USE_KEY );
		$text    = implode( "\n", $release );

		// The plugin is distributed only through WordPress.org's directory: no release, no write.
		foreach ( $release as $where => $line ) {
			self::assertDoesNotMatchRegularExpression( '#/releases\b(?!/download/)|\bgh release\b|contents: write|environment:#', $line, $where );
		}
		self::assertMatchesRegularExpression( '/^  build:\n(?:    .*\n)*?    needs: ci$/m', $text, 'kept once the CI passed' );
		self::assertMatchesRegularExpression( '#- uses: actions/upload-artifact@[0-9a-f]{40} \# v[\d.]+\n\s+with:\n\s+name: sattleme-for-woocommerce-\$\{\{ github\.ref_name \}\}\n\s+path: \|\n\s+build/sattleme-for-woocommerce-\*\.zip\n\s+build/SHA256SUMS\n\s+if-no-files-found: error\n\s+retention-days: 90$#m', $text );
		self::assertStringNotContainsString( 'download-artifact', $text );
	}

	/**
	 * The lines of the workflows and the tools' shell scripts, but comments.
	 *
	 * @return array<string, string> Each line, by its file and number.
	 */
	private static function lines(): array {
		$root  = dirname( __DIR__, 2 );
		$lines = array();
		foreach ( array( '/.github/workflows/*.yml', '/tools/*.sh', '/tools/*/*.sh' ) as $pattern ) {
			$files = glob( $root . $pattern );
			self::assertIsArray( $files );
			foreach ( $files as $file ) {
				$content = file( $file, FILE_IGNORE_NEW_LINES );
				self::assertIsArray( $content, $file );
				foreach ( $content as $number => $line ) {
					if ( ! str_starts_with( ltrim( $line ), '#' ) ) {
						$lines[ substr( $file, strlen( $root ) + 1 ) . ':' . ( $number + 1 ) ] = $line;
					}
				}
			}
		}
		self::assertGreaterThan( 100, count( $lines ) );

		return $lines;
	}
}
