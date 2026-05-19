# Contributing to Rich Statistics

Thank you for your interest in contributing! This document covers the development workflow, coding standards, and PR process.

---

## Table of Contents

1. [Branch Structure](#branch-structure)
2. [Development Setup](#development-setup)
3. [Coding Standards](#coding-standards)
4. [Running Tests](#running-tests)
5. [Submitting a Pull Request](#submitting-a-pull-request)
6. [Reporting Bugs](#reporting-bugs)

---

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

| Branch | Environment | Subdomain | CI Workflow | Branch Type |
|--------|-------------|-----------|-------------|-------------|
| `main` | Production | `app.richstatistics.com` | `build-release.yml` (tagged) | Stable releases |
| `develop` | Development | `dev.richstatistics.com` | `build-develop.yml` (push) | Bleeding-edge development |
| `test` | Beta / Staging | `test.richstatistics.com` | `build-test.yml` (push) | Pre-release QA |

- **`main`** — Stable releases only. Merged from `test` via release PR, then tagged.
- **`develop`** — Active development branch. Base your feature branches here. All PRs target `develop`.
- **`test`** — Beta/staging branch. Receives merges from `develop` for pre-release QA. After sign-off, `test` merges into `main`.

Each push to `develop` triggers `build-develop.yml`; pushes to `test` trigger `build-test.yml`. Both build a plugin ZIP, sync PWA via webhook, and push desktop binaries to the environment's `dist/` directory. Tagged releases on `main` trigger `build-release.yml` which additionally creates versioned PWA snapshots.

### Server resource endpoints

| Resource | Production | Dev | Test |
|----------|-----------|-----|------|
| PWA web app | `https://app.richstatistics.com` | `https://dev.richstatistics.com` | `https://test.richstatistics.com` |
| Deploy webhook | `https://app.richstatistics.com/_deploy/` | `https://dev.richstatistics.com/_deploy/` | `https://test.richstatistics.com/_deploy/` |
| APT repository | `https://app.richstatistics.com/apt/` | `https://dev.richstatistics.com/apt/` | `https://test.richstatistics.com/apt/` |
| Desktop binaries | `https://app.richstatistics.com/dist/` | `https://dev.richstatistics.com/dist/` | `https://test.richstatistics.com/dist/` |

---

## Development Setup

### Prerequisites

- PHP 8.0+
- Composer
- MySQL 5.7+ or MariaDB 10.3+
- WordPress 6.0+ (local installation)
- WP-CLI (optional but recommended)

### Steps

```bash
# 1. Fork then clone
git clone https://github.com/YOUR_FORK/rich-statistics.git
cd rich-statistics

# 2. Install dev dependencies
composer install

# 3. Install WordPress test suite (adjust DB fields as needed)
bash bin/install-wp-tests.sh wordpress_tests root '' 127.0.0.1 latest

# 4. Run the test suite
composer test
```

The plugin works in development mode without the Freemius SDK — a built-in stub disables premium features gracefully so all free-tier tests run without any external dependency.

---

## Coding Standards

- **PHP:** follows [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/). Run `composer phpcs` to check.
- **JavaScript:** no bundler/transpiler — plain ES5-compatible JavaScript to match WordPress jQuery environment.
- **SQL:** all queries use `$wpdb->prepare()`. No raw user input in SQL.
- **Escaping:** all output is escaped at the point of output (`esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`).
- **Nonces:** all forms and AJAX handlers use and verify WordPress nonces.
- **File headers:** every PHP file starts with `defined( 'ABSPATH' ) || exit;`

---

## Running Tests

```bash
# Run the full test suite
composer test

# Run only a specific test class
composer test -- --filter RSA_Bot_Detection_Test

# Check coding standards
composer phpcs

# Auto-fix fixable coding standard issues
composer phpcbf
```

---

## Submitting a Pull Request

1. Create a feature branch FROM `develop`: `git checkout -b feature/your-feature-name`
2. Make your changes, add/update tests for new behaviour
3. Ensure `composer test` passes with zero failures
4. Ensure `composer phpcs` reports no errors
5. Update `CHANGELOG.md` under **[Unreleased]** describing your change
6. Open a PR against `develop` — describe the motivation, what changed, and how to test it
7. After merging, the `develop` branch auto-deploys to the dev environment (`dev.richstatistics.com`)

### Push Flow

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

1. **Feature work:** Branch from `develop`, PR back into `develop`. CI runs `tests.yml` + `build-develop.yml`.
2. **Dev deploy:** Every push to `develop` auto-deploys PWA + desktop to `dev.richstatistics.com`.
3. **Beta / Staging:** Merge `develop` → `test` via PR. Push to `test` auto-deploys to `test.richstatistics.com`. Test on the beta server before approving for production.
4. **Production release:** After QA passes on `test`, merge `test` → `main` via PR. Tag the merge commit `v<version>`. The tag triggers `build-release.yml` which builds the production ZIP, versioned PWA snapshot, desktop binaries, and deploys to `app.richstatistics.com`.

### Version Parity (MANDATORY)

**Every released version MUST have a bundled PWA snapshot in `docs/app/v/{version}/{channel}/`.**

The desktop app bundles versioned PWA snapshots. Users may have dozens of WordPress sites
running different plugin versions — the app detects each site's plugin version via
`/wp-json/rsa/v1/info` and serves the matching PWA files from `docs/app/v/{version}/{channel}/`.

**Channel structure:** Each version directory MUST contain `stable/` and `beta/` subdirectories
with identical PWA files. Channel differentiation is purely path-based.

**Rules:**
- **NEVER manually delete** a versioned snapshot folder from `docs/app/v/`. The CI prunes
  automatically to the last 12 versions on each release tag.
- **NEVER skip** a version — if v2.3.0 and v2.4.0 exist, v2.3.1 through v2.3.x must also exist.
- `docs/app/versions.json` and `docs/app/versions-beta.json` are **auto-generated** by CI. Never edit them manually.
- The `build-release.yml` workflow creates both `stable/` and `beta/` subdirs, prunes to the last 12, and updates both JSON files on every tag push.

If a user on plugin v2.2.5 opens the desktop app, it navigates to `/v/2.2.5/stable/`. If that folder
is missing, the app falls back to the latest bundled version — which may have incompatible API
changes. Version parity prevents this.

### What we review

- [ ] Tests added / updated
- [ ] No new linting errors
- [ ] CHANGELOG updated
- [ ] No PII storage introduced
- [ ] All new DB queries use `$wpdb->prepare()`
- [ ] Output escaped at the point of output
- [ ] Privacy disclosure shortcode updated if new data points added

---

## Reporting Bugs

Please [open an issue](https://github.com/richardkentgates/rich-statistics/issues) with:

- WordPress version
- PHP version
- Plugin version
- Steps to reproduce
- Expected vs. actual behaviour

For security vulnerabilities, **do not open a public issue** — see [SECURITY.md](SECURITY.md) instead.
