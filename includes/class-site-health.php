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
			'label' => __( 'Rapls Copy Notice log table', 'rapls-copy-notice' ),
			'test'  => array( $this, 'test_table' ),
		);
		$tests['direct'][ QCNOTICE_PREFIX . 'cron' ]         = array(
			'label' => __( 'Rapls Copy Notice scheduled tasks', 'rapls-copy-notice' ),
			'test'  => array( $this, 'test_cron' ),
		);
		$tests['direct'][ QCNOTICE_PREFIX . 'notify_email' ] = array(
			'label' => __( 'Rapls Copy Notice notification address', 'rapls-copy-notice' ),
			'test'  => array( $this, 'test_notify_email' ),
		);
		$tests['direct'][ QCNOTICE_PREFIX . 'proxy' ]        = array(
			'label' => __( 'Rapls Copy Notice visitor IP addresses', 'rapls-copy-notice' ),
			'test'  => array( $this, 'test_proxy' ),
		);
		$tests['direct'][ QCNOTICE_PREFIX . 'pending' ]      = array(
			'label' => __( 'Rapls Copy Notice notification delivery', 'rapls-copy-notice' ),
			'test'  => array( $this, 'test_pending' ),
		);
		$tests['direct'][ QCNOTICE_PREFIX . 'post_types' ]   = array(
			'label' => __( 'Rapls Copy Notice monitored post types', 'rapls-copy-notice' ),
			'test'  => array( $this, 'test_post_types' ),
		);
		$tests['direct'][ QCNOTICE_PREFIX . 'locks' ]        = array(
			'label' => __( 'Rapls Copy Notice database locks', 'rapls-copy-notice' ),
			'test'  => array( $this, 'test_locks' ),
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
				__( 'The copy log table is in place', 'rapls-copy-notice' ),
				__( 'Copies can be stored.', 'rapls-copy-notice' )
			);
		}

		return $this->result(
			'table',
			'critical',
			__( 'The copy log table is missing or out of date', 'rapls-copy-notice' ),
			__( 'Copies cannot be stored until the table is created. Deactivate and reactivate Rapls Copy Notice to create it. If this message stays, the database user may not be allowed to create tables.', 'rapls-copy-notice' )
		);
	}

	/**
	 * The digest and purge events are scheduled and not stuck.
	 *
	 * @return array
	 */
	public function test_cron() {
		$problems = array();
		$messages = array(
			Cron::DIGEST_HOOK => array(
				__( 'The hourly summary email is not scheduled.', 'rapls-copy-notice' ),
				__( 'The hourly summary email is more than an hour late.', 'rapls-copy-notice' ),
			),
			Cron::PURGE_HOOK  => array(
				__( 'The daily removal of old log entries is not scheduled.', 'rapls-copy-notice' ),
				__( 'The daily removal of old log entries is more than an hour late.', 'rapls-copy-notice' ),
			),
		);

		foreach ( $messages as $hook => $texts ) {
			$next = wp_next_scheduled( $hook );
			if ( ! $next ) {
				$problems[] = $texts[0];
			} elseif ( $next < time() - self::OVERDUE_GRACE ) {
				$problems[] = $texts[1];
			}
		}

		if ( ! $problems ) {
			return $this->result(
				'cron',
				'good',
				__( 'Copy summaries and log cleanup are scheduled', 'rapls-copy-notice' ),
				__( 'The hourly summary email and the daily removal of old log entries are scheduled with WP-Cron.', 'rapls-copy-notice' )
			);
		}

		return $this->result(
			'cron',
			'recommended',
			__( 'Copy summaries or log cleanup are not running on time', 'rapls-copy-notice' ),
			// Separate paragraphs, so no language has to join sentences with a space.
			array_merge( $problems, array( __( 'WP-Cron runs when the site gets visits, so a very quiet site, or one with WP-Cron disabled and no server cron job, delays these tasks.', 'rapls-copy-notice' ) ) )
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
				__( 'Copy notifications have a valid address', 'rapls-copy-notice' ),
				__( 'Copy notifications are sent to a valid email address.', 'rapls-copy-notice' )
			);
		}

		return $this->result(
			'notify_email',
			'recommended',
			__( 'Copy notifications have no valid address', 'rapls-copy-notice' ),
			__( 'Copies are still recorded in the log, but no email can be sent. Enter an address in "Send notifications to" on the Notifications tab (Tools → Rapls Copy Notice), or correct the Administration Email Address under Settings → General.', 'rapls-copy-notice' ),
			'notifications'
		);
	}

	/**
	 * Copies older than two summary runs that are still not notified mean
	 * delivery keeps failing. Only the count is used.
	 *
	 * @return array
	 */
	public function test_pending() {
		$pending = Logger::count_unnotified_before( gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) );
		if ( 0 === $pending ) {
			return $this->result(
				'pending',
				'good',
				__( 'Copy notifications are being delivered', 'rapls-copy-notice' ),
				__( 'No copy recorded more than two hours ago is still waiting to be notified.', 'rapls-copy-notice' )
			);
		}

		return $this->result(
			'pending',
			'recommended',
			sprintf(
				/* translators: %s: number of log entries. */
				_n( 'Notification for %s copy has not been sent', 'Notifications for %s copies have not been sent', $pending, 'rapls-copy-notice' ),
				number_format_i18n( $pending )
			),
			__( 'Copies recorded more than two hours ago are still waiting to be sent to you. Sending may be failing, there may be no valid notification address, or the hourly summary may not be running. Use "Send a test email" on the Notifications tab to check that this site can send email (an SMTP plugin may be needed), and look on this screen for "Copy summaries or log cleanup are not running on time". These copies stay in the log and go out with the next summary once this is fixed.', 'rapls-copy-notice' ),
			'notifications'
		);
	}

	/**
	 * At least one post type is monitored.
	 *
	 * @return array
	 */
	public function test_post_types() {
		if ( ! empty( Settings::get( 'post_types' ) ) ) {
			return $this->result(
				'post_types',
				'good',
				__( 'Copies are detected on at least one post type', 'rapls-copy-notice' ),
				__( 'At least one post type is selected on the Detection tab (Tools → Rapls Copy Notice).', 'rapls-copy-notice' )
			);
		}

		return $this->result(
			'post_types',
			'recommended',
			__( 'No post type is monitored for copies', 'rapls-copy-notice' ),
			__( 'No post type is selected on the Detection tab (Tools → Rapls Copy Notice), so no copies are detected anywhere on the site.', 'rapls-copy-notice' ),
			'detection'
		);
	}

	/**
	 * The database takes named locks (GET_LOCK), which keep the limits
	 * exact when requests arrive together.
	 *
	 * @return array
	 */
	public function test_locks() {
		if ( Lock::available() ) {
			return $this->result(
				'locks',
				'good',
				__( 'The database supports the locks the copy limits use', 'rapls-copy-notice' ),
				__( 'The rate limits and the instant email limit hold even when many copies arrive at the same moment.', 'rapls-copy-notice' )
			);
		}

		return $this->result(
			'locks',
			'recommended',
			__( 'The database does not allow the locks the copy limits use', 'rapls-copy-notice' ),
			array(
				__( 'This site\'s database does not allow named locks (MySQL\'s GET_LOCK function), as some database clusters do not.', 'rapls-copy-notice' ),
				__( 'Copies are still recorded and summarized, but the rate limits may not hold against many reports sent at the same moment, and two summaries running at once may send the same copies twice. "One email per copy, right away" sends nothing right away: those copies go into the hourly summary instead. Ask your host whether GET_LOCK can be enabled.', 'rapls-copy-notice' ),
			)
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

		// Behind a proxy, it is good only if this request's proxy is listed:
		// a list that no longer matches the CDN's addresses is no better
		// than none.
		$trusted = (array) Settings::get( 'trusted_proxies' );
		if ( ! $looks_proxy || ( $trusted && Privacy::ip_matches_any( $remote, $trusted ) ) ) {
			return $this->result(
				'proxy',
				'good',
				__( 'Visitor IP addresses are read safely', 'rapls-copy-notice' ),
				empty( $trusted )
					? __( 'Visitor IP addresses come from the direct connection. Forwarding headers are used only from proxies you list as trusted.', 'rapls-copy-notice' )
					: __( 'Forwarding headers are used only for requests from the addresses in "Trusted proxies"; other requests use the address of the direct connection.', 'rapls-copy-notice' )
			);
		}

		return $this->result(
			'proxy',
			'recommended',
			__( 'This site may be behind a proxy that is not listed as trusted', 'rapls-copy-notice' ),
			__( 'This request arrived with a forwarding header, so the site may sit behind a reverse proxy or CDN. If it does, every visitor appears with the proxy\'s IP address, and the per-IP rate limit applies to all of them together. Add the proxy\'s addresses in "Trusted proxies" on the Detection tab (Tools → Rapls Copy Notice). If you do not use a proxy, nothing needs to change.', 'rapls-copy-notice' ),
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
		$yes = __( 'Yes', 'rapls-copy-notice' );
		$no  = __( 'No', 'rapls-copy-notice' );

		$next_digest = wp_next_scheduled( Cron::DIGEST_HOOK );

		$info[ QCNOTICE_SLUG ] = array(
			'label'  => __( 'Rapls Copy Notice', 'rapls-copy-notice' ),
			'fields' => array(
				'version'      => array(
					'label' => __( 'Version', 'rapls-copy-notice' ),
					'value' => QCNOTICE_VERSION,
				),
				'db_version'   => array(
					'label' => __( 'Database schema', 'rapls-copy-notice' ),
					'value' => (string) get_option( Installer::DB_VERSION_OPTION, '' ),
				),
				'records'      => array(
					'label' => __( 'Log entries stored', 'rapls-copy-notice' ),
					'value' => Logger::count(),
				),
				'records_7d'   => array(
					'label' => __( 'Log entries in the last 7 days', 'rapls-copy-notice' ),
					'value' => Logger::count_since( gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ),
				),
				'notify_mode'  => array(
					'label' => __( 'Notification mode', 'rapls-copy-notice' ),
					'value' => Settings::MODE_INSTANT === Settings::get( 'notify_mode' ) ? __( 'One email per copy, right away', 'rapls-copy-notice' ) : __( 'One summary email per hour', 'rapls-copy-notice' ),
					'debug' => Settings::get( 'notify_mode' ),
				),
				'next_digest'  => array(
					'label' => __( 'Next summary check', 'rapls-copy-notice' ),
					'value' => $next_digest ? wp_date( 'Y-m-d H:i:s', $next_digest ) : __( 'Not scheduled', 'rapls-copy-notice' ),
				),
				'post_types'   => array(
					'label' => __( 'Monitored post types', 'rapls-copy-notice' ),
					'value' => implode( ', ', Settings::get( 'post_types' ) ),
				),
				'rate_limits'  => array(
					'label' => __( 'Rate limits', 'rapls-copy-notice' ),
					'value' => sprintf(
						/* translators: 1: copies per minute per IP address, 2: copies per hour per IP address, 3: copies per hour for the whole site. */
						__( '%1$d a minute and %2$d an hour per IP address, %3$d an hour for the site', 'rapls-copy-notice' ),
						Settings::get( 'rate_ip_per_minute' ),
						Rate_Limit::ip_hourly_limit( Settings::get( 'rate_ip_per_minute' ), Settings::get( 'rate_site_per_hour' ) ),
						Settings::get( 'rate_site_per_hour' )
					),
					'debug' => sprintf( '%d/min per IP, %d/h per IP, %d/h site', Settings::get( 'rate_ip_per_minute' ), Rate_Limit::ip_hourly_limit( Settings::get( 'rate_ip_per_minute' ), Settings::get( 'rate_site_per_hour' ) ), Settings::get( 'rate_site_per_hour' ) ),
				),
				'proxies'      => array(
					'label' => __( 'Trusted proxies', 'rapls-copy-notice' ),
					'value' => count( Settings::get( 'trusted_proxies' ) ),
				),
				'anonymize_ip' => array(
					'label' => __( 'IP addresses shortened', 'rapls-copy-notice' ),
					'value' => Settings::get( 'anonymize_ip' ) ? $yes : $no,
				),
				'retention'    => array(
					'label' => __( 'Retention (days)', 'rapls-copy-notice' ),
					'value' => Settings::get( 'retention_days' ),
				),
				'locks'        => array(
					'label' => __( 'Database locks available', 'rapls-copy-notice' ),
					'value' => Lock::available() ? $yes : $no,
				),
				'object_cache' => array(
					'label' => __( 'Rate-limit counters stored in', 'rapls-copy-notice' ),
					'value' => wp_using_ext_object_cache() ? __( 'Object cache', 'rapls-copy-notice' ) : __( 'Database (short-lived transients)', 'rapls-copy-notice' ),
				),
				'visitor_side' => array(
					'label' => __( 'Message / source link for readers', 'rapls-copy-notice' ),
					'value' => ( Settings::get( 'show_message' ) ? $yes : $no ) . ' / ' . ( Settings::get( 'add_attribution' ) ? $yes : $no ),
				),
			),
		);

		return $info;
	}

	/**
	 * Builds a test result in the shape Site Health expects.
	 *
	 * @param string          $id          Test suffix.
	 * @param string          $status      good, recommended or critical.
	 * @param string          $label       Heading.
	 * @param string|string[] $description Plain-text explanation.
	 * @param string          $tab         Settings tab to link to, if any.
	 * @return array
	 */
	private function result( $id, $status, $label, $description, $tab = '' ) {
		$actions = '';
		if ( '' !== $tab ) {
			$actions = sprintf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( Admin\Admin::url( $tab ) ),
				esc_html__( 'Open Rapls Copy Notice settings', 'rapls-copy-notice' )
			);
		}

		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Rapls Copy Notice', 'rapls-copy-notice' ),
				'color' => 'critical' === $status ? 'red' : 'blue',
			),
			'description' => implode(
				'',
				array_map(
					static function ( $paragraph ) {
						return '<p>' . esc_html( $paragraph ) . '</p>'; },
					(array) $description
				)
			),
			'actions'     => $actions,
			'test'        => QCNOTICE_PREFIX . $id,
		);
	}
}
