<?php
/**
 * Payment attempts for ifthenpay, stored on the LifterLMS order.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Payments;

use Ifthenpay\LifterLMS\Api\IfthenpayClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LifterLMS's order is the record of truth, so each Pay by Link attempt lives in hidden order meta
 * instead of a table of our own. The ref carries the order id (`LLMS_{order}_{rand}`), which is how
 * the callback and the return find their order. A retry adds an attempt; a late payment on an older
 * link still resolves, because every attempt's ref stays on the order.
 *
 * @phpstan-type Attempt array{ref: string, gateway_key: string, amount: float, payment_url: string, status: string, method: string, request_id: string, created_at: int, updated_at: int, expires_at: int}
 */
final class PaymentAttempts {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_PAID      = 'paid';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Display-only: a pending attempt whose link is past its expiry date.
	 */
	public const STATUS_EXPIRED = 'expired';

	public const REF_PREFIX = 'LLMS_';

	// Underscore-prefixed, so WordPress keeps them out of the Custom Fields box.
	private const META_ATTEMPTS = '_iftp_llms_attempts';
	private const META_CURRENT  = '_iftp_llms_ref';

	// Random characters after `LLMS_{order}_`; keeps the Pay by Link id short.
	private const REF_RANDOM_LENGTH = 6;

	// Oldest unpaid attempts beyond this are dropped, so a student retrying forever can't grow the meta.
	private const MAX_ATTEMPTS = 20;

	private const LOCK_PREFIX = 'iftp_llms_lock_';
	private const LOCK_TTL    = 5 * MINUTE_IN_SECONDS;

	/**
	 * A fresh ref for an order.
	 *
	 * @param int $order_id The LifterLMS order id.
	 */
	public static function new_ref( int $order_id ): string {
		return self::REF_PREFIX . $order_id . '_' . strtoupper( wp_generate_password( self::REF_RANDOM_LENGTH, false, false ) );
	}

	/**
	 * The order id a ref belongs to, or 0 when it isn't one of ours.
	 *
	 * @param string $ref The transaction reference.
	 */
	public static function order_id_from_ref( string $ref ): int {
		return 1 === preg_match( '/^' . self::REF_PREFIX . '(\d+)_[A-Za-z0-9]+$/', $ref, $m ) ? (int) $m[1] : 0;
	}

	/**
	 * Every attempt of an order, newest first.
	 *
	 * @param int $order_id The LifterLMS order id.
	 * @return array<int, Attempt>
	 */
	public function all( int $order_id ): array {
		$attempts = array_values( $this->read( $order_id ) );

		usort(
			$attempts,
			static function ( array $a, array $b ): int {
				return $b['created_at'] <=> $a['created_at'];
			}
		);

		return $attempts;
	}

	/**
	 * One attempt of an order, or null when the ref was never issued for it.
	 *
	 * @param int    $order_id The LifterLMS order id.
	 * @param string $ref      The transaction reference.
	 * @return Attempt|null
	 */
	public function get( int $order_id, string $ref ): ?array {
		$attempts = $this->read( $order_id );

		return $attempts[ $ref ] ?? null;
	}

	/**
	 * The order's latest attempt, or null.
	 *
	 * @param int $order_id The LifterLMS order id.
	 * @return Attempt|null
	 */
	public function current( int $order_id ): ?array {
		$ref = (string) get_post_meta( $order_id, self::META_CURRENT, true );

		return '' !== $ref ? $this->get( $order_id, $ref ) : null;
	}

	/**
	 * Records a new pending attempt and makes it current. Older pending attempts are superseded (cancelled),
	 * though a late payment on one of them still resolves.
	 *
	 * @param int    $order_id    The LifterLMS order id.
	 * @param string $ref         The new ref.
	 * @param string $gateway_key The Gateway Key the link was created on.
	 * @param float  $amount      The amount asked.
	 * @param string $payment_url The Pay by Link URL.
	 * @param int    $expires_at  When the link stops being payable (unix).
	 */
	public function start( int $order_id, string $ref, string $gateway_key, float $amount, string $payment_url, int $expires_at ): void {
		$attempts = $this->read( $order_id );
		$now      = time();

		foreach ( $attempts as $key => $attempt ) {
			if ( self::STATUS_PENDING === $attempt['status'] ) {
				$attempts[ $key ]['status']     = self::STATUS_CANCELLED;
				$attempts[ $key ]['updated_at'] = $now;
			}
		}

		$attempts[ $ref ] = array(
			'ref'         => $ref,
			'gateway_key' => $gateway_key,
			'amount'      => $amount,
			'payment_url' => $payment_url,
			'status'      => self::STATUS_PENDING,
			'method'      => '',
			'request_id'  => '',
			'created_at'  => $now,
			'updated_at'  => $now,
			'expires_at'  => $expires_at,
		);

		$this->write( $order_id, $this->trim( $attempts ) );
		update_post_meta( $order_id, self::META_CURRENT, $ref );
	}

