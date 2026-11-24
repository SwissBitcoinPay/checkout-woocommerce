<?php
/**
 * A redirect, caught.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration\Support;

/**
 * Thrown in place of a redirect (wp_redirect), which would exit.
 */
final class Redirected extends \Exception {

	/**
	 * A redirect.
	 *
	 * @param string $location Where to.
	 * @param int    $status   Its HTTP status.
	 */
	public function __construct( public readonly string $location, public readonly int $status ) {
		parent::__construct( "Redirected ({$status}) to {$location}" );
	}

	/**
	 * Has redirects thrown, until the test ends.
	 */
	public static function catch_them(): void {
		add_filter(
			'wp_redirect',
			static function ( string $location, int $status ): string {
				if ( '' === $location ) {
					return $location; // No location: WordPress redirects nowhere.
				}
				throw new self( $location, $status );
			},
			10,
			2
		);
	}
}
