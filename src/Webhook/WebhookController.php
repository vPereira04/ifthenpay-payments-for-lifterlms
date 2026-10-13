<?php
/**
 * Inbound callback handler.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Webhook;

use Ifthenpay\LifterLMS\Methods;
use Ifthenpay\LifterLMS\Payments\OrderPayment;
use Ifthenpay\LifterLMS\Payments\PaymentAttempts;
use Ifthenpay\LifterLMS\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where ifthenpay marks a payment paid. LifterLMS has no inbound listener, so ifthenpay calls our
 * own endpoint. The ref names the order; a confirmed payment becomes a succeeded LifterLMS
 * transaction (OrderPayment), which completes the order, enrolls the student and sends the receipt.
 *
 * @phpstan-import-type Attempt from PaymentAttempts
 */
final class WebhookController {

	/**
	 * Ifthenpay calls back into `/{SLUG}/{ref}/?apk=…&val=…&mtd=…&req=…`.
	 */
	public const SLUG = 'iftp_llms_callback';

	/**
	 * The query var the rewrite rule fills with the ref.
	 */
	public const REF_VAR = 'iftp_llms_cb_ref';

	/**
	 * The attempts stored on each order.
	 *
	 * @var PaymentAttempts
	 */
	private PaymentAttempts $attempts;

	/**
	 * Anti-phishing, amount and method checks.
	 *
	 * @var WebhookValidator
	 */
	private WebhookValidator $validator;

	/**
	 * Sets up the controller.
	 *
	 * @param PaymentAttempts  $attempts  The attempts stored on each order.
	 * @param WebhookValidator $validator Anti-phishing, amount and method checks.
	 */
	public function __construct( PaymentAttempts $attempts, WebhookValidator $validator ) {
		$this->attempts  = $attempts;
		$this->validator = $validator;
	}

	/**
	 * Wires up the rewrite endpoint ifthenpay calls back into.
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( self::class, 'add_query_vars' ) );

		// Priority 1: ahead of redirect_canonical (10), which could bounce ifthenpay to another URL.
		add_action( 'template_redirect', array( $this, 'handle' ), 1 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( self::class, 'maybe_flush' ) );
		}
	}

	/**
	 * `/{SLUG}/{ref}/` → `index.php?{REF_VAR}={ref}`.
	 */
	public static function add_rewrite_rule(): void {
		add_rewrite_rule( self::rule_regex(), 'index.php?' . self::REF_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Flushes once when the stored rules lack ours. Activation flushes too, but an update (or a plugin
	 * activated before this code existed) never runs the activation hook. Just an array lookup otherwise.
	 */
	public static function maybe_flush(): void {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return;
		}

		$rules = get_option( 'rewrite_rules' );

		// An empty value means WordPress rebuilds the rules on its own.
		if ( is_array( $rules ) && array() !== $rules && ! isset( $rules[ self::rule_regex() ] ) ) {
			flush_rewrite_rules( false );
		}
	}

	/**
	 * The rewrite rule's regex, also its key in the stored rules.
	 */
	private static function rule_regex(): string {
		return '^' . self::SLUG . '/([^/]+)/?$';
	}

	/**
	 * Registers the endpoint's query var as public.
	 *
	 * @param mixed $vars WordPress's registered public query vars.
	 * @return mixed
	 */
	public static function add_query_vars( $vars ) {
		if ( is_array( $vars ) ) {
			$vars[] = self::REF_VAR;
		}

		return $vars;
	}

	/**
	 * Registers and flushes the rewrite rule. Run on activation, or the endpoint 404s until something else flushes.
	 */
	public static function flush(): void {
		self::add_rewrite_rule();
		flush_rewrite_rules();
	}

	/**
	 * The callback URL template registered with ifthenpay, which fills in the placeholders on each call.
	 */
	public static function url(): string {
		$params = 'apk=[ANTI_PHISHING_KEY]&val=[AMOUNT]&mtd=[PAYMENT_METHOD]&req=[REQUEST_ID]';

		// With plain permalinks the rewrite rule never matches; the query var is public, so the query-string form works there.
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return home_url( '/' ) . '?' . self::REF_VAR . '=[ORDER_ID]&' . $params;
		}

		return home_url( '/' . self::SLUG . '/' ) . '[ORDER_ID]/?' . $params;
	}

