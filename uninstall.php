<?php
/**
 * Uninstall: removes every `iftp_llms_*` option, transient and cron event.
 * LifterLMS's own orders and transactions (with their ifthenpay notes and attempt meta) stay, since they're LifterLMS's records.
 *
 * @package Ifthenpay\LifterLMS
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// A closure called right away, so nothing lands in the global scope.
( static function (): void {
	global $wpdb;

	// Options, transients and cron live per site, so on a network I run this on each one.
	$cleanup_site = static function () use ( $wpdb ): void {
		// Every prefixed option and transient, the activation cooldowns and payment locks included.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall sweep; caches are cleared right below.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( 'iftp_llms_' ) . '%',
				$wpdb->esc_like( '_transient_iftp_llms_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_iftp_llms_' ) . '%'
			)
		);

		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		wp_clear_scheduled_hook( 'iftp_llms_resync_callback' );
	};

	if ( is_multisite() ) {
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			$cleanup_site();
			restore_current_blog();
		}
	} else {
		$cleanup_site();
	}
} )();
