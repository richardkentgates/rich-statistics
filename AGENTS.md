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
| `docs/app/2.2.7/` | Latest bundled PWA version (server serves under `/v/<version>/` via Apache rewrite) |
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
`/clicks`, `/heatmap`, `/export`, `/woocommerce`, `/user-flow` (+journey/+sources), `/purge-page`, `/ai/query`

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

WordPress Coding Standards via PHPCS (requires `wp-coding-standards/wpcs` — installed via `composer install`):
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

```
feature/foo ──PR──→ develop ──push──→ auto-deploy: rs-dev
                        │
                   merge PR
                        ↓
                      test ──push──→ auto-deploy: rs-test
                        │
                   merge PR
                        ↓
                      main ──tag v*──→ build-release.yml → rs-app
```

| Branch | Environment | Server | CI Workflow | Branch Type |
|--------|-------------|--------|-------------|-------------|
| `main` | Production | `rs-app.richardkentgates.com` | `build-release.yml` (tagged v*.*.*) | Stable releases |
| `develop` | Dev/Beta | `rs-dev.richardkentgates.com` | `build-develop.yml` (push) | Bleeding-edge |
| `test` | Staging | `rs-test.richardkentgates.com` | `build-test.yml` (push) | Integration testing |

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

All three build workflows share reusable sub-workflows for ZIP and desktop builds, keeping each pipeline concise.

### Reusable workflows (`.github/workflows/job-*.yml`)

| Workflow | Purpose |
|----------|---------|
| `job-build-zip.yml` | PHP syntax check, composer install, PHPCS, create plugin ZIP, upload artifact |
| `job-build-desktop.yml` | Tauri build for Linux amd64 + arm64 + Windows, push binaries, update APT repo + update.json |

### `build-develop.yml` (branch: develop)
- **Trigger**: Push to `develop`, or `workflow_dispatch`
- **build-zip**: Plugin ZIP via `job-build-zip` (version: `dev.<run_number>`)
- **deploy-web**: Syncs PWA to `rs-dev` via webhook
- **build-desktop**: Desktop binaries via `job-build-desktop`, pushed to `rs-dev/dist/`

### `build-test.yml` (branch: test)
- **Trigger**: Push to `test`, or `workflow_dispatch`
- **build-zip**: Plugin ZIP via `job-build-zip` (version: `test.<run_number>`)
- **deploy-web**: Syncs PWA to `rs-test` via webhook
- **build-desktop**: Desktop binaries via `job-build-desktop`, pushed to `rs-test/dist/`

### `build-release.yml` (tagged on main)
- **Trigger**: Tag push (`v*`), or `workflow_dispatch`
- **build**: Plugin ZIP, versioned PWA snapshot (`docs/app/v/<version>/`), GitHub Release
- **build-desktop**: Desktop binaries via `job-build-desktop` with `stamp-version: true`, pushed to `rs-app/dist/`
- **ping-deploy**: Syncs PWA to `rs-app` via webhook

### `setup-webhook.yml` (manual only)
- **Trigger**: `workflow_dispatch` with environment choice
- **Purpose**: One-time bootstrap of webhook handler + update script on any environment (production/dev/test)

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

See `ROADMAP.md` §6 for the full prioritized list.

| Priority | Gap | Status |
|----------|-----|--------|
| P2.2 | E2E test pipeline | ❌ Not started |
| P4.2 | WordPress.org SVN submission | ⏳ `bin/deploy-wporg.sh` ready; needs `wporg-assets/` screenshots then run it |

**Recently completed (May 2026):**
- P1: Environment-aware plugin (RSA_APP_URL + config.js env) ✅
- P2.1: PHPCS in CI (all 4 workflows) ✅
- P2.3: Migration + env detection tests (19 new tests) ✅
- P3: Signatures (CI wiring verified, next tag push) ✅
- P4.1: readme.txt (full 2.x changelog) ✅
- P5.1: Uptime monitoring — external system handles it ✅
- P5.2: Error tracking docs in ROADMAP §8.2 ✅
- P5.3–4: Ops docs (rollback + backup in ROADMAP §8) ✅
- D1–7: All documentation files audited and fixed ✅
- F2/F3: Removed orphaned templates + dead `/verify-install` endpoint ✅
- F4: Premium gating with `require_premium_or_exit()` on all renderers ✅
- F6/F7: Fixed tracker `total_time` NULL-coercion bug + optimized to single query ✅
- F9: 10 unit tests for `rsa_detect_app_env()` covering all env code paths ✅
- F10: `build.sh` includes env config files in versioned snapshots ✅
- F13: Snapshot path standardized to `docs/app/v/{version}/` in both `build.sh` and `build-release.yml` ✅
