# Changelog

All notable changes to Rich Statistics are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `wp-coding-standards/wpcs` added as dev dependency
- Versioned PWA snapshot `config.js` now includes `env` auto-detection
- `readme.txt` changelog updated with all 2.x releases
- `tests/bootstrap.php` now uses `test.richstatistics.com` for `RSA_APP_URL` in test environment
- Unit test bootstrap now defines `RSA_APP_ENV`, `RSA_APP_VERSION`, `RSA_MIN_APP_VERSION`
- Ops documentation: rollback procedure, backup strategy, monitoring plan (ROADMAP §8)
- GitHub Wiki synced: Release Tracks, Installation, Code-Map pages updated
- WordPress.org SVN deploy script: `bin/deploy-wporg.sh` automates trunk sync, asset upload, and tagging
- `wporg-assets/` directory scaffolded for screenshots and banners

### Fixed
- `build.sh` now uses `docs/app/v/{version}/` (consistent with `build-release.yml`); env config files (`config-dev.js`, `index-dev.html` etc.) included in versioned snapshots
- `build-release.yml` versioned snapshot now includes env config files
- Removed orphaned `email-settings.php` and `data-settings.php` templates (fully covered by `preferences.php`)
- Fixed email test redirect from non-existent `email-settings` page → `preferences` page
- `build.sh`: removed stale `webapp/` directory reference (no longer exists)

## [2.4.2] - 2026-05-14

### Fixed
- `AnalyticsTest` now uses `DELETE` instead of `TRUNCATE` for WordPress test transaction compatibility

## [2.4.1] - 2026-05-14

### Fixed
- Server script deployment inlined in CI workflow to avoid path resolution issues

## [2.4.0] - 2026-05-13

### Added
- `POST /ai/tool` REST endpoint returning structured JSON — free tools (overview, pages, audience, referrers, behavior) for all users, premium tools (campaigns, user-flow, clicks, heatmap, woocommerce) with active licence
- Free Insights panel on admin Overview dashboard — server-side insight cards derived from analytics data (no LLM needed)
- AI provider settings in PWA (Install → AI Assistant Provider) — users bring their own OpenAI-compatible endpoint and API key
- AI Analytics Assistant wiki page documenting the tool-calling architecture
- Legal compliance section on GitHub Pages landing page with GDPR, ePrivacy, CCPA statute citations

### Changed
- AI architecture refactored: `/ai/query` (monolithic LLM call) replaced by `/ai/tool` — plugin returns structured data, the app calls the user's own LLM
- PWA `renderAiChat()` rewritten for tool-calling orchestration — fetches data via `/ai/tool`, sends to user-configured LLM for conversational answers
- No AI provider configuration stored server-side — API key and provider settings live in the app's localStorage
- Version bumped to 2.4.0

### Removed
- `POST /ai/query` endpoint and `ai_query()` method
- AI Integration section from admin Preferences page (`rsa_ai_provider`, `rsa_ai_api_key`, `rsa_ai_endpoint`, `rsa_ai_model` options)
- `templates/admin/ai-chat.php` — AI chat now lives in the PWA/desktop app
- Admin menu entry for AI Assistant (redirects to Overview)
- Premium gating of AI chat in PWA — `/ai/tool` endpoint gates tools per-tier, app-side AI engine is free

## [2.3.0] - 2026-05-12

### Added
- Domain migration: rs-*.richardkentgates.com → *.richstatistics.com
- PWA AI Chat view with premium gating
- App server architecture DR documentation
- i18n, a11y, security audit fixes
- Created uninstall.php (was missing)
- Plugin Checker: 0 errors
- CI workflow refactoring: reusable sub-workflows
- Added WordPress admin Install page

## [2.2.9] - 2026-05-11

### Added
- PWA AI Chat view with premium gating and navigation link
- App server architecture documentation for disaster recovery

## [2.2.8] - 2026-05-11

### Added
- Env-aware PWA config.js + config-dev.js/index-dev.html
- Premium admin gating (require_premium_or_exit)
- Migration test coverage (9 new tests) + env detection tests (10 new)

### Fixed
- Documentation overhaul across all 6 doc files
- Removed dead /verify-install endpoint
- Tracker session total_time NULL-coercion fix

## [2.2.7] - 2026-05-10

### Changed
- Server restructured to standard LAMP layout (`public_html/`, `desktop/` renamed to `dist/`)
- All download URLs updated from `/desktop/` to `/dist/`
- CORS origin allowlist replaces reflected-origin (security hardening)
- `$_POST` global now saved/restored in `/track` REST endpoint (was polluting global state)
- AI API key masked in preferences form (first 8 chars only)
- AI error responses no longer leak internal details

### Fixed
- `TrackerRateLimitTest` now handles null `$wp_filter` (BrainMonkey compatibility)
- CLI export restricts output path to WordPress directory
- Nonce verification moved before capability check in OTP generator
- Apache vhost: missing `_deploy/` Alias added (was returning 404)
- APT repository: ModSecurity exclusion added for directory listing
- Security headers: X-Frame-Options, X-Content-Type-Options, HSTS, Referrer-Policy

### Security
- CORS: allowlist-based origin validation (was reflecting arbitrary origins with credentials)
- `$_POST`: save/restore pattern prevents global state corruption from public endpoint
- CLI export: path restricted to ABSPATH to prevent arbitrary file writes
- AI errors: logged server-side; generic message returned to client
- Branch protection enabled on `main` (PR required, status checks enforced)
- Server: dist/ directory locked to 755, web root parent permissions restricted

## [2.2.6] - 2026-05-09

### Added
- Premium feature gating UI in PWA (locked features show upgrade prompt)
- `Build Dev` workflow for develop branch CI

