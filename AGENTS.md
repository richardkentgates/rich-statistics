# Rich Statistics — Agent Instructions

## Overview

Rich Statistics is a WordPress analytics plugin with a companion PWA and Tauri desktop app.
Premium features are gated by Freemius (product ID 25954).

## Key Files

| File | Purpose |
|------|---------|
| `rich-statistics.php` | Main plugin file (version 2.1.0, RSA_VERSION constant) |
| `includes/class-rest-api.php` | REST API namespace `rsa/v1`, auth callbacks |
| `includes/class-db.php` | DB schema, migrations, table helpers |
| `includes/class-tracker.php` | Frontend tracking + ingest |
| `includes/class-analytics.php` | All read queries |
| `includes/class-admin.php` | Admin menus, app page, roles |
| `includes/class-woocommerce.php` | WooCommerce event tracking |
| `cli/class-cli.php` | WP-CLI commands (`wp rich-stats`) |
| `tests/integration/` | PHPUnit integration tests (12 files) |
| `tests/unit/` | PHPUnit unit tests with BrainMonkey (5 files) |
| `docs/app/` | PWA source files (vanilla JS, no build step) |
| `docs/app/versions.json` | Available PWA version snapshots |
| `docs/app/2.2.6/` | Latest bundled PWA version |
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
3. Add to `premiumFeatures` map in `docs/app/2.2.6/app.js`

### Creating a new PWA version
1. Copy the latest version folder under `docs/app/`
2. Update `docs/app/versions.json`
3. Update the latest folder's files

## Feature Tiers

**Free:** Overview, Pages, Audience, Referrers, Behavior, Campaigns, User Settings

**Premium:** Heatmaps, Click Tracking, User Flow, WooCommerce, Export, AI Chat, Purge Page, Statistics Analyst role

## External Services

- Freemius — License management and plugin updates
- OpenAI/Custom — AI query feature
- `rs-app.richardkentgates.com` — Hosted PWA and .deb packages

## Build / Deploy

- No JS build step (vanilla JS)
- `build.sh` — Downloads Chart.js, creates plugin ZIP
- `bin/install-wp-tests.sh` — Sets up WordPress test environment
