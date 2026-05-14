# Rich Statistics

> Privacy-first analytics for WordPress publishers — no PII, no consent banners required.

<!-- Status -->
[![CI](https://github.com/richardkentgates/rich-statistics/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/richardkentgates/rich-statistics/actions/workflows/tests.yml)
[![Release build](https://github.com/richardkentgates/rich-statistics/actions/workflows/build-release.yml/badge.svg)](https://github.com/richardkentgates/rich-statistics/actions/workflows/build-release.yml)
[![Dev build](https://github.com/richardkentgates/rich-statistics/actions/workflows/build-develop.yml/badge.svg?branch=develop)](https://github.com/richardkentgates/rich-statistics/actions/workflows/build-develop.yml)
[![Test build](https://github.com/richardkentgates/rich-statistics/actions/workflows/build-test.yml/badge.svg?branch=test)](https://github.com/richardkentgates/rich-statistics/actions/workflows/build-test.yml?query=branch%3Atest)
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
[![Tauri](https://img.shields.io/badge/desktop-Tauri%202-FFC131?logo=tauri&logoColor=white)](src-tauri/)
[![Rust](https://img.shields.io/badge/Rust-stable-000000?logo=rust&logoColor=white)](https://www.rust-lang.org)
[![Composer](https://img.shields.io/badge/Composer-managed-885630?logo=composer&logoColor=white)](composer.json)
[![PHPUnit](https://img.shields.io/badge/tested%20with-PHPUnit-366488)](tests/)
[![Top language](https://img.shields.io/github/languages/top/richardkentgates/rich-statistics)](https://github.com/richardkentgates/rich-statistics)

<!-- Platform -->
[![PWA](https://img.shields.io/badge/PWA-installable-5A0FC8?logo=googlechrome&logoColor=white)](https://app.richstatistics.com)
[![Linux amd64](https://img.shields.io/badge/Linux-amd64%20.deb-FCC624?logo=linux&logoColor=black)](https://app.richstatistics.com/dist/rich-statistics-linux-amd64.deb)
[![Linux arm64](https://img.shields.io/badge/Linux-arm64%20.deb-FCC624?logo=linux&logoColor=black)](https://app.richstatistics.com/dist/rich-statistics-linux-arm64.deb)
[![Windows](https://img.shields.io/badge/Windows%20.exe-0078D4?logo=windows&logoColor=white)](https://app.richstatistics.com/dist/rich-statistics-windows.exe)

<!-- Plugin features -->
[![Multisite](https://img.shields.io/badge/Multisite-compatible-21759B?logo=wordpress&logoColor=white)](https://app.richstatistics.com)
[![WP-CLI](https://img.shields.io/badge/WP--CLI-supported-blue)](cli/)
[![Freemius](https://img.shields.io/badge/premium-Freemius-FF6B35)](https://freemius.com)
[![No runtime deps](https://img.shields.io/badge/runtime%20dependencies-none-success)](https://app.richstatistics.com)

<!-- Activity -->
[![Last commit](https://img.shields.io/github/last-commit/richardkentgates/rich-statistics/main)](https://github.com/richardkentgates/rich-statistics/commits/main)
[![Release date](https://img.shields.io/github/release-date/richardkentgates/rich-statistics)](https://github.com/richardkentgates/rich-statistics/releases/latest)
[![Downloads](https://img.shields.io/github/downloads/richardkentgates/rich-statistics/total)](https://github.com/richardkentgates/rich-statistics/releases)
[![Contributors](https://img.shields.io/github/contributors/richardkentgates/rich-statistics)](https://github.com/richardkentgates/rich-statistics/graphs/contributors)
[![Repo size](https://img.shields.io/github/repo-size/richardkentgates/rich-statistics)](https://github.com/richardkentgates/rich-statistics)

<!-- Privacy / Compliance -->
[![No cookies](https://img.shields.io/badge/cookies-none-success)](https://app.richstatistics.com)
[![No PII](https://img.shields.io/badge/PII-none%20stored-success)](https://app.richstatistics.com#privacy)
[![GDPR friendly](https://img.shields.io/badge/GDPR-no%20consent%20banner-success)](https://app.richstatistics.com#privacy)
[![CCPA](https://img.shields.io/badge/CCPA-compliant-success)](https://app.richstatistics.com#privacy)
[![Self-hosted](https://img.shields.io/badge/hosting-self--hosted-0078D4)](https://app.richstatistics.com)
[![Accessibility](https://img.shields.io/badge/a11y-WCAG%202.1%20AA-blueviolet)](https://app.richstatistics.com)

**Website:** [statistics.richardkentgates.com](https://statistics.richardkentgates.com) &nbsp;|&nbsp; **Web App:** [app.richstatistics.com](https://app.richstatistics.com) &nbsp;|&nbsp; **Dev:** [dev.richstatistics.com](https://dev.richstatistics.com) &nbsp;|&nbsp; **Test:** [test.richstatistics.com](https://test.richstatistics.com)

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
| Behavior analysis | Time-on-page histogram, session depth, entry pages |
| Bot filtering | 10-signal client-side scoring + server-side UA/header checks |
| Data retention | Configurable 1–730 days (default 90) |
| Email digests | Daily/weekly/monthly HTML digest via `wp_mail` |
| WP-CLI | `wp rich-stats overview/top_pages/audience/export/purge/status` |
| Multisite | Per-site tables, network admin dashboard with cross-site AI, network-wide disable switch |
| Privacy by design | `sessionStorage` UUID only; no cookies, no third-party requests. IP hashed for rate limiting (60s TTL), never stored. Use `[rich_statistics_privacy_disclosure]` shortcode for full regulatory mapping. |

## Desktop Apps

Rich Statistics offers native desktop apps for Linux and Windows across all release tracks:

| Track | Linux amd64 | Linux arm64 | Windows |
|-------|------------|-------------|---------|
| **Production** | [.deb](https://app.richstatistics.com/dist/rich-statistics-linux-amd64.deb) | [.deb](https://app.richstatistics.com/dist/rich-statistics-linux-arm64.deb) | [.exe](https://app.richstatistics.com/dist/rich-statistics-windows.exe) |
| **Dev / Beta** | [.deb](https://dev.richstatistics.com/dist/rich-statistics-linux-amd64.deb) | [.deb](https://dev.richstatistics.com/dist/rich-statistics-linux-arm64.deb) | [.exe](https://dev.richstatistics.com/dist/rich-statistics-windows.exe) |
| **Test / Staging** | [.deb](https://test.richstatistics.com/dist/rich-statistics-linux-amd64.deb) | [.deb](https://test.richstatistics.com/dist/rich-statistics-linux-arm64.deb) | [.exe](https://test.richstatistics.com/dist/rich-statistics-windows.exe) |

All desktop apps include automatic updates via the built-in Tauri updater. Linux (APT) updates via `sudo apt upgrade`; Windows and direct .deb installs check `update.json` on each launch and prompt to download new versions.

### Premium (via Freemius)

| Feature | Description |
|---|---|
| Click tracking | Protocol tracking (tel/mailto/geo/sms/download) with destination capture — phone number, email address, coordinates, SMS number, or file URL recorded per click. CSS selector-based click tracking also records element tag, ID, class, text, and viewport position. |
| Heatmap | Viewport-relative thermal overlay on any page URL |
| User Flow | Path Explorer (Miller columns) with drop-off funnel — step-by-step page navigation across sessions |
| WooCommerce Analytics | Conversion funnel (product views → add-to-cart → orders), top products, and revenue-over-time chart. Requires WooCommerce to be active. |
| REST API | Full `rsa/v1` API powered by WP Application Passwords |
| PWA web app | Installable mobile app connected to your site's REST API |
| AI Analytics Assistant | Conversational insights powered by your own AI provider (configured in the PWA app). Plugin provides structured tool data (`POST /ai/tool`); the app calls your LLM. |
| Desktop apps | Native Linux (.deb, amd64 + arm64) and Windows (.exe) |
| Data export | CSV/JSON export of pageviews, sessions, clicks, referrers |

---

## Release Tracks

Rich Statistics maintains three release tracks, each deployed to its own endpoint on the application server:

| Track | Branch | Subdomain | Stability | Use Case |
|-------|--------|-----------|-----------|----------|
| **Production** | `main` | `app.richstatistics.com` | Stable | Official releases — use in production |
| **Beta / Dev** | `develop` | `dev.richstatistics.com` | Bleeding-edge | Preview upcoming features, test integrations |
| **Staging / Test** | `test` | `test.richstatistics.com` | Unstable | Integration testing, QA validation |

Each track has its own:
- **WordPress plugin** built from its branch
- **PWA web app** served from its subdomain
- **Desktop builds** (`.deb` / `.exe`) pushed to its `dist/` directory
- **APT repository** for Linux package management

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

### Installing from Dev / Test Branches

Each branch produces a plugin ZIP as a CI artifact. Install one of these to test pre-release features:

**Choose your track and clone the corresponding branch:**
```bash
# Production (main)
git clone -b main https://github.com/richardkentgates/rich-statistics.git

# Beta / Dev (develop)
git clone -b develop https://github.com/richardkentgates/rich-statistics.git

# Staging / Test (test)
git clone -b test https://github.com/richardkentgates/rich-statistics.git
```

**Build the plugin ZIP:**
```bash
cd rich-statistics
bash build.sh
# Output: build/rich-statistics-{version}.zip
```

Upload the ZIP to your WordPress site via **Plugins → Add New → Upload Plugin**, then activate.

> **Note:** CI artifacts expire after 1 day. To build from a specific commit, clone at that commit and run `bash build.sh`.

---

## Application Server Endpoints

Each branch syncs its web app, desktop binaries, and APT repository to a dedicated subdomain:

| Resource | Production | Dev | Test |
|----------|-----------|-----|------|
| PWA web app | `https://app.richstatistics.com` | `https://dev.richstatistics.com` | `https://test.richstatistics.com` |
| Linux .deb (amd64) | `https://app.richstatistics.com/dist/rich-statistics-linux-amd64.deb` | `https://dev.richstatistics.com/dist/rich-statistics-linux-amd64.deb` | `https://test.richstatistics.com/dist/rich-statistics-linux-amd64.deb` |
| Linux .deb (arm64) | `https://app.richstatistics.com/dist/rich-statistics-linux-arm64.deb` | `https://dev.richstatistics.com/dist/rich-statistics-linux-arm64.deb` | `https://test.richstatistics.com/dist/rich-statistics-linux-arm64.deb` |
| Windows .exe | `https://app.richstatistics.com/dist/rich-statistics-windows.exe` | `https://dev.richstatistics.com/dist/rich-statistics-windows.exe` | `https://test.richstatistics.com/dist/rich-statistics-windows.exe` |
| APT repository | `https://app.richstatistics.com/apt/` | `https://dev.richstatistics.com/apt/` | `https://test.richstatistics.com/apt/` |
| Webhook | `https://app.richstatistics.com/_deploy/` | `https://dev.richstatistics.com/_deploy/` | `https://test.richstatistics.com/_deploy/` |

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
- **No PII stored** — IP addresses are hashed for rate limiting (60s TTL) then discarded; raw IPs are never stored
- **No third-party requests** — Chart.js is bundled locally; no CDN calls at runtime
- **Referrers truncated** — only the domain is stored, not the full referrer URL
- **Sensitive query params stripped** — any query parameter that looks like an email or is longer than 40 characters is removed from stored page paths
- **Self-hosted** — all data stays on your server
- **User-Agent parsed, not stored** — only derived OS/browser/version are saved; raw UA string is discarded

### Data Collected

On every page view the plugin collects: session UUID (sessionStorage), page path (sanitized), referrer domain, OS, browser, browser version, language, timezone, viewport dimensions, time on page, UTM campaign parameters, and a bot detection score. Server-side, the IP address is SHA-256 hashed for rate limiting (transient, 60s TTL) and the User-Agent is parsed then discarded. Full details in the `RSA_Tracker` and `RSA_Bot_Detection` classes.

### Privacy Disclosure Shortcode

Place `[rich_statistics_privacy_disclosure]` on any public page or post to render a visitor-facing disclosure table. It tells your visitors exactly what data their browser sends to your analytics, what is stored, what is not, and their rights under GDPR, ePrivacy, CCPA/CPRA, VCDPA, CPA, CTDPA, UCPA, TDPSA, and COPPA. Premium feature data (clicks, heatmaps, WooCommerce) is included automatically when the active license qualifies.

### Compliance Summary

| Regulation | Status | Basis |
|---|---|---|
| GDPR (EU) | Compliant | Art. 6(1)(f) legitimate interest; no PII; no consent banner required for pseudonymous analytics |
| ePrivacy Directive | Compliant | No cookies, no device fingerprinting |
| CCPA/CPRA (California) | Compliant | No "personal information" as defined; no sale/share of data |
| VCDPA (Virginia) | Compliant | No personal data; no targeted advertising or profiling |
| CPA (Colorado) | Compliant | No personal data; no targeted advertising |
| CTDPA (Connecticut) | Compliant | Pseudonymous data exempt; internal operations exemption |
| UCPA (Utah) | Compliant | Pseudonymous analytics exempt |
| TDPSA (Texas) | Compliant | Pseudonymous analytics exempt |
| COPPA (US Federal) | Compliant | No child-directed data collection; no PII |

> **Note:** Compliance claims are based on the plugin's data collection behavior. Site operators should consult legal counsel for their specific use case, especially when combining this plugin with other tracking tools or processing personal data elsewhere on the site.

---

## WP-CLI

```bash
# Site overview
wp rich-stats overview --period=30d

# Top pages
wp rich-stats top_pages --period=7d --limit=20

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
| GET | `/filter-options` | Available browser/OS filter values |
| GET | `/user-flow` | Step-based path flow data (Miller columns) |
| GET | `/user-flow/journey` | Page-to-page journey data |
| GET | `/user-flow/sources` | Entry source breakdown |
| GET | `/clicks` | Click element totals (premium) |
| GET | `/heatmap` | Heatmap coordinates for a page (premium; supports `date_from`/`date_to`) |
| GET | `/woocommerce` | WooCommerce funnel, revenue, and top-product data (premium; requires WooCommerce active) |
| GET | `/export` | CSV/JSON export (`data_type`: pageviews/sessions/clicks/referrers) |
| GET | `/info` | Plugin version + site info (public — no auth required) |
| POST | `/track` | Pageview ingest (nonce-protected, public) |
| POST | `/ai/tool` | Structured analytics data for AI tool-calling. Free tools: overview, pages, audience, referrers, behavior. Premium tools: campaigns, user-flow, clicks, heatmap, woocommerce. Returns JSON — no LLM call server-side. Used by the PWA, desktop app, and multisite network dashboard. |
| POST | `/verify-otp` | Validate 6-digit App Code for PWA pairing (public) |
| GET/POST | `/user-settings` | Sync app site list across devices |
| POST | `/purge-page` | Delete all data for a specific page (premium) |

**Note:** Page tracking uses WordPress AJAX (`admin-ajax.php?action=rsa_track`), not the REST API.

All GET endpoints accept a `period` query parameter: `7d`, `30d`, `90d`, `thismonth`, `lastmonth`.

---

## PWA Web App (Premium)

The production web app is served from `https://app.richstatistics.com`. Dev and test builds are available at their respective subdomains:

| Track | URL |
|-------|-----|
| **Production** | `https://app.richstatistics.com` |
| **Dev / Beta** | `https://dev.richstatistics.com` |
| **Test / Staging** | `https://test.richstatistics.com` |

### Connecting to your site

1. Navigate to **Users → Your Profile** in WordPress
2. Scroll to the **Rich Statistics App** section and click **Generate App Code**
3. Open or install the web app for your chosen track
4. Tap **Add Site**, enter your site URL, and enter the App Code when prompted
5. Create an **Application Password** in the section below on the profile page
6. Enter the username and Application Password in the app to complete the connection
7. Install to your home screen via your browser's "Add to Home Screen" prompt

> **Note:** Dev and test web apps connect to the same WordPress REST API as production — they only differ in the PWA/desktop client version bundled.

## Desktop Apps (Premium)

Native desktop apps for Linux and Windows are built with each release. They wrap the same
dashboard as the PWA in a lightweight native window — no Electron, no bundled browser.
All apps include automatic update detection: Linux APT updates via `sudo apt upgrade`;
Windows and direct .deb installs check `update.json` via the Tauri updater on each launch
and prompt to download new versions.

### Linux — Install via APT (recommended)

The APT repository provides automatic updates via your normal system package manager.

```bash
# Add the GPG key
curl -fsSL https://app.richstatistics.com/apt/public.gpg \
    | sudo gpg --dearmor -o /usr/share/keyrings/rich-statistics.gpg

# Add the repository
echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/rich-statistics.gpg] \
    https://app.richstatistics.com/apt stable main" \
    | sudo tee /etc/apt/sources.list.d/rich-statistics.list

# Install
sudo apt update && sudo apt install rich-statistics
```

Once enrolled, `sudo apt upgrade` keeps the app up to date like any other package.

### Dev / Test APT repositories

Each track has its own APT repository with the same structure:

| Track | APT Repository URL |
|-------|-------------------|
| **Production** | `https://app.richstatistics.com/apt` |
| **Dev / Beta** | `https://dev.richstatistics.com/apt` |
| **Test / Staging** | `https://test.richstatistics.com/apt` |

Replace the URL in the APT install instructions above to subscribe to a different track.

### Manual install (direct download)

| Track | Linux amd64 | Linux arm64 | Windows |
|-------|------------|-------------|---------|
| **Production** | [.deb](https://app.richstatistics.com/dist/rich-statistics-linux-amd64.deb) | [.deb](https://app.richstatistics.com/dist/rich-statistics-linux-arm64.deb) | [.exe](https://app.richstatistics.com/dist/rich-statistics-windows.exe) |
| **Dev / Beta** | [.deb](https://dev.richstatistics.com/dist/rich-statistics-linux-amd64.deb) | [.deb](https://dev.richstatistics.com/dist/rich-statistics-linux-arm64.deb) | [.exe](https://dev.richstatistics.com/dist/rich-statistics-windows.exe) |
| **Test / Staging** | [.deb](https://test.richstatistics.com/dist/rich-statistics-linux-amd64.deb) | [.deb](https://test.richstatistics.com/dist/rich-statistics-linux-arm64.deb) | [.exe](https://test.richstatistics.com/dist/rich-statistics-windows.exe) |

Linux:
```bash
sudo dpkg -i rich-statistics-linux-*.deb
```

Windows: Run the downloaded `.exe` installer and follow the setup wizard.

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
