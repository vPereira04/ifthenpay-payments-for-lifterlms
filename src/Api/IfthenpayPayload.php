<?php
/**
 * Pure helpers for ifthenpay data.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Api;

use Ifthenpay\LifterLMS\Settings\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns Gateway Key rows and the methods catalog into the methods table, and builds the Pay by Link body.
 *
 * @phpstan-import-type MethodRow from SettingsRepository
 */
final class IfthenpayPayload {

	/**
	 * Static-only helper.
	 */
	private function __construct() {
	}

	/**
	 * Formats an amount the way ifthenpay expects: two decimals, a dot, no thousands separator.
	 *
	 * @param float $amount The amount.
	 */
	public static function format_amount( float $amount ): string {
		return number_format( $amount, 2, '.', '' );
	}

	/**
	 * The Pay by Link request body.
	 *
	 * @param string $id              Our reference, echoed back as `[ORDER_ID]` on the callback.
	 * @param string $amount          Already formatted with format_amount().
	 * @param string $description     The payer-facing description.
	 * @param string $accounts        `ENTITY|ACCOUNT;…` from accounts_string().
	 * @param string $success_url     Return URL on success.
	 * @param string $error_url       Return URL on error.
	 * @param string $cancel_url      Return URL on cancel.
	 * @param string $lang            One of ifthenpay's page languages, see lang().
	 * @param string $selected_method The default method's catalog Position, or '' for none.
	 * @param int    $expiry_days     Days the link stays payable, or 0 for ifthenpay's default.
	 * @return array<string, string>
	 */
	public static function build( string $id, string $amount, string $description, string $accounts, string $success_url, string $error_url, string $cancel_url, string $lang, string $selected_method = '', int $expiry_days = 0 ): array {
		$payload = array(
			'id'          => $id,
			'amount'      => $amount,
			'description' => $description,
			'accounts'    => $accounts,
			'success_url' => $success_url,
			'error_url'   => $error_url,
			'cancel_url'  => $cancel_url,
			'otp'         => 'true',
			'lang'        => $lang,
		);

		if ( '' !== $selected_method ) {
			$payload['selected_method'] = $selected_method;
		}

		// wp_date() formats in the site's timezone, which is the day the merchant means.
		if ( $expiry_days > 0 ) {
			$payload['expiredate'] = (string) wp_date( 'Ymd', time() + $expiry_days * DAY_IN_SECONDS );
		}

		return $payload;
	}

	/**
	 * The Gateway Key value of a row, whichever key name the API used.
	 *
	 * @param array<string, mixed> $row A Gateway Key row.
	 */
	public static function gateway_key_of( array $row ): string {
		$key = $row['GatewayKey'] ?? $row['gatewayKey'] ?? $row['Chave'] ?? '';

		return is_scalar( $key ) ? trim( (string) $key ) : '';
	}

	/**
	 * Gateway Key => label options for the select, e.g. "My key (ABCD-123456)".
	 *
	 * @param array<int, array<string, mixed>> $rows Gateway Key rows.
	 * @return array<string, string>
	 */
	public static function gateway_key_options( array $rows ): array {
		$options = array();

		foreach ( $rows as $row ) {
			$key = self::gateway_key_of( $row );

			if ( '' === $key ) {
				continue;
			}

			$alias = isset( $row['Alias'] ) && is_scalar( $row['Alias'] ) ? trim( (string) $row['Alias'] ) : '';

			$options[ $key ] = '' !== $alias && $alias !== $key ? $alias . ' (' . $key . ')' : $key;
		}

		return $options;
	}

	/**
	 * The row for a Gateway Key, or null when it isn't in the list.
	 *
	 * @param array<int, array<string, mixed>> $rows        Gateway Key rows.
	 * @param string                           $gateway_key The Gateway Key to find.
	 * @return array<string, mixed>|null
	 */
	public static function find_row( array $rows, string $gateway_key ): ?array {
		foreach ( $rows as $row ) {
			if ( '' !== $gateway_key && self::gateway_key_of( $row ) === $gateway_key ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * The methods table for a Gateway Key row: every visible catalog method, with the account
	 * ifthenpay provisioned on the row ('' when it isn't) and the admin's previous `enabled` choice.
	 *
	 * @param array<string, mixed>             $row      The Gateway Key row.
	 * @param array<int, array<string, mixed>> $catalog  The methods catalog.
	 * @param array<string, MethodRow>         $previous The table saved before, to carry `enabled` over.
	 * @return array<string, MethodRow>
	 */
	public static function method_rows( array $row, array $catalog, array $previous ): array {
		$methods = array();

		foreach ( $catalog as $entry ) {
			$entity = self::text( $entry, 'Entity' );

			if ( '' === $entity || empty( $entry['IsVisible'] ) ) {
				continue;
			}

			$entity  = strtoupper( $entity );
			$account = self::account( $row, $entity );
			$logo    = self::text( $entry, 'SmallImageUrl' );

			// A position is only useful as `selected_method` when ifthenpay lets that method be pre-selected.
			$can_preselect = ! array_key_exists( 'AllowSelectedMethod', $entry ) || ! empty( $entry['AllowSelectedMethod'] );

			$methods[ $entity ] = array(
				'entity'    => $entity,
				'alias'     => self::text( $entry, 'Method' ),
				'logo'      => '' !== $logo ? $logo : self::text( $entry, 'ImageUrl' ),
				'logo_dark' => self::text( $entry, 'SmallImageUrlDark' ),
				'account'   => $account,
				'position'  => $can_preselect && isset( $entry['Position'] ) && is_numeric( $entry['Position'] ) ? (int) $entry['Position'] : 0,
				'enabled'   => '' !== $account && ( $previous[ $entity ]['enabled'] ?? true ),
			);
		}

		return $methods;
	}

	/**
	 * The `accounts` request field: `ENTITY|ACCOUNT;ENTITY2|ACCOUNT2`.
	 *
	 * @param array<string, MethodRow> $methods Enabled methods.
	 */
	public static function accounts_string( array $methods ): string {
		return implode( ';', array_filter( array_column( $methods, 'account' ) ) );
	}

	/**
	 * Maps a WordPress locale to one of ifthenpay's page languages.
	 *
	 * @param string $locale A WordPress locale, e.g. `pt_PT`.
	 */
	public static function lang( string $locale ): string {
		$prefix = strtolower( substr( $locale, 0, 2 ) );

		return in_array( $prefix, array( 'pt', 'es', 'fr' ), true ) ? $prefix : 'en';
	}

	/**
	 * A method's `ENTITY|ACCOUNT` from the row, or ''. The API pads the pipe ("MBWAY |1234"),
	 * and Multibanco's column is "Multibanco", not its code "MB" like every other method.
	 *
	 * @param array<string, mixed> $row    The Gateway Key row.
	 * @param string               $entity The method code.
	 */
	private static function account( array $row, string $entity ): string {
		$value = $row[ 'MB' === $entity ? 'Multibanco' : $entity ] ?? '';

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		return implode( '|', array_map( 'trim', explode( '|', $value, 2 ) ) );
	}

	/**
	 * A scalar field of an API array as a trimmed string ('' otherwise).
	 *
	 * @param array<string, mixed> $data The API array.
	 * @param string               $key  The field.
	 */
	private static function text( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? trim( (string) $data[ $key ] ) : '';
	}
}
