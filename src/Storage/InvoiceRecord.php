<?php
/**
 * One attempt to pay an order.
 *
 * @package Sattleme\WooCommerce
 */

namespace Sattleme\WooCommerce\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * A row of the invoices' table: one attempt to pay an order with bitcoin, its random reference,
 * the invoice the SDK made for it (its JSON, SPEC.md §3) and what its checks found.
 */
final class InvoiceRecord {

	/** The SDK reports a payment made in an invoice's last seconds up to this long after its end. */
	public const GRACE_SECONDS = 120;

	public const STATE_CREATING = 'creating';
	public const STATE_RETRY    = 'retry';
	public const STATE_FAILED   = 'failed';
	public const STATE_INVOICE  = 'invoice';

	public const OUTCOME_PAID      = 'paid';
	public const OUTCOME_EXPIRED   = 'expired';
	public const OUTCOME_ABANDONED = 'abandoned';

	/**
	 * A row, as read.
	 *
	 * @param int                       $id            The invoice's id.
	 * @param int                       $order_id      The order.
	 * @param string                    $ref           The attempt's reference.
	 * @param string                    $state         Where the attempt is (InvoiceRecord::STATE_*).
	 * @param array<string, mixed>|null $invoice       The SDK's invoice (an Invoice, or a DeclaredInvoice).
	 * @param array<string, mixed>|null $status        The last status a check found (PaymentStatus).
	 * @param int|null                  $expires_at    When the invoice ends (Unix seconds).
	 * @param bool                      $is_final      Whether it is final: paid, expired, failed or given up on.
	 * @param string                    $outcome       How it ended (paid, expired, abandoned).
	 * @param string                    $error         The error.
	 * @param int                       $errors        Failed checks in a row.
	 * @param int                       $created_at    When it was made (Unix seconds).
	 * @param int|null                  $checked_at    When it was last checked (Unix seconds).
	 * @param int                       $checks        How many checks found a status.
	 * @param int|null                  $next_check_at When it is checked next (Unix seconds).
	 * @param string                    $chain         The chain of background checks (its random id).
	 * @param int|null                  $lease_until   The check's lease, until when it holds the invoice (Unix seconds).
	 * @param bool                      $shredded      Whether the customer's personal data was removed.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $order_id,
		public readonly string $ref,
		public readonly string $state,
		public readonly ?array $invoice,
		public readonly ?array $status,
		public readonly ?int $expires_at,
		public readonly bool $is_final,
		public readonly string $outcome,
		public readonly string $error,
		public readonly int $errors,
		public readonly int $created_at,
		public readonly ?int $checked_at,
		public readonly int $checks,
		public readonly ?int $next_check_at,
		public readonly string $chain,
		public readonly ?int $lease_until,
		public readonly bool $shredded,
	) {}

	/**
	 * A row of $wpdb->get_row( …, ARRAY_A ).
	 *
	 * @param array<mixed> $row The row, by column.
	 */
	public static function from_row( array $row ): self {
		return new self(
			self::int_of( $row['id'] ?? 0 ),
			self::int_of( $row['order_id'] ?? 0 ),
			self::string_of( $row['ref'] ?? '' ),
			self::string_of( $row['state'] ?? '' ),
			self::json_of( $row['invoice'] ?? null ),
			self::json_of( $row['status'] ?? null ),
			self::nullable_int_of( $row['expires_at'] ?? null ),
			1 === self::int_of( $row['final'] ?? 0 ),
			self::string_of( $row['outcome'] ?? '' ),
			self::string_of( $row['error'] ?? '' ),
			self::int_of( $row['errors'] ?? 0 ),
			self::int_of( $row['created_at'] ?? 0 ),
			self::nullable_int_of( $row['checked_at'] ?? null ),
			self::int_of( $row['checks'] ?? 0 ),
			self::nullable_int_of( $row['next_check_at'] ?? null ),
			self::string_of( $row['chain'] ?? '' ),
			self::nullable_int_of( $row['lease_until'] ?? null ),
			1 === self::int_of( $row['shredded'] ?? 0 ),
		);
	}

	/**
	 * Whether the SDK made an invoice for this attempt (it may still be paid, or was).
	 */
	public function has_invoice(): bool {
		return self::STATE_INVOICE === $this->state && null !== $this->invoice;
	}

	/**
	 * Whether the invoice is one the SDK handed out again (InvoicePending: a DeclaredInvoice, an
	 * earlier one of this reference): shown only once a check found it open.
	 */
	public function is_declared(): bool {
		return is_int( $this->invoice['declaredAt'] ?? null );
	}

	/**
	 * The invoice's amount, in sats (a string of digits), or "0" without an invoice.
	 */
	public function sats(): string {
		$sats = $this->invoice['sats'] ?? null;

		return is_string( $sats ) && 1 === preg_match( '/^[0-9]{1,18}$/D', $sats ) ? $sats : '0';
	}

	/**
	 * When the SDK reports its payment as final at the latest (Unix seconds): its end and the
	 * grace period. 0 without an invoice.
	 */
	public function end(): int {
		return null === $this->expires_at ? 0 : $this->expires_at + self::GRACE_SECONDS;
	}

	/**
	 * The invoice's BOLT11 Lightning invoice, if it has one.
	 */
	public function bolt11(): ?string {
		return $this->string_member( $this->invoice, 'bolt11' );
	}

	/**
	 * The invoice's payment hash (hex), if it has a Lightning invoice.
	 */
	public function payment_hash(): ?string {
		return $this->string_member( $this->invoice, 'paymentHash' );
	}

	/**
	 * The deposit address the order leased, if it has one.
	 */
	public function onchain_address(): ?string {
		return $this->string_member( $this->invoice, 'onchainAddress' );
	}

