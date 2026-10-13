<?php
/**
 * The ifthenpay box and "Pay now" on LifterLMS's own order screens.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Admin;

use Ifthenpay\LifterLMS\Api\IfthenpayClient;
use Ifthenpay\LifterLMS\Methods;
use Ifthenpay\LifterLMS\Payments\PaymentAttempts;
use Ifthenpay\LifterLMS\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds an ifthenpay box to the `llms_order` edit screen (the latest attempt and its link), and a "Pay now" button to the student's My Account › View order page for
 * pending ifthenpay orders. Pay now reuses a still-valid link, or creates a fresh one.
 *
 * @phpstan-import-type Attempt from PaymentAttempts
 */
final class OrderDisplay {

	private const ORDER_POST_TYPE = 'llms_order';
	private const PENDING_STATUS  = 'llms-pending';

	private const ADMIN_HANDLE = 'iftp-llms-order-admin';

	// The Pay now form's fields.
	private const PAY_NOW_FIELD = 'iftp_llms_pay_now';
	private const NONCE_FIELD   = '_iftp_llms_nonce';
	private const NONCE_ACTION  = 'iftp_llms_pay_now_';

	/**
	 * Wires the hooks: the admin box in wp-admin, Pay now on the frontend.
	 */
	public function register(): void {
		if ( is_admin() ) {
			add_action( 'lifterlms_order_meta_box_after_payment_information', array( $this, 'render_admin_box' ), 10, 1 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ), 10, 1 );

			return;
		}

