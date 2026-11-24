<?php
/**
 * The fakes the plugin runs with in the tests.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration\Support;

use Sattleme\WooCommerce\Services;

/**
 * The plugin's parts are made once per process (Plugin::boot()): with this clock, these
 * clients and this log, which each test resets (reset()).
 */
final class Fakes {

	/**
	 * The clock.
	 *
	 * @var FakeClock|null
	 */
	private static ?FakeClock $clock = null;

	/**
	 * The clients.
	 *
	 * @var FakeClients|null
	 */
	private static ?FakeClients $clients = null;

	/**
	 * The log.
	 *
	 * @var RecordingLogger|null
	 */
	private static ?RecordingLogger $logger = null;

	/**
	 * The plugin's parts, with the fakes (the testing filter).
	 */
	public static function services(): Services {
		return new Services( self::clock(), self::clients(), self::logger() );
	}

	/**
	 * The clock.
	 */
	public static function clock(): FakeClock {
		self::$clock ??= new FakeClock();

		return self::$clock;
	}

	/**
	 * The clients.
	 */
	public static function clients(): FakeClients {
		self::$clients ??= new FakeClients( new FakeCheckout( self::clock() ) );

		return self::$clients;
	}

	/**
	 * The SDK's client.
	 */
	public static function sdk(): FakeCheckout {
		return self::clients()->client;
	}

	/**
	 * The log.
	 */
	public static function logger(): RecordingLogger {
		self::$logger ??= new RecordingLogger();

		return self::$logger;
	}

	/**
	 * Back to the real time, a client answering by default, an empty log.
	 */
	public static function reset(): void {
		$clock                   = self::clock();
		$clock->now              = time();
		self::clients()->client  = new FakeCheckout( $clock );
		self::clients()->refusal = null;
		self::clients()->made    = 0;
		self::logger()->lines    = array();
	}
}
