<?php
/**
 * The page's QR codes, read back.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration\Support;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Reads an SVG of Qr::svg() as a wallet's camera would: drawn as an image, then decoded by
 * another library than the one that made it (chillerlan/php-qrcode, a development dependency).
 */
final class QrReader {

	/** Pixels per module. */
	private const SCALE = 6;

	/**
	 * The text the QR code of $svg holds.
	 *
	 * @param string $svg The SVG.
	 * @throws \UnexpectedValueException When it is not a QR code of the plugin.
	 * @throws \RuntimeException When GD makes no image.
	 */
	public static function read( string $svg ): string {
		// As the page prints it: through wp_kses(), which writes attributes' names in lower case
		// (HTML's parser gives SVG's viewBox its case back) and "/>" as " />".
		if ( 1 !== preg_match( '/viewBox="0 0 (\d+) \1"/i', $svg, $size ) || 1 !== preg_match( '/<path fill="#000" d="([^"]*)"\s*\/>/', $svg, $path ) ) {
			throw new \UnexpectedValueException( 'Not a QR code of the plugin' );
		}
		$pixels = (int) $size[1] * self::SCALE;
		if ( $pixels < 1 ) {
			throw new \UnexpectedValueException( 'Not a QR code of the plugin' );
		}
		$image = imagecreatetruecolor( $pixels, $pixels );
		if ( false === $image ) {
			throw new \RuntimeException( 'GD made no image' );
		}
		$white = (int) imagecolorallocate( $image, 255, 255, 255 );
		$black = (int) imagecolorallocate( $image, 0, 0, 0 );
		imagefilledrectangle( $image, 0, 0, $pixels - 1, $pixels - 1, $white );
		preg_match_all( '/M(\d+) (\d+)h(\d+)v1h-\3z/', $path[1], $runs, PREG_SET_ORDER );
		foreach ( $runs as $run ) {
			$x = (int) $run[1] * self::SCALE;
			$y = (int) $run[2] * self::SCALE;
			imagefilledrectangle( $image, $x, $y, $x + (int) $run[3] * self::SCALE - 1, $y + self::SCALE - 1, $black );
		}
		ob_start();
		imagepng( $image );
		$png = (string) ob_get_clean();

		$options = new QROptions();

		$options->readerUseImagickIfAvailable = false; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- The library's option.

		return (string) ( new QRCode( $options ) )->readFromBlob( $png );
	}
}
