<?php
/**
 * Settings storage and validation.
 *
 * @package QuietCopyNotice
 */

namespace QuietCopyNotice;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All settings live in one non-autoloaded array option. Reads merge over the
 * defaults, so the plugin works before the settings screen is ever saved.
 */
class Settings {

	const OPTION = QCNOTICE_PREFIX . 'settings';

	const MODE_DIGEST  = 'digest';
	const MODE_INSTANT = 'instant';

	/**
	 * Most entries accepted in an IP or post ID list.
	 */
	const LIST_MAX = 500;

	/**
	 * Longest subject label accepted.
	 */
	const SUBJECT_MAX = 100;

	/**
	 * Longest message shown to visitors after a copy.
	 */
	const MESSAGE_MAX = 200;

	/**
	 * Longest label put before the source link.
	 */
	const LABEL_MAX = 50;

	/**
	 * Per-request cache of the merged settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default values.
	 *
	 * An empty notify_email means "the site admin address", resolved when a
	 * mail is sent so a later change of admin email is picked up. Empty
	 * visitor-side texts likewise mean "the built-in wording", so they follow
	 * the site language instead of the language at activation.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'notify_email'       => '',
			'subject_label'      => '',
			'notify_mode'        => self::MODE_DIGEST,
			'instant_hourly_cap' => 20,
			'min_length'         => 20,
			'post_types'         => array( 'post', 'page' ),
			'content_selector'   => '.entry-content, .post-content, article',
			'mark_content'       => false,
			'exclude_roles'      => array( 'administrator', 'editor' ),
			'exclude_ips'        => array(),
			'exclude_posts'      => array(),
			'trusted_proxies'    => array(),
			'rate_ip_per_minute' => 10,
			'rate_site_per_hour' => 500,
			'anonymize_ip'       => true,
			'retention_days'     => 90,
			'show_message'       => false,
			'message_text'       => '',
			'add_attribution'    => false,
			'attribution_label'  => '',
		);
	}

	/**
	 * Numeric bounds, as [min, max].
	 *
	 * @return array
	 */
	public static function bounds() {
		return array(
			'instant_hourly_cap' => array( 1, 1000 ),
			'min_length'         => array( 1, 500 ),
			'rate_ip_per_minute' => array( 1, 1000 ),
			'rate_site_per_hour' => array( 1, 100000 ),
			'retention_days'     => array( 1, 3650 ),
		);
	}

	/**
	 * Fields on each settings tab. Saving a tab only touches its own fields.
	 *
	 * @return array
	 */
	public static function tab_fields() {
		return array(
			'notifications' => array( 'notify_email', 'subject_label', 'notify_mode', 'instant_hourly_cap' ),
			'detection'     => array( 'min_length', 'post_types', 'mark_content', 'content_selector', 'exclude_roles', 'exclude_ips', 'exclude_posts', 'trusted_proxies', 'rate_ip_per_minute', 'rate_site_per_hour' ),
			'privacy'       => array( 'anonymize_ip', 'retention_days' ),
			'visitors'      => array( 'show_message', 'message_text', 'add_attribution', 'attribution_label' ),
		);
	}

	/**
	 * Returns all settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			self::$cache = self::coerce( array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) ) );
		}
		return self::$cache;
	}

	/**
	 * Gives every value the type of its default, so a value damaged in the
	 * database (by hand or by another plugin) cannot break the front end.
	 * A value of the wrong kind falls back to the default.
	 *
	 * @param array $settings Merged settings.
	 * @return array
	 */
	private static function coerce( array $settings ) {
		foreach ( self::defaults() as $key => $default ) {
			$value = $settings[ $key ];
			if ( is_array( $default ) ) {
				$settings[ $key ] = is_array( $value ) ? array_values( array_filter( $value, 'is_scalar' ) ) : $default;
			} elseif ( is_bool( $default ) ) {
				$settings[ $key ] = is_scalar( $value ) ? (bool) $value : $default;
			} elseif ( is_int( $default ) ) {
				$settings[ $key ] = is_numeric( $value ) ? (int) $value : $default;
			} else {
				$settings[ $key ] = is_scalar( $value ) ? (string) $value : $default;
			}
		}
		return $settings;
	}