	/**
	 * Moves an attempt to a new status. Paid is history: nothing moves an attempt off it.
	 *
	 * @param int    $order_id   The LifterLMS order id.
	 * @param string $ref        The transaction reference.
	 * @param string $status     One of STATUS_PAID, STATUS_FAILED, STATUS_CANCELLED.
	 * @param string $method     The method code (paid only).
	 * @param string $request_id The ifthenpay request id (paid only).
	 * @return bool Whether the attempt changed.
	 */
	public function set_status( int $order_id, string $ref, string $status, string $method = '', string $request_id = '' ): bool {
		$attempts = $this->read( $order_id );

		if ( ! isset( $attempts[ $ref ] ) || self::STATUS_PAID === $attempts[ $ref ]['status'] || $status === $attempts[ $ref ]['status'] ) {
			return false;
		}

		$attempts[ $ref ]['status']     = $status;
		$attempts[ $ref ]['updated_at'] = time();

		if ( '' !== $method ) {
			$attempts[ $ref ]['method'] = $method;
		}

		if ( '' !== $request_id ) {
			$attempts[ $ref ]['request_id'] = $request_id;
		}

		$this->write( $order_id, $attempts );

		return true;
	}

	/**
	 * A live attempt on another of the student's pending orders for the same course or membership, or null.
	 * Two access plans of one course are two orders, so I check across them.
	 *
	 * @param int $user_id         The student.
	 * @param int $product_id      The course or membership.
	 * @param int $except_order_id The order being paid now.
	 * @return Attempt|null
	 */
	public function live_for_product( int $user_id, int $product_id, int $except_order_id ): ?array {
		$student = $user_id > 0 && $product_id > 0 && function_exists( 'llms_get_student' ) ? llms_get_student( $user_id ) : null;

		if ( ! $student instanceof \LLMS_Student ) {
			return null;
		}

		$found = $student->get_orders(
			array(
				'statuses' => array( 'llms-pending' ),
				'count'    => self::MAX_ATTEMPTS,
			)
		);

		foreach ( (array) ( $found['orders'] ?? array() ) as $order_id => $order ) {
			if ( (int) $order_id === $except_order_id || ! $order instanceof \LLMS_Order || (int) $order->get( 'product_id' ) !== $product_id ) {
				continue;
			}

			$current = $this->current( (int) $order_id );

			if ( null !== $current && self::is_live( $current ) ) {
				return $current;
			}
		}

		return null;
	}

