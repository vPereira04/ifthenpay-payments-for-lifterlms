<?php
/**
 * The LifterLMS payment gateway.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Gateway;

use Ifthenpay\LifterLMS\Api\IfthenpayClient;
use Ifthenpay\LifterLMS\Api\IfthenpayPayload;
use Ifthenpay\LifterLMS\Methods;
use Ifthenpay\LifterLMS\Payments\PaymentAttempts;
use Ifthenpay\LifterLMS\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pay by Link from ifthenpay at LifterLMS checkout. Checkout sends the student to ifthenpay's hosted
 * page; the callback (Webhook\WebhookController) records the LifterLMS transaction once paid.
 * One-off plans only, and refunds stay LifterLMS's manual ones. Each link is an attempt stored
 * on the order (Payments\PaymentAttempts); LifterLMS's own order screens are the record.
 *
 * @phpstan-import-type Attempt from PaymentAttempts
 */
class IfthenpayGateway extends \LLMS_Payment_Gateway {

	/**
	 * Our settings panel's field id and custom field type inside LifterLMS's Checkout settings.
	 */
	public const CONTRACT_FIELD = 'iftp_llms_contract';

	/**
	 * Sets up the gateway. LifterLMS instantiates it once, on init, from the class name.
	 */
	public function __construct() {
		$this->id                = Plugin::GATEWAY_ID;
		$this->admin_title       = 'ifthenpay';
		$this->admin_description = __( 'Take one-off payments with ifthenpay Pay by Link: Multibanco, MB WAY, card, Apple Pay, Google Pay and more. Orders complete when ifthenpay confirms the payment.', 'ifthenpay-payments-for-lifterlms' );
		$this->title             = 'ifthenpay';
		$this->description       = __( 'Pay with Multibanco, MB WAY, card, Apple Pay or Google Pay.', 'ifthenpay-payments-for-lifterlms' );

		$this->supports = array(
			'checkout_fields'           => false,
			'cc_save'                   => false,
			'refunds'                   => false,
			'single_payments'           => true,
			'recurring_payments'        => false,
			'recurring_retry'           => false,
			'test_mode'                 => false,
			'modify_recurring_payments' => false,
		);

		$this->admin_order_fields = array(
			'customer'     => false,
			'subscription' => false,
			'source'       => false,
		);

		add_filter( 'llms_get_gateway_settings_fields', array( $this, 'settings_fields' ), 10, 2 );

		// The panel saves through our own AJAX, so LifterLMS's form submit stores nothing for it.
		add_filter( 'llms_update_option_' . self::CONTRACT_FIELD, '__return_null' );
	}

	/**
	 * Appends our settings panel to the ifthenpay section, after LifterLMS's own fields.
	 * Rendering is `lifterlms_admin_field_iftp_llms_contract` (Admin\SettingsPanel).
	 *
	 * @param mixed  $fields     LifterLMS's settings fields for the section.
	 * @param string $gateway_id The gateway whose section this is.
	 * @return mixed
	 */
	public function settings_fields( $fields, $gateway_id ) {
		if ( $this->id !== $gateway_id || ! is_array( $fields ) ) {
			return $fields;
		}

		$fields[] = array(
			'id'   => self::CONTRACT_FIELD,
			'type' => self::CONTRACT_FIELD,
		);

		return $fields;
	}

	/**
	 * Hidden from checkout until ifthenpay can actually take the payment.
	 *
	 * @param \LLMS_Access_Plan|null $plan  The access plan.
	 * @param \LLMS_Order|null       $order The order, when switching source.
	 * @return bool
	 */
	public function can_process_access_plan( $plan, $order = null ) {
		if ( ! Plugin::instance()->settings()->is_configured() ) {
			return false;
		}

		return (bool) parent::can_process_access_plan( $plan, $order );
	}