	/**
	 * Returns one setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Address notifications go to.
	 *
	 * @return string Empty when no valid address is available.
	 */
	public static function notify_email() {
		$email = self::get( 'notify_email' );
		if ( '' === $email ) {
			$email = get_option( 'admin_email' );
		}
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Message shown to visitors after a copy.
	 *
	 * @return string
	 */
	public static function message_text() {
		$text = self::get( 'message_text' );
		return '' === $text ? __( 'Thank you for reading. If you quote this article, please link to it.', 'rapls-copy-notice' ) : $text;
	}

	/**
	 * Label put before the source title and URL in copied text.
	 *
	 * @return string
	 */
	public static function attribution_label() {
		$label = self::get( 'attribution_label' );
		return '' === $label ? __( 'Source:', 'rapls-copy-notice' ) : $label;
	}

	/**
	 * Creates the option with autoload off if it does not exist yet.
	 *
	 * @return void
	 */
	public static function ensure_option() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, array_merge( self::defaults(), self::from_ccc() ), '', false );
		}
	}

	/**
	 * Settings carried over from Check Copy Contents, if its options are still
	 * in the database. Read once, when this plugin's option is first created.
	 *
	 * Those values were saved by a plugin with no CSRF protection, so they go
	 * through the same validation as a form submission. The "notify for
	 * logged-in users" flag is not carried over: CCC switched it on by default,
	 * and this plugin keeps ignoring administrators and editors unless told
	 * otherwise.
	 *
	 * @return array Validated settings; empty when there is nothing to import.
	 */
	public static function from_ccc() {
		$imported = array();

		$email = get_option( 'ccc_plugin_value_mail' );
		if ( is_string( $email ) && '' !== $email && get_option( 'admin_email' ) !== $email ) {
			$email = self::sanitize_field( 'notify_email', $email );
			if ( '' !== $email ) {
				$imported['notify_email'] = $email;
			}
		}

		$letters = get_option( 'ccc_plugin_value_letters' );
		if ( is_scalar( $letters ) && ctype_digit( (string) $letters ) ) {
			$imported['min_length'] = self::sanitize_field( 'min_length', $letters );
		}

		// CCC stored the subject HTML-encoded. Its own default is not carried over.
		$subject = get_option( 'ccc_plugin_value_subject' );
		if ( is_string( $subject ) ) {
			$subject = html_entity_decode( $subject, ENT_QUOTES, 'UTF-8' );
			if ( ! in_array( $subject, array( '', 'Blog copy notification', '[From:CCC]ブログのコピー通知' ), true ) ) {
				$imported['subject_label'] = self::sanitize_field( 'subject_label', $subject );
			}
		}

		return array_filter(
			$imported,
			static function ( $value ) {
				return '' !== $value;
			}
		);
	}

	/**
	 * Validates the submitted fields of one tab and saves them.
	 *
	 * @param string $tab   Tab key from tab_fields().
	 * @param array  $input Raw, unslashed form input.
	 * @return array|false Keys of the fields whose input was not valid and was
	 *                     replaced, adjusted or partly dropped; false when the
	 *                     tab is unknown.
	 */
	public static function save_tab( $tab, array $input ) {
		$fields = self::tab_fields();
		if ( ! isset( $fields[ $tab ] ) ) {
			return false;
		}

		$settings = self::all();
		$adjusted = array();
		foreach ( $fields[ $tab ] as $key ) {
			$raw              = isset( $input[ $key ] ) ? $input[ $key ] : null;
			$settings[ $key ] = self::sanitize_field( $key, $raw );
			if ( self::was_adjusted( $key, $raw, $settings[ $key ] ) ) {
				$adjusted[] = $key;
			}
		}

		self::ensure_option();
		update_option( self::OPTION, $settings, false );
		self::$cache = null;
		return $adjusted;
	}

	/**
	 * Whether a submitted value was not stored as the admin entered it.
	 * Whitespace, letter case and duplicate list entries do not count.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $raw   Submitted value.
	 * @param mixed  $clean Stored value.
	 * @return bool
	 */
	private static function was_adjusted( $key, $raw, $clean ) {
		if ( null === $raw ) {
			return false;
		}
		if ( isset( self::bounds()[ $key ] ) ) {
			return ! is_scalar( $raw ) || trim( (string) $raw ) !== (string) $clean;
		}
		switch ( $key ) {
			case 'notify_email':
				return is_string( $raw ) && '' !== trim( $raw ) && '' === $clean;
			case 'content_selector':
				return ! is_string( $raw ) || trim( (string) preg_replace( '/\s+/', ' ', $raw ) ) !== $clean;
			case 'exclude_ips':
			case 'trusted_proxies':
			case 'exclude_posts':
				return count( array_unique( array_map( 'strtolower', self::split_lines( $raw ) ) ) ) !== count( $clean );
		}
		return false;
	}

	/**
	 * Validates one field. Unknown or malformed input falls back to the
	 * default or is dropped; it is never stored as given.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	public static function sanitize_field( $key, $value ) {
		$defaults = self::defaults();
		$bounds   = self::bounds();

		if ( isset( $bounds[ $key ] ) ) {
			// Anything but a plain non-negative integer falls back to the default.
			if ( ! is_scalar( $value ) || ! ctype_digit( trim( (string) $value ) ) ) {
				return $defaults[ $key ];
			}
			return min( $bounds[ $key ][1], max( $bounds[ $key ][0], absint( trim( (string) $value ) ) ) );
		}

		switch ( $key ) {
			case 'notify_email':
				// Reject rather than repair: sanitize_email() would turn "a@b.c\nBcc: x" into a different address.
				$email = is_string( $value ) ? trim( $value ) : '';
				return ( is_email( $email ) && sanitize_email( $email ) === $email ) ? $email : '';

			case 'notify_mode':
				return in_array( $value, array( self::MODE_DIGEST, self::MODE_INSTANT ), true ) ? $value : self::MODE_DIGEST;

			case 'post_types':
				return self::sanitize_whitelist( $value, array_keys( self::available_post_types() ) );

			case 'exclude_roles':
				return self::sanitize_whitelist( $value, array_keys( wp_roles()->roles ) );

			case 'content_selector':
				return self::sanitize_selector( $value );

			case 'exclude_ips':
			case 'trusted_proxies':
				return self::sanitize_ip_list( $value );

			case 'exclude_posts':
				return self::sanitize_id_list( $value );

			case 'subject_label':
				// Admin text only. sanitize_text_field() also removes line breaks, so it cannot add mail headers.
				return is_string( $value ) ? mb_substr( sanitize_text_field( $value ), 0, self::SUBJECT_MAX ) : '';

			case 'message_text':
				return is_string( $value ) ? mb_substr( sanitize_text_field( $value ), 0, self::MESSAGE_MAX ) : '';

			case 'attribution_label':
				return is_string( $value ) ? mb_substr( sanitize_text_field( $value ), 0, self::LABEL_MAX ) : '';

			case 'anonymize_ip':
			case 'mark_content':
			case 'show_message':
			case 'add_attribution':
				return ! empty( $value );
		}

		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : null;
	}

	/**
	 * Public post types an admin can pick from.
	 *
	 * @return array Name => label.
	 */
	public static function available_post_types() {
		$types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
			if ( 'attachment' === $name ) {
				continue;
			}
			$types[ $name ] = $object->labels->singular_name;
		}
		return $types;
	}

	/**
	 * Keeps only values from an allowed list.
	 *
	 * @param mixed $value   Submitted values.
	 * @param array $allowed Allowed values.
	 * @return array
	 */
	private static function sanitize_whitelist( $value, array $allowed ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$value = array_map( 'strval', array_filter( $value, 'is_scalar' ) );
		return array_values( array_intersect( $allowed, $value ) );
	}

	/**
	 * Accepts a CSS selector list made of ordinary selector characters only.
	 *
	 * @param mixed $value Submitted selector.
	 * @return string
	 */
	private static function sanitize_selector( $value ) {
		$default = self::defaults()['content_selector'];
		if ( ! is_string( $value ) ) {
			return $default;
		}
		$value = trim( preg_replace( '/\s+/', ' ', $value ) );
		if ( '' === $value || strlen( $value ) > 500 || ! preg_match( '/^[\p{L}\p{N}_\-\.#,:\s\[\]="\'>+~*()^$|!]+$/u', $value ) ) {
			return $default;
		}
		return $value;
	}

	/**
	 * Splits a textarea into lines and keeps valid IP addresses and CIDR ranges.
	 *
	 * @param mixed $value Submitted list (string or array).
	 * @return array
	 */
	private static function sanitize_ip_list( $value ) {
		$out = array();
		foreach ( self::split_lines( $value ) as $line ) {
			if ( Privacy::is_valid_range( $line ) ) {
				$out[] = strtolower( $line );
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, self::LIST_MAX );
	}

	/**
	 * Splits a textarea into positive integers.
	 *
	 * @param mixed $value Submitted list (string or array).
	 * @return array
	 */
	private static function sanitize_id_list( $value ) {
		$out = array();
		foreach ( self::split_lines( $value ) as $line ) {
			if ( ctype_digit( $line ) && absint( $line ) > 0 ) {
				$out[] = absint( $line );
			}
		}
		return array_slice( array_values( array_unique( $out ) ), 0, self::LIST_MAX );
	}

	/**
	 * Splits on newlines and commas, trimming each entry.
	 *
	 * @param mixed $value String or array.
	 * @return array
	 */
	private static function split_lines( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( "\n", array_filter( $value, 'is_scalar' ) );
		}
		if ( ! is_string( $value ) ) {
			return array();
		}
		return array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $value ) ), 'strlen' );
	}
}
