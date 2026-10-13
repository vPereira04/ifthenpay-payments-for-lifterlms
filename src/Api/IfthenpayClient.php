<?php
/**
 * Ifthenpay HTTP API client.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the ifthenpay HTTP API.
 */
final class IfthenpayClient {

	private const API_BASE = 'https://api.ifthenpay.com';

	// The backoffice Type our Gateway Keys are created under, and the cms tag on callback registration.
	public const GATEWAY_TYPE = 'Forminator'; // do not change..
	public const CALLBACK_CMS = 'Forminator'; // do not change..

	// Switching Gateway Keys back and forth re-reads the same rows; a short window keeps that to one call.
	public const GATEWAY_ROWS_TRANSIENT = 'iftp_llms_gateway_rows';
	private const GATEWAY_ROWS_TTL      = 5 * MINUTE_IN_SECONDS;

	// The methods catalog is the same for every ifthenpay account and rarely changes.
	public const CATALOG_TRANSIENT = 'iftp_llms_methods_catalog';
	private const CATALOG_TTL      = 12 * HOUR_IN_SECONDS;

	// Pay by Link answers with a short pinpay.pt link, or an ifthenpay.com one. Subdomains count too.
	public const PAYMENT_HOSTS = array( 'pinpay.pt', 'ifthenpay.com' );

	/**
	 * Why the last payment link request failed, for order notes. Never shown to customers.
	 *
	 * @var string
	 */
	private string $last_error = '';

	/**
	 * Why the last payment link request failed.
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * The backoffice Type for our Gateway Keys. Filterable, in case ifthenpay files LMS keys under another one.
	 */
	public static function gateway_type(): string {
		$type = apply_filters( 'iftp_llms_gateway_type', self::GATEWAY_TYPE );

		return is_string( $type ) && '' !== $type ? $type : self::GATEWAY_TYPE;
	}

	/**
	 * The `cms` tag for callback registration. It follows the filtered Type, lower-cased.
	 */
	public static function callback_cms(): string {
		$type = self::gateway_type();

		return self::GATEWAY_TYPE === $type ? self::CALLBACK_CMS : sanitize_key( $type );
	}

	/**
	 * GET /gateway/get?boKey={key}&Type=LifterLMS — the Gateway Key rows for this Backoffice Key.
	 * Failed or empty lookups aren't cached, so a freshly provisioned key shows up right away.
	 *
	 * @param string $backoffice_key The Backoffice Key.
	 * @param bool   $fresh          Skip the cache, e.g. when connecting.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_gateway_rows( string $backoffice_key, bool $fresh = false ): array {
		if ( '' === $backoffice_key ) {
			return array();
		}

		$key_hash = md5( $backoffice_key );
		$cached   = $fresh ? false : get_transient( self::GATEWAY_ROWS_TRANSIENT );

		if ( is_array( $cached ) && ( $cached['key_hash'] ?? '' ) === $key_hash && ! empty( $cached['rows'] ) && is_array( $cached['rows'] ) ) {
			return $cached['rows'];
		}

		$response = wp_remote_get(
			add_query_arg(
				array(
					'boKey' => rawurlencode( $backoffice_key ),
					'Type'  => rawurlencode( self::gateway_type() ),
				),
				self::API_BASE . '/gateway/get'
			),
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body = $this->decode_response( $response );

		if ( array() === $body ) {
			return array();
		}

		// One gateway comes back as a bare object, several as a list.
		$rows = isset( $body[0] ) ? array_values( array_filter( $body, 'is_array' ) ) : array( $body );

		set_transient(
			self::GATEWAY_ROWS_TRANSIENT,
			array(
				'key_hash' => $key_hash,
				'rows'     => $rows,
			),
			self::GATEWAY_ROWS_TTL
		);

		return $rows;
	}

	/**
	 * Drops the cached Gateway Key rows, e.g. after a disconnect.
	 */
	public function forget_gateway_rows(): void {
		delete_transient( self::GATEWAY_ROWS_TRANSIENT );
	}