	/**
	 * Claims a short lock on a key: a ref's payment, or a student's new link. Only one concurrent request
	 * wins: the claim is an INSERT on the options table's unique option_name. A stale claim (a crashed request) is reclaimed.
	 *
	 * @param string $key What to lock.
	 * @return bool Whether this request holds the claim.
	 */
	public function claim( string $key ): bool {
		global $wpdb;

		$name = self::lock_name( $key );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic lock; add_option() can't report a lost race.
		$won = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) );

		if ( 1 === $won ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read the lock straight from the table; the options cache may be stale.
		$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		if ( $since > 0 && time() - $since > self::LOCK_TTL ) {
			// Only the request whose UPDATE lands on the stale value takes it over.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- same atomic lock.
			return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) time(), $name, (string) $since ) );
		}

		return false;
	}

	/**
	 * Releases a claim.
	 *
	 * @param string $key What was locked.
	 */
	public function release( string $key ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the lock row was written directly too.
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::lock_name( $key ) ) );
	}

	/**
	 * Whether an attempt's link can still be paid. ifthenpay can't revoke a link, so this holds until it's paid,
	 * reported failed or cancelled, or expires.
	 *
	 * @param array<string, mixed> $attempt The attempt.
	 * @phpstan-param Attempt $attempt
	 */
	public static function is_live( array $attempt ): bool {
		return self::STATUS_PENDING === self::display_status( $attempt )
			&& IfthenpayClient::is_payment_url( $attempt['payment_url'] );
	}

	/**
	 * The status to show: a pending attempt past its expiry date reads as expired.
	 *
	 * @param array<string, mixed> $attempt The attempt.
	 * @phpstan-param Attempt $attempt
	 */
	public static function display_status( array $attempt ): string {
		if ( self::STATUS_PENDING === $attempt['status'] && $attempt['expires_at'] > 0 && $attempt['expires_at'] < time() ) {
			return self::STATUS_EXPIRED;
		}

		return $attempt['status'];
	}

	/**
	 * When a link created now with this many expiry days stops being payable: the end of the
	 * `expiredate` day ifthenpay gets (IfthenpayPayload), in the site timezone.
	 *
	 * @param int $expiry_days Payment link lifetime in days.
	 */
	public static function expires_at( int $expiry_days ): int {
		$day = (string) wp_date( 'Y-m-d', time() + $expiry_days * DAY_IN_SECONDS );

		try {
			return ( new \DateTimeImmutable( $day . ' 23:59:59', wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return time() + $expiry_days * DAY_IN_SECONDS;
		}
	}

	/**
	 * The order's attempts, keyed by ref, with every field typed.
	 *
	 * @param int $order_id The LifterLMS order id.
	 * @return array<string, Attempt>
	 */
	private function read( int $order_id ): array {
		$raw      = get_post_meta( $order_id, self::META_ATTEMPTS, true );
		$attempts = array();

		if ( ! is_array( $raw ) ) {
			return $attempts;
		}

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['ref'] ) || '' === (string) $item['ref'] ) {
				continue;
			}

			$ref              = (string) $item['ref'];
			$attempts[ $ref ] = array(
				'ref'         => $ref,
				'gateway_key' => (string) ( $item['gateway_key'] ?? '' ),
				'amount'      => (float) ( $item['amount'] ?? 0 ),
				'payment_url' => (string) ( $item['payment_url'] ?? '' ),
				'status'      => (string) ( $item['status'] ?? self::STATUS_PENDING ),
				'method'      => (string) ( $item['method'] ?? '' ),
				'request_id'  => (string) ( $item['request_id'] ?? '' ),
				'created_at'  => (int) ( $item['created_at'] ?? 0 ),
				'updated_at'  => (int) ( $item['updated_at'] ?? 0 ),
				'expires_at'  => (int) ( $item['expires_at'] ?? 0 ),
			);
		}

		return $attempts;
	}

	/**
	 * Saves the order's attempts.
	 *
	 * @param int                    $order_id The LifterLMS order id.
	 * @param array<string, Attempt> $attempts Attempts keyed by ref.
	 */
	private function write( int $order_id, array $attempts ): void {
		update_post_meta( $order_id, self::META_ATTEMPTS, $attempts );
	}

	/**
	 * Drops the oldest unpaid attempts beyond MAX_ATTEMPTS. Paid ones always stay.
	 *
	 * @param array<string, Attempt> $attempts Attempts keyed by ref.
	 * @return array<string, Attempt>
	 */
	private function trim( array $attempts ): array {
		if ( count( $attempts ) <= self::MAX_ATTEMPTS ) {
			return $attempts;
		}

		uasort(
			$attempts,
			static function ( array $a, array $b ): int {
				return $a['created_at'] <=> $b['created_at'];
			}
		);

		foreach ( $attempts as $ref => $attempt ) {
			if ( count( $attempts ) <= self::MAX_ATTEMPTS ) {
				break;
			}

			if ( self::STATUS_PAID !== $attempt['status'] ) {
				unset( $attempts[ $ref ] );
			}
		}

		return $attempts;
	}

	/**
	 * The lock's option name.
	 *
	 * @param string $key What is locked.
	 */
	private static function lock_name( string $key ): string {
		return self::LOCK_PREFIX . md5( $key );
	}
}