	/**
	 * What to show as one QR code: a bitcoin: or lightning: URI.
	 */
	public function payment_uri(): ?string {
		return $this->string_member( $this->invoice, 'paymentUri' );
	}

	/**
	 * The state of the last status (open, unconfirmed, paid, expired), or null before the first.
	 */
	public function status_state(): ?string {
		return $this->string_member( $this->status, 'state' );
	}

	/**
	 * How a paid invoice was paid: lightning, onchain or mixed.
	 */
	public function method(): ?string {
		return $this->string_member( $this->status, 'method' );
	}

	/**
	 * What came in time, in sats (on-chain, and by invoices of the rest): "0" when nothing did.
	 */
	public function received(): string {
		$received = $this->status['received'] ?? null;

		if ( ! is_string( $received ) || 1 !== preg_match( '/^[0-9]{1,18}$/D', $received ) ) {
			return '0';
		}
		$received = ltrim( $received, '0' );

		return '' === $received ? '0' : $received;
	}

	/**
	 * Whether something came in time.
	 */
	public function has_received(): bool {
		return '0' !== $this->received();
	}

	/**
	 * What is left to pay of an on-chain underpayment, and how: sats, bolt11, paymentUri.
	 *
	 * @return array{sats: string, bolt11: ?string, paymentUri: ?string}|null
	 */
	public function rest(): ?array {
		$rest = $this->status['rest'] ?? null;
		if ( ! is_array( $rest ) ) {
			return null;
		}
		$sats = $rest['sats'] ?? null;
		if ( ! is_string( $sats ) || 1 !== preg_match( '/^[0-9]{1,18}$/D', $sats ) ) {
			return null;
		}

		return array(
			'sats'       => $sats,
			'bolt11'     => $this->string_member( $rest, 'bolt11' ),
			'paymentUri' => $this->string_member( $rest, 'paymentUri' ),
		);
	}

	/**
	 * The confirmations of an on-chain payment being confirmed, if any.
	 */
	public function confirmations(): ?int {
		$confirmations = $this->status['confirmations'] ?? null;

		return is_int( $confirmations ) ? $confirmations : null;
	}

	/**
	 * The transactions that paid the invoice, as the last status lists them (hex txids).
	 *
	 * @return list<string>
	 */
	public function txids(): array {
		return self::hex_list( $this->status['txids'] ?? null );
	}

	/**
	 * The proofs of the Lightning payments: the preimage of the invoice, or those of its rest's.
	 *
	 * @return list<string>
	 */
	public function preimages(): array {
		$preimages = self::hex_list( $this->status['preimages'] ?? null );
		$preimage  = $this->string_member( $this->status, 'preimage' );
		if ( null !== $preimage && 1 === preg_match( '/^[0-9a-f]{64}$/D', $preimage ) ) {
			array_unshift( $preimages, $preimage );
		}

		return $preimages;
	}

	/**
	 * Whether the invoice ended unpaid: final, expired, nothing received. Only a check made after
	 * its end says so: its time being over is not enough (a payment of its last seconds, or an
	 * on-chain one the SDK dates in time, is found by that check).
	 */
	public function ended_unpaid(): bool {
		return $this->is_final && self::OUTCOME_EXPIRED === $this->outcome && ! $this->has_received();
	}

	/**
	 * Whether the invoice may still be paid, or its payment be found or confirmed: the SDK made
	 * it and its final status is not known yet, whatever the time; or another request is making
	 * it right now.
	 *
	 * @param int $now The time, in Unix seconds.
	 */
	public function may_be_paid( int $now ): bool {
		if ( $this->is_final ) {
			return false;
		}
		if ( ! $this->has_invoice() ) {
			// Being made by another request right now: it may give an invoice in a moment.
			return self::STATE_CREATING === $this->state && $now < $this->created_at + 60;
		}

		return true;
	}

	/**
	 * Whether the invoice keeps its order from any other payment (another invoice, another
	 * method): until it ended unpaid. One that may still be paid does, and so does one paid, one
	 * that ended underpaid (what came is the merchant's, to settle by hand) and one whose checks
	 * were given up on.
	 *
	 * @param int $now The time, in Unix seconds.
	 */
	public function is_live( int $now ): bool {
		return $this->may_be_paid( $now ) || ( $this->has_invoice() && ! $this->ended_unpaid() );
	}

	/**
	 * A string member of $data, or null.
	 *
	 * @param array<mixed>|null $data The data.
	 * @param string            $name The member.
	 */
	private function string_member( ?array $data, string $name ): ?string {
		$value = $data[ $name ] ?? null;

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * A list of lowercase 32-byte hex strings, else empty.
	 *
	 * @param mixed $value The value.
	 * @return list<string>
	 */
	private static function hex_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$list = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) && 1 === preg_match( '/^[0-9a-f]{64}$/D', $item ) ) {
				$list[] = $item;
			}
		}

		return $list;
	}

	/**
	 * JSON text as an array, else null.
	 *
	 * @param mixed $value The column's value.
	 * @return array<string, mixed>|null
	 */
	private static function json_of( mixed $value ): ?array {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$decoded = json_decode( $value, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		$result = array();
		foreach ( $decoded as $key => $item ) {
			$result[ (string) $key ] = $item;
		}

		return $result;
	}

	/**
	 * A column as an int.
	 *
	 * @param mixed $value The column's value.
	 */
	private static function int_of( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * A nullable column as an int, or null.
	 *
	 * @param mixed $value The column's value.
	 */
	private static function nullable_int_of( mixed $value ): ?int {
		return null === $value ? null : self::int_of( $value );
	}

	/**
	 * A column as a string.
	 *
	 * @param mixed $value The column's value.
	 */
	private static function string_of( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