	/**
	 * GET /gateway/methods/available — the methods catalog. A failed or empty fetch isn't cached.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_catalog(): array {
		$cached = get_transient( self::CATALOG_TRANSIENT );

		if ( is_array( $cached ) && array() !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get( self::API_BASE . '/gateway/methods/available', array( 'timeout' => 15 ) );

		// An error page can still be JSON, and I don't want to keep that as the catalog for 12 hours.
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$catalog = array_values( array_filter( $this->decode_response( $response ), 'is_array' ) );

		if ( array() !== $catalog ) {
			set_transient( self::CATALOG_TRANSIENT, $catalog, self::CATALOG_TTL );
		}

		return $catalog;
	}

	/**
	 * The method codes in the catalog, upper-cased. Empty when ifthenpay can't be reached.
	 *
	 * @return array<int, string>
	 */
	public function catalog_entities(): array {
		$entities = array();

		foreach ( $this->get_catalog() as $entry ) {
			if ( isset( $entry['Entity'] ) && is_scalar( $entry['Entity'] ) && '' !== trim( (string) $entry['Entity'] ) ) {
				$entities[] = strtoupper( trim( (string) $entry['Entity'] ) );
			}
		}

		return array_values( array_unique( $entities ) );
	}

	/**
	 * POST /gateway/pinpay/{gateway_key} — creates a Pay by Link.
	 *
	 * @param string               $gateway_key The Gateway Key to create the link under.
	 * @param array<string, mixed> $payload     The Pay by Link request body.
	 * @return string The hosted payment page URL, or '' on failure (see get_last_error()).
	 */
	public function create_payment_link( string $gateway_key, array $payload ): string {
		$this->last_error = '';

		$response = wp_remote_post(
			self::API_BASE . '/gateway/pinpay/' . rawurlencode( $gateway_key ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode( $payload ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();

			return '';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = $this->decode_response( $response );
		$url  = $body['RedirectUrl'] ?? $body['redirect_url'] ?? '';

		if ( $code < 200 || $code >= 300 || ! is_string( $url ) || '' === $url ) {
			$this->last_error = sprintf( 'HTTP %d: %s', $code, wp_remote_retrieve_body( $response ) );

			return '';
		}

		if ( ! self::is_payment_url( $url ) ) {
			$this->last_error = sprintf( 'Unexpected payment link host: %s', $url );

			return '';
		}

		return $url;
	}

	/**
	 * POST /endpoint/callback/activation/?cms=lifterlms — (re-)registers the callback URL for a Gateway Key.
	 *
	 * @param string $gateway_key       The Gateway Key.
	 * @param string $anti_phishing_key The key ifthenpay echoes back as `[ANTI_PHISHING_KEY]`.
	 * @param string $callback_url      The URL template ifthenpay calls.
	 */
	public function activate_callback( string $gateway_key, string $anti_phishing_key, string $callback_url ): bool {
		$response = wp_remote_post(
			self::API_BASE . '/endpoint/callback/activation/?cms=' . rawurlencode( self::callback_cms() ),
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'apKey' => $anti_phishing_key,
						'chave' => $gateway_key,
						'urlCb' => $callback_url,
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return $code >= 200 && $code < 300;
	}

	/**
	 * Whether a URL is an https link on one of ifthenpay's payment hosts (subdomains included).
	 *
	 * @param string $url The URL to check.
	 */
	public static function is_payment_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return false;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		foreach ( self::PAYMENT_HOSTS as $allowed ) {
			if ( $host === $allowed || substr( $host, -strlen( '.' . $allowed ) ) === '.' . $allowed ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decodes a wp_remote_* JSON body into an array ([] for anything else).
	 *
	 * @param array<string, mixed> $response The HTTP response.
	 * @return array<mixed>
	 */
	private function decode_response( array $response ): array {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : array();
	}
}
