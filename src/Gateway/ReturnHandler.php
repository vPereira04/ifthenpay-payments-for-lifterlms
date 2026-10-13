<?php
/**
 * The payer's return from ifthenpay's hosted page.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Gateway;

use Ifthenpay\LifterLMS\Payments\PaymentAttempts;
use Ifthenpay\LifterLMS\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends the payer somewhere sensible after ifthenpay's page. This never marks anything paid:
 * only the callback does. Error and cancel returns are the only signal for those outcomes, so
 * they mark the attempt (never over paid) and keep the order pending for a retry.
 *
 * @phpstan-import-type Attempt from PaymentAttempts
 */
final class ReturnHandler {

	// Prefixed, never bare: affiliate plugins read `?ref=`, and these aren't registered query vars.
	public const ARG_KIND = 'iftp_llms_return';
	public const ARG_REF  = 'iftp_llms_ref';
	public const ARG_SIG  = 'iftp_llms_sig';

	public const KIND_SUCCESS = 'success';
	public const KIND_ERROR   = 'error';
	public const KIND_CANCEL  = 'cancel';

	private const KINDS = array( self::KIND_SUCCESS, self::KIND_ERROR, self::KIND_CANCEL );

	/**
	 * The attempts stored on each order.
	 *
	 * @var PaymentAttempts
	 */
	private PaymentAttempts $attempts;

	/**
	 * Sets up the handler.
	 *
	 * @param PaymentAttempts $attempts The attempts stored on each order.
	 */
	public function __construct( PaymentAttempts $attempts ) {
		$this->attempts = $attempts;
	}

	/**
	 * Hooks the return check.
	 */
	public function register(): void {
		// Priority 1: before redirect_canonical and before the theme starts on the page.
		add_action( 'template_redirect', array( $this, 'handle' ), 1 );
	}

