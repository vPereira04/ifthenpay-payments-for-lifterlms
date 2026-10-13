<?php
/**
 * Admin AJAX behind the ifthenpay settings panel.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Ajax;

use Ifthenpay\LifterLMS\Api\IfthenpayClient;
use Ifthenpay\LifterLMS\Mail\IfthenpayEmailHelper;
use Ifthenpay\LifterLMS\Methods;
use Ifthenpay\LifterLMS\Plugin;
use Ifthenpay\LifterLMS\Settings\SettingsRepository;
use Ifthenpay\LifterLMS\Sync\GatewaySync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connect/disconnect, Gateway Key switch, preference autosave and activation requests.
 * Every handler checks the panel nonce, then the capability, then sanitizes, then answers.
 * Responses carry the panel state (never the Backoffice Key); the panel can add its rendered
 * fragments through the `iftp_llms_settings_state` filter.
 */
final class Controller {

	/**
	 * Nonce action for every handler here, sent as the `nonce` POST field.
	 */
	public const NONCE_ACTION = 'iftp_llms_settings';

	public const ACTION_CONNECT            = 'iftp_llms_connect';
	public const ACTION_DISCONNECT         = 'iftp_llms_disconnect';
	public const ACTION_SELECT_GATEWAY_KEY = 'iftp_llms_select_gateway_key';
	public const ACTION_SAVE_PREFERENCES   = 'iftp_llms_save_preferences';
	public const ACTION_REQUEST_ACTIVATION = 'iftp_llms_request_activation';
	public const ACTION_GET_METHODS_TABLE  = 'iftp_llms_get_methods_table';

	private const BACKOFFICE_KEY_FORMAT = '/^\d{4}-\d{4}-\d{4}-\d{4}$/';

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Gateway Key + methods sync.
	 *
	 * @var GatewaySync
	 */
	private GatewaySync $sync;

	/**
	 * Ifthenpay API client.
	 *
	 * @var IfthenpayClient
	 */
	private IfthenpayClient $client;

	/**
	 * Sets up the controller.
	 *
	 * @param SettingsRepository $settings Settings storage.
	 * @param GatewaySync        $sync     Gateway Key + methods sync.
	 * @param IfthenpayClient    $client   Ifthenpay API client.
	 */
	public function __construct( SettingsRepository $settings, GatewaySync $sync, IfthenpayClient $client ) {
		$this->settings = $settings;
		$this->sync     = $sync;
		$this->client   = $client;
	}

	/**
	 * Wires the admin-only AJAX actions.
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION_CONNECT, array( $this, 'connect' ) );
		add_action( 'wp_ajax_' . self::ACTION_DISCONNECT, array( $this, 'disconnect' ) );
		add_action( 'wp_ajax_' . self::ACTION_SELECT_GATEWAY_KEY, array( $this, 'select_gateway_key' ) );
		add_action( 'wp_ajax_' . self::ACTION_SAVE_PREFERENCES, array( $this, 'save_preferences' ) );
		add_action( 'wp_ajax_' . self::ACTION_REQUEST_ACTIVATION, array( $this, 'request_activation' ) );
		add_action( 'wp_ajax_' . self::ACTION_GET_METHODS_TABLE, array( $this, 'get_methods_table' ) );
	}

	/**
	 * Validates a Backoffice Key against ifthenpay and stores it. The key is never sent back.
	 */
	public function connect(): void {
		$this->authorize();

		$key = $this->post_text( 'backoffice_key' );

		if ( 1 !== preg_match( self::BACKOFFICE_KEY_FORMAT, $key ) ) {
			wp_send_json_error( array( 'message' => __( "That doesn't look like a Backoffice Key. It has the format 0000-0000-0000-0000.", 'ifthenpay-payments-for-lifterlms' ) ), 400 );
		}

		if ( ! $this->sync->connect( $key ) ) {
			wp_send_json_error( array( 'message' => __( "ifthenpay didn't return any LifterLMS Gateway Key for this Backoffice Key. Check the key, or ask ifthenpay to create a LifterLMS Gateway Key.", 'ifthenpay-payments-for-lifterlms' ) ), 400 );
		}

		wp_send_json_success( $this->state() );
	}

	/**
	 * Forgets the Backoffice Key and configuration; the confirm prompt happens client-side.
	 */
	public function disconnect(): void {
		$this->authorize();

		$this->settings->disconnect();
		$this->client->forget_gateway_rows();

		wp_send_json_success( $this->state() );
	}

	/**
	 * Switches the Gateway Key (registering the callback for it) and answers with its methods table.
	 */
	public function select_gateway_key(): void {
		$this->authorize();
		$this->require_connection();

		if ( ! $this->sync->select( $this->post_text( 'gateway_key' ) ) ) {
			wp_send_json_error( array( 'message' => __( "That Gateway Key isn't available for your Backoffice Key anymore. Reload the page and try again.", 'ifthenpay-payments-for-lifterlms' ) ), 400 );
		}

		wp_send_json_success( $this->state() );
	}

	/**
	 * Autosave: enabled methods, the starred default, description and expiry days.
	 */
	public function save_preferences(): void {
		$this->authorize();
		$this->require_connection();

		$this->settings->save_preferences(
			array_map( 'strtoupper', $this->post_list( 'enabled' ) ),
			strtoupper( $this->post_text( 'default_method' ) ),
			$this->post_text( 'description' ),
			absint( $this->post_text( 'expiry_days', (string) SettingsRepository::DEFAULT_EXPIRY_DAYS ) )
		);

		wp_send_json_success(
			array(
				'ready'         => $this->settings->is_ready(),
				'configured'    => $this->settings->is_configured(),
				'defaultMethod' => $this->settings->default_method(),
			)
		);
	}

