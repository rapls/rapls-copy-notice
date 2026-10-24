=== Quiet Copy Notice ===
Contributors: rapls
Tags: copy, notification, analytics, content protection, copy tracking
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.3.16
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Copy notification and copy tracking for posts: logs what visitors copy and emails you a plain-text summary. Contacts no outside service.

== Description ==

When a visitor selects text in one of your posts and copies it, Quiet Copy
Notice records what was copied and from which post, and tells you by email.
It does not stop anyone from copying. Readers can still select, copy and
right-click as usual; you simply find out which passages are being taken.

* A log under **Tools → Quiet Copy Notice**: date, post, copied text, shortened IP
  address and browser. Filter by post, delete selected entries or everything.
* Email notifications, either one summary per hour (the default) or one email
  per copy with an hourly cap, after which copies roll into the summary.
* Only copies of at least 20 characters (adjustable), only from the post body,
  and only on the post types you choose. If your theme's markup is unusual, the
  plugin can mark the end of the post body itself so it is found in any theme.
* Copies by logged-in administrators and editors are ignored by default. You
  can also ignore IP addresses (CIDR ranges allowed) and individual posts.
* Your own subject line, so the emails are easy to filter.
* A dashboard widget with the number of copies in the last 7 days and the three
  most copied posts.
* Checks in **Tools → Site Health** for the log table, the scheduled tasks, the
  notification address, notifications that keep failing, the monitored post
  types, proxy setup and database locks.
* Two optional features for readers, both off by default: a short message after
  they copy (for example, asking them to link to the source), and the post title
  and URL appended to the copied text.
* Log entries are deleted automatically after 90 days (adjustable).
* Works with page caching: the detection script needs no per-visitor token.

= Quiet Copy Notice Pro (optional add-on) =

Everything described above is free, with no trial and no limits tied to an
upgrade, and this plugin does not need anything else to work. Quiet Copy Notice
Pro is a separate plugin, sold on the author's website, for sites that want to
act on copies:

* Statistics by post and by paragraph, compared with the previous period.
* Notifications to Slack, Chatwork, Discord or your own webhook, with a delivery history and retries, and a choice of which visitor data they receive.
* Rules that route each copy to the right channel, and burst alerts.
* Daily web search for your most copied paragraphs, with your own search API key.
* CSV export of the log.
* Multisite: the 5-site license covers a whole network, with an overview of every site.

One-time purchase, no subscription. Details: https://raplsworks.com/quiet-copy-notice-pro-en/

The plugin's screen has a **Pro features** tab describing the add-on, and short
notes about it on the Log and Notifications tabs and in its row on the Plugins
screen. All of them disappear once the add-on is active, and the
`qcnotice_show_upsell` filter hides them (return `false`).

= Paragraphs =

Each copy also records a fingerprint of the paragraph it came from: a SHA-1 hash
of that paragraph's text, worked out in the reader's browser. The paragraph
itself is not stored. In the log, **Copies of this paragraph** lists every copy
taken from the same paragraph. Browsers only offer the hashing function on HTTPS
pages, so sites served over plain HTTP record copies without it.

= For developers =

* `qcnotice_logged` (action) fires after a copy is stored, with the row ID and
  the stored values.
* `qcnotice_notification_delivered` (filter) lets an add-on send each
  notification to another channel; return `true` when it was delivered.
* `qcnotice_email_rows` (filter) lets an add-on choose which rows go into the
  notification email; an empty array skips the email for that notification.
* `qcnotice_is_pro` (filter) is set to `true` by the Pro add-on;
  `qcnotice_show_upsell` and `qcnotice_pro_url` control the Pro features tab.
* `qcnotice_privacy_policy_paragraphs` (filter) gets the suggested privacy
  policy paragraphs, keyed `collected`, `ip`, `use` and `retention`, as plain text;
  an add-on that sends copy records out should replace `use`.
* `qcnotice_log_emptied` (action) fires after "Delete all" has removed every log
  entry. New entries are numbered from 1 again, so an add-on that keeps entry
  IDs should drop them here.
* `qcnotice_admin_tabs` (filter), `qcnotice_admin_load` and
  `qcnotice_render_tab_{key}` (actions) let an add-on add tabs to the plugin's
  screen.

Values that came from a visitor's browser are passed to these hooks as stored.
Escape them for wherever you output them.

= How visitor data is handled =

Everything a copy report contains comes from the visitor's browser, so the
plugin treats all of it as untrusted.

* **Escaped wherever it is shown.** The copied text, user agent, referrer and IP
  address are escaped every time they are printed in the admin screens, for the
  exact place they appear (HTML text, attribute or URL). The plugin does not
  rely on cleaning values when they are saved.
