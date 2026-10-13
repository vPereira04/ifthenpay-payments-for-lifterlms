<?php
/**
 * The ifthenpay settings panel inside LifterLMS › Settings › Checkout › ifthenpay.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Admin;

use Ifthenpay\LifterLMS\Ajax\Controller as AjaxController;
use Ifthenpay\LifterLMS\Gateway\IfthenpayGateway;
use Ifthenpay\LifterLMS\Methods;
use Ifthenpay\LifterLMS\Plugin;
use Ifthenpay\LifterLMS\Settings\SettingsRepository;
use Ifthenpay\LifterLMS\Sync\GatewaySync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the settings contract (Connect, Gateway Key, methods table, description, expiry)
 * as the custom LifterLMS field type `iftp_llms_contract`, plus the Connect and Disconnect
 * dialogs, and loads the script that drives them.
 *
 * LifterLMS prints the section as one form-table inside `<form id="mainform">`. I print two
 * `<tbody>`s straight into that open table, give the controls no `name` (so Save Changes
 * ignores them), and save through our own AJAX (Ajax\Controller). The dialogs go in the
 * footer, outside the form, so Enter in them can't submit LifterLMS's settings.
 *
 * @phpstan-import-type MethodRow from SettingsRepository
 */
final class SettingsPanel {

	public const HANDLE = 'iftp-llms-admin';

	private const SETTINGS_PAGE = 'llms-settings';
	private const SETTINGS_TAB  = 'checkout';
	private const HOOK_SUFFIX   = 'lifterlms_page_llms-settings';

	private const DESCRIPTION_MAX_LENGTH = 200;

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
	 * Takes the shared services from the plugin.
	 */
	public function __construct() {
		$this->settings = Plugin::instance()->settings();
		$this->sync     = Plugin::instance()->sync();
	}

