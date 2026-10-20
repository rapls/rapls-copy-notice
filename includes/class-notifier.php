<?php
/**
 * Email notifications.
 *
 * @package QuietCopyNotice
 */

namespace QuietCopyNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends plain-text mail only. Subjects carry fixed text, the site name and a
 * count; nothing a visitor sent ever reaches a subject or header line.
 */
class Notifier {

	/**
	 * Counter of instant mails sent in the current hour.
	 */
	const INSTANT_COUNTER = QCNOTICE_PREFIX . 'instant_sent';

	/**
	 * Most rows listed in full in one digest. The rest are counted.
	 */
	const DIGEST_DETAIL_MAX = 100;

	/**
	 * Most rows a single digest run covers.
	 */
	const DIGEST_BATCH_MAX = 5000;

	/**
	 * Seconds a new row is left alone by the digest.
	 */
	const DIGEST_SETTLE = MINUTE_IN_SECONDS;

	/**
	 * Characters of copied text shown per entry.
	 */
	const TEXT_EXCERPT = 200;

	/**
	 * Separator between entries.
	 */
	const RULE = '---------------------------------------------------';

	/**
	 * Sends one mail for one row, unless this hour's instant cap is used up.
	 * Rows over the cap stay unnotified and go out with the next digest.
	 *
	 * @param int $log_id Row ID.
	 * @return void
	 */
	public static function send_instant( $log_id ) {
		$row = Logger::get( $log_id );
		if ( ! $row || 1 === (int) $row['notified'] ) {
			return;
		}

		// Under a lock, so mails sent at the same moment cannot all read the
		// same count and pass the cap. Busy counts as over the cap: the row
		// waits for the digest.
		$within_cap = Lock::run(
			'instant_cap',
			static function () {
				$counter = get_transient( self::INSTANT_COUNTER );
				if ( ! is_array( $counter ) || ! isset( $counter['start'], $counter['count'] ) ) {
					$counter = array(
						'start' => time(),
						'count' => 0,
					);
				}
				if ( (int) $counter['count'] >= (int) Settings::get( 'instant_hourly_cap' ) ) {
					return false;
				}
				$counter['count'] = (int) $counter['count'] + 1;
				set_transient( self::INSTANT_COUNTER, $counter, max( 1, HOUR_IN_SECONDS - ( time() - (int) $counter['start'] ) ) );
				return true;
			},
			false
		);
		if ( ! $within_cap ) {
			return;
		}

		if ( self::send( array( $row ), 1, 'instant' ) ) {
			Logger::mark_notified( $log_id );
		}
	}

	/**
	 * Hourly digest of everything not yet notified.
	 *
	 * Rows from the last minute are left for the next run: in instant mode
	 * the request that stored them may still be sending their own mail, and
	 * taking them here too would notify them twice. The cutoff is an ID, so
	 * marking "through the last row" never covers a row that was skipped.
	 *
	 * @return void
	 */
	public static function send_digest() {
		$max_id = Logger::max_id_before( gmdate( 'Y-m-d H:i:s', time() - self::DIGEST_SETTLE ) );
		if ( $max_id <= 0 ) {
			return;
		}
		$rows = Logger::unnotified( self::DIGEST_BATCH_MAX, $max_id );
		if ( empty( $rows ) ) {
			return;
		}

		$last  = end( $rows );
		$total = count( $rows );

		if ( self::send( $rows, $total, 'digest' ) ) {
			Logger::mark_notified_through( (int) $last['id'] );
		}
	}

	/**
	 * Delivers one notification: the email, then any channel an add-on adds.
	 *
	 * @param array  $rows    Every row the notification covers.
	 * @param int    $total   Number of events it covers.
	 * @param string $context 'instant' or 'digest'.
	 * @return bool Whether it was delivered, or deliberately not needed.
	 *              Rows are marked notified only then.
	 */
	private static function send( array $rows, $total, $context ) {
		/**
		 * Lets an add-on choose which rows go into the email, for example by
		 * routing rules. An empty array means the email is not needed for
		 * this notification, which counts as handled.
		 *
		 * @param array  $rows    Rows covered.
		 * @param string $context 'instant' or 'digest'.
		 */
		$email_rows = apply_filters( QCNOTICE_PREFIX . 'email_rows', $rows, $context );
		$email_rows = is_array( $email_rows ) ? array_values( $email_rows ) : $rows;

		if ( empty( $email_rows ) ) {
			$delivered = true;
		} else {
			$email_total = count( $email_rows ) === count( $rows ) ? $total : count( $email_rows );
			$delivered   = self::send_email( array_slice( $email_rows, 0, self::DIGEST_DETAIL_MAX ), $email_total );
		}

		/**
		 * Lets an add-on deliver the same notification to other channels.
		 * Return true when the add-on delivered it, so the rows are not
		 * notified again; otherwise return $delivered unchanged.
		 *
		 * Every value in $rows except id, created_at, post_id and text_length
		 * came from a visitor's browser. Escape for the target format.
		 *
		 * @param bool   $delivered Whether a channel has delivered it so far.
		 * @param array  $rows      Every log row covered (a digest passes up to 5000).
		 * @param int    $total     Number of events covered.
		 * @param string $context   'instant' or 'digest'.
		 */
		return (bool) apply_filters( QCNOTICE_PREFIX . 'notification_delivered', $delivered, $rows, (int) $total, $context );
	}

