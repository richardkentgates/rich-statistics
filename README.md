# Rich Statistics

> Privacy-first analytics for WordPress publishers — no PII, no consent banners required.

<!-- Status -->
[![CI](https://github.com/richardkentgates/rich-statistics/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/richardkentgates/rich-statistics/actions/workflows/tests.yml)
[![Build](https://github.com/richardkentgates/rich-statistics/actions/workflows/build-release.yml/badge.svg)](https://github.com/richardkentgates/rich-statistics/actions/workflows/build-release.yml)
[![Release](https://img.shields.io/github/v/release/richardkentgates/rich-statistics)](https://github.com/richardkentgates/rich-statistics/releases/latest)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](LICENSE)
[![Open Issues](https://img.shields.io/github/issues/richardkentgates/rich-statistics)](https://github.com/richardkentgates/rich-statistics/issues)
[![Stars](https://img.shields.io/github/stars/richardkentgates/rich-statistics?style=social)](https://github.com/richardkentgates/rich-statistics/stargazers)
[![Forks](https://img.shields.io/github/forks/richardkentgates/rich-statistics?style=social)](https://github.com/richardkentgates/rich-statistics/network/members)

<!-- Stack -->
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?logo=mysql&logoColor=white)](https://dev.mysql.com)
[![MariaDB](https://img.shields.io/badge/MariaDB-10.3%2B-C0765A?logo=mariadb&logoColor=white)](https://mariadb.org)
[![JavaScript](https://img.shields.io/badge/JavaScript-ES5%20vanilla-F7DF1E?logo=javascript&logoColor=black)](assets/js/)
[![Tauri](https://img.shields.io/badge/desktop-Tauri%202-FFC131?logo=tauri&logoColor=white)](webapp/)
[![Rust](https://img.shields.io/badge/Rust-stable-000000?logo=rust&logoColor=white)](https://www.rust-lang.org)
[![Composer](https://img.shields.io/badge/Composer-managed-885630?logo=composer&logoColor=white)](composer.json)
[![PHPUnit](https://img.shields.io/badge/tested%20with-PHPUnit-366488)](tests/)
[![Top language](https://img.shields.io/github/languages/top/richardkentgates/rich-statistics)](https://github.com/richardkentgates/rich-statistics)

<!-- Platform -->
[![PWA](https://img.shields.io/badge/PWA-installable-5A0FC8?logo=googlechrome&logoColor=white)](https://rs-app.richardkentgates.com)
[![Linux amd64](https://img.shields.io/badge/Linux-amd64%20.deb-FCC624?logo=linux&logoColor=black)](https://rs-app.richardkentgates.com/desktop/rich-statistics-linux-amd64.deb)
[![Linux arm64](https://img.shields.io/badge/Linux-arm64%20.deb-FCC624?logo=linux&logoColor=black)](https://rs-app.richardkentgates.com/desktop/rich-statistics-linux-arm64.deb)

<!-- Plugin features -->
[![Multisite](https://img.shields.io/badge/Multisite-compatible-21759B?logo=wordpress&logoColor=white)](https://statistics.richardkentgates.com)
[![WP-CLI](https://img.shields.io/badge/WP--CLI-supported-blue)](cli/)
[![Freemius](https://img.shields.io/badge/premium-Freemius-FF6B35)](https://freemius.com)
[![No runtime deps](https://img.shields.io/badge/runtime%20dependencies-none-success)](https://statistics.richardkentgates.com)

<!-- Activity -->
[![Last commit](https://img.shields.io/github/last-commit/richardkentgates/rich-statistics/main)](https://github.com/richardkentgates/rich-statistics/commits/main)
[![Release date](https://img.shields.io/github/release-date/richardkentgates/rich-statistics)](https://github.com/richardkentgates/rich-statistics/releases/latest)
[![Downloads](https://img.shields.io/github/downloads/richardkentgates/rich-statistics/total)](https://github.com/richardkentgates/rich-statistics/releases)
[![Contributors](https://img.shields.io/github/contributors/richardkentgates/rich-statistics)](https://github.com/richardkentgates/rich-statistics/graphs/contributors)
[![Repo size](https://img.shields.io/github/repo-size/richardkentgates/rich-statistics)](https://github.com/richardkentgates/rich-statistics)

<!-- Privacy / Compliance -->
[![No cookies](https://img.shields.io/badge/cookies-none-success)](https://statistics.richardkentgates.com)
[![No PII](https://img.shields.io/badge/PII-none%20stored-success)](https://statistics.richardkentgates.com#privacy)
[![GDPR friendly](https://img.shields.io/badge/GDPR-no%20consent%20banner-success)](https://statistics.richardkentgates.com#privacy)
[![CCPA](https://img.shields.io/badge/CCPA-compliant-success)](https://statistics.richardkentgates.com#privacy)
[![Self-hosted](https://img.shields.io/badge/hosting-self--hosted-0078D4)](https://statistics.richardkentgates.com)
[![Accessibility](https://img.shields.io/badge/a11y-WCAG%202.1%20AA-blueviolet)](https://statistics.richardkentgates.com)

**Website:** [statistics.richardkentgates.com](https://statistics.richardkentgates.com) &nbsp;|&nbsp; **Web App:** [rs-app.richardkentgates.com](https://rs-app.richardkentgates.com)

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
| UTM campaign tracking | Capture `utm_source`, `utm_medium`, `utm_campaign` from landing URLs; attributed to the full session |
| Campaigns view | Admin page showing each source/medium/campaign combination with sessions and pageviews |
| User Flow | Path Explorer (Miller columns) with drop-off funnel — step-by-step page navigation across sessions |
| Behavior analysis | Time-on-page histogram, session depth, entry pages |
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
| Heatmap | Viewport-relative thermal overlay on any page URL |
| WooCommerce Analytics | Conversion funnel (product views → add-to-cart → orders), top products, and revenue-over-time chart. Requires WooCommerce to be active. |
| REST API | Full `rsa/v1` API powered by WP Application Passwords |
| PWA web app | Installable mobile app connected to your site's REST API |

---

## Requirements

- PHP 8.0 or higher
- WordPress 6.0 or higher
- MySQL 5.7+ / MariaDB 10.3+

---

## Installation

### From WordPress.org (recommended)

```bash
wp plugin install rich-statistics --activate
```

Or search for **Rich Statistics** in your WordPress admin under **Plugins → Add New**, then activate.

1. Once activated, navigate to **Analytics** in the admin sidebar

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

# Install PHP dev dependencies (Freemius SDK is already committed to vendor/freemius/)
composer install
```

---

## Configuration

After activation, navigate to **Rich Statistics → Preferences** in the WordPress admin to configure:

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
wp rich-stats top-pages --period=7d --limit=20

# Audience breakdown
wp rich-stats audience --period=30d

# Export to CSV
wp rich-stats export --period=90d > export.csv

# Purge old data (dry run first)
wp rich-stats purge --dry-run
wp rich-stats purge --older-than=90

# Send test digest email
wp rich-stats email-test --recipient=you@example.com

# Show plugin/cron/DB status
wp rich-stats status

# WooCommerce funnel + revenue + top products (Premium)
wp rich-stats woocommerce --period=30d

# Click-tracking summary (Premium)
wp rich-stats clicks --period=7d

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
| GET | `/pages` | Top pages ranked by views (filters: `browser`, `os`, `path`, `sort`, `sort_dir`) |
| GET | `/audience` | OS/browser/viewport/language/timezone breakdowns |
| GET | `/referrers` | Top referrer domains |
| GET | `/behavior` | Time histogram, session depth, entry pages |
| GET | `/campaigns` | UTM source/medium/campaign breakdown with session + pageview counts |
| GET | `/user-flow` | Step-based path flow data (Miller columns) |
| GET | `/clicks` | Click element totals (premium) |
| GET | `/heatmap` | Heatmap coordinates for a page (premium; supports `date_from`/`date_to`) |
| GET | `/woocommerce` | WooCommerce funnel, revenue, and top-product data (premium; requires WooCommerce active) |
| GET | `/export` | CSV/JSON export (`data_type`: pageviews/sessions/clicks/referrers) |
| GET | `/info` | Plugin version + site info (public — no auth required) |
| POST | `/track` | Ingest endpoint (used by the tracker on every page load) |
| POST | `/verify-otp` | Validate 6-digit App Code for PWA pairing (public) |
| GET/POST | `/user-settings` | Sync app site list across devices |

All GET endpoints accept a `period` query parameter: `7d`, `30d`, `90d`, `thismonth`, `lastmonth`.

---

## PWA Web App (Premium)

A progressive web app is included at `wp-content/plugins/rich-statistics/webapp/`.

1. Navigate to **Users → Your Profile** in WordPress
2. Scroll to the **Rich Statistics App** section and click **Generate App Code**
3. Open or install the web app (use the **Download App** button or visit `rs-app.richardkentgates.com`)
4. Tap **Add Site**, enter your site URL, and enter the App Code when prompted
5. Create an **Application Password** in the section below on the profile page
6. Enter the username and Application Password in the app to complete the connection
7. Install to your home screen via your browser’s “Add to Home Screen” prompt---

## Linux Desktop App (Premium)

A native Linux desktop app is built automatically with each release. It wraps the same
dashboard as the PWA in a lightweight WebKitGTK window — no Electron, no bundled browser.

| Architecture | Download |
|---|---|
| x86_64 (Intel/AMD) | [rich-statistics-linux-amd64.deb](https://rs-app.richardkentgates.com/desktop/rich-statistics-linux-amd64.deb) |
| ARM64 (Raspberry Pi / Apple Silicon VM) | [rich-statistics-linux-arm64.deb](https://rs-app.richardkentgates.com/desktop/rich-statistics-linux-arm64.deb) |

**Install (Debian / Ubuntu / Raspberry Pi OS):**
```bash
# Copy to /tmp first — apt requires a world-readable path to sandbox the download
cp ~/Downloads/rich-statistics-linux-amd64.deb /tmp/
sudo apt install /tmp/rich-statistics-linux-amd64.deb
```

> **Note:** Running `sudo apt install ./file.deb` directly from `~/Downloads` triggers
> a harmless `_apt` sandboxing notice because apt cannot read files from your home
> directory. Copying to `/tmp` avoids it. The package installs correctly either way.

On non-apt systems, install `webkit2gtk-4.1` via your package manager, then run the binary directly from the release.

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
