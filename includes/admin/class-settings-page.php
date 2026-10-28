<?php
/**
 * Settings tabs.
 *
 * @package Rapls\CopyNotice
 */

namespace Rapls\CopyNotice\Admin;

use Rapls\CopyNotice\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Notifications, Detection, Privacy and Visitors forms. Each posts to
 * admin-post.php and is validated in Settings::save_tab().
 */
class Settings_Page {

	/**
	 * Current settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings = Settings::all();
	}

	/**
	 * Labels of the fields whose input can be rejected or adjusted on save,
	 * for the notice that names them.
	 *
	 * @return array Key => label.
	 */
	public static function field_labels() {
		return array(
			'notify_email'       => __( 'Send notifications to', 'rapls-copy-notice' ),
			'instant_hourly_cap' => __( 'Instant email limit', 'rapls-copy-notice' ),
			'min_length'         => __( 'Minimum length', 'rapls-copy-notice' ),
			'content_selector'   => __( 'Content area', 'rapls-copy-notice' ),
			'exclude_ips'        => __( 'Ignore IP addresses', 'rapls-copy-notice' ),
			'exclude_posts'      => __( 'Ignore posts', 'rapls-copy-notice' ),
			'trusted_proxies'    => __( 'Trusted proxies', 'rapls-copy-notice' ),
			'rate_ip_per_minute' => __( 'Limit per IP', 'rapls-copy-notice' ),
			'rate_site_per_hour' => __( 'Limit for the site', 'rapls-copy-notice' ),
			'retention_days'     => __( 'Keep log entries for', 'rapls-copy-notice' ),
		);
	}