	/**
	 * Wires the hooks.
	 */
	public function register(): void {
		add_action( 'lifterlms_admin_field_' . IfthenpayGateway::CONTRACT_FIELD, array( $this, 'render_field' ), 10, 0 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_footer', array( $this, 'render_dialogs' ), 10, 0 );
		add_filter( 'iftp_llms_settings_state', array( $this, 'add_fragments' ) );
	}

	/**
	 * Whether this is our section of LifterLMS's settings, viewed by someone who may change it.
	 */
	public static function is_settings_screen(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only picks which screen loads the panel.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return self::SETTINGS_PAGE === $page
			&& self::SETTINGS_TAB === $tab
			&& Plugin::GATEWAY_ID === $section
			&& current_user_can( Plugin::admin_cap() );
	}

	/**
	 * Our section's URL.
	 */
	public static function url(): string {
		return add_query_arg(
			array(
				'page'    => self::SETTINGS_PAGE,
				'tab'     => self::SETTINGS_TAB,
				'section' => Plugin::GATEWAY_ID,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * LifterLMS's main Checkout settings, where the store currency lives.
	 */
	public static function checkout_settings_url(): string {
		return add_query_arg(
			array(
				'page'    => self::SETTINGS_PAGE,
				'tab'     => self::SETTINGS_TAB,
				'section' => 'main',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Loads the panel's script and styles on our section only.
	 *
	 * @param string $hook_suffix The admin page hook.
	 */
	public function enqueue( $hook_suffix ): void {
		if ( self::HOOK_SUFFIX !== $hook_suffix || ! self::is_settings_screen() ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, IFTP_LLMS_URL . 'assets/css/admin.css', array( 'dashicons' ), Plugin::asset_version( 'assets/css/admin.css' ) );
		wp_enqueue_script( self::HANDLE, IFTP_LLMS_URL . 'assets/js/admin.js', array( 'jquery' ), Plugin::asset_version( 'assets/js/admin.js' ), true );

		wp_localize_script(
			self::HANDLE,
			'iftpLlmsSettings',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( AjaxController::NONCE_ACTION ),
				'actions'    => array(
					'connect'           => AjaxController::ACTION_CONNECT,
					'disconnect'        => AjaxController::ACTION_DISCONNECT,
					'selectGatewayKey'  => AjaxController::ACTION_SELECT_GATEWAY_KEY,
					'savePreferences'   => AjaxController::ACTION_SAVE_PREFERENCES,
					'requestActivation' => AjaxController::ACTION_REQUEST_ACTIVATION,
				),
				'connected'  => $this->settings->is_connected(),
				'configured' => $this->settings->is_configured(),
				'eurStore'   => SettingsRepository::is_eur_store(),
				'maxExpiry'  => SettingsRepository::MAX_EXPIRY_DAYS,
				'i18n'       => $this->strings(),
			)
		);
	}

	/**
	 * Every string admin.js shows. The currency one carries a link, built from escaped parts.
	 *
	 * @return array<string, string>
	 */
	private function strings(): array {
		return array(
			'connect'             => __( 'Connect', 'ifthenpay-payments-for-lifterlms' ),
			'connecting'          => __( 'Connecting…', 'ifthenpay-payments-for-lifterlms' ),
			'disconnect'          => __( 'Disconnect', 'ifthenpay-payments-for-lifterlms' ),
			'disconnecting'       => __( 'Disconnecting…', 'ifthenpay-payments-for-lifterlms' ),
			'enterKey'            => __( 'Enter your Backoffice Key.', 'ifthenpay-payments-for-lifterlms' ),
			'unreachable'         => __( "We couldn't reach ifthenpay. Check your connection and try again.", 'ifthenpay-payments-for-lifterlms' ),
			'connected'           => __( 'Connected to ifthenpay', 'ifthenpay-payments-for-lifterlms' ),
			'loadingMethods'      => __( 'Loading payment methods…', 'ifthenpay-payments-for-lifterlms' ),
			'saving'              => __( 'Saving…', 'ifthenpay-payments-for-lifterlms' ),
			'saved'               => __( 'All changes saved', 'ifthenpay-payments-for-lifterlms' ),
			'saveFailed'          => __( "We couldn't save your ifthenpay changes. Check your connection and try again.", 'ifthenpay-payments-for-lifterlms' ),
			'unsaved'             => __( 'Your ifthenpay changes are still being saved.', 'ifthenpay-payments-for-lifterlms' ),
			'error'               => __( 'Something went wrong. Please try again.', 'ifthenpay-payments-for-lifterlms' ),
			'requesting'          => __( 'Requesting…', 'ifthenpay-payments-for-lifterlms' ),
			'requested'           => __( 'Requested', 'ifthenpay-payments-for-lifterlms' ),
			'requestSent'         => __( 'Activation request sent. ifthenpay will get back to you shortly.', 'ifthenpay-payments-for-lifterlms' ),
			'requestFailed'       => __( "The activation request couldn't be sent. Please try again.", 'ifthenpay-payments-for-lifterlms' ),
			'readinessGatewayKey' => esc_html__( "Choose a Gateway Key to finish setting up ifthenpay. Until then, students won't see it at checkout.", 'ifthenpay-payments-for-lifterlms' ),
			'readinessMethods'    => esc_html__( "Enable at least one payment method. Until then, students won't see ifthenpay at checkout.", 'ifthenpay-payments-for-lifterlms' ),
			'readinessCurrency'   => self::currency_message_html(),
		);
	}

	/**
	 * "Change the currency to Euro in Checkout Settings", with the link. Escaped HTML.
	 */
	public static function currency_message_html(): string {
		return sprintf(
			/* translators: %s: link to LifterLMS's Checkout Settings, labelled "Checkout Settings". */
			esc_html__( 'ifthenpay only takes payments in euros. Change the currency to Euro in %s to show ifthenpay at checkout.', 'ifthenpay-payments-for-lifterlms' ),
			'<a href="' . esc_url( self::checkout_settings_url() ) . '">' . esc_html__( 'Checkout Settings', 'ifthenpay-payments-for-lifterlms' ) . '</a>'
		);
	}

	/**
	 * The readiness message for the current state, or '' when there's nothing to fix (or not connected yet).
	 * Escaped HTML.
	 */
	private function readiness_html(): string {
		if ( ! $this->settings->is_connected() || $this->settings->is_configured() ) {
			return '';
		}

		$strings = $this->strings();

		if ( '' === $this->settings->gateway_key() ) {
			return $strings['readinessGatewayKey'];
		}

		if ( array() === $this->settings->enabled_methods() ) {
			return $strings['readinessMethods'];
		}

		return $strings['readinessCurrency'];
	}

	/**
	 * Adds the re-rendered fragments to every settings AJAX response.
	 *
	 * @param mixed $state The panel state from Ajax\Controller.
	 * @return mixed
	 */
	public function add_fragments( $state ) {
		if ( ! is_array( $state ) ) {
			return $state;
		}

		$options = isset( $state['gatewayKeys'] ) && is_array( $state['gatewayKeys'] ) ? $state['gatewayKeys'] : null;

		$state['connectionHtml']  = $this->connection_html();
		$state['gatewayKeysHtml'] = $this->gateway_key_options_html( $options );
		$state['methodsHtml']     = $this->methods_html();

		return $state;
	}

	/**
	 * Prints the panel's two `<tbody>`s into LifterLMS's open form-table.
	 */
	public function render_field(): void {
		if ( ! current_user_can( Plugin::admin_cap() ) ) {
			return;
		}

		// Picks up methods ifthenpay activated since the last visit.
		$this->sync->refresh();

		$connected = $this->settings->is_connected();
		$readiness = $this->readiness_html();
		$max       = SettingsRepository::MAX_EXPIRY_DAYS;
		?>
		<tbody class="iftp-llms-contract" id="iftp-llms-contract" data-iftp-llms-contract>
			<tr class="iftp-llms-contract__head-row">
				<td colspan="2">
					<div class="iftp-llms-contract__head">
						<h3 class="llms-subtitle iftp-llms-contract__title"><?php esc_html_e( 'ifthenpay account', 'ifthenpay-payments-for-lifterlms' ); ?></h3>
						<span class="iftp-llms-save-state" data-iftp-llms-save-state data-state="idle" role="status" aria-live="polite">
							<span class="iftp-llms-save-state__icon" aria-hidden="true"></span>
							<span class="iftp-llms-save-state__text" data-iftp-llms-save-text></span>
						</span>
					</div>
					<p class="description"><?php esc_html_e( 'These ifthenpay settings save automatically. The settings above save with Save Changes.', 'ifthenpay-payments-for-lifterlms' ); ?></p>
				</td>
			</tr>
			<tr class="iftp-llms-contract__readiness-row" data-iftp-llms-readiness<?php echo '' === $readiness ? ' hidden' : ''; ?>>
				<td colspan="2">
					<div class="notice notice-warning inline iftp-llms-contract__readiness">
						<p data-iftp-llms-readiness-text><?php echo $readiness; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in readiness_html(). ?></p>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Account', 'ifthenpay-payments-for-lifterlms' ); ?></th>
				<td class="forminp" id="iftp-llms-connection">
					<?php echo $this->connection_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in connection_html(). ?>
				</td>
			</tr>
		</tbody>
		<tbody class="iftp-llms-contract__config" data-iftp-llms-config<?php echo $connected ? '' : ' hidden'; ?>>
			<tr>
				<th scope="row"><label for="iftp-llms-gateway-key"><?php esc_html_e( 'Gateway Key', 'ifthenpay-payments-for-lifterlms' ); ?></label></th>
				<td class="forminp">
					<select id="iftp-llms-gateway-key" data-iftp-llms-gateway-key aria-describedby="iftp-llms-gateway-key-help">
						<?php echo $this->gateway_key_options_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in gateway_key_options_html(). ?>
					</select>
					<span class="spinner iftp-llms-contract__spinner" data-iftp-llms-gk-spinner></span>
					<p class="description" id="iftp-llms-gateway-key-help"><?php esc_html_e( 'The ifthenpay Gateway Key used for LifterLMS. Changing it reloads the payment methods below.', 'ifthenpay-payments-for-lifterlms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row" id="iftp-llms-methods-label"><?php esc_html_e( 'Payment methods', 'ifthenpay-payments-for-lifterlms' ); ?></th>
				<td class="forminp" id="iftp-llms-methods" aria-busy="false">
					<?php echo $this->methods_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in methods_html(). ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="iftp-llms-description"><?php esc_html_e( 'Payment page description', 'ifthenpay-payments-for-lifterlms' ); ?></label></th>
				<td class="forminp">
					<input type="text" id="iftp-llms-description" maxlength="<?php echo esc_attr( (string) self::DESCRIPTION_MAX_LENGTH ); ?>" value="<?php echo esc_attr( $this->settings->description() ); ?>" placeholder="<?php echo esc_attr( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) ); ?>" data-iftp-llms-pref aria-describedby="iftp-llms-description-help" />
					<p class="description" id="iftp-llms-description-help"><?php esc_html_e( 'Shown to the student on the ifthenpay payment page. Leave empty to use your site name.', 'ifthenpay-payments-for-lifterlms' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="iftp-llms-expiry-days"><?php esc_html_e( 'Expiry days', 'ifthenpay-payments-for-lifterlms' ); ?></label></th>
				<td class="forminp">
					<input type="number" id="iftp-llms-expiry-days" class="tiny iftp-llms-contract__expiry" min="1" max="<?php echo esc_attr( (string) $max ); ?>" step="1" inputmode="numeric" value="<?php echo esc_attr( (string) $this->settings->expiry_days() ); ?>" data-iftp-llms-pref aria-describedby="iftp-llms-expiry-help iftp-llms-expiry-error" />
					<p class="description" id="iftp-llms-expiry-help"><?php esc_html_e( 'How many days an unpaid payment link stays valid. After that, the payment is marked as expired.', 'ifthenpay-payments-for-lifterlms' ); ?></p>
					<p class="iftp-llms-contract__field-error" id="iftp-llms-expiry-error" data-iftp-llms-expiry-error hidden>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: the most days a payment link can stay valid. */
								__( 'Enter a whole number of days from 1 to %d.', 'ifthenpay-payments-for-lifterlms' ),
								$max
							)
						);
						?>
					</p>
				</td>
			</tr>
		</tbody>
		<?php
	}

	/**
	 * Connected state + Connect/Disconnect button. The Backoffice Key itself is never printed.
	 */
	public function connection_html(): string {
		ob_start();

		if ( $this->settings->is_connected() ) {
			?>
			<div class="iftp-llms-connection iftp-llms-connection--connected">
				<span class="iftp-llms-connection__state">
					<span class="dashicons dashicons-yes-alt iftp-llms-connection__icon" aria-hidden="true"></span><?php esc_html_e( 'Connected', 'ifthenpay-payments-for-lifterlms' ); ?>
				</span>
				<button type="button" class="llms-button-secondary small" data-iftp-llms-open-disconnect aria-haspopup="dialog" aria-controls="iftp-llms-disconnect-modal"><?php esc_html_e( 'Disconnect', 'ifthenpay-payments-for-lifterlms' ); ?></button>
			</div>
			<?php
		} else {
			?>
			<div class="iftp-llms-connection">
				<span class="iftp-llms-connection__state iftp-llms-connection__state--off"><?php esc_html_e( 'Not connected', 'ifthenpay-payments-for-lifterlms' ); ?></span>
				<button type="button" class="llms-button-primary small" data-iftp-llms-open-connect aria-haspopup="dialog" aria-controls="iftp-llms-connect-modal"><?php esc_html_e( 'Connect', 'ifthenpay-payments-for-lifterlms' ); ?></button>
			</div>
			<p class="description"><?php esc_html_e( 'Connect your ifthenpay account to load your Gateway Keys and payment methods.', 'ifthenpay-payments-for-lifterlms' ); ?></p>
			<?php
		}

		return (string) ob_get_clean();
	}

	/**
	 * The Gateway Key `<option>`s.
	 *
	 * @param array<string, string>|null $options Gateway Key => label, or null to look them up.
	 */
	public function gateway_key_options_html( ?array $options = null ): string {
		$options  = null === $options ? $this->sync->gateway_key_options() : $options;
		$selected = $this->settings->gateway_key();
		$html     = '';

		if ( array() === $options ) {
			return '<option value="">' . esc_html__( 'No LifterLMS Gateway Key found. Ask ifthenpay to create one.', 'ifthenpay-payments-for-lifterlms' ) . '</option>';
		}

		if ( '' === $selected ) {
			$html .= '<option value="">' . esc_html__( 'Choose a Gateway Key', 'ifthenpay-payments-for-lifterlms' ) . '</option>';
		}

		foreach ( $options as $key => $label ) {
			$html .= '<option value="' . esc_attr( (string) $key ) . '"' . selected( (string) $key, $selected, false ) . '>' . esc_html( (string) $label ) . '</option>';
		}

		return $html;
	}

	/**
	 * The methods cell: the table, or why there's none.
	 * The stars are one radio group, so the browser keeps a single default and a click on the
	 * starred one can't clear it.
	 */
	public function methods_html(): string {
		$gateway_key = $this->settings->gateway_key();
		$methods     = $this->settings->methods();

		if ( '' === $gateway_key ) {
			return '<p class="iftp-llms-contract__empty">' . esc_html__( 'Choose a Gateway Key to see its payment methods.', 'ifthenpay-payments-for-lifterlms' ) . '</p>';
		}

		if ( array() === $methods ) {
			return '<p class="iftp-llms-contract__empty">' . esc_html__( 'No payment methods found for this Gateway Key.', 'ifthenpay-payments-for-lifterlms' ) . '</p>';
		}

		$default = $this->settings->default_method();

		ob_start();
		?>
		<div class="iftp-llms-methods-wrap" role="region" aria-labelledby="iftp-llms-methods-label" tabindex="0">
			<table class="llms-table zebra text-left size-large iftp-llms-methods">
				<caption class="screen-reader-text"><?php esc_html_e( 'Payment methods for this Gateway Key', 'ifthenpay-payments-for-lifterlms' ); ?></caption>
				<thead>
					<tr>
						<th scope="col" class="iftp-llms-methods__control iftp-llms-methods__control--enabled"><?php esc_html_e( 'Enabled', 'ifthenpay-payments-for-lifterlms' ); ?></th>
						<th scope="col" class="iftp-llms-methods__control"><?php esc_html_e( 'Default method', 'ifthenpay-payments-for-lifterlms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Method', 'ifthenpay-payments-for-lifterlms' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Account', 'ifthenpay-payments-for-lifterlms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $methods as $method ) : ?>
						<?php $this->render_method_row( $method, $gateway_key, $default ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="description"><?php esc_html_e( 'Star an enabled method to pre-select it on the ifthenpay payment page.', 'ifthenpay-payments-for-lifterlms' ); ?></p>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * One methods-table row.
	 *
	 * @param array<string, mixed> $method         The method.
	 * @param string               $gateway_key    The selected Gateway Key.
	 * @param string               $default_method The default method's entity, or ''.
	 *
	 * @phpstan-param MethodRow $method
	 */
	private function render_method_row( array $method, string $gateway_key, string $default_method ): void {
		$entity      = $method['entity'];
		$provisioned = '' !== $method['account'];
		$enabled     = $provisioned && $method['enabled'];
		$is_default  = $enabled && $entity === $default_method;
		$label       = self::method_label( $method );
		$star_id     = 'iftp-llms-star-' . sanitize_html_class( strtolower( $entity ) );
		?>
		<tr class="iftp-llms-methods__row<?php echo $provisioned ? '' : ' iftp-llms-methods__row--off'; ?>">
			<td class="iftp-llms-methods__control iftp-llms-methods__control--enabled">
				<input
					type="checkbox"
					class="iftp-llms-methods__enabled"
					value="<?php echo esc_attr( $entity ); ?>"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %s: payment method name. */ __( 'Enable %s', 'ifthenpay-payments-for-lifterlms' ), $label ) ); ?>"
					data-iftp-llms-enabled
					<?php checked( $enabled ); ?>
					<?php disabled( ! $provisioned ); ?>
				/>
			</td>
			<td class="iftp-llms-methods__control">
				<?php if ( $provisioned ) : ?>
					<input
						type="radio"
						id="<?php echo esc_attr( $star_id ); ?>"
						class="screen-reader-text"
						name="iftp_llms_default_method"
						value="<?php echo esc_attr( $entity ); ?>"
						data-iftp-llms-star
						<?php checked( $is_default ); ?>
						<?php disabled( ! $enabled ); ?>
					/>
					<label for="<?php echo esc_attr( $star_id ); ?>" class="iftp-llms-star<?php echo $enabled ? '' : ' iftp-llms-star--hidden'; ?>" title="<?php esc_attr_e( 'Set as default payment method', 'ifthenpay-payments-for-lifterlms' ); ?>">
						<span class="iftp-llms-star__icon dashicons <?php echo $is_default ? 'dashicons-star-filled' : 'dashicons-star-empty'; ?>" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: payment method name. */ __( 'Make %s the default payment method', 'ifthenpay-payments-for-lifterlms' ), $label ) ); ?></span>
					</label>
				<?php endif; ?>
			</td>
			<td>
				<span class="iftp-llms-methods__method">
					<span class="iftp-llms-methods__logo-slot">
						<?php if ( '' !== $method['logo'] ) : ?>
							<img class="iftp-llms-methods__logo" src="<?php echo esc_url( $method['logo'] ); ?>" alt="" />
						<?php endif; ?>
					</span>
					<span class="iftp-llms-methods__label"><?php echo esc_html( $label ); ?></span>
				</span>
			</td>
			<td>
				<?php if ( $provisioned ) : ?>
					<span class="iftp-llms-methods__pill"><?php echo esc_html( $method['account'] ); ?></span>
				<?php else : ?>
					<?php $requested = $this->settings->is_activation_requested( $gateway_key, $entity ); ?>
					<span class="iftp-llms-methods__activation">
						<span class="iftp-llms-methods__status"><?php esc_html_e( 'Not activated', 'ifthenpay-payments-for-lifterlms' ); ?></span>
						<button type="button" class="llms-button-secondary small" data-iftp-llms-request-activation data-entity="<?php echo esc_attr( $entity ); ?>"<?php disabled( $requested ); ?>>
							<span data-iftp-llms-activation-label><?php echo $requested ? esc_html__( 'Requested', 'ifthenpay-payments-for-lifterlms' ) : esc_html__( 'Request activation', 'ifthenpay-payments-for-lifterlms' ); ?></span><span class="screen-reader-text"> <?php echo esc_html( sprintf( /* translators: %s: payment method name. */ __( 'for %s', 'ifthenpay-payments-for-lifterlms' ), $label ) ); ?></span>
						</button>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Our name for a method; ifthenpay's alias only for codes we don't know.
	 *
	 * @param array<string, mixed> $method The method.
	 *
	 * @phpstan-param MethodRow $method
	 */
	private static function method_label( array $method ): string {
		if ( Methods::is_known( $method['entity'] ) ) {
			return Methods::label( $method['entity'] );
		}

		return '' !== $method['alias'] ? $method['alias'] : $method['entity'];
	}

	/**
	 * The Connect and Disconnect dialogs, outside `#mainform`. admin.js owns their open/closed state.
	 */
	public function render_dialogs(): void {
		if ( ! self::is_settings_screen() ) {
			return;
		}
		?>
		<div class="iftp-llms-modal" id="iftp-llms-connect-modal" data-iftp-llms-modal hidden>
			<div class="iftp-llms-modal__overlay" data-iftp-llms-modal-close></div>
			<div class="iftp-llms-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="iftp-llms-connect-title" aria-describedby="iftp-llms-connect-intro">
				<div class="llms-modal-header iftp-llms-modal__header">
					<h2 class="iftp-llms-modal__title" id="iftp-llms-connect-title">
						<img class="iftp-llms-modal__icon" src="<?php echo esc_url( IFTP_LLMS_URL . 'assets/img/icon-white.svg' ); ?>" alt="" width="24" height="24" />
						<?php esc_html_e( 'Connect to ifthenpay', 'ifthenpay-payments-for-lifterlms' ); ?>
					</h2>
					<button type="button" class="iftp-llms-modal__close" data-iftp-llms-modal-close aria-label="<?php esc_attr_e( 'Close', 'ifthenpay-payments-for-lifterlms' ); ?>">
						<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					</button>
				</div>
				<div class="llms-modal-content iftp-llms-modal__body">
					<p id="iftp-llms-connect-intro"><?php esc_html_e( "Paste the Backoffice Key from your ifthenpay account. We use it to load your Gateway Keys and payment methods. It's never shown again on this page.", 'ifthenpay-payments-for-lifterlms' ); ?></p>
					<label class="iftp-llms-modal__label" for="iftp-llms-backoffice-key"><?php esc_html_e( 'Backoffice Key', 'ifthenpay-payments-for-lifterlms' ); ?></label>
					<input type="text" id="iftp-llms-backoffice-key" class="iftp-llms-modal__input" placeholder="0000-0000-0000-0000" autocomplete="off" spellcheck="false" inputmode="numeric" aria-describedby="iftp-llms-backoffice-key-help iftp-llms-connect-message" />
					<p class="description" id="iftp-llms-backoffice-key-help"><?php esc_html_e( 'The 16-digit key from your ifthenpay Backoffice.', 'ifthenpay-payments-for-lifterlms' ); ?></p>
					<p class="iftp-llms-modal__message" id="iftp-llms-connect-message" role="alert"></p>
				</div>
				<div class="iftp-llms-modal__footer">
					<button type="button" class="llms-button-secondary small" data-iftp-llms-modal-close><?php esc_html_e( 'Cancel', 'ifthenpay-payments-for-lifterlms' ); ?></button>
					<button type="button" class="llms-button-primary small" id="iftp-llms-connect-submit"><?php esc_html_e( 'Connect', 'ifthenpay-payments-for-lifterlms' ); ?></button>
				</div>
			</div>
		</div>

		<div class="iftp-llms-modal" id="iftp-llms-disconnect-modal" data-iftp-llms-modal hidden>
			<div class="iftp-llms-modal__overlay" data-iftp-llms-modal-close></div>
			<div class="iftp-llms-modal__dialog" role="alertdialog" aria-modal="true" aria-labelledby="iftp-llms-disconnect-title" aria-describedby="iftp-llms-disconnect-text">
				<div class="llms-modal-header iftp-llms-modal__header">
					<h2 class="iftp-llms-modal__title" id="iftp-llms-disconnect-title">
						<img class="iftp-llms-modal__icon" src="<?php echo esc_url( IFTP_LLMS_URL . 'assets/img/icon-white.svg' ); ?>" alt="" width="24" height="24" />
						<?php esc_html_e( 'Disconnect ifthenpay?', 'ifthenpay-payments-for-lifterlms' ); ?>
					</h2>
					<button type="button" class="iftp-llms-modal__close" data-iftp-llms-modal-close aria-label="<?php esc_attr_e( 'Close', 'ifthenpay-payments-for-lifterlms' ); ?>">
						<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					</button>
				</div>
				<div class="llms-modal-content iftp-llms-modal__body">
					<p id="iftp-llms-disconnect-text"><?php esc_html_e( "This removes the Backoffice Key, Gateway Key and payment method choices saved here. Students won't see ifthenpay at checkout until you connect again. Existing LifterLMS orders and their payments stay as they are.", 'ifthenpay-payments-for-lifterlms' ); ?></p>
					<p class="iftp-llms-modal__message" id="iftp-llms-disconnect-message" role="alert"></p>
				</div>
				<div class="iftp-llms-modal__footer">
					<button type="button" class="llms-button-secondary small" data-iftp-llms-modal-close><?php esc_html_e( 'Cancel', 'ifthenpay-payments-for-lifterlms' ); ?></button>
					<button type="button" class="llms-button-danger small" id="iftp-llms-disconnect-submit"><?php esc_html_e( 'Disconnect', 'ifthenpay-payments-for-lifterlms' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}
}
