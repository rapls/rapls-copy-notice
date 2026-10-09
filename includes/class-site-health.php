<?php
/**
 * Site Health tests and debug information.
 *
 * @package QuietCopyNotice
 */

namespace QuietCopyNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks that only look at this site's own state. None of them makes an HTTP
 * request, and none prints anything a visitor sent.
 */
class Site_Health {

	/**
	 * How late a scheduled event may run before it counts as stuck.
	 */
	const OVERDUE_GRACE = HOUR_IN_SECONDS;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
		add_filter( 'debug_information', array( $this, 'add_debug_info' ) );
	}

	/**
	 * Adds the direct tests.
	 *
	 * @param array $tests Registered tests.
	 * @return array
	 */
	public function add_tests( $tests ) {
		$tests['direct'][ QCNOTICE_PREFIX . 'table' ]        = array(
			'label' => __( 'Quiet Copy Notice log table', 'quiet-copy-notice' ),
			'test'  => array( $this, 'test_table' ),
		);
		$tests['direct'][ QCNOTICE_PREFIX . 'cron' ]         = array(
			'label' => __( 'Quiet Copy Notice scheduled tasks', 'quiet-copy-notice' ),
			'test'  => array( $this, 'test_cron' ),
		);
		$tests['direct'][ QCNOTICE_PREFIX . 'notify_email' ] = array(
			'label' => __( 'Quiet Copy Notice notification address', 'quiet-copy-notice' ),
			'test'  => array( $this, 'test_notify_email' ),
		);
		$tests['direct'][ QCNOTICE_PREFIX . 'proxy' ]        = array(
			'label' => __( 'Quiet Copy Notice visitor IP addresses', 'quiet-copy-notice' ),
			'test'  => array( $this, 'test_proxy' ),
		);
		return $tests;
	}

	/**
	 * The log table exists and its schema is current.
	 *
	 * @return array
	 */
	public function test_table() {
		global $wpdb;

		$table   = Installer::table();
		$exists  = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check.
		$current = get_option( Installer::DB_VERSION_OPTION ) === Installer::DB_VERSION;

		if ( $exists && $current ) {
			return $this->result(
				'table',
				'good',
				__( 'The copy log table is in place', 'quiet-copy-notice' ),
				__( 'Copies can be stored.', 'quiet-copy-notice' )
			);
		}

		return $this->result(
			'table',
			'critical',
			__( 'The copy log table is missing or out of date', 'quiet-copy-notice' ),
			__( 'Copies cannot be stored until the table is created. Deactivate and reactivate Quiet Copy Notice to create it. If this message stays, the database user may not be allowed to create tables.', 'quiet-copy-notice' )
		);
	}

	/**
	 * The digest and purge events are scheduled and not stuck.
	 *
	 * @return array
	 */
	public function test_cron() {
		$problems = array();
		$hooks    = array(
			Cron::DIGEST_HOOK => __( 'hourly summary email', 'quiet-copy-notice' ),
			Cron::PURGE_HOOK  => __( 'daily removal of old records', 'quiet-copy-notice' ),
		);

		foreach ( $hooks as $hook => $label ) {
			$next = wp_next_scheduled( $hook );
			if ( ! $next ) {
				/* translators: %s: name of a scheduled task. */
				$problems[] = sprintf( __( 'The %s is not scheduled.', 'quiet-copy-notice' ), $label );
			} elseif ( $next < time() - self::OVERDUE_GRACE ) {
				/* translators: %s: name of a scheduled task. */
				$problems[] = sprintf( __( 'The %s is more than an hour late.', 'quiet-copy-notice' ), $label );
			}
		}

		if ( ! $problems ) {
			return $this->result(
				'cron',
				'good',
				__( 'Copy summaries and log cleanup are scheduled', 'quiet-copy-notice' ),
				__( 'The hourly summary email and the daily removal of old records are scheduled with WP-Cron.', 'quiet-copy-notice' )
			);
		}

		return $this->result(
			'cron',
			'recommended',
			__( 'Copy summaries or log cleanup are not running on time', 'quiet-copy-notice' ),
			implode( ' ', $problems ) . ' ' . __( 'WP-Cron runs when the site gets visits, so a very quiet site, or one with WP-Cron disabled and no server cron job, delays these tasks.', 'quiet-copy-notice' )
		);
	}

	/**
	 * Notifications have somewhere to go.
	 *
	 * @return array
	 */
	public function test_notify_email() {
		if ( '' !== Settings::notify_email() ) {
			return $this->result(
				'notify_email',
				'good',
				__( 'Copy notifications have a valid address', 'quiet-copy-notice' ),
				__( 'Copy notifications are sent to a valid email address.', 'quiet-copy-notice' )
			);
		}

		return $this->result(
			'notify_email',
			'recommended',
			__( 'Copy notifications have no valid address', 'quiet-copy-notice' ),
			__( 'Copies are still recorded in the log, but no email can be sent. Enter an address under Tools → Quiet Copy Notice → Notifications, or fix the site administration email address.', 'quiet-copy-notice' ),
			'notifications'
		);
	}

	/**
	 * Warns when the site looks like it sits behind a reverse proxy that is
	 * not listed as trusted: a forwarding header is present and either the
	 * direct peer has a private or loopback address, or the header is
	 * Cloudflare's. A forwarding header alone is not enough, because an
	 * office proxy in front of the administrator's browser adds one too.
	 * Only the presence of the headers is checked; their values are neither
	 * used nor shown.
	 *
	 * @return array
	 */
	public function test_proxy() {
		$headers   = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED' );
		$forwarded = false;
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$forwarded = true;
				break;
			}
		}

		$remote      = Privacy::normalize_ip( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		$internal    = '' !== $remote && false === filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		$behind_cdn  = ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		$looks_proxy = $forwarded && ( $internal || $behind_cdn );

		if ( ! $looks_proxy || ! empty( Settings::get( 'trusted_proxies' ) ) ) {
			return $this->result(
				'proxy',
				'good',
				__( 'Visitor IP addresses are read safely', 'quiet-copy-notice' ),
				__( 'Visitor IP addresses come from the direct connection. Forwarding headers are used only from proxies you list as trusted.', 'quiet-copy-notice' )
			);
		}

		return $this->result(
			'proxy',
			'recommended',
			__( 'This site may be behind a proxy that is not listed as trusted', 'quiet-copy-notice' ),
			__( 'This request arrived with a forwarding header, so the site may sit behind a reverse proxy or CDN. If it does, every visitor appears with the proxy\'s IP address, and the per-IP rate limit applies to all of them together. Add the proxy\'s addresses under Tools → Quiet Copy Notice → Detection → Trusted proxies. If you do not use a proxy, nothing needs to change.', 'quiet-copy-notice' ),
			'detection'
		);
	}

	/**
	 * Adds a section to Site Health → Info. Settings and counts only.
	 *
	 * @param array $info Debug information.
	 * @return array
	 */
	public function add_debug_info( $info ) {
		$yes = __( 'Yes', 'quiet-copy-notice' );
		$no  = __( 'No', 'quiet-copy-notice' );

		$next_digest = wp_next_scheduled( Cron::DIGEST_HOOK );

		$info[ QCNOTICE_SLUG ] = array(
			'label'  => __( 'Quiet Copy Notice', 'quiet-copy-notice' ),
			'fields' => array(
				'version'      => array(
					'label' => __( 'Version', 'quiet-copy-notice' ),
					'value' => QCNOTICE_VERSION,
				),
				'db_version'   => array(
					'label' => __( 'Database schema', 'quiet-copy-notice' ),
					'value' => (string) get_option( Installer::DB_VERSION_OPTION, '' ),
				),
				'records'      => array(
					'label' => __( 'Records stored', 'quiet-copy-notice' ),
					'value' => Logger::count(),
				),
				'records_7d'   => array(
					'label' => __( 'Records in the last 7 days', 'quiet-copy-notice' ),
					'value' => Logger::count_since( gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ),
				),
				'notify_mode'  => array(
					'label' => __( 'Notification mode', 'quiet-copy-notice' ),
					'value' => Settings::get( 'notify_mode' ),
				),
				'next_digest'  => array(
					'label' => __( 'Next summary check', 'quiet-copy-notice' ),
					'value' => $next_digest ? wp_date( 'Y-m-d H:i:s', $next_digest ) : $no,
				),
				'post_types'   => array(
					'label' => __( 'Monitored post types', 'quiet-copy-notice' ),
					'value' => implode( ', ', Settings::get( 'post_types' ) ),
				),
				'rate_limits'  => array(
					'label' => __( 'Rate limits', 'quiet-copy-notice' ),
					'value' => sprintf( '%d/min per IP, %d/h site', Settings::get( 'rate_ip_per_minute' ), Settings::get( 'rate_site_per_hour' ) ),
				),
				'proxies'      => array(
					'label' => __( 'Trusted proxies', 'quiet-copy-notice' ),
					'value' => count( Settings::get( 'trusted_proxies' ) ),
				),
				'anonymize_ip' => array(
					'label' => __( 'IP addresses shortened', 'quiet-copy-notice' ),
					'value' => Settings::get( 'anonymize_ip' ) ? $yes : $no,
				),
				'retention'    => array(
					'label' => __( 'Retention (days)', 'quiet-copy-notice' ),
					'value' => Settings::get( 'retention_days' ),
				),
				'object_cache' => array(
					'label' => __( 'Rate-limit counters stored in', 'quiet-copy-notice' ),
					'value' => wp_using_ext_object_cache() ? __( 'Object cache', 'quiet-copy-notice' ) : __( 'Database (short-lived transients)', 'quiet-copy-notice' ),
				),
				'visitor_side' => array(
					'label' => __( 'Message / source link for readers', 'quiet-copy-notice' ),
					'value' => ( Settings::get( 'show_message' ) ? $yes : $no ) . ' / ' . ( Settings::get( 'add_attribution' ) ? $yes : $no ),
				),
			),
		);

		return $info;
	}

	/**
	 * Builds a test result in the shape Site Health expects.
	 *
	 * @param string $id          Test suffix.
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Heading.
	 * @param string $description Plain-text explanation.
	 * @param string $tab         Settings tab to link to, if any.
	 * @return array
	 */
	private function result( $id, $status, $label, $description, $tab = '' ) {
		$actions = '';
		if ( '' !== $tab ) {
			$actions = sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( Admin\Admin::url( $tab ) ),
				esc_html__( 'Open the settings', 'quiet-copy-notice' )
			);
		}

		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Quiet Copy Notice', 'quiet-copy-notice' ),
				'color' => 'critical' === $status ? 'red' : 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => QCNOTICE_PREFIX . $id,
		);
	}
}