	/**
	 * The enabled methods' logos, shown next to the gateway at checkout.
	 *
	 * @return string
	 */
	public function get_icon() {
		$html = '';

		foreach ( Plugin::instance()->settings()->enabled_methods() as $method ) {
			if ( '' === $method['logo'] ) {
				continue;
			}

			$html .= '<img class="iftp-llms-method-logo" src="' . esc_url( $method['logo'] ) . '" alt="' . esc_attr( Methods::label( $method['entity'] ) ) . '" height="24" loading="lazy" decoding="async">';
		}

		if ( '' !== $html ) {
			$html = '<span class="iftp-llms-method-logos">' . $html . '</span>';
		}

		return apply_filters( 'llms_get_gateway_icon', $html, $this->id );
	}

	/**
	 * Starts the payment: free orders complete right here, paid ones go to ifthenpay.
	 * LifterLMS ignores the return value, so every path redirects.
	 *
	 * @param \LLMS_Order                  $order  The pending order.
	 * @param \LLMS_Access_Plan            $plan   The access plan.
	 * @param \LLMS_Student|\WP_User|mixed $person The buyer.
	 * @param \LLMS_Coupon|false           $coupon The coupon, if any.
	 * @return void
	 */
	public function handle_pending_order( $order, $plan, $person, $coupon = false ) {
		if ( ! $order instanceof \LLMS_Order || ! $plan instanceof \LLMS_Access_Plan ) {
			return;
		}

		// Free orders (no payment due), exactly as LifterLMS's Manual gateway handles them.
		if ( floatval( 0 ) === $order->get_initial_price( array(), 'float' ) ) {
			if ( $plan->is_free() ) {
				$order->set( 'status', 'llms-completed' );
			} else {
				// Free trial, coupon down to zero etc.: a 0 € transaction so a receipt still goes out.
				$order->record_transaction(
					array(
						'amount'             => floatval( 0 ),
						'source_description' => __( 'Free', 'ifthenpay-payments-for-lifterlms' ),
						'transaction_id'     => uniqid(),
						'status'             => 'llms-txn-succeeded',
						'payment_gateway'    => 'manual',
						'payment_type'       => 'single',
					)
				);
			}

			$this->complete_transaction( $order );

			return;
		}

		$link = $this->create_link_for_order( $order, false );

		if ( is_wp_error( $link ) ) {
			$this->log( 'ifthenpay payment link failed', $link->get_error_code(), $link->get_error_message(), $order->get( 'id' ) );

			$order->add_note(
				sprintf(
					/* translators: %s: the error ifthenpay (or the plugin) reported. */
					__( 'Could not create the ifthenpay payment link: %s', 'ifthenpay-payments-for-lifterlms' ),
					$link->get_error_message()
				)
			);

			llms_add_notice( esc_html__( "We couldn't start your ifthenpay payment. Please try again in a moment, or choose another payment method.", 'ifthenpay-payments-for-lifterlms' ), 'error' );

			$this->redirect_back( $plan );
		}

		$this->redirect_to_payment( $link['url'] );
	}

