<?php
/**
 * A log the tests read.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration\Support;

use Sattleme\WooCommerce\Support\Logger;

/**
 * Keeps every line the plugin logs, and writes it to WooCommerce's log as well.
 */
final class RecordingLogger extends Logger {

	/**
	 * The lines: "<level> <message>".
	 *
	 * @var list<string>
	 */
	public array $lines = array();

	/**
	 * Keeps the line.
	 *
	 * @param string $level   The level.
	 * @param string $message The message.
	 */
	protected function log( string $level, string $message ): void {
		$this->lines[] = $level . ' ' . $message;
		parent::log( $level, $message );
	}

	/**
	 * Every line, as one text.
	 */
	public function text(): string {
		return implode( "\n", $this->lines );
	}
}
