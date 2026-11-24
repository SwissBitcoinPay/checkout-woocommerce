<?php
/**
 * The queries sent to the database.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Tests\Integration\Support;

/**
 * The queries WordPress sends to the database from the moment record() is called, as its
 * "query" filter sees them: as they are sent (the test library turns a DROP TABLE into one of a
 * temporary table, which drops nothing).
 */
final class Queries {

	/**
	 * The queries, in turn.
	 *
	 * @var list<string>
	 */
	public array $list = array();

	/**
	 * Records the queries from now, until the test ends.
	 */
	public static function record(): self {
		$queries = new self();
		add_filter(
			'query',
			static function ( string $query ) use ( $queries ): string {
				$queries->list[] = $query;

				return $query;
			},
			1
		);

		return $queries;
	}
}