	/**
	 * Renders one tab.
	 *
	 * @param string $tab Tab key.
	 * @return void
	 */
	public function render( $tab ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Admin::ACTION_SAVE ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
		wp_nonce_field( Admin::ACTION_SAVE . '_' . $tab );

		if ( 'detection' === $tab && empty( $this->settings['post_types'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No post types are selected, so no copies are detected anywhere on the site.', 'rapls-copy-notice' ) . '</p></div>';
		}

		echo '<table class="form-table" role="presentation"><tbody>';
		switch ( $tab ) {
			case 'notifications':
				$this->notifications();
				break;
			case 'detection':
				$this->detection();
				break;
			case 'privacy':
				$this->privacy();
				break;
			case 'visitors':
				$this->visitors();
				break;
		}
		echo '</tbody></table>';

		submit_button();
		echo '</form>';

		$this->after_form( $tab );
	}

	/**
	 * Notifications tab.
	 *
	 * @return void
	 */
	private function notifications() {
		$this->row_start( 'notify_email', __( 'Send notifications to', 'rapls-copy-notice' ) );
		printf(
			'<input type="email" class="regular-text" id="rapls-cn-notify_email" name="rapls_cn[notify_email]" value="%1$s" placeholder="%2$s" aria-describedby="%3$s" />',
			esc_attr( $this->settings['notify_email'] ),
			esc_attr( (string) get_option( 'admin_email' ) ),
			esc_attr( self::help_id( 'notify_email' ) )
		);
		$this->row_end( __( 'Leave empty to use the site administration email address. Example: you@example.com', 'rapls-copy-notice' ), 'notify_email' );

		$this->row_start( 'subject_label', __( 'Email subject', 'rapls-copy-notice' ) );
		printf(
			'<input type="text" class="regular-text" id="rapls-cn-subject_label" name="rapls_cn[subject_label]" value="%1$s" maxlength="%2$d" placeholder="%3$s" aria-describedby="%4$s" />',
			esc_attr( $this->settings['subject_label'] ),
			(int) Settings::SUBJECT_MAX,
			esc_attr__( 'Copy notification', 'rapls-copy-notice' ),
			esc_attr( self::help_id( 'subject_label' ) )
		);
		$this->row_end( __( 'Emails are titled "[Site name] Copy notification (3 copies)". This field changes only the middle part, so you can filter these emails. Nothing a visitor sent appears in the subject.', 'rapls-copy-notice' ), 'subject_label' );

		$this->row_start( '', __( 'When to send', 'rapls-copy-notice' ) );
		echo '<fieldset aria-describedby="' . esc_attr( self::help_id( 'notify_mode' ) ) . '"><legend class="screen-reader-text">' . esc_html__( 'When to send', 'rapls-copy-notice' ) . '</legend>';
		$modes = array(
			Settings::MODE_DIGEST  => __( 'One summary email per hour', 'rapls-copy-notice' ),
			Settings::MODE_INSTANT => __( 'One email per copy, right away', 'rapls-copy-notice' ),
		);
		foreach ( $modes as $value => $label ) {
			printf(
				'<label><input type="radio" name="rapls_cn[notify_mode]" value="%1$s"%2$s /> %3$s</label><br />',
				esc_attr( $value ),
				checked( $this->settings['notify_mode'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		$this->row_end( __( 'WordPress sends summary emails when someone visits the site, so on a quiet site they can arrive more than an hour apart.', 'rapls-copy-notice' ), 'notify_mode' );

		$this->number_row( 'instant_hourly_cap', __( 'Instant email limit', 'rapls-copy-notice' ), __( 'Used only with "One email per copy, right away". Copies over the limit go into the next hourly summary instead.', 'rapls-copy-notice' ), __( 'emails per hour', 'rapls-copy-notice' ) );
	}

	/**
	 * Content after a tab's form.
	 *
	 * @param string $tab Tab key.
	 * @return void
	 */
	private function after_form( $tab ) {
		if ( 'notifications' === $tab ) {
			echo '<h2>' . esc_html__( 'Test email', 'rapls-copy-notice' ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( Admin::ACTION_TEST_EMAIL ) . '" />';
			wp_nonce_field( Admin::ACTION_TEST_EMAIL );
			$to = Settings::notify_email();
			echo '<p>' . esc_html(
				'' !== $to
					? sprintf(
						/* translators: %s: email address. */
						__( 'Sends a short test message to %s, where notifications go now, so you can check that they arrive. If you changed the address above, save first.', 'rapls-copy-notice' ),
						$to
					)
					: __( 'There is no valid address to send notifications to. Enter one in "Send notifications to", or correct the Administration Email Address under Settings → General.', 'rapls-copy-notice' )
			) . '</p>';
			submit_button( __( 'Send a test email', 'rapls-copy-notice' ), 'secondary', 'rapls_cn_send_test', false, '' !== $to ? null : array( 'disabled' => 'disabled' ) );
			echo '</form>';

			Upsell::row(
				__( 'Slack, Chatwork, Discord and webhooks', 'rapls-copy-notice' ),
				__( 'Rapls Copy Notice Pro sends these notifications to your team chat or your own system too, with rules that decide which copies go where.', 'rapls-copy-notice' )
			);
		}
	}

	/**
	 * Detection tab.
	 *
	 * @return void
	 */
	private function detection() {
		$this->number_row( 'min_length', __( 'Minimum length', 'rapls-copy-notice' ), __( 'Copies are recorded when at least this many characters are copied.', 'rapls-copy-notice' ), __( 'characters or more', 'rapls-copy-notice' ) );

		$this->row_start( '', __( 'Post types', 'rapls-copy-notice' ) );
		$this->checkboxes( 'post_types', Settings::available_post_types(), __( 'Post types', 'rapls-copy-notice' ) );
		$this->row_end( __( 'Copies are detected on single views of these post types.', 'rapls-copy-notice' ), 'post_types' );

		$this->row_start( '', __( 'Find the post body', 'rapls-copy-notice' ) );
		$this->checkbox( 'mark_content', __( 'Mark the end of the post body so it is found in any theme', 'rapls-copy-notice' ) );
		$this->row_end( __( 'Adds one empty, hidden element after the post content on monitored pages, and uses the element around it as the content area. When this is on, the selectors below are only a fallback. Leave it off if your theme already uses one of the selectors below.', 'rapls-copy-notice' ), 'mark_content' );

		$this->row_start( 'content_selector', __( 'Content area', 'rapls-copy-notice' ) );
		printf(
			'<input type="text" class="large-text code" id="rapls-cn-content_selector" name="rapls_cn[content_selector]" value="%1$s" aria-describedby="%2$s" />',
			esc_attr( $this->settings['content_selector'] ),
			esc_attr( self::help_id( 'content_selector' ) )
		);
		$this->row_end( __( 'CSS selectors, separated by commas, tried in order. The first selector that matches anything on the page is used, and every element it matches counts as the content area. Copies from outside those elements, or from form fields such as a comment box, are not recorded.', 'rapls-copy-notice' ), 'content_selector' );

		$roles = array();
		foreach ( wp_roles()->get_names() as $role => $name ) {
			$roles[ $role ] = translate_user_role( $name );
		}
		$this->row_start( '', __( 'Ignore logged-in users with these roles', 'rapls-copy-notice' ) );
		$this->checkboxes( 'exclude_roles', $roles, __( 'Ignore logged-in users with these roles', 'rapls-copy-notice' ) );
		$this->row_end( __( 'The detection script is not loaded for logged-in users with these roles.', 'rapls-copy-notice' ), 'exclude_roles' );

		$this->textarea_row( 'exclude_ips', __( 'Ignore IP addresses', 'rapls-copy-notice' ), __( 'One per line. Ranges such as 192.0.2.0/24 are allowed. You can paste an address from the log: when "Shorten IP addresses before storing them" on the Privacy tab is on, the log shows 192.0.2.55 as 192.0.2.0, and entering 192.0.2.0 ignores every address from 192.0.2.0 to 192.0.2.255.', 'rapls-copy-notice' ) );
		$this->textarea_row( 'exclude_posts', __( 'Ignore posts', 'rapls-copy-notice' ), __( 'Post IDs, one per line. The log shows each post\'s ID after its title, for example #123.', 'rapls-copy-notice' ) );
		$this->textarea_row( 'trusted_proxies', __( 'Trusted proxies', 'rapls-copy-notice' ), __( 'Leave empty unless the site is behind a reverse proxy or CDN. Only when a request arrives from one of these addresses is the X-Forwarded-For header used to find the visitor\'s IP.', 'rapls-copy-notice' ) );

		$this->number_row( 'rate_ip_per_minute', __( 'Limit per IP', 'rapls-copy-notice' ), __( 'The maximum number of copy reports accepted from one IP address (IPv6 visitors are counted per network). One address is also held to a fifth of the site limit per hour, so it cannot use up the site limit alone.', 'rapls-copy-notice' ), __( 'per minute', 'rapls-copy-notice' ) );
		$this->number_row( 'rate_site_per_hour', __( 'Limit for the site', 'rapls-copy-notice' ), __( 'The maximum number of copy reports accepted in total. Reports over either limit are neither stored nor emailed.', 'rapls-copy-notice' ), __( 'per hour', 'rapls-copy-notice' ) );
	}

	/**
	 * Privacy tab.
	 *
	 * @return void
	 */
	private function privacy() {
		$this->row_start( '', __( 'IP addresses', 'rapls-copy-notice' ) );
		$this->checkbox( 'anonymize_ip', __( 'Shorten IP addresses before storing them', 'rapls-copy-notice' ) );
		$this->row_end( __( 'Removes the last part of IPv4 addresses and the last 80 bits of IPv6 addresses. Log entries already stored are not changed.', 'rapls-copy-notice' ), 'anonymize_ip' );

		$this->number_row( 'retention_days', __( 'Keep log entries for', 'rapls-copy-notice' ), __( 'Older log entries are deleted once a day.', 'rapls-copy-notice' ), __( 'days', 'rapls-copy-notice' ) );

		$this->row_start( '', __( 'Privacy policy', 'rapls-copy-notice' ) );
		printf(
			'<a href="%1$s" aria-describedby="%3$s">%2$s</a>',
			esc_url( admin_url( 'options-privacy.php?tab=policyguide' ) ),
			esc_html__( 'Suggested text for your privacy policy', 'rapls-copy-notice' ),
			esc_attr( self::help_id( 'privacy_policy' ) )
		);
		$this->row_end( __( 'Rapls Copy Notice itself sets no cookies and sends nothing to outside services. An add-on that sends notifications elsewhere adds that to the suggested text.', 'rapls-copy-notice' ), 'privacy_policy' );
	}

	/**
	 * Visitors tab: what readers see or get when they copy. Both are off by
	 * default. They run in the browser, so they follow the Detection settings
	 * the browser knows about, not the checks made on the server.
	 *
	 * @return void
	 */
	private function visitors() {
		$this->row_start( '', __( 'Message after copying', 'rapls-copy-notice' ) );
		$this->checkbox( 'show_message', __( 'Show a short message to the reader after they copy', 'rapls-copy-notice' ) );
		$this->row_end( __( 'Shown for five seconds at the bottom of the screen. It does not stop the copy.', 'rapls-copy-notice' ), 'show_message' );

		$default_message = __( 'Thank you for reading. If you quote this article, please link to it.', 'rapls-copy-notice' );
		/* translators: %s: the default wording. */
		$message_help = sprintf( __( 'Plain text. Leave empty to use: "%s"', 'rapls-copy-notice' ), $default_message );
		$this->text_row( 'message_text', __( 'Message', 'rapls-copy-notice' ), Settings::MESSAGE_MAX, $default_message, $message_help );

		$this->row_start( '', __( 'Source link', 'rapls-copy-notice' ) );
		$this->checkbox( 'add_attribution', __( 'Add the post title and URL to copied text', 'rapls-copy-notice' ) );
		$this->row_end( __( 'Appended after a blank line, for example "Source: Post title" and the URL on the next line.', 'rapls-copy-notice' ), 'add_attribution' );

		$default_label = __( 'Source:', 'rapls-copy-notice' );
		/* translators: %s: the default wording. */
		$label_help = sprintf( __( 'Leave empty to use: "%s"', 'rapls-copy-notice' ), $default_label );
		$this->text_row( 'attribution_label', __( 'Source label', 'rapls-copy-notice' ), Settings::LABEL_MAX, $default_label, $label_help );

		$this->row_start( '', __( 'Applies to', 'rapls-copy-notice' ) );
		echo '<p>' . esc_html__( 'Both options follow the Detection settings for the minimum length, the content area, post types, ignored posts and ignored roles. Ignored IP addresses and the rate limits are checked on the server, so readers they apply to still see the message and get the source line. Copies from form fields, such as a comment the reader is typing, are left alone: not recorded, no message, no source line.', 'rapls-copy-notice' ) . '</p>';
		$this->row_end();
	}

	/**
	 * A single checkbox; a hidden 0 is sent when it is unchecked.
	 *
	 * @param string $key   Field key.
	 * @param string $label Checkbox label.
	 * @return void
	 */
	private function checkbox( $key, $label ) {
		printf(
			'<input type="hidden" name="rapls_cn[%1$s]" value="0" /><label><input type="checkbox" name="rapls_cn[%1$s]" value="1"%2$s aria-describedby="%4$s" /> %3$s</label>',
			esc_attr( $key ),
			checked( $this->settings[ $key ], true, false ),
			esc_html( $label ),
			esc_attr( self::help_id( $key ) )
		);
	}

	/**
	 * A one-line text input row.
	 *
	 * @param string $key         Field key.
	 * @param string $label       Label.
	 * @param int    $max         Maximum length.
	 * @param string $placeholder Placeholder, the built-in wording.
	 * @param string $help        Help text.
	 * @return void
	 */
	private function text_row( $key, $label, $max, $placeholder, $help ) {
		$this->row_start( $key, $label );
		printf(
			'<input type="text" class="large-text" id="%1$s" name="rapls_cn[%2$s]" value="%3$s" maxlength="%4$d" placeholder="%5$s" aria-describedby="%6$s" />',
			esc_attr( 'rapls-cn-' . $key ),
			esc_attr( $key ),
			esc_attr( $this->settings[ $key ] ),
			(int) $max,
			esc_attr( $placeholder ),
			esc_attr( self::help_id( $key ) )
		);
		$this->row_end( $help, $key );
	}

	/**
	 * Opens a form row.
	 *
	 * @param string $key   Field key, or empty when the label does not point at one input.
	 * @param string $label Row label.
	 * @return void
	 */
	private function row_start( $key, $label ) {
		echo '<tr><th scope="row">';
		if ( '' !== $key ) {
			echo '<label for="' . esc_attr( 'rapls-cn-' . $key ) . '">' . esc_html( $label ) . '</label>';
		} else {
			echo esc_html( $label );
		}
		echo '</th><td>';
	}

	/**
	 * Closes a form row.
	 *
	 * @param string $help Help text.
	 * @param string $key  Field key: the help text gets the id that the input
	 *                     points at with aria-describedby.
	 * @return void
	 */
	private function row_end( $help = '', $key = '' ) {
		if ( '' !== $help ) {
			echo '<p class="description"' . ( '' !== $key ? ' id="' . esc_attr( self::help_id( $key ) ) . '"' : '' ) . '>' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Id of a field's help text.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private static function help_id( $key ) {
		return 'rapls-cn-' . $key . '-description';
	}

	/**
	 * A number input row with the field's bounds.
	 *
	 * @param string $key   Field key.
	 * @param string $label Label.
	 * @param string $help  Help text.
	 * @param string $unit  Text after the input, such as "characters or more".
	 * @return void
	 */
	private function number_row( $key, $label, $help, $unit = '' ) {
		$bounds = Settings::bounds()[ $key ];
		$this->row_start( $key, $label );
		printf(
			'<input type="number" class="small-text" id="%1$s" name="rapls_cn[%2$s]" value="%3$d" min="%4$d" max="%5$d" step="1" aria-describedby="%7$s %8$s" /> <span id="%7$s">%6$s</span>',
			esc_attr( 'rapls-cn-' . $key ),
			esc_attr( $key ),
			(int) $this->settings[ $key ],
			(int) $bounds[0],
			(int) $bounds[1],
			esc_html( $unit ),
			esc_attr( 'rapls-cn-' . $key . '-unit' ),
			esc_attr( self::help_id( $key ) )
		);
		$this->row_end( $help, $key );
	}

	/**
	 * A one-entry-per-line textarea row.
	 *
	 * @param string $key   Field key.
	 * @param string $label Label.
	 * @param string $help  Help text.
	 * @return void
	 */
	private function textarea_row( $key, $label, $help ) {
		$this->row_start( $key, $label );
		printf(
			'<textarea class="large-text code" rows="4" id="%1$s" name="rapls_cn[%2$s]" aria-describedby="%4$s">%3$s</textarea>',
			esc_attr( 'rapls-cn-' . $key ),
			esc_attr( $key ),
			esc_textarea( implode( "\n", (array) $this->settings[ $key ] ) ),
			esc_attr( self::help_id( $key ) )
		);
		$this->row_end( $help, $key );
	}

	/**
	 * A group of checkboxes.
	 *
	 * @param string $key     Field key.
	 * @param array  $choices Value => label.
	 * @param string $legend  Group name for screen readers.
	 * @return void
	 */
	private function checkboxes( $key, array $choices, $legend ) {
		echo '<fieldset aria-describedby="' . esc_attr( self::help_id( $key ) ) . '"><legend class="screen-reader-text">' . esc_html( $legend ) . '</legend>';
		foreach ( $choices as $value => $label ) {
			printf(
				'<label><input type="checkbox" name="rapls_cn[%1$s][]" value="%2$s"%3$s /> %4$s</label><br />',
				esc_attr( $key ),
				esc_attr( $value ),
				checked( in_array( (string) $value, (array) $this->settings[ $key ], true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}
}
