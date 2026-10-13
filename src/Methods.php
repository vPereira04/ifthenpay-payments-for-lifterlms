<?php
/**
 * Payment method codes, labels and logos.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS;

use Ifthenpay\LifterLMS\Settings\SettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ifthenpay method codes we know, their human labels, and where their logos come from.
 */
final class Methods {

	public const MB      = 'MB';
	public const MBWAY   = 'MBWAY';
	public const PAYSHOP = 'PAYSHOP';
	public const CCARD   = 'CCARD';
	public const GOOGLE  = 'GOOGLE';
	public const APPLE   = 'APPLE';
	public const PIX     = 'PIX';
	public const COFIDIS = 'COFIDIS';

	public const CODES = array(
		self::MB,
		self::MBWAY,
		self::PAYSHOP,
		self::CCARD,
		self::GOOGLE,
		self::APPLE,
		self::PIX,
		self::COFIDIS,
	);

	// Spellings ifthenpay (or older rows) use for the same method.
	private const ALIASES = array(
		'MULTIBANCO'  => self::MB,
		'MB WAY'      => self::MBWAY,
		'MB_WAY'      => self::MBWAY,
		'CARD'        => self::CCARD,
		'CREDITCARD'  => self::CCARD,
		'GOOGLEPAY'   => self::GOOGLE,
		'GOOGLE PAY'  => self::GOOGLE,
		'APPLEPAY'    => self::APPLE,
		'APPLE PAY'   => self::APPLE,
		'COFIDISPAY'  => self::COFIDIS,
		'COFIDIS PAY' => self::COFIDIS,
	);

	// Shown for ad hoc rows and for anything without a catalog logo.
	public const FALLBACK_LOGO = 'assets/img/cash.svg';

	/**
	 * Static-only helper.
	 */
	private function __construct() {
	}

	/**
	 * The canonical code for whatever ifthenpay sent, e.g. `Multibanco` → `MB`. A numeric
	 * entity is Multibanco's; unknown codes come back upper-cased.
	 *
	 * @param string $code A method code or alias.
	 */
	public static function normalize( string $code ): string {
		$code = strtoupper( trim( $code ) );

		if ( '' !== $code && ctype_digit( $code ) ) {
			return self::MB;
		}

		return self::ALIASES[ $code ] ?? $code;
	}

	/**
	 * Whether a code is one of ours.
	 *
	 * @param string $code A method code or alias.
	 */
	public static function is_known( string $code ): bool {
		return in_array( self::normalize( $code ), self::CODES, true );
	}

	/**
	 * The human label for a method code, e.g. `MBWAY` → "MB WAY". Unknown codes come back as-is.
	 *
	 * @param string $code A method code or alias.
	 */
	public static function label( string $code ): string {
		$labels = array(
			self::MB      => __( 'Multibanco', 'ifthenpay-payments-for-lifterlms' ),
			self::MBWAY   => __( 'MB WAY', 'ifthenpay-payments-for-lifterlms' ),
			self::PAYSHOP => __( 'Payshop', 'ifthenpay-payments-for-lifterlms' ),
			self::CCARD   => __( 'Credit card', 'ifthenpay-payments-for-lifterlms' ),
			self::GOOGLE  => __( 'Google Pay', 'ifthenpay-payments-for-lifterlms' ),
			self::APPLE   => __( 'Apple Pay', 'ifthenpay-payments-for-lifterlms' ),
			self::PIX     => __( 'Pix', 'ifthenpay-payments-for-lifterlms' ),
			self::COFIDIS => __( 'Cofidis Pay', 'ifthenpay-payments-for-lifterlms' ),
		);

		return $labels[ self::normalize( $code ) ] ?? $code;
	}

	/**
	 * Code => {logo, logo_dark} from the stored methods table (ifthenpay's catalog images).
	 * `logo_dark` is '' when ifthenpay has no dark variant.
	 *
	 * @param SettingsRepository $settings Settings storage.
	 * @return array<string, array{logo: string, logo_dark: string}>
	 */
	public static function logos( SettingsRepository $settings ): array {
		$logos = array();

		foreach ( $settings->methods() as $entity => $method ) {
			$logos[ $entity ] = array(
				'logo'      => $method['logo'],
				'logo_dark' => $method['logo_dark'],
			);
		}

		return $logos;
	}

	/**
	 * One method's logo URL, or our bundled fallback icon.
	 *
	 * @param string             $code     A method code or alias.
	 * @param SettingsRepository $settings Settings storage.
	 */
	public static function logo_url( string $code, SettingsRepository $settings ): string {
		$logos = self::logos( $settings );
		$code  = self::normalize( $code );

		if ( isset( $logos[ $code ] ) && '' !== $logos[ $code ]['logo'] ) {
			return $logos[ $code ]['logo'];
		}

		return IFTP_LLMS_URL . self::FALLBACK_LOGO;
	}
}
