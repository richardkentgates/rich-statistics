# Rich Statistics — Agent Instructions

## Overview

Rich Statistics is a WordPress analytics plugin with a companion PWA and Tauri desktop app.
Premium features are gated by Freemius (product ID 25954).

## Key Files

| File | Purpose |
|------|---------|
| `rich-statistics.php` | Main plugin file (version 2.2.7, RSA_VERSION constant) |
| `includes/class-rest-api.php` | REST API namespace `rsa/v1`, auth callbacks |
| `includes/class-db.php` | DB schema, migrations, table helpers |
| `includes/class-tracker.php` | Frontend tracking + ingest |
| `includes/class-analytics.php` | All read queries |
| `includes/class-admin.php` | Admin menus, app page, roles |
| `includes/class-woocommerce.php` | WooCommerce event tracking |
| `cli/class-cli.php` | WP-CLI commands (`wp rich-stats`) |
| `ROADMAP.md` | Audit findings, infrastructure plan, version compatibility roadmap |
| `tests/integration/` | PHPUnit integration tests (12 files) |
| `tests/unit/` | PHPUnit unit tests with BrainMonkey (5 files) |
| `docs/app/` | PWA source files (vanilla JS, no build step) |
| `docs/app/versions.json` | Available PWA version snapshots |
| `docs/app/v/2.2.7/` | Latest bundled PWA version (canonical: `/v/<version>/`) |
| `src-tauri/` | Tauri 2 desktop app wrapper |

## Database Tables (each uses `{$wpdb->prefix}rsa_` prefix)

- `events` — Raw pageviews
- `sessions` — Session aggregates
- `clicks` — Click tracking (premium)
- `heatmap` — Heatmap data (premium)
- `wc_events` — WooCommerce events (premium)

## REST API (namespace `rsa/v1`)

**Public:** `/info`, `/track`, `/verify-otp`

**Free tier** (`check_basic_auth` — capability only):
`/overview`, `/pages`, `/audience`, `/referrers`, `/behavior`, `/campaigns`, `/filter-options`, `/user-settings`

**Premium** (`check_premium_auth` — capability + Freemius license):
`/clicks`, `/heatmap`, `/export`, `/woocommerce`, `/user-flow` (+journey/+sources), `/purge-page`, `/verify-install`, `/ai/query`

## Running Tests

```bash
# All integration tests
php vendor/bin/phpunit --no-coverage tests/integration/

# Single file
php vendor/bin/phpunit --no-coverage tests/integration/WoocommerceTest.php

# Unit tests
php vendor/bin/phpunit --no-coverage tests/unit/

# All tests
php vendor/bin/phpunit --no-coverage
```

Freemius is stubbed in `tests/bootstrap.php` — `rs_fs()->can_use_premium_code__premium_only()` returns `false` in tests.

## Coding Standards

WordPress Coding Standards via PHPCS:
```bash
composer phpcs
composer phpcbf
```

## Common Tasks

### Adding a new REST endpoint
1. Add the callback method in `includes/class-rest-api.php`
2. Register the route in `register_routes()` with `$basic` or `$premium`
3. Add integration test in `tests/integration/RestApiTest.php`

### Adding a premium feature
1. Gate the admin template with `rs_fs()->can_use_premium_code__premium_only()`
2. Gate the REST endpoint with `$premium` callback
3. Add to `premiumFeatures` map in `docs/app/2.2.7/app.js`

### Creating a new PWA version
1. Copy the latest version folder under `docs/app/v/`
2. Update `docs/app/versions.json`
3. Update the latest folder's files

## Branch Structure

| Branch | Environment | Server | CI Workflow | Branch Type |
|--------|-------------|--------|-------------|-------------|
| `main` | Production | `rs-app.richardkentgates.com` | `build-release.yml` (tagged releases) | Stable releases |
| `develop` | Dev/Beta | `rs-dev.richardkentgates.com` | `build-dev.yml` (push) | Bleeding-edge |
| `test` | Staging | `rs-test.richardkentgates.com` | `build-dev.yml` (push) | Integration testing |

