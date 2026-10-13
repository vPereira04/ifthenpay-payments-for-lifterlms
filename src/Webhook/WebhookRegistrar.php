<?php
/**
 * Callback registration with ifthenpay.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Webhook;

use Ifthenpay\LifterLMS\Api\IfthenpayClient;
use Ifthenpay\LifterLMS\Plugin;
use Ifthenpay\LifterLMS\Settings\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers this site's callback URL for the selected Gateway Key, and re-registers it when
 * the key or the URL drifts (permalink change, domain move), since ifthenpay keeps calling the old one.
 */
final class WebhookRegistrar {

	// Throttles automatic retries while ifthenpay keeps rejecting them.
	public const RETRY_LOCK = 'iftp_llms_callback_retry_lock';

	/**
	 * The daily drift check, so a migrated site heals without an admin visit.
	 */
	public const CRON_HOOK = 'iftp_llms_resync_callback';

	private const RETRY_WAIT = 15 * MINUTE_IN_SECONDS;

	/**
	 * Settings storage, holding the anti-phishing keys and the registration fingerprint.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Ifthenpay API client.
	 *
	 * @var IfthenpayClient
	 */
	private IfthenpayClient $client;

	/**
	 * Sets up the registrar.
	 *
	 * @param SettingsRepository $settings Settings storage.
	 * @param IfthenpayClient    $client   Ifthenpay API client.
	 */
	public function __construct( SettingsRepository $settings, IfthenpayClient $client ) {
		$this->settings = $settings;
		$this->client   = $client;
	}

	/**
	 * Wires up the drift check: on our own admin screens, and on the daily cron so a migrated
	 * site heals without an admin visit.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_resync' ) );
		add_action( self::CRON_HOOK, array( $this, 'resync_if_drifted' ) );

		// Activation schedules it too; this covers a site that was already active.
		add_action( 'admin_init', array( self::class, 'schedule' ) );
	}

	/**
	 * Schedules the daily drift check, unless it already is.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Removes the daily drift check.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Whether ifthenpay already has the current URL and a key for this Gateway Key.
	 *
	 * @param string $gateway_key The Gateway Key.
	 */
	public function is_in_sync( string $gateway_key ): bool {
		return self::fingerprint( $gateway_key ) === $this->settings->callback_hash()
			&& '' !== $this->settings->anti_phishing_key( $gateway_key );
	}

	/**
	 * Registers the callback now. I reuse an existing anti-phishing key, since rotating it
	 * would break callbacks for payment links already handed out.
	 *
	 * @param string $gateway_key The Gateway Key.
	 */
	public function register_webhook( string $gateway_key ): bool {
		if ( '' === $gateway_key ) {
			return false;
		}

		$key    = $this->settings->anti_phishing_key( $gateway_key );
		$is_new = '' === $key;

		if ( $is_new ) {
			$key = wp_generate_password( 32, false, false );
		}

		if ( ! $this->client->activate_callback( $gateway_key, $key, WebhookController::url() ) ) {
			set_transient( self::RETRY_LOCK, 1, self::RETRY_WAIT );

			return false;
		}

		// Only stored once ifthenpay accepted it; until then it keeps calling back with the old key.
		if ( $is_new ) {
			$this->settings->set_anti_phishing_key( $gateway_key, $key );
		}

		$this->settings->set_callback_hash( self::fingerprint( $gateway_key ) );
		delete_transient( self::RETRY_LOCK );

		return true;
	}

	/**
	 * Re-registers when out of sync, at most once per retry window. Just a hash compare on the happy path.
	 *
	 * @param string $gateway_key The Gateway Key.
	 */
	public function resync( string $gateway_key ): void {
		if ( '' === $gateway_key || $this->is_in_sync( $gateway_key ) || false !== get_transient( self::RETRY_LOCK ) ) {
			return;
		}

		$this->register_webhook( $gateway_key );
	}

	/**
	 * The drift check for the selected Gateway Key (cron entry point).
	 */
	public function resync_if_drifted(): void {
		if ( $this->settings->is_connected() ) {
			$this->resync( $this->settings->gateway_key() );
		}
	}

	/**
	 * Runs the drift check on our settings section only, not on every wp-admin page.
	 */
	public function maybe_resync(): void {
		// The settings AJAX handlers register on their own.
		if ( wp_doing_ajax() || ! current_user_can( Plugin::admin_cap() ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only picks which screens run the check.
		$page    = sanitize_key( wp_unslash( (string) ( $_GET['page'] ?? '' ) ) );
		$section = sanitize_key( wp_unslash( (string) ( $_GET['section'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'llms-settings' === $page && Plugin::GATEWAY_ID === $section ) {
			$this->resync_if_drifted();
		}
	}

	/**
	 * A hash rather than the URL itself, so a migration's search-replace can't hide the drift.
	 *
	 * @param string $gateway_key The Gateway Key.
	 */
	private static function fingerprint( string $gateway_key ): string {
		return md5( $gateway_key . '|' . WebhookController::url() );
	}
}