### Fixed
- Versioned app snapshot creation in CI desktop build job

## [2.2.5] - 2026-05-09

### Fixed
- Test isolation in AJAX handler tests (cleanup between test methods)

## [2.2.4] - 2026-05-09

### Fixed
- OTP rate limiting edge case (IP hash collision handling)

## [2.2.3] - 2026-05-09

### Added
- PWA version switching in Tauri desktop app

## [2.2.2] - 2026-05-09

### Fixed
- Chart.js loading in versioned PWA snapshots

## [2.2.1] - 2026-05-09

### Changed
- PWA UX improvements for mobile viewport

## [2.2.0] - 2026-05-08

### Fixed
- Added Python setup step for Windows CI build

## [2.1.9] - 2026-05-08

### Fixed
- Windows CI now uses `python` for JSON parsing

## [2.1.8] - 2026-05-08

### Fixed
- UTF8 encoding specified without BOM for Windows compatibility

## [2.1.7] - 2026-05-08

### Fixed
- Windows version stamp now uses `-Raw` and `WriteAllText` for `tauri.conf.json`

## [2.1.6] - 2026-05-08

### Fixed
- Windows CI version stamping

## [2.1.5] - 2026-05-08

### Fixed
- Proper regex for `tauri.conf.json` version stamp on Windows

## [2.1.4] - 2026-05-08

### Fixed
- Simple string replacement for `tauri.conf.json` version on Windows

## [2.1.3] - 2026-05-08

### Fixed
- Proper JSON parsing for `tauri.conf.json` on Windows CI

## [2.1.2] - 2026-05-08

### Changed
- Version bumped to 2.1.2 for release build

## [2.1.1] - 2026-05-08

### Added
- Test environment with `rs-test.richardkentgates.com` endpoint

## [2.1.0] - 2026-05-08

### Added
- Windows desktop app support (`.exe` installer via NSIS)
- Windows platform added to `update.json` for automatic updates
- `build-desktop-windows` CI job for automated Windows builds

### Fixed
- CSV export formatting now includes UTF-8 BOM for Excel compatibility
- Consistency fix: `export_events()` now uses proper CSV quoting (matching `export_data()`)
- Integration tests now blocking in CI (removed `continue-on-error`)

### Changed
- Tauri config updated with Windows NSIS target
- Documentation updated: `webapp/` references corrected to `src-tauri/`
- Development docs now include Windows in update.json examples

### Security
- (none)

## [2.0.2] - 2026-03-20

### Fixed
- PWA service worker cache invalidation on version change
- OTP site-pairing rate-limiting now properly resets on success

## [2.0.1] - 2026-03-18

### Added
- WooCommerce analytics (funnel, revenue, top products)
- Campaign tracking (UTM parameter breakdown)

### Fixed
- Heatmap overlay rendering on high-DPI displays

## [2.0.0] - 2026-03-15

### Added
- Complete rewrite with privacy-first architecture
- Progressive Web App (PWA) for mobile/desktop
- Linux desktop app (`.deb` for amd64/arm64 via Tauri)
- OTP-based site pairing (replaces file import)
- Click tracking with heatmap visualization
- User flow analysis (entry sources, journey mapping)
- Email digest reports (scheduled)

### Changed
- Switched from cookie-based to cookieless tracking
- Bot detection now two-layer (client + server-side)

### Removed
- Legacy `.rsasite` file import (replaced by OTP flow)

## [1.4.10] - 2026-03-20

### Added
- Offline and site-unreachable banners with persistent data cache

## [1.4.9] - 2026-03-20

### Changed
- Version bumped to 1.4.9

## [1.4.8] - 2026-03-10

### Fixed
- Session tracking accuracy improvements
- Browser/OS detection updates

## [1.4.7] - 2026-03-05

### Added
- Custom date range support for all analytics views
- Export data via REST API (CSV/JSON)

### Fixed
- Timezone handling in analytics aggregation

## [1.4.6] - 2026-02-28

### Added
- Audience breakdown: viewport sizes, timezones
- Referrer domain aggregation

### Fixed
- PWA "Add to Home Screen" prompt timing

## [1.4.5] - 2026-02-20

### Fixed
- Admin CSS compatibility with WordPress 6.4+
- Chart.js tooltip positioning

## [1.4.4] - 2026-02-15

### Added
- Initial PWA support
- Tauri desktop app foundation

### Fixed
- Bot detection false positives
- PHP 8.3 compatibility

## [1.4.3] - 2026-03-19

### Changed
- Version bumped to 1.4.3

## [1.4.2] - 2026-03-19

### Fixed
- CI SSH authentication to app server now uses `webfactory/ssh-agent`

## [1.4.1] - 2026-03-18

### Fixed
- Mobile hamburger menu rendering
- Heatmap desktop height calculation

## [1.4.0] - 2026-01-30

### Added
- Freemius integration for premium licensing
- REST API with Application Password auth
- WP-CLI commands for automation

### Changed
- Plugin architecture refactored to class-based structure

## [1.3.0] - 2025-12-15

### Added
- Email digest reports
- Data export (CSV format)
- Settings API for user preferences

### Fixed
- Database migration reliability

## [1.2.0] - 2026-03-17

### Fixed
- i18n and a11y improvements in OTP/connect flow

## [1.1.0] - 2026-03-16

### Added
- `href_value` destination capture
- REST API and PWA fixes

## [1.0.1] - 2026-03-16

### Changed
- Version bumped to 1.0.1

## [1.0.0] - 2025-10-01

### Added
- Initial release
- Pageview tracking with bot filtering
- Basic analytics dashboard
- Session tracking
- WordPress admin integration
