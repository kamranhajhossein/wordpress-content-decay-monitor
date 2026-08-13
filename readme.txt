=== Content Decay Monitor ===
Contributors: kamranhajhossein
Tags: seo, content audit, content decay, editorial workflow, woocommerce
Requires at least: 6.5
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Identify outdated WordPress content, prioritize SEO updates, schedule reviews, and export actionable reports.

== Description ==

Content Decay Monitor assigns a transparent editorial priority score to posts, pages, products, and public custom post types. It checks content age, word count, images, excerpts, and links without sending site data to an external service.

Features include a priority dashboard, configurable thresholds, admin columns, filters, review tracking, exclusions, WP-Cron rescans, optional email digests, and CSV export.

The score is a maintenance priority indicator and is not presented as a Google ranking score or proof of traffic decline.

== Installation ==

1. Upload the plugin ZIP from Plugins > Add New > Upload Plugin.
2. Activate Content Decay Monitor.
3. Choose monitored content types from Content Decay > Settings.
4. Run the first full scan from the dashboard.

== Frequently Asked Questions ==

= Does this connect to Google Search Console? =

Not in version 1.0.0. The current release uses local editorial and on-page signals only.

= Does marking content as reviewed change its public modified date? =

No. A private review timestamp is stored separately.

= Does the plugin send content to an external service? =

No. Analysis runs inside WordPress and no tracking or external API request is used.

== Changelog ==

= 1.1.0 =

* Added per-content review schedules, due dates, and maintenance notes.
* Added a content health meta box with quick review actions.
* Added overdue filters and administrator reminders.
* Added bulk review, exclude, and include actions.
* Expanded CSV reports with review workflow fields.

= 1.0.1 =

* Fixed a PHP parse error in the scheduled content scanner.

= 1.0.0 =

* Initial release.