	/**
	 * A signed return URL for one ref and outcome.
	 *
	 * @param string $kind One of KIND_*.
	 * @param string $ref  The transaction reference.
	 */
	public static function url( string $kind, string $ref ): string {
		return add_query_arg(
			array(
				self::ARG_KIND => $kind,
				self::ARG_REF  => rawurlencode( $ref ),
				self::ARG_SIG  => self::signature( $ref, $kind ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Signs a return URL's ref + outcome. The payer comes back through a plain GET from ifthenpay,
	 * so there's no nonce; this proves I issued the URL and nobody flipped success into cancel.
	 *
	 * @param string $ref  The transaction reference.
	 * @param string $kind One of KIND_*.
	 */
	public static function signature( string $ref, string $kind ): string {
		return wp_hash( 'iftp_llms_return|' . $ref . '|' . $kind );
	}

	/**
	 * Handles a signed return; anything else falls through to the normal page.
	 */
	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- ifthenpay's redirect can't carry a nonce; the signature below authenticates it instead.
		$kind = sanitize_key( wp_unslash( (string) ( $_GET[ self::ARG_KIND ] ?? '' ) ) );
		$ref  = sanitize_text_field( wp_unslash( (string) ( $_GET[ self::ARG_REF ] ?? '' ) ) );
		$sig  = sanitize_text_field( wp_unslash( (string) ( $_GET[ self::ARG_SIG ] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $kind || '' === $ref || ! in_array( $kind, self::KINDS, true ) || ! hash_equals( self::signature( $ref, $kind ), $sig ) ) {
			return;
		}

		$order_id = PaymentAttempts::order_id_from_ref( $ref );
		$order    = $order_id > 0 ? Plugin::get_order( $order_id ) : null;
		$record   = null !== $order ? $this->attempts->get( $order_id, $ref ) : null;

		if ( null === $record || null === $order ) {
			return;
		}

		if ( PaymentAttempts::STATUS_PAID === $record['status'] || in_array( $order->get( 'status' ), array( 'llms-completed', 'llms-active' ), true ) ) {
			$this->redirect_to_completion( $order );
		}

		if ( self::KIND_SUCCESS === $kind ) {
			// Multibanco and Payshop settle later, and even card callbacks can trail the browser.
			llms_add_notice( esc_html__( "We're waiting for ifthenpay to confirm your payment. Your order completes automatically as soon as it does.", 'ifthenpay-payments-for-lifterlms' ), 'notice' );
			llms_redirect_and_exit( $order->get_view_link() );

			// llms_redirect_and_exit() is pluggable, so I don't rely on an override exiting.
			exit;
		}

		$this->handle_failure( $kind, $record, $order );
	}

	/**
	 * Marks the attempt failed or cancelled (never over paid), notes it on the order and offers a retry.
	 *
	 * @param string               $kind   KIND_ERROR or KIND_CANCEL.
	 * @param array<string, mixed> $record The stored attempt.
	 * @param \LLMS_Order          $order  Its LifterLMS order.
	 * @phpstan-param Attempt $record
	 * @return never
	 */
	private function handle_failure( string $kind, array $record, \LLMS_Order $order ): void {
		$cancelled = self::KIND_CANCEL === $kind;

		if ( PaymentAttempts::STATUS_PENDING === $record['status'] ) {
			$changed = $this->attempts->set_status( (int) $order->get( 'id' ), $record['ref'], $cancelled ? PaymentAttempts::STATUS_CANCELLED : PaymentAttempts::STATUS_FAILED );

			if ( $changed ) {
				$order->add_note(
					sprintf(
						$cancelled
							/* translators: %s: transaction reference. */
							? __( 'The student cancelled the ifthenpay payment (ref %s). The order stays pending.', 'ifthenpay-payments-for-lifterlms' )
							/* translators: %s: transaction reference. */
							: __( 'The ifthenpay payment did not go through (ref %s). The order stays pending.', 'ifthenpay-payments-for-lifterlms' ),
						$record['ref']
					)
				);
			}
		}

		$message = $cancelled
			? esc_html__( 'You cancelled the payment, so your order is still waiting for it.', 'ifthenpay-payments-for-lifterlms' )
			: esc_html__( "The payment didn't go through, so your order is still waiting for it.", 'ifthenpay-payments-for-lifterlms' );

		$retry = self::retry_url( $order );

		// LifterLMS prints notices unescaped, so everything in here is escaped already.
		if ( '' !== $retry ) {
			$message .= ' <a href="' . esc_url( $retry ) . '">' . esc_html__( 'Try again', 'ifthenpay-payments-for-lifterlms' ) . '</a>';
		}

		llms_add_notice( $message, 'error' );
		llms_redirect_and_exit( $order->get_view_link() );

		// llms_redirect_and_exit() is pluggable, so I don't rely on an override exiting.
		exit;
	}

	/**
	 * Off to LifterLMS's own "order complete" destination.
	 *
	 * @param \LLMS_Order $order The paid order.
	 * @return never
	 */
	private function redirect_to_completion( \LLMS_Order $order ): void {
		$gateway = Plugin::gateway();
		$url     = null !== $gateway ? $gateway->completion_redirect_url( $order ) : $order->get_view_link();

		// Same as LifterLMS's complete_transaction(): a plan may redirect off-site, and LifterLMS vets that URL itself.
		llms_redirect_and_exit( $url, array( 'safe' => false ) );

		// llms_redirect_and_exit() is pluggable, so I don't rely on an override exiting.
		exit;
	}

	/**
	 * The plan's checkout, where LifterLMS picks the pending order back up. '' when there's none.
	 *
	 * @param \LLMS_Order $order The pending order.
	 */
	private static function retry_url( \LLMS_Order $order ): string {
		$plan = function_exists( 'llms_get_post' ) ? llms_get_post( (int) $order->get( 'plan_id' ) ) : null;

		if ( ! $plan instanceof \LLMS_Access_Plan ) {
			return '';
		}

		$url = (string) $plan->get_checkout_url( false );

		return 0 === strpos( $url, 'http' ) ? $url : '';
	}
}