* **Plain-text email only.** Notifications are always sent as `text/plain`. The
  subject line contains your site name, the label you set (by default "Copy
  notification") and a count, never anything a visitor sent or a post title. Copied text is quoted line by line in
  the body so it cannot imitate other fields.
* **Rate limited.** By default at most 10 reports per minute from one IP address
  and 500 per hour for the whole site are accepted. One address is also held to
  a fifth of the site limit per hour (100 by default), so it cannot use up the
  site limit alone, and IPv6 addresses are counted per /64 block. Reports over a
  limit are neither stored nor emailed.
* **Checked against real posts.** A report is accepted only for a published
  post of a monitored type. Copied text is capped at 500 characters and the
  user agent and referrer host at 255.
* **Nothing echoed back.** The endpoint answers with a status code and an empty
  body.
* **IP addresses shortened by default.** The last part of an IPv4 address and
  the last 80 bits of an IPv6 address are removed before storage. Headers such
  as `X-Forwarded-For` are ignored unless you list your reverse proxy as trusted,
  and then only for requests that come from that proxy.

= Privacy =

Quiet Copy Notice sets no cookies and stores nothing in the visitor's
browser. To enforce the rate limits, the site keeps a one-way hash of each
visitor's IP address (of its /64 block for IPv6) for up to an hour, in a
counter that then expires. The optional reader message and source link use
only the post's own title and URL and your text; they record nothing extra.
This plugin never contacts any external service: copy reports go only to your
own site, and notifications are sent with WordPress's own mail function,
through whatever mail system the site uses (an SMTP plugin or email service,
for example). Suggested wording for your privacy policy is added to
**Settings → Privacy**.

An add-on can send notifications elsewhere: Quiet Copy Notice Pro, for
example, sends them to the chat services or webhook you switch on. It then
changes the suggested privacy policy wording to name those services and what
they receive.

== Installation ==

1. Install and activate the plugin.
2. That's it. With the default settings, copies of 20 or more characters from
   posts and pages are logged and summarized to the site administration email
   once an hour.
3. Optional: adjust the settings under **Tools → Quiet Copy Notice**.

== Frequently Asked Questions ==

= Does this stop people from copying my content? =

No, and it is not meant to. Blocking selection or right-click gets in the way of
ordinary readers and does not stop anyone determined. This plugin only tells you
what is being copied.

= Nothing is being logged. What should I check? =

Log out, or use a private window, since administrators and editors are ignored
by default. Copy at least 20 characters from the body of a single post or page. If your
theme does not use `.entry-content`, `.post-content` or `article` around the
post body, either enter the right CSS selector under **Detection → Content
area**, or turn on **Detection → Find the post body**. That option is off by
default because it adds one empty, hidden element after the post content.
Copies from form fields (a comment box, a contact form) are never recorded:
that text is usually the reader's own.

= How do I check that notification emails arrive? =

Use **Send a test email** on the Notifications tab. It sends a short fixed
message to the notification address. If it does not arrive, check the spam
folder and the site's email settings; many hosts need an SMTP plugin.

= Why did the hourly summary arrive late? =

Summaries are sent by WP-Cron, which runs when someone visits the site. On a
site with little traffic, the email goes out with the first visit after it is due.

= Can I count copies in Google Analytics or another tool? =

The plugin does not send anything to analytics services itself. It does fire a
`qcnotice:copy` event on the page each time it reports a copy to your site, with
the post ID and the length of the selection, which your own script can forward.
The event fires in the browser, before the site applies its rate limits and
ignored IP addresses, so the count can be a little higher than the log's:

`document.addEventListener( 'qcnotice:copy', function ( e ) { /* e.detail.postId, e.detail.length */ } );`

= My site is behind Cloudflare or another proxy and every IP looks the same. =

Add the proxy's IP addresses or ranges under **Detection → Trusted proxies**.
The visitor's address is then taken from `X-Forwarded-For`, but only on requests
that really come from those proxies.

= I used Check Copy Contents. Can I switch to this plugin? =

Yes. Quiet Copy Notice covers the same need, logging copied text and emailing
you about it, and it is a separate plugin written from scratch. If that plugin's
settings are still in your database when you first activate this one, the
notification address, minimum length and a custom subject are carried over.
Deactivate the old plugin afterwards, or you will get two emails per copy.

== Screenshots ==

1. The log under Tools → Quiet Copy Notice: date, post, copied text, shortened IP address and browser, with a filter by post.
2. A summary email: plain text, with the copied text quoted, the post and its address, and the length of each copy.
3. The Notifications tab: where emails go, the subject label, hourly summaries or one email per copy, and a test email button.
4. The Detection tab: minimum length, post types, the content area, and who and what to ignore.
5. The Visitors tab: an optional message after copying and an optional source line added to copied text, both off by default.
6. The dashboard widget: copies in the last 7 days and the most copied posts.

== Changelog ==

= 1.3.16 =
* First release.
