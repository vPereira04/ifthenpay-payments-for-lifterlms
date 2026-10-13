<?php
/**
 * Callback anti-phishing, amount and method checks.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Webhook;

use Ifthenpay\LifterLMS\Api\IfthenpayClient;
use Ifthenpay\LifterLMS\Methods;
use Ifthenpay\LifterLMS\Payments\PaymentAttempts;
use Ifthenpay\LifterLMS\Settings\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks an inbound callback against the stored attempt it claims to resolve.
 *
 * @phpstan-import-type Attempt from PaymentAttempts
 */
final class WebhookValidator {

	private const AMOUNT_TOLERANCE = 0.01;

	/**
	 * Settings storage, holding the anti-phishing key per Gateway Key.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Ifthenpay API client, for the methods catalog.
	 *
	 * @var IfthenpayClient
	 */
	private IfthenpayClient $client;

	/**
	 * Sets up the validator.
	 *
	 * @param SettingsRepository $settings Settings storage.
	 * @param IfthenpayClient    $client   Ifthenpay API client.
	 */
	public function __construct( SettingsRepository $settings, IfthenpayClient $client ) {
		$this->settings = $settings;
		$this->client   = $client;
	}

	/**
	 * Whether the callback's anti-phishing key matches the one registered for the attempt's Gateway Key.
	 * Runs on every callback, failure notices included, before anything branches on status.
	 *
	 * @param WebhookPayload       $payload The parsed callback.
	 * @param array<string, mixed> $record  The stored attempt.
	 * @phpstan-param Attempt $record
	 */
	public function key_matches( WebhookPayload $payload, array $record ): bool {
		$gateway_key = $record['gateway_key'];

		// A placeholder key never came from ifthenpay, so there's nothing to verify against.
		if ( '' === $gateway_key || 'manual' === $gateway_key ) {
			return false;
		}

		$expected = $this->settings->anti_phishing_key( $gateway_key );

		// No stored key means nothing to verify against, so I refuse rather than guess.
		// WebhookRegistrar re-registers a key on the next visit to our settings section.
		return '' !== $expected && '' !== $payload->apk() && hash_equals( $expected, $payload->apk() );
	}

	/**
	 * Whether the paid amount is the attempt's amount, give or take a cent.
	 *
	 * @param WebhookPayload       $payload The parsed callback.
	 * @param array<string, mixed> $record  The stored attempt.
	 * @phpstan-param Attempt $record
	 */
	public function amount_matches( WebhookPayload $payload, array $record ): bool {
		return abs( $payload->amount() - $record['amount'] ) <= self::AMOUNT_TOLERANCE;
	}

	/**
	 * Whether the method is one ifthenpay's catalog (or our own list) knows, or a numeric entity.
	 *
	 * @param WebhookPayload $payload The parsed callback.
	 */
	public function method_allowed( WebhookPayload $payload ): bool {
		$method = trim( $payload->method() );

		if ( '' === $method ) {
			return false;
		}

		if ( ctype_digit( $method ) || Methods::is_known( $method ) ) {
			return true;
		}

		return in_array( $method, $this->client->catalog_entities(), true );
	}
}
