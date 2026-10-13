<?php
/**
 * Our own settings storage.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything the ifthenpay panel saves. The Backoffice Key lives on its own, the rest of the
 * gateway configuration as one array, and the callback state (anti-phishing key per Gateway Key,
 * fingerprint of the last accepted registration) in two more. None is autoloaded: only the
 * settings screen, checkout and the callback read them.
 *
 * @phpstan-type MethodRow array{entity: string, alias: string, logo: string, logo_dark: string, account: string, position: int, enabled: bool}
 */
final class SettingsRepository {

	public const OPTION_BACKOFFICE_KEY = 'iftp_llms_backoffice_key';
	public const OPTION_CONFIG         = 'iftp_llms_config';
	public const OPTION_APK_KEYS       = 'iftp_llms_anti_phishing_keys';
	public const OPTION_CALLBACK_HASH  = 'iftp_llms_callback_hash';

	public const CURRENCY = 'EUR';

	public const DEFAULT_EXPIRY_DAYS = 3;
	public const MAX_EXPIRY_DAYS     = 365;

	private const ACTIVATION_TRANSIENT_PREFIX = 'iftp_llms_activation_';

	/**
	 * The config array, read once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $config = null;

	/**
	 * The connected Backoffice Key, or ''. Server-side only: never send it to the browser.
	 */
	public function backoffice_key(): string {
		return (string) get_option( self::OPTION_BACKOFFICE_KEY, '' );
	}

	/**
	 * Whether a Backoffice Key is connected.
	 */
	public function is_connected(): bool {
		return '' !== $this->backoffice_key();
	}

	/**
	 * Stores a validated Backoffice Key.
	 *
	 * @param string $key The Backoffice Key.
	 */
	public function save_backoffice_key( string $key ): void {
		update_option( self::OPTION_BACKOFFICE_KEY, $key, false );
	}

	/**
	 * Forgets the Backoffice Key and the gateway configuration. I keep the anti-phishing keys:
	 * links handed out before a disconnect still need them to validate their callback.
	 */
	public function disconnect(): void {
		delete_option( self::OPTION_BACKOFFICE_KEY );
		delete_option( self::OPTION_CONFIG );
		delete_option( self::OPTION_CALLBACK_HASH );

		$this->config = null;
	}

	/**
	 * The selected Gateway Key, or ''.
	 */
	public function gateway_key(): string {
		return (string) ( $this->config()['gateway_key'] ?? '' );
	}

	/**
	 * The methods table for the selected Gateway Key, keyed by entity.
	 *
	 * @return array<string, MethodRow>
	 */
	public function methods(): array {
		$stored  = $this->config()['methods'] ?? array();
		$methods = array();

		if ( ! is_array( $stored ) ) {
			return array();
		}

		// I fill in missing keys, so callers can trust the shape.
		foreach ( $stored as $entity => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$entity             = (string) $entity;
			$methods[ $entity ] = array(
				'entity'    => $entity,
				'alias'     => isset( $row['alias'] ) && is_scalar( $row['alias'] ) ? (string) $row['alias'] : '',
				'logo'      => isset( $row['logo'] ) && is_scalar( $row['logo'] ) ? (string) $row['logo'] : '',
				'logo_dark' => isset( $row['logo_dark'] ) && is_scalar( $row['logo_dark'] ) ? (string) $row['logo_dark'] : '',
				'account'   => isset( $row['account'] ) && is_scalar( $row['account'] ) ? (string) $row['account'] : '',
				'position'  => isset( $row['position'] ) && is_numeric( $row['position'] ) ? (int) $row['position'] : 0,
				'enabled'   => ! empty( $row['enabled'] ),
			);
		}

		return $methods;
	}

	/**
	 * Methods that are provisioned on the Gateway Key and switched on, keyed by entity.
	 *
	 * @return array<string, MethodRow>
	 */
	public function enabled_methods(): array {
		return array_filter(
			$this->methods(),
			static function ( array $method ): bool {
				return $method['enabled'] && '' !== $method['account'];
			}
		);
	}