	/**
	 * A payment link for an order: the current attempt's link when `$reuse` is on and it's still payable,
	 * else a fresh attempt (older pending attempts are superseded). Checkout always asks for a fresh link;
	 * "Pay now" reuses.
	 *
	 * @param \LLMS_Order $order The pending order.
	 * @param bool        $reuse Whether an existing valid link will do.
	 * @return array{ref: string, url: string}|\WP_Error
	 */
	public function create_link_for_order( \LLMS_Order $order, bool $reuse = true ) {
		$plugin   = Plugin::instance();
		$settings = $plugin->settings();
		$attempts = $plugin->attempts();
		$order_id = (int) $order->get( 'id' );

		if ( 'llms-pending' !== $order->get( 'status' ) ) {
			return new \WP_Error( 'iftp_llms_order_not_pending', __( 'This order is not waiting for a payment.', 'ifthenpay-payments-for-lifterlms' ) );
		}

		if ( $reuse ) {
			$current = $attempts->current( $order_id );

			if ( null !== $current && self::is_reusable( $current ) ) {
				return array(
					'ref' => $current['ref'],
					'url' => $current['payment_url'],
				);
			}
		}

		if ( ! $settings->is_configured() ) {
			return new \WP_Error( 'iftp_llms_not_configured', __( 'ifthenpay is not fully configured (Backoffice Key, Gateway Key, an enabled method and a EUR store).', 'ifthenpay-payments-for-lifterlms' ) );
		}

		$amount = number_format( (float) $order->get_price( 'total', array(), 'float' ), 2, '.', '' );

		if ( (float) $amount <= 0 ) {
			return new \WP_Error( 'iftp_llms_invalid_amount', __( 'The order total must be above zero.', 'ifthenpay-payments-for-lifterlms' ) );
		}

		$ref         = PaymentAttempts::new_ref( $order_id );
		$gateway_key = $settings->gateway_key();
		$expiry_days = $settings->expiry_days();
		$payload     = IfthenpayPayload::build(
			$ref,
			$amount,
			$settings->payment_description(),
			IfthenpayPayload::accounts_string( $settings->enabled_methods() ),
			ReturnHandler::url( ReturnHandler::KIND_SUCCESS, $ref ),
			ReturnHandler::url( ReturnHandler::KIND_ERROR, $ref ),
			ReturnHandler::url( ReturnHandler::KIND_CANCEL, $ref ),
			IfthenpayPayload::lang( determine_locale() ),
			$settings->default_method_position(),
			$expiry_days
		);

		$client = $plugin->client();
		$url    = $client->create_payment_link( $gateway_key, $payload );

		if ( '' === $url ) {
			return new \WP_Error( 'iftp_llms_link_failed', '' !== $client->get_last_error() ? $client->get_last_error() : __( 'ifthenpay did not return a payment link.', 'ifthenpay-payments-for-lifterlms' ) );
		}

		$attempts->start( $order_id, $ref, $gateway_key, (float) $amount, $url, PaymentAttempts::expires_at( $expiry_days ) );

		$order->add_note(
			sprintf(
				/* translators: %s: transaction reference. */
				__( 'ifthenpay payment link created (ref %s).', 'ifthenpay-payments-for-lifterlms' ),
				$ref
			)
		);

		return array(
			'ref' => $ref,
			'url' => $url,
		);
	}

	/**
	 * LifterLMS's own "order complete" destination (plan redirect, product, or account page),
	 * for the return handler. The parent method is protected.
	 *
	 * @param \LLMS_Order $order The paid order.
	 */
	public function completion_redirect_url( \LLMS_Order $order ): string {
		return (string) $this->get_complete_transaction_redirect_url( $order );
	}

	/**
	 * Whether an attempt's link can still be paid.
	 *
	 * @param array<string, mixed> $attempt The attempt.
	 * @phpstan-param Attempt $attempt
	 */
	private static function is_reusable( array $attempt ): bool {
		return PaymentAttempts::STATUS_PENDING === PaymentAttempts::display_status( $attempt )
			&& IfthenpayClient::is_payment_url( $attempt['payment_url'] );
	}

	/**
	 * Off to ifthenpay's hosted page. The allowed hosts are widened for this one redirect only.
	 *
	 * @param string $url The Pay by Link URL (already checked against IfthenpayClient::PAYMENT_HOSTS).
	 * @return never
	 */
	private function redirect_to_payment( string $url ): void {
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$allow = static function ( $hosts ) use ( $host ) {
			$hosts   = is_array( $hosts ) ? $hosts : array();
			$hosts[] = $host;

			return $hosts;
		};

		add_filter( 'allowed_redirect_hosts', $allow );
		wp_safe_redirect( $url, 303 );
		remove_filter( 'allowed_redirect_hosts', $allow );
		exit;
	}

	/**
	 * Back to where the student came from, or the plan's checkout, with the notice already queued.
	 *
	 * @param \LLMS_Access_Plan $plan The access plan.
	 * @return never
	 */
	private function redirect_back( \LLMS_Access_Plan $plan ): void {
		$url = wp_get_referer();

		if ( ! is_string( $url ) || '' === $url ) {
			$url = (string) $plan->get_checkout_url( false );
		}

		llms_redirect_and_exit( '' !== $url ? $url : home_url( '/' ) );

		// llms_redirect_and_exit() is pluggable, so I don't rely on an override exiting.
		exit;
	}
}
