# Rich Statistics — AI Assistant Instructions

This file gives GitHub Copilot and other AI assistants the context needed to work
effectively in this repository. Read [DEVELOPMENT.md](../DEVELOPMENT.md) and
[ARCHITECTURE.md](../ARCHITECTURE.md) for full detail on any topic below.

---

## What this repo is

**Rich Statistics** is a WordPress analytics plugin (free + premium via Freemius)
that tracks pageviews, sessions, clicks, UTMs, and heatmaps — with zero PII stored
and no cookie consent required. It also ships a companion PWA and a native Linux
desktop app (Tauri/.deb) that connect to the plugin via the WordPress REST API.

---

## Three deliverables

| | |
|---|---|
| **WordPress plugin ZIP** | The main PHP plugin, distributed via WordPress.org (free) and Freemius (premium) |
| **PWA / web app** | Vanilla JS at `docs/app/`, served from `rs-app.richardkentgates.com` |
| **Linux desktop app** | Tauri-wrapped `.deb` in `src-tauri/`, served from `rs-app.richardkentgates.com/desktop/` |

---

## File responsibility map

| Path | Purpose |
|---|---|
| `rich-statistics.php` | Plugin bootstrap: constants, Freemius init, class autoload |
| `includes/class-db.php` | DB schema, activation, migrations (`SCHEMA_VERSION`) |
| `includes/class-tracker.php` | Frontend JS enqueue + ingest AJAX handler (`rsa_track`) |
| `includes/class-bot-detection.php` | Two-layer bot scoring (JS bitmask + server UA) |
| `includes/class-analytics.php` | All read SQL queries (overview, pages, audience, etc.) |
| `includes/class-admin.php` | Admin menus, enqueue, page rendering, filter handling |
| `includes/class-rest-api.php` | Premium REST API (`rsa/v1/*`) for the PWA/desktop app |
| `includes/class-click-tracking.php` | Premium click listener + admin click-map page |
| `includes/class-heatmap.php` | Premium heatmap aggregation (nightly cron) + display |
| `includes/class-email.php` | Scheduled HTML digest emails |
| `includes/class-pwa-download.php` | Serves PWA ZIP download; OTP generation for app auth |
| `includes/class-woocommerce.php` | WooCommerce analytics: event recording (product views, add-to-cart, orders), WC path normalisation, dashboard data queries |
| `cli/class-cli.php` | WP-CLI command group: `wp rich-stats *` |
| `assets/js/tracker.js` | Frontend tracker (bot signals, UTM, session, Beacon API) |
| `assets/js/admin-charts.js` | Admin dashboard Chart.js wrappers |
| `assets/js/heatmap-overlay.js` | Canvas heatmap overlay (premium) |
| `assets/js/rsa-profile-otp.js` | App Code / OTP generation on user profile page |
| `templates/admin/` | PHP view partials (one per admin sub-page) |
| `docs/app/` | Live PWA files — updated by webhook on each release |
| `docs/wiki/` | GitHub Pages wiki — HTML documentation |
| `webapp/` | Tauri source for the `.deb` desktop app |
| `vendor/freemius/` | Freemius SDK — **committed, ships in the plugin ZIP** |
| `bin/setup-app-server.sh` | Provisions the app server from scratch (Debian 12) |
| `bin/server-webhook.php` | Source for `_deploy/index.php` on the app server |
| `bin/server-update-webapp.sh` | Source for `/usr/local/bin/rsa-app-update` on server |
| `.github/workflows/tests.yml` | Unit + integration tests on every push/PR |
| `.github/workflows/build-release.yml` | Builds ZIP + .deb, deploys app server, on `v*.*.*` tags |

---

## Database tables

All prefixed `{wpdb->prefix}rsa_`. See `includes/class-db.php` for schema.

| Table | Contents |
|---|---|
| `rsa_events` | One row per pageview (session_id, page, browser, os, UTMs, bot_score, …) |
| `rsa_sessions` | One row per browser session (pages_viewed, total_time, entry/exit pages) |
| `rsa_clicks` | Premium: one row per tracked click (element, coordinates, matched_rule) |
| `rsa_heatmap` | Premium: nightly-aggregated click buckets (x_pct, y_pct, weight, date_bucket) |

---

## Coding rules (always follow these)

- **Security first.** All SQL uses `$wpdb->prepare()`. All output uses `esc_html()`,
  `esc_attr()`, `esc_url()`, or `wp_kses_post()`. All AJAX handlers verify nonces.
  Every PHP file starts with `defined( 'ABSPATH' ) || exit;`.
