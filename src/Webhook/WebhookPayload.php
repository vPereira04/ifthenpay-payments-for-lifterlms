<?php
/**
 * Parsed inbound callback payload.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ifthenpay callback's `$_GET` params, parsed into one typed shape. Paid callbacks carry
 * `apk`, `val`, `mtd` and `req`; failure notices carry `apk` and `status`.
 */
final class WebhookPayload {

	// Base64 alphabet. A '+' that arrived unencoded reads as a space, so spaces pass and get restored.
	private const APK_PATTERN = '#^[A-Za-z0-9+/= ]+$#';

	/**
	 * The transaction reference, from the callback URL's path.
	 *
	 * @var string
	 */
	private string $ref;

	/**
	 * The anti-phishing key ifthenpay echoed back.
	 *
	 * @var string
	 */
	private string $apk;

	/**
	 * The amount ifthenpay says was paid.
	 *
	 * @var float
	 */
	private float $amount;

	/**
	 * The method code as sent (upper-cased, alphanumerics only).
	 *
	 * @var string
	 */
	private string $method;

	/**
	 * Ifthenpay's request id for the payment.
	 *
	 * @var string
	 */
	private string $request_id;

	/**
	 * The failure status ('' on a paid callback).
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Use from_request().
	 *
	 * @param string $ref        The transaction reference.
	 * @param string $apk        The anti-phishing key.
	 * @param float  $amount     The paid amount.
	 * @param string $method     The method code.
	 * @param string $request_id Ifthenpay's request id.
	 * @param string $status     The failure status, or ''.
	 */
	private function __construct( string $ref, string $apk, float $amount, string $method, string $request_id, string $status ) {
		$this->ref        = $ref;
		$this->apk        = $apk;
		$this->amount     = $amount;
		$this->method     = $method;
		$this->request_id = $request_id;
		$this->status     = $status;
	}

	/**
	 * Builds a payload from the ref the rewrite rule captured plus the current request's `$_GET`.
	 *
	 * @param string $ref The transaction reference.
	 */
	public static function from_request( string $ref ): self {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- server-to-server callback from ifthenpay; WebhookValidator checks the anti-phishing key instead.
		$apk    = filter_var( wp_unslash( $_GET['apk'] ?? '' ), FILTER_VALIDATE_REGEXP, array( 'options' => array( 'regexp' => self::APK_PATTERN ) ) );
		$amount = sanitize_text_field( wp_unslash( (string) ( $_GET['val'] ?? '' ) ) );
		$method = sanitize_text_field( wp_unslash( (string) ( $_GET['mtd'] ?? '' ) ) );
		$req    = sanitize_text_field( wp_unslash( (string) ( $_GET['req'] ?? '' ) ) );
		$status = sanitize_key( wp_unslash( (string) ( $_GET['status'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return new self(
			$ref,
			is_string( $apk ) ? str_replace( ' ', '+', $apk ) : '',
			(float) str_replace( ',', '.', $amount ),
			strtoupper( (string) preg_replace( '/[^A-Za-z0-9_ -]/', '', $method ) ),
			$req,
			$status
		);
	}

	/**
	 * The transaction reference.
	 */
	public function ref(): string {
		return $this->ref;
	}

	/**
	 * The anti-phishing key ifthenpay echoed back.
	 */
	public function apk(): string {
		return $this->apk;
	}

	/**
	 * The paid amount.
	 */
	public function amount(): float {
		return $this->amount;
	}

	/**
	 * The method code, as sent.
	 */
	public function method(): string {
		return $this->method;
	}

	/**
	 * Ifthenpay's request id.
	 */
	public function request_id(): string {
		return $this->request_id;
	}

	/**
	 * The failure status, or '' on a paid callback.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Whether this is a failure notice rather than a paid callback.
	 */
	public function is_failure(): bool {
		return '' !== $this->status;
	}

	/**
	 * Whether the failure notice means the payer cancelled (as opposed to an error).
	 */
	public function is_cancellation(): bool {
		return in_array( $this->status, array( 'cancel', 'cancelled', 'canceled' ), true );
	}
}