	/**
	 * Builds and sends one plain-text message.
	 *
	 * @param array $rows  Rows listed in the body.
	 * @param int   $total Total number of events the message covers.
	 * @return bool Whether wp_mail reported success.
	 */
	private static function send_email( array $rows, $total ) {
		$to = Settings::notify_email();
		if ( '' === $to ) {
			return false;
		}

		$label = Settings::get( 'subject_label' );
		if ( '' === $label ) {
			$label = __( 'Copy notification', 'quiet-copy-notice' );
		}

		// Site name, the admin's label and a count. Nothing a visitor sent.
		$subject = sprintf(
			'[%1$s] %2$s (%3$s)',
			self::plain( get_bloginfo( 'name' ) ),
			self::one_line( $label ),
			sprintf(
				/* translators: %d: number of copy events. */
				_n( '%d copy', '%d copies', $total, 'quiet-copy-notice' ),
				$total
			)
		);

		$body   = array();
		$body[] = self::RULE . "\n" . __( 'It appears that the following text was copied.', 'quiet-copy-notice' ) . "\n" . self::RULE;
		foreach ( $rows as $row ) {
			$body[] = self::format_row( $row ) . "\n" . self::RULE;
		}
		if ( $total > count( $rows ) ) {
			$body[] = sprintf(
				/* translators: %d: number of events not listed in the email. */
				_n( '...and %d more. See the log screen for all of them.', '...and %d more. See the log screen for all of them.', $total - count( $rows ), 'quiet-copy-notice' ),
				$total - count( $rows )
			);
		}
		$body[] = __( 'Log:', 'quiet-copy-notice' ) . ' ' . admin_url( 'tools.php?page=' . QCNOTICE_SLUG ) . "\n"
			. __( 'Settings:', 'quiet-copy-notice' ) . ' ' . admin_url( 'tools.php?page=' . QCNOTICE_SLUG . '&tab=notifications' );

		$force_plain = static function () {
			return 'text/plain';
		};
		add_filter( 'wp_mail_content_type', $force_plain, PHP_INT_MAX );
		$sent = wp_mail(
			$to,
			$subject,
			implode( "\n\n", $body ),
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
		remove_filter( 'wp_mail_content_type', $force_plain, PHP_INT_MAX );

		return (bool) $sent;
	}

	/**
	 * One entry of the mail body.
	 *
	 * Visitor-supplied values are quoted line by line, so a copied text with
	 * line breaks cannot pass itself off as another field or entry.
	 *
	 * @param array $row Log row.
	 * @return string
	 */
	private static function format_row( array $row ) {
		$time = strtotime( $row['created_at'] . ' UTC' );
		$post = get_post( (int) $row['post_id'] );

		$lines   = array();
		$text    = (string) $row['copied_text'];
		$lines[] = self::quote( mb_strlen( $text ) > self::TEXT_EXCERPT ? mb_substr( $text, 0, self::TEXT_EXCERPT ) . '…' : $text );
		$lines[] = '';
		$lines[] = __( 'Date:', 'quiet-copy-notice' ) . ' ' . wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
		$lines[] = __( 'Post:', 'quiet-copy-notice' ) . ' ' . ( $post ? self::plain( get_the_title( $post ) ) : '#' . (int) $row['post_id'] );
		if ( $post ) {
			$lines[] = __( 'URL:', 'quiet-copy-notice' ) . ' ' . get_permalink( $post );
		}
		$lines[] = sprintf(
			/* translators: %d: length of the original selection in characters. */
			_n( 'Length: %d character', 'Length: %d characters', (int) $row['text_length'], 'quiet-copy-notice' ),
			(int) $row['text_length']
		);
		$lines[] = __( 'IP:', 'quiet-copy-notice' ) . ' ' . self::one_line( $row['ip'] );
		$lines[] = __( 'Browser:', 'quiet-copy-notice' ) . ' ' . self::one_line( $row['user_agent'] );
		if ( '' !== $row['referrer_host'] ) {
			$lines[] = __( 'Referrer:', 'quiet-copy-notice' ) . ' ' . self::one_line( $row['referrer_host'] );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Prefixes every line with "> ".
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function quote( $text ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		return '> ' . implode( "\n> ", array_map( array( __CLASS__, 'one_line' ), $lines ) );
	}

	/**
	 * Removes control characters, including line breaks.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function one_line( $text ) {
		return trim( (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $text ) );
	}

	/**
	 * HTML-free single-line text for a plain-text mail.
	 *
	 * @param string $text Text that may contain markup or entities.
	 * @return string
	 */
	private static function plain( $text ) {
		return self::one_line( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
	}
}