Each branch has its own:
- WordPress plugin ZIP (CI artifact)
- PWA web app (deployed via webhook)
- Linux `.deb` + Windows `.exe` desktop binaries (pushed to server `dist/`)

## Server Endpoints

| Resource | Production | Dev | Test |
|----------|-----------|-----|------|
| PWA origin | `rs-app.richardkentgates.com` | `rs-dev.richardkentgates.com` | `rs-test.richardkentgates.com` |
| Webhook | `rs-app.richardkentgates.com/_deploy/` | `rs-dev.richardkentgates.com/_deploy/` | `rs-test.richardkentgates.com/_deploy/` |
| APT repo | `rs-app.richardkentgates.com/apt/` | `rs-dev.richardkentgates.com/apt/` | `rs-test.richardkentgates.com/apt/` |
| Desktop binaries | `rs-app.richardkentgates.com/dist/` | `rs-dev.richardkentgates.com/dist/` | `rs-test.richardkentgates.com/dist/` |
| Server path | `/var/www/rs-app/public_html/` | `/var/www/rs-app-dev/` | `/var/www/rs-app-test/` |
| Git branch (updater) | `main` | `develop` | `test` |
| Webhook token | `/etc/rsa-webhook-token` | `/etc/rsa-webhook-token-dev` | `/etc/rsa-webhook-token-test` |

## CI Pipelines

### `build-dev.yml` (branches: develop, test)
- **Trigger**: Push to `develop` or `test`, or `workflow_dispatch`
- **Build**: Plugin ZIP (PHP syntax check, no tests)
- **deploy-web-dev**: Syncs PWA to `rs-dev` via webhook (develop push only)
- **build-desktop-dev**: Builds Linux + Windows desktop binaries, pushes to `rs-dev/dist/` (develop push only)
- **build-desktop-test**: Builds Linux + Windows desktop binaries, pushes to `rs-test/dist/` (test push only)
- **deploy-web-test**: Syncs PWA to `rs-test` via webhook (test push only, or manual dispatch)
- **test**: Runs PHPUnit unit + integration tests

### `build-release.yml` (tagged on main)
- **Trigger**: Tag push (`v*`)
- **Build**: Plugin ZIP, versioned PWA snapshot
- **Ping deploy**: Syncs PWA to `rs-app` via webhook
- **build-desktop-linux**: Linux `.deb` for amd64 + arm64, pushes to `rs-app/dist/`
- **build-desktop-windows**: Windows `.exe` installer, pushes to `rs-app/dist/`

## Infrastructure

See `ROADMAP.md` for the full audit of server infrastructure, version compatibility, CI/CD routing, and planned improvements across dev/test/prod environments.

## Feature Tiers

**Free:** Overview, Pages, Audience, Referrers, Behavior, Campaigns, User Settings

**Premium:** Heatmaps, Click Tracking, User Flow, WooCommerce, Export, AI Chat, Purge Page, Statistics Analyst role

## External Services

- Freemius — License management and plugin updates
- OpenAI/Custom — AI query feature
- `rs-app.richardkentgates.com` — Production PWA, desktop downloads, APT repo
- `rs-dev.richardkentgates.com` — Dev/bleeding-edge PWA and desktop builds
- `rs-test.richardkentgates.com` — Test/staging PWA (used for integration testing)

## Build / Deploy

- No JS build step (vanilla JS)
- `build.sh` — Downloads Chart.js, creates plugin ZIP
- `bin/install-wp-tests.sh` — Sets up WordPress test environment

## Remaining Work

See `ROADMAP.md` §6 for the full prioritized list. Verified discrepancies found during audit fix verification:

| Priority | Gap | Environment |
|----------|-----|-------------|
| P0 | APT repo missing | Dev |
| P0 | `dist/update.json` missing | Dev |
| P1 | `dist/update.json` version stale (2.1.0 vs 2.2.7) | Test |
| P1 | `v/` version directories incomplete | Dev, Test |
| P2 | `RSA_APP_URL` hardcoded to production | All |
| P3 | PHPCS not run in CI | All |
| P4 | WordPress.org SVN submission | — |
| P5 | Monitoring, rollback, backup | Prod |
