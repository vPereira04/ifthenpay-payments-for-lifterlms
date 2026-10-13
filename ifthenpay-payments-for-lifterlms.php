<?php
/**
 * Plugin Name: ifthenpay Payments for LifterLMS
 * Plugin URI: https://ifthenpay.com
 * Description: Adds ifthenpay Pay by Link (Multibanco, MB WAY, card, Apple Pay, Google Pay, Pix, Payshop, Cofidis) to LifterLMS's native checkout, with orders confirmed by ifthenpay's callback.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: lifterlms
 * Author: ifthenpay
 * Author URI: https://ifthenpay.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ifthenpay-payments-for-lifterlms
 * Domain Path: /languages
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IFTP_LLMS_VERSION', '1.0.0' );
define( 'IFTP_LLMS_FILE', __FILE__ );
define( 'IFTP_LLMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'IFTP_LLMS_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( IFTP_LLMS_DIR . 'vendor/autoload.php' ) ) {
	require_once IFTP_LLMS_DIR . 'vendor/autoload.php';
}

// LifterLMS loads after us alphabetically, so I wait for plugins_loaded. A closure, so I don't add a global function.
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'LLMS_Payment_Gateway' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					wp_admin_notice( esc_html__( 'ifthenpay Payments for LifterLMS needs LifterLMS installed and active.', 'ifthenpay-payments-for-lifterlms' ), array( 'type' => 'warning' ) );
				},
				10,
				0
			);

			return;
		}

		if ( ! class_exists( \Ifthenpay\LifterLMS\Plugin::class ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					wp_admin_notice( esc_html__( 'ifthenpay Payments for LifterLMS could not load its classes. Run "composer install" in the plugin folder.', 'ifthenpay-payments-for-lifterlms' ), array( 'type' => 'error' ) );
				},
				10,
				0
			);

			return;
		}

		\Ifthenpay\LifterLMS\Plugin::instance()->boot();
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( ! class_exists( \Ifthenpay\LifterLMS\Plugin::class ) ) {
			return;
		}

		\Ifthenpay\LifterLMS\Webhook\WebhookRegistrar::schedule();

		// So the callback endpoint resolves right away instead of 404ing until the next flush.
		\Ifthenpay\LifterLMS\Webhook\WebhookController::flush();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( \Ifthenpay\LifterLMS\Plugin::class ) ) {
			\Ifthenpay\LifterLMS\Webhook\WebhookRegistrar::unschedule();
		}

		flush_rewrite_rules();
	}
);
