# WordPress Content Decay Monitor

A practical WordPress plugin for identifying outdated content, prioritizing SEO updates, and keeping editorial maintenance on schedule.

![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-21759B?logo=wordpress&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/License-GPL--2.0%2B-blue.svg)

## Why this plugin exists

Publishing new content is only half of SEO maintenance. Existing pages lose freshness, links become outdated, and important articles are forgotten. Content Decay Monitor turns those maintenance signals into a prioritized dashboard without requiring an external API.

## Features

- Transparent decay score from 0 to 100
- Fresh, Watch, Stale, and Critical classifications
- Custom age thresholds and preferred minimum word count
- Signals for content age, word count, featured image, excerpt, internal links, and external references
- Support for posts, pages, WooCommerce products, and public custom post types
- Dedicated dashboard with status cards and priority sorting
- Decay score column and status filter in WordPress content lists
- One-click **Mark reviewed** action without changing the public modified date
- Per-content review intervals and automatic next-review dates
- Private SEO maintenance notes inside the post editor
- Content Health meta box with analysis signals and quick actions
- Bulk review, exclude, and include actions
- Overdue review filtering and administrator reminders
- Exclude and restore content from monitoring
- Daily background rescans with WP-Cron
- Optional daily or weekly email digest
- UTF-8 CSV export suitable for Persian and multilingual content
- Optional complete data cleanup during uninstall
- No tracking, advertisements, or external API requests

## How scoring works

The score is an editorial priority indicator, not a Google ranking score.

| Signal | Maximum risk contribution |
| --- | ---: |
| Time since last modification or review | 60 |
| Low word count | 15 |
| Missing featured image | 5 |
| Missing excerpt | 5 |
| Missing internal links | 8 |
| Missing external references | 7 |

Status bands:

- **Fresh:** 0–29
- **Watch:** 30–54
- **Stale:** 55–74
- **Critical:** 75–100

All age and word-count thresholds can be adjusted from the settings page.

## Installation

1. Download the release ZIP.
2. In WordPress, open **Plugins → Add New → Upload Plugin**.
3. Upload and activate the plugin.
4. Open **Content Decay → Settings** and select the content types to monitor.
5. Return to **Content Decay** and click **Run full scan**.

## Requirements

- WordPress 6.5 or newer
- PHP 7.4 or newer
- A working WP-Cron configuration for scheduled scans and email digests

WooCommerce is optional. Products become available when WooCommerce is installed.

## Privacy

The plugin stores analysis results as private post metadata in the site's own database. It does not transmit content or analytics to third parties. Email digests are sent through the WordPress mail system only when explicitly enabled.

## Important limitation

Version 1.0.0 analyzes on-page and editorial freshness signals. It does not claim to detect actual organic traffic decline. A future Search Console integration can add clicks, impressions, CTR, and position trends after explicit authorization.

## Development

The source follows WordPress capability checks, nonce verification, sanitization, escaping, and prepared SQL practices. Test changes on a staging site before deploying them to production.

## Changelog

### 1.1.1

- Updated WordPress compatibility metadata through 7.0
- Added automated PHP compatibility and secret scanning checks

### 1.1.0

- Added per-content review schedules, due dates, and private maintenance notes
- Added Content Health meta box and quick review controls
- Added overdue filters, dashboard card, and administrator reminders
- Added bulk review, exclusion, and restoration actions
- Expanded CSV reports with review workflow data

### 1.0.1

- Fixed a PHP parse error in the scheduled content scanner

### 1.0.0

- Initial public release
- Decay scoring engine and configurable thresholds
- Dashboard, list columns, filters, review and exclusion actions
- Scheduled rescans, email digests, and CSV export

## Author

**Kamran Hajhossein**  
[kamranh.com](https://kamranh.com) · [webrabin.com](https://webrabin.com)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
