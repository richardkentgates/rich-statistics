# Rich Statistics

> Privacy-first analytics for WordPress publishers — no PII, no consent banners required.

[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress)](https://wordpress.org)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](LICENSE)
[![Tests](https://github.com/richardkentgates/rich-statistics/actions/workflows/tests.yml/badge.svg)](https://github.com/richardkentgates/rich-statistics/actions/workflows/tests.yml)

**Website:** [statistics.richardkentgates.com](https://statistics.richardkentgates.com)

---

## What is Rich Statistics?

Rich Statistics is a self-hosted WordPress analytics plugin that collects publisher-useful metrics — operating system, browser, timezone, language, time on page, referrers, and more — without ever storing personally identifiable information.

Because no PII is collected and sessions are identified only with a `sessionStorage` UUID that dies with the browser tab, Rich Statistics sites **do not require cookie consent banners** under GDPR, CCPA, or ePrivacy Directive for the analytics data this plugin collects.

---

## Features

### Free (all sites)

| Feature | Description |
|---|---|
| Pageviews & sessions | Daily sparklines, totals, bounce rate |
| Audience breakdown | OS, browser, viewport, language, timezone |
| Top pages | Ranked by views with average time on page |
| Referrer tracking | Domain-level only, no full URLs |
| Behavior analysis | Time-on-page histogram, session depth |
| Bot filtering | 10-signal client-side scoring + server-side UA/header checks |
| Data retention | Configurable 1–730 days (default 90) |
| Email digests | Daily/weekly/monthly HTML digest via `wp_mail` |
| WP-CLI | `wp rich-stats overview/top-pages/audience/export/purge/status` |
| Multisite | Per-site tables, network admin, network-wide disable switch |
| Privacy by design | `sessionStorage` UUID only; no cookies, no third-party requests |

### Premium (via Freemius)

| Feature | Description |
|---|---|
| Click tracking | Protocol tracking (tel/mailto/geo/sms/download) with destination capture — phone number, email address, coordinates, SMS number, or file URL recorded per click |
| Heatmaps | Viewport-relative thermal overlay on any page URL |
| REST API | Full `rsa/v1` API powered by WP Application Passwords |
| PWA web app | Installable mobile app connected to your site's REST API |

---

## Requirements

- PHP 8.0 or higher
- WordPress 6.0 or higher
- MySQL 5.7+ / MariaDB 10.3+

---

## Installation

### From Freemius (recommended)

1. Purchase or download the free version from [Freemius](https://freemius.com)
2. Upload the ZIP via **WordPress → Plugins → Add New → Upload Plugin**
3. Activate the plugin
4. Navigate to **Rich Stats** in the admin sidebar

### Manual / Development

```bash
# Clone the repository
git clone https://github.com/richardkentgates/rich-statistics.git

# Run the build script to install dependencies and create the plugin ZIP
cd rich-statistics
bash build.sh

# The ZIP is created at ./build/rich-statistics-{version}.zip
# Upload that ZIP to WordPress
```

### Development Setup (without build)

```bash
git clone https://github.com/richardkentgates/rich-statistics.git
cd rich-statistics

# Install PHP dev dependencies
composer install

# Download Freemius SDK (required for premium features in production)
# The plugin loads a fallback stub in development when freemius/ is absent
```

---

## Configuration

After activation, navigate to **Rich Stats → Data** in the WordPress admin to configure:

- **Data retention** — how many days of data to keep (1–730)
- **Bot score threshold** — sensitivity of bot detection (1–10)
- **Click tracking protocols** — which href protocols trigger click events
- **Click element selectors** — additional CSS IDs/classes to track

---

## Privacy & Compliance

Rich Statistics is designed to be **privacy-first**:

- **No cookies** — sessions use `sessionStorage` only (cleared when tab closes)
- **No PII** — IP addresses, full URLs with personal data, and email addresses are never stored
- **No third-party requests** — Chart.js is bundled locally; no CDN calls at runtime
- **Referrers truncated** — only the domain is stored, not the full referrer URL
- **Sensitive query params stripped** — any query parameter that looks like an email or is longer than 40 characters is removed from stored page paths
- **Self-hosted** — all data stays on your server

For detailed compliance information, see the [Privacy section of our documentation](https://statistics.richardkentgates.com#privacy).

---

## WP-CLI

```bash
# Site overview
wp rich-stats overview --period=30d

# Top pages
wp rich-stats top-pages --period=7d --count=20

# Audience breakdown
wp rich-stats audience --period=30d

# Export to CSV
wp rich-stats export --period=90d > export.csv

# Purge old data (dry run first)
wp rich-stats purge --dry-run
wp rich-stats purge --days=90

# Send test digest email
wp rich-stats email-test --recipient=you@example.com

# Show plugin/cron/DB status
wp rich-stats status

# Multisite: scope to blog ID 3
wp rich-stats overview --blog-id=3
```

---

## REST API (Premium)

Base URL: `https://yoursite.com/wp-json/rsa/v1/`

Authentication: **WordPress Application Passwords** (`Authorization: Basic base64(user:app_password)`)

| Method | Endpoint | Description |
|---|---|---|
| GET | `/overview` | KPIs + daily sparkline |
| GET | `/pages` | Top pages ranked by views |
| GET | `/audience` | OS/browser/viewport/language/timezone breakdowns |
| GET | `/referrers` | Top referrer domains |
| GET | `/behavior` | Time histogram, session depth, entry pages |
| GET | `/clicks` | Click element totals (premium) |
| GET | `/heatmap` | Heatmap coordinates for a page (premium) |
| GET | `/export` | CSV download of raw event data |
| POST | `/track` | Ingest endpoint (used by the PWA) |

All GET endpoints accept a `period` query parameter: `7d`, `30d`, `90d`, `thismonth`, `lastmonth`.

---

## PWA Web App (Premium)

A progressive web app is included at `wp-content/plugins/rich-statistics/webapp/`.

1. Navigate to **Users → Your Profile** in WordPress
2. Scroll to **Application Passwords** — create a new password
3. Click the **Open / Install Web App** button (visible to admin users with Premium)
4. Enter your site URL + username + the Application Password
5. Install to your home screen via your browser's "Add to Home Screen" prompt

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for development setup, coding standards, and pull request guidelines.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## Security

To report a security vulnerability, see [SECURITY.md](SECURITY.md).

---

## License

Rich Statistics is licensed under the [GNU General Public License v2.0](LICENSE) or later, consistent with the WordPress ecosystem.

**Third-party components included in this distribution:**

| Component | Version | License |
|---|---|---|
| [Chart.js](https://www.chartjs.org/) | 4.4.2 | MIT |
| [Freemius WordPress SDK](https://freemius.com/) | 2.7.4+ | MIT / GPL |

> **Freemius note:** The premium tier of this plugin is distributed through [Freemius](https://freemius.com/). Premium features (click tracking, heatmaps, REST API, PWA) are only available to active premium licence holders. Free-tier features remain fully GPL and are available to everyone.