	/**
	 * Re-reads the selected Gateway Key's methods (newly activated ones show up) and answers with the state.
	 */
	public function get_methods_table(): void {
		$this->authorize();
		$this->require_connection();

		$this->sync->refresh();

		wp_send_json_success( $this->state() );
	}

	/**
	 * Emails ifthenpay support to provision a method, with a 24-hour cooldown per Gateway Key + method.
	 * The Backoffice Key goes into the email server-side; it never travels through the browser.
	 */
	public function request_activation(): void {
		$this->authorize();
		$this->require_connection();

		$gateway_key = $this->settings->gateway_key();
		$entity      = strtoupper( $this->post_text( 'entity' ) );

		if ( '' === $gateway_key || ! isset( $this->settings->methods()[ $entity ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose a Gateway Key and a payment method first.', 'ifthenpay-payments-for-lifterlms' ) ), 400 );
		}

		if ( $this->settings->is_activation_requested( $gateway_key, $entity ) ) {
			wp_send_json_error( array( 'message' => __( 'Activation for this method was already requested. Please wait 24 hours before asking again.', 'ifthenpay-payments-for-lifterlms' ) ), 429 );
		}

		$sent = IfthenpayEmailHelper::send_activation_email(
			array(
				'gateway_key'       => $gateway_key,
				'entity'            => $entity,
				'backoffice_key'    => $this->settings->backoffice_key(),
				'customer_email'    => (string) wp_get_current_user()->user_email,
				'site_url'          => home_url( '/' ),
				'site_name'         => (string) get_bloginfo( 'name' ),
				'wp_version'        => (string) get_bloginfo( 'version' ),
				'lifterlms_version' => defined( 'LLMS_VERSION' ) ? (string) LLMS_VERSION : '',
				'plugin_version'    => IFTP_LLMS_VERSION,
			)
		);

		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'The activation request could not be sent. Please try again.', 'ifthenpay-payments-for-lifterlms' ) ), 500 );
		}

		// Only after a successful send, so a mail failure can be retried right away.
		$this->settings->mark_activation_requested( $gateway_key, $entity );

		wp_send_json_success();
	}

	/**
	 * Everything the panel needs to redraw itself. Never includes the Backoffice Key.
	 *
	 * @return array<string, mixed>
	 */
	private function state(): array {
		$gateway_key = $this->settings->gateway_key();
		$default     = $this->settings->default_method();
		$methods     = array();

		foreach ( $this->settings->methods() as $entity => $method ) {
			$methods[] = array(
				'entity'              => $entity,
				'label'               => '' !== $method['alias'] ? $method['alias'] : Methods::label( $entity ),
				'logo'                => $method['logo'],
				'logoDark'            => $method['logo_dark'],
				'account'             => $method['account'],
				'provisioned'         => '' !== $method['account'],
				'enabled'             => $method['enabled'] && '' !== $method['account'],
				'isDefault'           => $entity === $default,
				'activationRequested' => '' !== $gateway_key && $this->settings->is_activation_requested( $gateway_key, $entity ),
			);
		}

		$state = array(
			'connected'     => $this->settings->is_connected(),
			'ready'         => $this->settings->is_ready(),
			'configured'    => $this->settings->is_configured(),
			'eurStore'      => SettingsRepository::is_eur_store(),
			'gatewayKey'    => $gateway_key,
			'gatewayKeys'   => $this->sync->gateway_key_options(),
			'methods'       => $methods,
			'defaultMethod' => $default,
			'description'   => $this->settings->description(),
			'expiryDays'    => $this->settings->expiry_days(),
		);

		/**
		 * Lets the settings panel add its re-rendered fragments to every response.
		 *
		 * @param array<string, mixed> $state The panel state.
		 */
		$filtered = apply_filters( 'iftp_llms_settings_state', $state );

		return is_array( $filtered ) ? $filtered : $state;
	}

	/**
	 * Nonce, then capability, or a JSON error.
	 */
	private function authorize(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( Plugin::admin_cap() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'ifthenpay-payments-for-lifterlms' ) ), 403 );
		}
	}

	/**
	 * Stops when no Backoffice Key is connected.
	 */
	private function require_connection(): void {
		if ( ! $this->settings->is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'Connect your Backoffice Key first.', 'ifthenpay-payments-for-lifterlms' ) ), 400 );
		}
	}

	/**
	 * A single-line POST value.
	 *
	 * @param string $key      The POST key.
	 * @param string $fallback Returned when the key is missing or not a string.
	 */
	private function post_text( string $key, string $fallback = '' ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every handler runs authorize() first.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $fallback;
	}

	/**
	 * A POST array of keys (method codes), sanitized. sanitize_text_field() turns nested arrays into ''.
	 *
	 * @param string $key The POST key.
	 * @return array<int, string>
	 */
	private function post_list( string $key ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every handler runs authorize() first.
		if ( ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every handler runs authorize() first.
		$values = array_map( 'sanitize_key', array_map( 'sanitize_text_field', wp_unslash( $_POST[ $key ] ) ) );

		return array_values(
			array_filter(
				$values,
				static function ( string $value ): bool {
					return '' !== $value;
				}
			)
		);
	}
}
