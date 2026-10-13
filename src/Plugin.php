<?php
/**
 * Plugin bootstrap.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS;

use Ifthenpay\LifterLMS\Admin\Notices;
use Ifthenpay\LifterLMS\Admin\OrderDisplay;
use Ifthenpay\LifterLMS\Admin\SettingsPanel;
use Ifthenpay\LifterLMS\Ajax\Controller as AjaxController;
use Ifthenpay\LifterLMS\Api\IfthenpayClient;
use Ifthenpay\LifterLMS\Gateway\IfthenpayGateway;
use Ifthenpay\LifterLMS\Gateway\ReturnHandler;
use Ifthenpay\LifterLMS\Payments\PaymentAttempts;
use Ifthenpay\LifterLMS\Settings\SettingsRepository;
use Ifthenpay\LifterLMS\Sync\GatewaySync;
use Ifthenpay\LifterLMS\Webhook\WebhookController;
use Ifthenpay\LifterLMS\Webhook\WebhookRegistrar;
use Ifthenpay\LifterLMS\Webhook\WebhookValidator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every piece together once, on `plugins_loaded`, and hands out the shared services
 * so each request builds one SettingsRepository / PaymentAttempts / client at most.
 */
final class Plugin {

	/**
	 * Our gateway id inside LifterLMS (settings section, order `payment_gateway`).
	 */
	public const GATEWAY_ID = 'ifthenpay';

	/**
	 * The singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * The admin capability, resolved once per request.
	 *
	 * @var string|null
	 */
	private static ?string $admin_cap = null;

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository|null
	 */
	private ?SettingsRepository $settings = null;

	/**
	 * The payment attempts stored on each order.
	 *
	 * @var PaymentAttempts|null
	 */
	private ?PaymentAttempts $attempts = null;

	/**
	 * Ifthenpay API client.
	 *
	 * @var IfthenpayClient|null
	 */
	private ?IfthenpayClient $client = null;

	/**
	 * Callback registration.
	 *
	 * @var WebhookRegistrar|null
	 */
	private ?WebhookRegistrar $registrar = null;

	/**
	 * Gateway Key + methods sync.
	 *
	 * @var GatewaySync|null
	 */
	private ?GatewaySync $sync = null;

	/**
	 * The singleton instance, created on first access.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Singleton — use instance() instead.
	 */
	private function __construct() {
	}

	/**
	 * Registers every hook.
	 */
	public function boot(): void {
		// LifterLMS builds its gateway registry on init, so the filter has to be in place by then.
		add_filter( 'lifterlms_payment_gateways', array( $this, 'register_gateway' ) );

		// Always on: the rewrite rule must exist on admin requests too, or a permalink flush there drops it.
		( new WebhookController( $this->attempts(), new WebhookValidator( $this->settings(), $this->client() ) ) )->register();

		if ( ! is_admin() ) {
			( new ReturnHandler( $this->attempts() ) )->register();
		}

		// is_admin() covers admin-ajax too.
		if ( is_admin() ) {
			( new AjaxController( $this->settings(), $this->sync(), $this->client() ) )->register();
			( new SettingsPanel() )->register();
			( new Notices() )->register();
		}

		// The order screen box in wp-admin, "Pay now" in My Account.
		( new OrderDisplay() )->register();

		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->registrar()->register();
		}
	}

	/**
	 * Adds our gateway to LifterLMS's list of gateway class names.
	 *
	 * @param mixed $gateways LifterLMS's gateway class names.
	 * @return mixed
	 */
	public function register_gateway( $gateways ) {
		if ( ! is_array( $gateways ) ) {
			return $gateways;
		}

		$gateways[] = IfthenpayGateway::class;

		return $gateways;
	}

	/**
	 * Shared settings storage.
	 */
	public function settings(): SettingsRepository {
		if ( null === $this->settings ) {
			$this->settings = new SettingsRepository();
		}

		return $this->settings;
	}

	/**
	 * The payment attempts stored on each order.
	 */
	public function attempts(): PaymentAttempts {
		if ( null === $this->attempts ) {
			$this->attempts = new PaymentAttempts();
		}

		return $this->attempts;
	}

	/**
	 * Shared ifthenpay API client.
	 */
	public function client(): IfthenpayClient {
		if ( null === $this->client ) {
			$this->client = new IfthenpayClient();
		}

		return $this->client;
	}

	/**
	 * Shared callback registration.
	 */
	public function registrar(): WebhookRegistrar {
		if ( null === $this->registrar ) {
			$this->registrar = new WebhookRegistrar( $this->settings(), $this->client() );
		}

		return $this->registrar;
	}

	/**
	 * Shared Gateway Key + methods sync.
	 */
	public function sync(): GatewaySync {
		if ( null === $this->sync ) {
			$this->sync = new GatewaySync( $this->settings(), $this->client(), $this->registrar() );
		}

		return $this->sync;
	}

	/**
	 * The capability that gates our settings AJAX and order box: LifterLMS's own when some role has it,
	 * else manage_options. One place to change it, and no lock-out if LifterLMS renames it.
	 */
	public static function admin_cap(): string {
		if ( null === self::$admin_cap ) {
			self::$admin_cap = self::capability_exists( 'manage_lifterlms' ) ? 'manage_lifterlms' : 'manage_options';
		}

		return self::$admin_cap;
	}

	/**
	 * Our gateway instance from LifterLMS's registry, or null before init / without LifterLMS.
	 */
	public static function gateway(): ?IfthenpayGateway {
		if ( ! function_exists( 'llms' ) || ! did_action( 'init' ) ) {
			return null;
		}

		$gateway = llms()->payment_gateways()->get_gateway_by_id( self::GATEWAY_ID );

		return $gateway instanceof IfthenpayGateway ? $gateway : null;
	}

	/**
	 * A LifterLMS order by id, or null when it doesn't exist (any more).
	 *
	 * @param int $order_id The `llms_order` post id.
	 */
	public static function get_order( int $order_id ): ?\LLMS_Order {
		if ( $order_id <= 0 || ! function_exists( 'llms_get_post' ) ) {
			return null;
		}

		$order = llms_get_post( $order_id );

		return $order instanceof \LLMS_Order ? $order : null;
	}

	/**
	 * The ?ver= for one of our assets. While debugging I use its mtime, so edits show without a version bump.
	 *
	 * @param string $relative Path from the plugin root, e.g. `assets/js/admin.js`.
	 */
	public static function asset_version( string $relative ): string {
		$debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		$path  = IFTP_LLMS_DIR . $relative;

		return $debug && file_exists( $path ) ? (string) filemtime( $path ) : IFTP_LLMS_VERSION;
	}

	/**
	 * Whether any role grants a capability.
	 *
	 * @param string $capability The capability.
	 */
	private static function capability_exists( string $capability ): bool {
		foreach ( wp_roles()->roles as $role ) {
			if ( ! empty( $role['capabilities'][ $capability ] ) ) {
				return true;
			}
		}

		return false;
	}
}
