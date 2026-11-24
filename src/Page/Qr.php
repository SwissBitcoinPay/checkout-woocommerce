<?php
/**
 * QR codes, as inline SVG.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Page;

use Sattleme\WooCommerce\Vendor\BaconQrCode\Common\ErrorCorrectionLevel;
use Sattleme\WooCommerce\Vendor\BaconQrCode\Encoder\Encoder;

defined( 'ABSPATH' ) || exit;

/**
 * A text as a QR code, made on the server by BaconQrCode (embedded and prefixed), drawn as an
 * inline SVG image: no script, no request, nothing loaded from elsewhere.
 */
final class Qr {

	/** Modules of white around the code, as the QR code standard asks for. */
	public const QUIET_ZONE = 4;

	/**
	 * What svg() draws with, for wp_kses() (the template prints the SVG through it): these
	 * elements and attributes alone, never a script, a link or a style.
	 */
	public const ALLOWED_HTML = array(
		'svg'  => array(
			'xmlns'           => true,
			'viewbox'         => true,
			'role'            => true,
			'aria-label'      => true,
			'shape-rendering' => true,
		),
		'rect' => array(
			'width'  => true,
			'height' => true,
			'fill'   => true,
		),
		'path' => array(
			'fill' => true,
			'd'    => true,
		),
	);

	/**
	 * $text as an SVG image labelled $label: dark modules as one path of horizontal runs, on a
	 * white background of its own, so that it scans whatever the page's colors. Null when the
	 * text is too long for a QR code (the page shows it as text then).
	 *
	 * @param string $text  The text.
	 * @param string $label The label.
	 */
	public static function svg( string $text, string $label ): ?string {
		try {
			// Error correction M (15 %), as wallets' own codes; byte mode, the URI's ASCII as it is.
			$matrix = Encoder::encode( $text, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ENCODING, null, false )->getMatrix();
		} catch ( \Throwable ) {
			return null;
		}
		$width = $matrix->getWidth();
		$size  = $width + 2 * self::QUIET_ZONE;
		$runs  = '';
		for ( $y = 0; $y < $width; $y++ ) {
			$x = 0;
			while ( $x < $width ) {
				if ( 1 !== $matrix->get( $x, $y ) ) {
					++$x;
					continue;
				}
				$start = $x;
				while ( $x < $width && 1 === $matrix->get( $x, $y ) ) {
					++$x;
				}
				$length = $x - $start;
				$runs  .= 'M' . ( $start + self::QUIET_ZONE ) . ' ' . ( $y + self::QUIET_ZONE ) . 'h' . $length . 'v1h-' . $length . 'z';
			}
		}

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" role="img" aria-label="' . esc_attr( $label ) . '" shape-rendering="crispEdges">'
			. '<rect width="' . $size . '" height="' . $size . '" fill="#fff"/>'
			. '<path fill="#000" d="' . $runs . '"/>'
			. '</svg>';
	}
}
