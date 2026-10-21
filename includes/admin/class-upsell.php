<?php
/**
 * Information about the optional Pro add-on.
 *
 * @package QuietCopyNotice
 */

namespace QuietCopyNotice\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the separate Pro add-on on this plugin's own screen.
 *
 * Nothing here is a feature, a setting or a gate: every free feature works
 * fully without the add-on. The add-on is detected only through the
 * qcnotice_is_pro filter, which the add-on itself turns on. Shown only to
 * administrators, only on this plugin's screen and its row on the Plugins
 * screen, never as a site-wide notice or on the dashboard. External links are
 * limited to the two buttons on the Pro features tab; every other mention
 * links to that tab.
 */
class Upsell {

	/**
	 * Whether the Pro add-on is active.
	 *
	 * @return bool
	 */
	public static function pro_active() {
		/**
		 * Set to true by the Pro add-on.
		 *
		 * @param bool $active Default false.
		 */
		return (bool) apply_filters( QCNOTICE_PREFIX . 'is_pro', false );
	}

	/**
	 * Whether to describe the add-on at all.
	 *
	 * @return bool
	 */
	public static function visible() {
		if ( self::pro_active() || ! current_user_can( Admin::CAPABILITY ) ) {
			return false;
		}
		/**
		 * Return false to hide every mention of the Pro add-on.
		 *
		 * @param bool $show Default true.
		 */
		return (bool) apply_filters( QCNOTICE_PREFIX . 'show_upsell', true );
	}

	/**
	 * The add-on's page on the author's site, in the admin's language.
	 *
	 * @return string
	 */
	public static function url() {
		$url = 0 === strpos( (string) get_user_locale(), 'ja' )
			? 'https://raplsworks.com/quiet-copy-notice-pro-ja/'
			: 'https://raplsworks.com/quiet-copy-notice-pro-en/';

		/**
		 * Filters the link to the Pro add-on's page.
		 *
		 * @param string $url URL.
		 */
		return (string) apply_filters( QCNOTICE_PREFIX . 'pro_url', $url );
	}

	/**
	 * A one-line note next to a related screen, linking to the Pro features
	 * tab. Not a control: no inputs, no disabled state, no external link.
	 *
	 * @param string $title Short title (translated by the caller).
	 * @param string $body  One sentence (translated by the caller).
	 * @return void
	 */
	public static function row( $title, $body ) {
		if ( ! self::visible() ) {
			return;
		}
		printf(
			'<div class="qcnotice-pro-row" role="note"><div class="qcnotice-pro-row__main"><p class="qcnotice-pro-row__title">%1$s <span class="qcnotice-pro-badge">PRO</span></p><p class="qcnotice-pro-row__body">%2$s</p></div><a class="button button-secondary" href="%3$s">%4$s</a></div>',
			esc_html( $title ),
			esc_html( $body ),
			esc_url( Admin::url( 'pro' ) ),
			esc_html__( 'View Pro features', 'quiet-copy-notice' )
		);
	}

