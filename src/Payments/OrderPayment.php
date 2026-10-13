<?php
/**
 * Records a confirmed ifthenpay payment on its LifterLMS order.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Payments;

use Ifthenpay\LifterLMS\Methods;
use Ifthenpay\LifterLMS\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a confirmed attempt into a succeeded LifterLMS transaction. LifterLMS then completes the
 * order, enrolls the student and sends the receipt off that transaction, exactly as for its own gateways.
 *
 * @phpstan-import-type Attempt from PaymentAttempts
 */
final class OrderPayment {

	/**
	 * Records the LifterLMS transaction and notes it on the order.
	 *
	 * @param \LLMS_Order          $order      The attempt's order.
	 * @param array<string, mixed> $attempt    The attempt, as read before it was marked paid.
	 * @param string               $method     The canonical method code, or '' when none is known.
	 * @param string               $request_id The ifthenpay request id.
	 * @phpstan-param Attempt $attempt
	 * @return bool Whether LifterLMS recorded the transaction.
	 */
	public static function record( \LLMS_Order $order, array $attempt, string $method, string $request_id ): bool {
		$already_settled = in_array( $order->get( 'status' ), array( 'llms-completed', 'llms-active' ), true );

		// The attempt is paid on ifthenpay's side whatever happens here; a failure in LifterLMS
		// (or in a hook on its status change) must not turn into an error ifthenpay retries.
		try {
			$order->record_transaction(
				array(
					'amount'             => $attempt['amount'],
					'source_description' => self::source_label( $method ),
					'transaction_id'     => '' !== $request_id ? $request_id : $attempt['ref'],
					'status'             => 'llms-txn-succeeded',
					'payment_gateway'    => Plugin::GATEWAY_ID,
					'payment_type'       => 'single',
				)
			);

			$order->add_note(
				sprintf(
					/* translators: 1: transaction reference, 2: payment method, 3: ifthenpay request id. */
					__( 'ifthenpay confirmed the payment (ref %1$s, %2$s, request %3$s).', 'ifthenpay-payments-for-lifterlms' ),
					$attempt['ref'],
					self::source_label( $method ),
					'' !== $request_id ? $request_id : '—'
				)
			);

			if ( $already_settled ) {
				$order->add_note(
					sprintf(
						/* translators: %s: transaction reference. */
						__( 'This order was already paid before ref %s was confirmed, so the student may have paid twice. Check and refund manually if needed.', 'ifthenpay-payments-for-lifterlms' ),
						$attempt['ref']
					)
				);
			}

			return true;
		} catch ( \Throwable $e ) {
			self::log( 'recording the LifterLMS transaction failed for ref ' . $attempt['ref'] . ': ' . $e->getMessage() );

			return false;
		}
	}

	/**
	 * What LifterLMS shows as the payment source: the method's name, or plain "ifthenpay" when none is known.
	 *
	 * @param string $method A method code, or ''.
	 */
	public static function source_label( string $method ): string {
		return '' !== $method ? Methods::label( $method ) : 'ifthenpay';
	}

	/**
	 * Logs a recording problem for the admin to find.
	 *
	 * @param string $message What went wrong.
	 */
	public static function log( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- I log recording failures on purpose, for admin troubleshooting.
		error_log( 'ifthenpay Payments for LifterLMS: ' . $message );
	}
}