	/**
	 * Validates and processes an inbound callback.
	 */
	public function handle(): void {
		$ref = sanitize_text_field( (string) get_query_var( self::REF_VAR ) );

		if ( '' === $ref ) {
			return;
		}

		$order_id = PaymentAttempts::order_id_from_ref( $ref );
		$order    = $order_id > 0 ? Plugin::get_order( $order_id ) : null;
		$attempt  = null !== $order ? $this->attempts->get( $order_id, $ref ) : null;

		if ( null === $order || null === $attempt ) {
			self::respond( 404, 'Unknown reference' );
		}

		$payload = WebhookPayload::from_request( $ref );

		// Every branch, failures included: otherwise a guessed ref is enough to cancel someone's payment.
		if ( ! $this->validator->key_matches( $payload, $attempt ) ) {
			self::respond( 403, 'Invalid anti-phishing key' );
		}

		if ( PaymentAttempts::STATUS_PAID === $attempt['status'] ) {
			self::respond( 200, 'already paid' );
		}

		if ( $payload->is_failure() ) {
			$this->handle_failure( $payload, $order, $attempt );
			self::respond( 200, 'OK' );
		}

		if ( ! $this->validator->amount_matches( $payload, $attempt ) ) {
			self::respond( 403, 'Amount mismatch' );
		}

		if ( ! $this->validator->method_allowed( $payload ) ) {
			self::respond( 403, 'Unknown payment method' );
		}

		$this->handle_success( $payload, $order, $attempt );

		self::respond( 200, 'OK' );
	}

	/**
	 * Records the payment once: only the request holding the claim records the LifterLMS transaction.
	 *
	 * @param WebhookPayload       $payload The parsed callback.
	 * @param \LLMS_Order          $order   The attempt's order.
	 * @param array<string, mixed> $attempt The stored attempt.
	 * @phpstan-param Attempt $attempt
	 */
	private function handle_success( WebhookPayload $payload, \LLMS_Order $order, array $attempt ): void {
		$order_id = (int) $order->get( 'id' );

		// false = a concurrent callback holds it, and that one records the transaction.
		if ( ! $this->attempts->claim( $attempt['ref'] ) ) {
			self::respond( 200, 'already paid' );
		}

		// Re-read under the claim: a callback that finished between my first read and the claim already recorded it.
		$fresh = $this->attempts->get( $order_id, $attempt['ref'] );

		if ( null === $fresh || PaymentAttempts::STATUS_PAID === $fresh['status'] ) {
			$this->attempts->release( $attempt['ref'] );
			self::respond( 200, 'already paid' );
		}

		$method = Methods::normalize( $payload->method() );

		OrderPayment::record( $order, $fresh, $method, $payload->request_id() );
		$this->attempts->set_status( $order_id, $fresh['ref'], PaymentAttempts::STATUS_PAID, $method, $payload->request_id() );
		$this->attempts->release( $fresh['ref'] );
	}

	/**
	 * Marks the attempt failed or cancelled (never over paid). The order stays pending, so the student can retry.
	 *
	 * @param WebhookPayload       $payload The parsed callback.
	 * @param \LLMS_Order          $order   The attempt's order.
	 * @param array<string, mixed> $attempt The stored attempt.
	 * @phpstan-param Attempt $attempt
	 */
	private function handle_failure( WebhookPayload $payload, \LLMS_Order $order, array $attempt ): void {
		$cancelled = $payload->is_cancellation();
		$status    = $cancelled ? PaymentAttempts::STATUS_CANCELLED : PaymentAttempts::STATUS_FAILED;

		if ( ! $this->attempts->set_status( (int) $order->get( 'id' ), $attempt['ref'], $status ) ) {
			return;
		}

		$order->add_note(
			sprintf(
				$cancelled
					/* translators: %s: transaction reference. */
					? __( 'ifthenpay reported the payment as cancelled (ref %s). The order stays pending, so the student can try again.', 'ifthenpay-payments-for-lifterlms' )
					/* translators: %s: transaction reference. */
					: __( 'ifthenpay reported the payment as failed (ref %s). The order stays pending, so the student can try again.', 'ifthenpay-payments-for-lifterlms' ),
				$attempt['ref']
			)
		);
	}

	/**
	 * Sends a plain-text response and exits. Not `wp_die()`: on the frontend it renders a full
	 * HTML screen instead of the plain body ifthenpay expects.
	 *
	 * @param int    $status The HTTP status code.
	 * @param string $body   The plain-text body.
	 * @return never
	 */
	private static function respond( int $status, string $body ): void {
		status_header( $status );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $body );
		exit;
	}
}