	/**
	 * The Pro features tab: a description and two links, nothing to set.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! self::visible() ) {
			return;
		}

		$url = self::url();
		$cta = sprintf(
			'<a class="qcnotice-pro-cta" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>',
			esc_url( $url ),
			esc_html__( 'See Quiet Copy Notice Pro', 'quiet-copy-notice' ),
			/* translators: Hidden accessibility text. */
			esc_html__( '(opens in a new tab)', 'quiet-copy-notice' )
		);

		echo '<div class="qcnotice-pro-page">';

		echo '<section class="qcnotice-pro-hero">';
		echo '<span class="qcnotice-pro-eyebrow">' . esc_html__( 'Quiet Copy Notice Pro — optional add-on', 'quiet-copy-notice' ) . '</span>';
		echo '<h2>' . esc_html__( 'See which passages are taken, and where they end up.', 'quiet-copy-notice' ) . '</h2>';
		echo '<p>' . esc_html__( 'Everything on the other tabs is free, and stays free: copy detection, the log, email notifications, the reader message and source link, the dashboard widget and Site Health checks. There is no trial and no limit tied to Pro.', 'quiet-copy-notice' ) . '</p>';
		echo '<p>' . esc_html__( 'Pro is a separate plugin for sites that want to act on copies, not only hear about them: understand what is copied, tell the right people, and find where it was reposted.', 'quiet-copy-notice' ) . '</p>';
		echo '<p>' . $cta . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
		echo '</section>';

		echo '<section class="qcnotice-pro-section"><h2>' . esc_html__( 'Free covers detection. Pro covers what you do next.', 'quiet-copy-notice' ) . '</h2>';
		echo '<table class="qcnotice-pro-steps"><thead><tr><th scope="col">' . esc_html__( 'Free', 'quiet-copy-notice' ) . '</th><th scope="col">' . esc_html__( 'Pro adds', 'quiet-copy-notice' ) . '</th></tr></thead><tbody>';
		foreach ( array(
			array( __( 'Log of every copy', 'quiet-copy-notice' ), __( 'Statistics by post and by paragraph, against the previous period', 'quiet-copy-notice' ) ),
			array( __( 'Email: hourly summary or instant', 'quiet-copy-notice' ), __( 'Slack, Chatwork, Discord and your own webhook', 'quiet-copy-notice' ) ),
			array( __( 'One address for every copy', 'quiet-copy-notice' ), __( 'Rules that send each copy to the right place, and burst alerts', 'quiet-copy-notice' ) ),
			array( __( 'Copies of the same paragraph, listed together', 'quiet-copy-notice' ), __( 'Daily web search for those paragraphs, with your own search API key', 'quiet-copy-notice' ) ),
			array( __( 'Log on screen', 'quiet-copy-notice' ), __( 'CSV export, safe to open in a spreadsheet', 'quiet-copy-notice' ) ),
			array( __( 'Works on each site of a network', 'quiet-copy-notice' ), __( 'The 5-site license covers a whole network, with an overview of every site', 'quiet-copy-notice' ) ),
		) as $row ) {
			echo '<tr><td>' . esc_html( $row[0] ) . '</td><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		echo '</tbody></table></section>';

		echo '<section class="qcnotice-pro-section"><h2>' . esc_html__( 'What Pro adds', 'quiet-copy-notice' ) . '</h2><div class="qcnotice-pro-grid">';
		foreach ( array(
			array( __( 'Know which paragraphs are copied', 'quiet-copy-notice' ), __( 'Copies per day for 7, 30 or 90 days drawn against the period before, the most copied posts, and the paragraphs inside each post that readers copy most.', 'quiet-copy-notice' ) ),
			array( __( 'Notify your team where it works', 'quiet-copy-notice' ), __( 'Send each notification to Slack, Chatwork, Discord or your own system. Visitor text is quoted so it cannot mention anyone or add links.', 'quiet-copy-notice' ) ),
			array( __( 'Route by post, category and length', 'quiet-copy-notice' ), __( 'Decide which copies go to which channel, including email, and drop the ones you do not need to hear about.', 'quiet-copy-notice' ) ),
			array( __( 'Hear about bursts at once', 'quiet-copy-notice' ), __( 'Get an alert as soon as one post is copied unusually often, instead of waiting for the hourly summary.', 'quiet-copy-notice' ) ),
			array( __( 'Find reposts', 'quiet-copy-notice' ), __( 'Once a day, the most copied paragraphs are searched for on the web with your own Brave Search or SerpApi key. Review the pages found and mark them confirmed or ignored.', 'quiet-copy-notice' ) ),
			array( __( 'Export for reports', 'quiet-copy-notice' ), __( 'Download the log for a date range or one post as CSV, with cells that cannot run as spreadsheet formulas.', 'quiet-copy-notice' ) ),
		) as $card ) {
			echo '<div class="qcnotice-pro-card"><h3>' . esc_html( $card[0] ) . '</h3><p>' . esc_html( $card[1] ) . '</p></div>';
		}
		echo '</div></section>';

		echo '<section class="qcnotice-pro-section"><h2>' . esc_html__( 'Recommended for', 'quiet-copy-notice' ) . '</h2><ul class="qcnotice-pro-list">';
		foreach ( array(
			__( 'Media sites and blogs whose articles are often quoted or reposted', 'quiet-copy-notice' ),
			__( 'Teams that work in Slack, Chatwork or Discord rather than email', 'quiet-copy-notice' ),
			__( 'Editors who want to know which passages readers value most', 'quiet-copy-notice' ),
			__( 'Networks of sites managed from one place', 'quiet-copy-notice' ),
		) as $item ) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ul></section>';

		echo '<section class="qcnotice-pro-footer"><h2>' . esc_html__( 'Want to act on copies, not just count them?', 'quiet-copy-notice' ) . '</h2>';
		echo '<p>' . $cta . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
		echo '<p class="description">' . esc_html__( 'One-time purchase, no subscription. Pro is installed next to this plugin and adds its tabs to this screen; your log and settings stay as they are.', 'quiet-copy-notice' ) . '</p>';
		echo '</section>';

		echo '</div>';
	}
}
