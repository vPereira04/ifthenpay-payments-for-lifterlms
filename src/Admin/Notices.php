<?php
/**
 * Setup warnings on our screens.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Admin;

use Ifthenpay\LifterLMS\Plugin;
use Ifthenpay\LifterLMS\Settings\SettingsRepository;
use Ifthenpay\LifterLMS\Webhook\WebhookRegistrar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flags what keeps ifthenpay off LifterLMS's checkout: a non-euro store, a connection that isn't
 * finished, or a callback ifthenpay hasn't accepted. Only on our settings section and the Payments
 * screen; the callback re-registration itself runs in WebhookRegistrar on admin_init, before this.
 */
final class Notices {

	/**
	 * Settings storage.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Callback registration.
	 *
	 * @var WebhookRegistrar
	 */
	private WebhookRegistrar $registrar;

	/**
	 * Takes the shared services from the plugin.
	 */
	public function __construct() {
		$this->settings  = Plugin::instance()->settings();
		$this->registrar = Plugin::instance()->registrar();
	}

	/**
	 * Wires the hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'queue' ), 1, 0 );
	}

	/**
	 * Decides once which notices this screen gets, each printed by its own closure.
	 */
	public function queue(): void {
		if ( ! SettingsPanel::is_settings_screen() ) {
			return;
		}

		$connected = $this->settings->is_connected();

		// On our section the panel's readiness row already says this once the rest is set up.
		if ( ! SettingsRepository::is_eur_store() && ! $this->settings->is_ready() ) {
			add_action(
				'admin_notices',
				static function (): void {
					$currency = SettingsRepository::store_currency();

					wp_admin_notice(
						sprintf(
							/* translators: 1: the store currency code, e.g. USD. 2: link to LifterLMS's Checkout Settings. */
							esc_html__( 'ifthenpay only takes payments in euros, and your LifterLMS currency is %1$s, so students won\'t see ifthenpay at checkout. Change the currency to Euro in %2$s.', 'ifthenpay-payments-for-lifterlms' ),
							esc_html( '' !== $currency ? $currency : '?' ),
							'<a href="' . esc_url( SettingsPanel::checkout_settings_url() ) . '">' . esc_html__( 'Checkout Settings', 'ifthenpay-payments-for-lifterlms' ) . '</a>'
						),
						array( 'type' => 'warning' )
					);
				},
				10,
				0
			);
		}

		$gateway_key = $this->settings->gateway_key();

		if ( $connected && '' !== $gateway_key && ! $this->registrar->is_in_sync( $gateway_key ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					wp_admin_notice(
						esc_html__( 'ifthenpay couldn\'t register this site\'s callback URL yet, so paid orders won\'t complete automatically. This page retries at most every 15 minutes. If it keeps failing, contact ifthenpay support.', 'ifthenpay-payments-for-lifterlms' ),
						array( 'type' => 'error' )
					);
				},
				10,
				0
			);
		}
	}
}
