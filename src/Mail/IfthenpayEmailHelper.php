<?php
/**
 * Activation request email sender.
 *
 * @package Ifthenpay\LifterLMS
 */

declare(strict_types=1);

namespace Ifthenpay\LifterLMS\Mail;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends the "Request Activation" email for a not-yet-provisioned method.
 */
final class IfthenpayEmailHelper {

	public const SUPPORT_EMAIL = 'suporte@ifthenpay.com';

	/**
	 * Static-only helper.
	 */
	private function __construct() {
	}

	/**
	 * Builds and sends the activation request email.
	 *
	 * @param array{gateway_key: string, entity: string, backoffice_key: string, customer_email: string, site_url: string, site_name: string, wp_version: string, lifterlms_version: string, plugin_version: string} $data The activation request context.
	 */
	public static function send_activation_email( array $data ): bool {
		$entity    = strtoupper( sanitize_text_field( $data['entity'] ) );
		$site_url  = esc_url_raw( $data['site_url'] );
		$recipient = self::get_support_email( $data );

		$subject = sprintf( '[dev_ifthenpay] [%s]: Ativacao de Servico', $entity );

		// Raw values: the template escapes on output, so escaping here too would show "&amp;".
		$items = array(
			'Chave de acesso ao backoffice:' => $data['backoffice_key'],
			'Gateway Key:'                   => $data['gateway_key'],
			'Email Cliente:'                 => $data['customer_email'],
			'Metodo a ativar:'               => $entity,
			'Loja online:'                   => $site_url,
			'Plataforma ecommerce:'          => sprintf(
				'WordPress %s / LifterLMS v%s',
				$data['wp_version'],
				$data['lifterlms_version']
			),
			'Versao do Modulo ifthenpay:'    => $data['plugin_version'],
			'Atualizar Conta Cliente:'       => 'Apos adicionar o metodo nao precisa tomar mais nenhuma acao, este metodo ficara disponivel para selecao na pagina de configuracao da extensao.',
		);

		ob_start();
		?>
		<div style="
			font-family: Arial, sans-serif;
			color: #333;
			background-color: #f9f9f9;
			padding: 20px;
			border: 1px solid #e0e0e0;
			border-radius: 6px;
			max-width: 600px;
			margin: auto;
		">
			<h2 style="margin-top: 0; font-size: 20px; line-height: 1.2;">
				Ativar metodo de pagamento para a Gateway
				<span style="color: #d32f2f;"><?php echo esc_html( $data['gateway_key'] ); ?></span>
			</h2>

			<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse: collapse;">
				<?php foreach ( $items as $label => $value ) : ?>
					<tr>
						<td style="padding: 8px 0; vertical-align: top; width: 200px; font-weight: bold;">
							<?php echo esc_html( $label ); ?>
						</td>
						<td style="padding: 8px 0;">
							<?php echo esc_html( $value ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<p style="margin-top: 20px; font-size: 12px; color: #777; text-align: center;">
				Pedido gerado automaticamente pelo modulo ifthenpay
			</p>
		</div>
		<?php
		$body = (string) ob_get_clean();

		$host    = wp_parse_url( $site_url, PHP_URL_HOST );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			// blogname is stored HTML-escaped; a mail header wants the plain text.
			'From: ' . wp_specialchars_decode( $data['site_name'], ENT_QUOTES ) . ' <no-reply@' . $host . '>',
		);

		return wp_mail( $recipient, $subject, $body, $headers );
	}

	/**
	 * Resolves the support inbox, filterable per site.
	 *
	 * @param array<string, string> $data The activation request context, passed to the filter.
	 */
	private static function get_support_email( array $data ): string {
		$email = apply_filters( 'iftp_llms_support_email', self::SUPPORT_EMAIL, $data );

		return is_string( $email ) && is_email( $email ) ? $email : self::SUPPORT_EMAIL;
	}
}