- **No raw user input** anywhere in SQL, HTML output, or shell commands.
- **WordPress Coding Standards** — tabs for indentation in PHP, spaces in JS,
  Yoda conditions, `snake_case` for PHP functions/vars, `camelCase` for JS.
- **No build step for JS.** Plain ES5-compatible JavaScript only. No `import`,
  no arrow functions, no template literals in production JS files.
- **No new PHP dependencies** without discussion — the plugin must remain self-contained.
- **Premium features must check** `rs_fs()->can_use_premium_code__premium_only()`
  before executing any premium logic.
- **Tests required** for all new behaviour. Unit tests in `tests/unit/`,
  integration tests in `tests/integration/`.

---

## Things that look unusual but are intentional

- `vendor/freemius/` is committed and is NOT in `.gitignore` — it ships in the ZIP.
- `vendor/chart.min.js` is committed directly (not npm) — it is a pure runtime dep.
- There is no webpack, Vite, or any bundler — everything is plain PHP + ES5 JS.
- `rs_fs()` returns a stub object in development (no SDK) — premium checks silently
  return false, which is correct and intentional.
- The plugin ZIP excludes `vendor/` except for `vendor/freemius/` and
  `vendor/autoload.php` + `vendor/composer/`.

---

## External services

| Service | Role |
|---|---|
| **GitHub** | Source, CI, Release artifacts |
| **WordPress.org** | Free plugin distribution |
| **Freemius** | Premium licensing, payments, in-plugin auto-updates |
| **rs-app.richardkentgates.com** | App server: serves PWA, `.deb` files; receives deploy webhook |

---

## GitHub secrets required for CI

| Secret | Used by |
|---|---|
| `APP_SERVER_SSH_KEY` | `build-desktop` job — SCP `.deb` files to app server |
| `DEPLOY_WEBHOOK_TOKEN` | `ping-deploy` job — `POST /_deploy/` webhook |
| `TAURI_SIGNING_PRIVATE_KEY` | `build-desktop` job — signs `.deb` for auto-updater verification |

---

## Branch model

```
main         ← tagged releases only
  └── develop ← all PRs target this branch
        └── feature/* or fix/*
```

Tags (`v1.x.x`) on `main` trigger the full release build. Tests run on push/PR to
both `main` and `develop`.

---

## Release process (exact steps — follow every time)

1. Finish work on `develop`, push
2. `git checkout main && git merge develop && git push origin main`
3. Bump version in **three places**: `rich-statistics.php` (header + `RSA_VERSION`), `readme.txt` (Stable tag), `CHANGELOG.md` (new section with date)
4. `git add rich-statistics.php readme.txt CHANGELOG.md && git commit -m "chore: bump version to X.Y.Z"`
5. `git push origin main`
6. `git tag vX.Y.Z && git push origin vX.Y.Z` ← this triggers all automation
7. Sync back: `git checkout develop && git merge main && git push origin develop`
8. Monitor: `gh run list --limit 10 --json databaseId,name,status,conclusion,headBranch`

**NEVER manually SCP or deploy app files to the server.** All deployment is handled
by the tag-triggered CI workflow automatically.

---

## What the tag triggers (build-release.yml)

| Job | What it does |
|---|---|
| `build` | Builds plugin ZIP, creates GitHub Release, commits versioned `docs/app/X.Y.Z/` snapshot to `main` |
| `build-desktop` (amd64 + arm64) | Builds `.deb` via Tauri, SCPs to server's `/var/www/rs-app/desktop/`, runs `rsa-apt-repo-update` on server |
| `ping-deploy` | POSTs to `/_deploy/` webhook; server pulls `docs/app/` from GitHub and goes live |

---

## App server

| Item | Value |
|---|---|
| IP | `104.197.231.120` |
| User | `richardkentgates` |
| Local SSH key | `~/.ssh/id_rsa` (only this works — `gcloud_key` and `id_ed25519` are rejected) |
| Web root | `/var/www/rs-app/` |
| APT repo | `https://rs-app.richardkentgates.com/apt stable main` |
| APT update script | `/usr/local/bin/rsa-apt-repo-update` |
| Webhook token file | `/etc/rsa-webhook-token` (chmod 640, chown root:www-data) |

### Test/dev WordPress server (separate GCP VM)
- IP `34.56.56.233`, user `richardkentgates`, SSH key `~/.ssh/id_rsa`
- WordPress root `/srv/www/wordpress`, plugin path `.../wp-content/plugins/rich-statistics/`
- Deploy: `rsync -az --delete --exclude='.git' -e "ssh -i ~/.ssh/id_rsa" ./ richardkentgates@34.56.56.233:/srv/www/wordpress/wp-content/plugins/rich-statistics/`
- `vendor/freemius/` MUST be included in rsync — it ships in the plugin