		// After LifterLMS's own actions (priority 10).
		add_action( 'llms_view_order_actions', array( $this, 'render_pay_now' ), 20, 1 );
		add_action( 'template_redirect', array( $this, 'handle_pay_now' ), 10, 0 );
	}

	/**
	 * Styles and the copy button's script, on the order edit screen only.
	 *
	 * @param string $hook_suffix The admin page hook.
	 */
	public function enqueue_admin( $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( 'post.php' !== $hook_suffix || null === $screen || self::ORDER_POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( SettingsPanel::HANDLE, IFTP_LLMS_URL . 'assets/css/admin.css', array(), Plugin::asset_version( 'assets/css/admin.css' ) );

		// No file of its own: a few static lines, with every string read from the button's data attributes.
		wp_register_script( self::ADMIN_HANDLE, false, array(), IFTP_LLMS_VERSION, true );
		wp_enqueue_script( self::ADMIN_HANDLE );
		wp_add_inline_script( self::ADMIN_HANDLE, self::copy_script() );
	}

	/**
	 * The ifthenpay box under LifterLMS's Payment Information.
	 *
	 * @param mixed $order The order being edited.
	 */
	public function render_admin_box( $order ): void {
		if ( ! $order instanceof \LLMS_Order || ! current_user_can( Plugin::admin_cap() ) ) {
			return;
		}

		$rows = Plugin::instance()->attempts()->all( (int) $order->get( 'id' ) );

		if ( array() === $rows && Plugin::GATEWAY_ID !== $order->get( 'payment_gateway' ) ) {
			return;
		}
		?>
		<div class="iftp-llms-order-box">
			<h4><?php esc_html_e( 'ifthenpay', 'ifthenpay-payments-for-lifterlms' ); ?></h4>
			<?php
			if ( array() === $rows ) {
				echo '<p class="iftp-llms-order-box__muted">' . esc_html__( 'No ifthenpay payment link was created for this order yet.', 'ifthenpay-payments-for-lifterlms' ) . '</p>';
			} else {
				$this->render_admin_rows( $rows[0], count( $rows ) - 1 );
			}
			?>
		</div>
		<?php
	}

	/**
	 * The latest attempt's details.
	 *
	 * @param array<string, mixed> $row     The latest attempt.
	 * @param int                  $earlier How many older attempts the order has.
	 *
	 * @phpstan-param Attempt $row
	 */
	private function render_admin_rows( array $row, int $earlier ): void {
		$status     = PaymentAttempts::display_status( $row );
		$is_pending = PaymentAttempts::STATUS_PENDING === $status;
		$has_link   = $is_pending && IfthenpayClient::is_payment_url( $row['payment_url'] );
		?>
		<div class="llms-metabox-field">
			<span class="iftp-llms-order-box__label"><?php esc_html_e( 'Reference:', 'ifthenpay-payments-for-lifterlms' ); ?></span>
			<span class="iftp-llms-order-box__ref"><?php echo esc_html( $row['ref'] ); ?></span>
		</div>

		<div class="llms-metabox-field">
			<span class="iftp-llms-order-box__label"><?php esc_html_e( 'Status:', 'ifthenpay-payments-for-lifterlms' ); ?></span>
			<span class="iftp-llms-order-box__badge iftp-llms-order-box__badge--<?php echo esc_attr( sanitize_html_class( $status ) ); ?>"><?php echo esc_html( self::status_label( $status ) ); ?></span>
		</div>

		<div class="llms-metabox-field">
			<span class="iftp-llms-order-box__label"><?php esc_html_e( 'Method:', 'ifthenpay-payments-for-lifterlms' ); ?></span>
			<?php if ( '' !== $row['method'] ) : ?>
				<span class="iftp-llms-order-box__method">
					<img class="iftp-llms-order-box__logo" src="<?php echo esc_url( Methods::logo_url( $row['method'], Plugin::instance()->settings() ) ); ?>" alt="" />
					<?php echo esc_html( Methods::label( $row['method'] ) ); ?>
				</span>
			<?php else : ?>
				<span class="iftp-llms-order-box__muted"><?php esc_html_e( 'Not chosen yet', 'ifthenpay-payments-for-lifterlms' ); ?></span>
			<?php endif; ?>
		</div>

		<div class="llms-metabox-field">
			<span class="iftp-llms-order-box__label"><?php esc_html_e( 'Request ID:', 'ifthenpay-payments-for-lifterlms' ); ?></span>
			<?php if ( '' !== $row['request_id'] ) : ?>
				<code><?php echo esc_html( $row['request_id'] ); ?></code>
			<?php else : ?>
				<span aria-hidden="true">—</span><span class="screen-reader-text"><?php esc_html_e( 'Not available', 'ifthenpay-payments-for-lifterlms' ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( $has_link ) : ?>
			<div class="llms-metabox-field">
				<span class="iftp-llms-order-box__label"><?php esc_html_e( 'Payment link:', 'ifthenpay-payments-for-lifterlms' ); ?></span>
				<span class="iftp-llms-order-box__actions">
					<a class="button button-small" href="<?php echo esc_url( $row['payment_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open link', 'ifthenpay-payments-for-lifterlms' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'ifthenpay-payments-for-lifterlms' ); ?></span></a>
					<button type="button" class="button button-small" data-iftp-llms-copy="<?php echo esc_url( $row['payment_url'] ); ?>" data-iftp-llms-copied="<?php esc_attr_e( 'Copied', 'ifthenpay-payments-for-lifterlms' ); ?>"><?php esc_html_e( 'Copy link', 'ifthenpay-payments-for-lifterlms' ); ?></button>
					<span class="screen-reader-text" role="status" data-iftp-llms-copy-status></span>
				</span>
			</div>

			<?php if ( $row['expires_at'] > 0 ) : ?>
				<div class="llms-metabox-field">
					<span class="iftp-llms-order-box__label"><?php esc_html_e( 'Link expires:', 'ifthenpay-payments-for-lifterlms' ); ?></span>
					<time datetime="<?php echo esc_attr( gmdate( 'c', $row['expires_at'] ) ); ?>"><?php echo esc_html( (string) wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $row['expires_at'] ) ); ?></time>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $earlier > 0 ) : ?>
			<p class="iftp-llms-order-box__muted">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of older ifthenpay payment attempts for this order. */
						_n( 'This order has %d earlier ifthenpay attempt.', 'This order has %d earlier ifthenpay attempts.', $earlier, 'ifthenpay-payments-for-lifterlms' ),
						$earlier
					)
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * "Pay now" on My Account › View order, for the student's own pending ifthenpay order.
	 *
	 * @param mixed $order The order being viewed.
	 */
	public function render_pay_now( $order ): void {
		if ( ! $order instanceof \LLMS_Order || ! self::can_pay_now( $order ) || ! Plugin::instance()->settings()->is_configured() ) {
			return;
		}

		$order_id = (int) $order->get( 'id' );
		?>
		<form class="iftp-llms-pay-now" method="post" action="<?php echo esc_url( (string) $order->get_view_link() ); ?>">
			<p class="iftp-llms-pay-now__hint"><?php esc_html_e( 'This order is waiting for payment. Continue to ifthenpay to pay for it.', 'ifthenpay-payments-for-lifterlms' ); ?></p>
			<input type="hidden" name="<?php echo esc_attr( self::PAY_NOW_FIELD ); ?>" value="<?php echo esc_attr( (string) $order_id ); ?>" />
			<?php wp_nonce_field( self::NONCE_ACTION . $order_id, self::NONCE_FIELD ); ?>
			<button type="submit" class="llms-button-primary iftp-llms-pay-now__button"><?php esc_html_e( 'Pay now', 'ifthenpay-payments-for-lifterlms' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Handles the Pay now form: off to ifthenpay, or back to the order with a notice.
	 */
	public function handle_pay_now(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only detects our form; the nonce is checked right below.
		if ( 'POST' !== $method || ! isset( $_POST[ self::PAY_NOW_FIELD ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is per order, so I need the id to check it.
		$order_id = absint( wp_unslash( $_POST[ self::PAY_NOW_FIELD ] ) );
		$nonce    = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		$order    = Plugin::get_order( $order_id );

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION . $order_id ) || null === $order || ! self::can_pay_now( $order ) ) {
			// Back to the order only when it's theirs; anyone else lands on the dashboard.
			$own = null !== $order && get_current_user_id() > 0 && (int) $order->get( 'user_id' ) === get_current_user_id();

			self::fail( $own ? $order : null, __( "This order can't be paid right now. Reload the page and try again.", 'ifthenpay-payments-for-lifterlms' ) );
		}

		$gateway = Plugin::gateway();
		$link    = null !== $gateway ? $gateway->create_link_for_order( $order, true ) : new \WP_Error( 'iftp_llms_no_gateway', '' );

		if ( is_wp_error( $link ) || ! IfthenpayClient::is_payment_url( $link['url'] ) ) {
			if ( is_wp_error( $link ) && '' !== $link->get_error_message() ) {
				$order->add_note(
					sprintf(
						/* translators: %s: the error ifthenpay (or the plugin) reported. */
						__( 'Pay now: could not get an ifthenpay payment link: %s', 'ifthenpay-payments-for-lifterlms' ),
						$link->get_error_message()
					)
				);
			}

			self::fail( $order, __( "We couldn't start your ifthenpay payment. Please try again in a moment.", 'ifthenpay-payments-for-lifterlms' ) );
		}

		self::redirect_to_payment( $link['url'] );
	}

	/**
	 * Whether the current user may pay this order with ifthenpay now.
	 *
	 * @param \LLMS_Order $order The order.
	 */
	private static function can_pay_now( \LLMS_Order $order ): bool {
		$user_id = get_current_user_id();

		return $user_id > 0
			&& (int) $order->get( 'user_id' ) === $user_id
			&& self::PENDING_STATUS === $order->get( 'status' )
			&& Plugin::GATEWAY_ID === $order->get( 'payment_gateway' );
	}

	/**
	 * Back to the order (or the dashboard) with an error notice.
	 *
	 * @param \LLMS_Order|null $order   The order, when known.
	 * @param string           $message The notice.
	 * @return never
	 */
	private static function fail( ?\LLMS_Order $order, string $message ): void {
		llms_add_notice( esc_html( $message ), 'error' );

		$url = null !== $order ? (string) $order->get_view_link() : '';

		if ( '' === $url && function_exists( 'llms_get_page_url' ) ) {
			$url = (string) llms_get_page_url( 'myaccount' );
		}

		wp_safe_redirect( '' !== $url ? $url : home_url( '/' ), 303 );
		exit;
	}

	/**
	 * Off to ifthenpay's hosted page, with the allowed hosts widened for this one redirect (as the gateway does).
	 *
	 * @param string $url The Pay by Link URL, already checked by IfthenpayClient::is_payment_url().
	 * @return never
	 */
	private static function redirect_to_payment( string $url ): void {
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
	 * A status's label.
	 *
	 * @param string $status One of PaymentAttempts::STATUS_*.
	 */
	private static function status_label( string $status ): string {
		$labels = array(
			PaymentAttempts::STATUS_PENDING   => __( 'Pending', 'ifthenpay-payments-for-lifterlms' ),
			PaymentAttempts::STATUS_PAID      => __( 'Paid', 'ifthenpay-payments-for-lifterlms' ),
			PaymentAttempts::STATUS_FAILED    => __( 'Failed', 'ifthenpay-payments-for-lifterlms' ),
			PaymentAttempts::STATUS_CANCELLED => __( 'Cancelled', 'ifthenpay-payments-for-lifterlms' ),
			PaymentAttempts::STATUS_EXPIRED   => __( 'Expired', 'ifthenpay-payments-for-lifterlms' ),
		);

		return $labels[ $status ] ?? ucfirst( $status );
	}

	/**
	 * The copy button's script. Static: the URL and the "Copied" text come from data attributes.
	 */
	private static function copy_script(): string {
		return <<<'JS'
( function () {
	'use strict';

	function fallbackCopy( text ) {
		var area = document.createElement( 'textarea' );
		var ok = false;

		area.value = text;
		area.className = 'screen-reader-text';
		area.setAttribute( 'readonly', '' );
		document.body.appendChild( area );
		area.select();

		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}

		document.body.removeChild( area );

		return ok ? Promise.resolve() : Promise.reject();
	}

	document.addEventListener( 'click', function ( e ) {
		var button = e.target && e.target.closest ? e.target.closest( '[data-iftp-llms-copy]' ) : null;
		var text;
		var done;
		var status;

		if ( ! button ) {
			return;
		}

		e.preventDefault();

		if ( button.hasAttribute( 'data-iftp-llms-copying' ) ) {
			return;
		}

		text = button.getAttribute( 'data-iftp-llms-copy' ) || '';
		done = button.getAttribute( 'data-iftp-llms-copied' ) || '';
		status = button.parentNode.querySelector( '[data-iftp-llms-copy-status]' );

		( navigator.clipboard && window.isSecureContext ? navigator.clipboard.writeText( text ) : fallbackCopy( text ) )
			.catch( function () {
				return fallbackCopy( text );
			} )
			.then( function () {
				var label = button.textContent;

				button.setAttribute( 'data-iftp-llms-copying', '' );
				button.textContent = done;

				if ( status ) {
					status.textContent = done;
				}

				window.setTimeout( function () {
					button.textContent = label;
					button.removeAttribute( 'data-iftp-llms-copying' );

					if ( status ) {
						status.textContent = '';
					}
				}, 2000 );
			} )
			.catch( function () {} );
	} );
}() );
JS;
	}
}