	/**
	 * The default method's entity, or '' for none (only ever an enabled method).
	 */
	public function default_method(): string {
		$default = (string) ( $this->config()['default_method'] ?? '' );

		return isset( $this->enabled_methods()[ $default ] ) ? $default : '';
	}

	/**
	 * The default method's catalog Position, as the payload's `selected_method` wants it, or ''.
	 */
	public function default_method_position(): string {
		$default = $this->default_method();

		if ( '' === $default ) {
			return '';
		}

		$position = $this->methods()[ $default ]['position'];

		return $position > 0 ? (string) $position : '';
	}

	/**
	 * The text shown on ifthenpay's payment page, as saved ('' means "site name").
	 */
	public function description(): string {
		return (string) ( $this->config()['description'] ?? '' );
	}

	/**
	 * The text to send to ifthenpay, falling back to the site name.
	 */
	public function payment_description(): string {
		if ( '' !== $this->description() ) {
			return $this->description();
		}

		$site_name = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );

		return '' !== $site_name ? $site_name : __( 'Course purchase', 'ifthenpay-payments-for-lifterlms' );
	}

	/**
	 * How many days a payment link stays valid.
	 */
	public function expiry_days(): int {
		$days = (int) ( $this->config()['expiry_days'] ?? self::DEFAULT_EXPIRY_DAYS );

		return $days >= 1 && $days <= self::MAX_EXPIRY_DAYS ? $days : self::DEFAULT_EXPIRY_DAYS;
	}

	/**
	 * Whether a payment link can be created: connected, a Gateway Key, and at least one method on.
	 */
	public function is_ready(): bool {
		return $this->is_connected() && '' !== $this->gateway_key() && array() !== $this->enabled_methods();
	}

	/**
	 * Whether checkout can offer ifthenpay: ready, and LifterLMS sells in euros.
	 */
	public function is_configured(): bool {
		return $this->is_ready() && self::is_eur_store();
	}

	/**
	 * LifterLMS's store currency, upper-cased ('' when LifterLMS isn't loaded).
	 */
	public static function store_currency(): string {
		return function_exists( 'get_lifterlms_currency' ) ? strtoupper( (string) get_lifterlms_currency() ) : '';
	}

	/**
	 * Whether LifterLMS is set to sell in euros, the only currency ifthenpay settles in.
	 */
	public static function is_eur_store(): bool {
		return self::CURRENCY === self::store_currency();
	}

	/**
	 * Switches to a Gateway Key with its freshly built methods table. The default resets
	 * when the key changes, since it may not exist on the new one.
	 *
	 * @param string                   $gateway_key The Gateway Key.
	 * @param array<string, MethodRow> $methods     Its methods table.
	 */
	public function save_gateway( string $gateway_key, array $methods ): void {
		$config = $this->config();

		if ( ( $config['gateway_key'] ?? '' ) !== $gateway_key ) {
			$config['default_method'] = '';
		}

		$config['gateway_key'] = $gateway_key;
		$config['methods']     = $methods;

		$this->write( $config );
	}

	/**
	 * Saves the panel's editable part. Methods only flip `enabled`; their accounts come from ifthenpay.
	 *
	 * @param array<int, string> $enabled_entities Entities the admin switched on.
	 * @param string             $default_method   The starred entity, or ''.
	 * @param string             $description      The payment description.
	 * @param int                $expiry_days      Payment link lifetime in days.
	 */
	public function save_preferences( array $enabled_entities, string $default_method, string $description, int $expiry_days ): void {
		$config  = $this->config();
		$methods = $this->methods();

		foreach ( $methods as $entity => $method ) {
			$methods[ $entity ]['enabled'] = '' !== $method['account'] && in_array( $entity, $enabled_entities, true );
		}

		$config['methods']        = $methods;
		$config['default_method'] = isset( $methods[ $default_method ] ) && $methods[ $default_method ]['enabled'] ? $default_method : '';
		$config['description']    = $description;
		$config['expiry_days']    = max( 1, min( self::MAX_EXPIRY_DAYS, $expiry_days ) );

		$this->write( $config );
	}

	/**
	 * Whether activation of a method was requested in the last 24 hours.
	 *
	 * @param string $gateway_key The Gateway Key.
	 * @param string $entity      The method entity.
	 */
	public function is_activation_requested( string $gateway_key, string $entity ): bool {
		return false !== get_transient( self::activation_transient( $gateway_key, $entity ) );
	}

	/**
	 * Starts the 24-hour cooldown for one method on one Gateway Key.
	 *
	 * @param string $gateway_key The Gateway Key.
	 * @param string $entity      The method entity.
	 */
	public function mark_activation_requested( string $gateway_key, string $entity ): void {
		set_transient( self::activation_transient( $gateway_key, $entity ), 1, DAY_IN_SECONDS );
	}

	/**
	 * The anti-phishing key registered with ifthenpay for a Gateway Key, or '' when none yet.
	 *
	 * @param string $gateway_key The Gateway Key.
	 */
	public function anti_phishing_key( string $gateway_key ): string {
		$keys = get_option( self::OPTION_APK_KEYS, array() );

		return is_array( $keys ) && isset( $keys[ $gateway_key ] ) && is_string( $keys[ $gateway_key ] ) ? $keys[ $gateway_key ] : '';
	}

	/**
	 * Stores the anti-phishing key ifthenpay accepted for a Gateway Key.
	 *
	 * @param string $gateway_key The Gateway Key.
	 * @param string $key         The anti-phishing key.
	 */
	public function set_anti_phishing_key( string $gateway_key, string $key ): void {
		$keys = get_option( self::OPTION_APK_KEYS, array() );
		$keys = is_array( $keys ) ? $keys : array();

		$keys[ $gateway_key ] = $key;

		update_option( self::OPTION_APK_KEYS, $keys, false );
	}

	/**
	 * The fingerprint of the last callback registration ifthenpay accepted, or ''.
	 */
	public function callback_hash(): string {
		return (string) get_option( self::OPTION_CALLBACK_HASH, '' );
	}

	/**
	 * Stores the fingerprint of an accepted registration.
	 *
	 * @param string $hash The fingerprint.
	 */
	public function set_callback_hash( string $hash ): void {
		update_option( self::OPTION_CALLBACK_HASH, $hash, false );
	}

	/**
	 * Deletes every option this class owns, anti-phishing keys included (uninstall only).
	 */
	public function delete_all(): void {
		delete_option( self::OPTION_BACKOFFICE_KEY );
		delete_option( self::OPTION_CONFIG );
		delete_option( self::OPTION_APK_KEYS );
		delete_option( self::OPTION_CALLBACK_HASH );

		$this->config = null;
	}

	/**
	 * The cooldown transient name for a Gateway Key + method.
	 *
	 * @param string $gateway_key The Gateway Key.
	 * @param string $entity      The method entity.
	 */
	private static function activation_transient( string $gateway_key, string $entity ): string {
		return self::ACTIVATION_TRANSIENT_PREFIX . md5( $gateway_key . '|' . strtoupper( $entity ) );
	}

	/**
	 * The stored config array.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		if ( null === $this->config ) {
			$stored       = get_option( self::OPTION_CONFIG, array() );
			$this->config = is_array( $stored ) ? $stored : array();
		}

		return $this->config;
	}

	/**
	 * Writes the config array.
	 *
	 * @param array<string, mixed> $config The config array.
	 */
	private function write( array $config ): void {
		update_option( self::OPTION_CONFIG, $config, false );

		$this->config = $config;
	}
}
