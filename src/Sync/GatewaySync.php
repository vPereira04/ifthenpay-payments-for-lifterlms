<?php
/**
 * Gateway Key + methods sync.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Sync;

use Ifthenpay\LifterLMS\Api\IfthenpayClient;
use Ifthenpay\LifterLMS\Api\IfthenpayPayload;
use Ifthenpay\LifterLMS\Settings\SettingsRepository;
use Ifthenpay\LifterLMS\Webhook\WebhookRegistrar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pulls Gateway Keys and their provisioned methods from ifthenpay into our settings,
 * and keeps the callback registered for whichever Gateway Key is selected.
 */
final class GatewaySync {

	/**
	 * Settings storage.
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
	 * Callback registration.
	 *
	 * @var WebhookRegistrar
	 */
	private WebhookRegistrar $registrar;

	/**
	 * Sets up the sync.
	 *
	 * @param SettingsRepository $settings  Settings storage.
	 * @param IfthenpayClient    $client    Ifthenpay API client.
	 * @param WebhookRegistrar   $registrar Callback registration.
	 */
	public function __construct( SettingsRepository $settings, IfthenpayClient $client, WebhookRegistrar $registrar ) {
		$this->settings  = $settings;
		$this->client    = $client;
		$this->registrar = $registrar;
	}

	/**
	 * Connects a Backoffice Key, but only if ifthenpay returns at least one Gateway Key for it.
	 * A single Gateway Key gets selected right away; with several, the admin picks.
	 *
	 * @param string $backoffice_key The Backoffice Key.
	 */
	public function connect( string $backoffice_key ): bool {
		$rows = $this->client->get_gateway_rows( $backoffice_key, true );

		if ( array() === $rows ) {
			return false;
		}

		$this->settings->save_backoffice_key( $backoffice_key );

		$options     = IfthenpayPayload::gateway_key_options( $rows );
		$gateway_key = $this->settings->gateway_key();

		if ( ! isset( $options[ $gateway_key ] ) ) {
			$gateway_key = 1 === count( $options ) ? (string) array_key_first( $options ) : '';
		}

		$this->apply( $rows, $gateway_key, true );

		return true;
	}

	/**
	 * Switches the Gateway Key ('' clears it).
	 *
	 * @param string $gateway_key The Gateway Key.
	 */
	public function select( string $gateway_key ): bool {
		$rows = $this->client->get_gateway_rows( $this->settings->backoffice_key() );

		if ( '' !== $gateway_key && null === IfthenpayPayload::find_row( $rows, $gateway_key ) ) {
			return false;
		}

		$this->apply( $rows, $gateway_key, true );

		return true;
	}

	/**
	 * Re-reads the selected Gateway Key's methods, so a method ifthenpay activated since shows up.
	 * When ifthenpay can't be reached I keep what's stored.
	 */
	public function refresh(): void {
		$gateway_key = $this->settings->gateway_key();

		if ( '' === $gateway_key ) {
			return;
		}

		$rows = $this->client->get_gateway_rows( $this->settings->backoffice_key() );

		if ( null !== IfthenpayPayload::find_row( $rows, $gateway_key ) ) {
			$this->apply( $rows, $gateway_key, false );
		}
	}

	/**
	 * Gateway Key => label options for the connected Backoffice Key.
	 *
	 * @return array<string, string>
	 */
	public function gateway_key_options(): array {
		if ( ! $this->settings->is_connected() ) {
			return array();
		}

		$options = IfthenpayPayload::gateway_key_options( $this->client->get_gateway_rows( $this->settings->backoffice_key() ) );
		$saved   = $this->settings->gateway_key();

		// If ifthenpay is unreachable right now, I still show what's saved instead of blanking it.
		if ( '' !== $saved && ! isset( $options[ $saved ] ) ) {
			$options[ $saved ] = $saved;
		}

		return $options;
	}

	/**
	 * Stores the Gateway Key with its methods table, and makes sure ifthenpay calls us back for it.
	 * An admin's own connect/switch registers right away; a background refresh waits out the retry lock.
	 *
	 * @param array<int, array<string, mixed>> $rows        Gateway Key rows.
	 * @param string                           $gateway_key The Gateway Key.
	 * @param bool                             $explicit    Whether the admin just asked for this.
	 */
	private function apply( array $rows, string $gateway_key, bool $explicit ): void {
		$row     = IfthenpayPayload::find_row( $rows, $gateway_key );
		$methods = null === $row ? array() : IfthenpayPayload::method_rows( $row, $this->client->get_catalog(), $this->settings->methods() );

		$this->settings->save_gateway( $gateway_key, $methods );

		if ( $explicit && '' !== $gateway_key && ! $this->registrar->is_in_sync( $gateway_key ) ) {
			$this->registrar->register_webhook( $gateway_key );

			return;
		}

		$this->registrar->resync( $gateway_key );
	}
}
