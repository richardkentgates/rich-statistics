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

Rich Statistics uses a three-branch development model corresponding to three server environments:

| Branch | Environment | Subdomain | CI Workflow |
|--------|-------------|-----------|-------------|
| `main` | Production | `rs-app.richardkentgates.com` | `build-release.yml` (tagged releases) |
| `develop` | Dev / Beta | `rs-dev.richardkentgates.com` | `build-dev.yml` (push) |
| `test` | Staging / QA | `rs-test.richardkentgates.com` | `build-dev.yml` (push) |

- **`main`** — Stable releases only. Merged from `develop` via release PR.
- **`develop`** — Primary development branch. Base your feature branches here.
- **`test`** — Integration testing and QA validation. Merged from `develop` for pre-release verification.

Each push to `develop` or `test` triggers the `build-dev.yml` workflow which:
1. Builds a plugin ZIP
2. Syncs the PWA web app to the corresponding subdomain via webhook
3. Builds and pushes Linux `.deb` + Windows `.exe` binaries to the environment's `dist/` directory
4. Updates the environment's APT repository

Tagged releases on `main` trigger `build-release.yml` which additionally creates versioned PWA snapshots under `docs/app/v/<version>/`.

### Server resource endpoints

| Resource | Production | Dev | Test |
|----------|-----------|-----|------|
| PWA web app | `https://rs-app.richardkentgates.com` | `https://rs-dev.richardkentgates.com` | `https://rs-test.richardkentgates.com` |
| Deploy webhook | `https://rs-app.richardkentgates.com/_deploy/` | `https://rs-dev.richardkentgates.com/_deploy/` | `https://rs-test.richardkentgates.com/_deploy/` |
| APT repository | `https://rs-app.richardkentgates.com/apt/` | `https://rs-dev.richardkentgates.com/apt/` | `https://rs-test.richardkentgates.com/apt/` |
| Desktop binaries | `https://rs-app.richardkentgates.com/dist/` | `https://rs-dev.richardkentgates.com/dist/` | `https://rs-test.richardkentgates.com/dist/` |

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
7. After merging, the `develop` branch auto-deploys to the dev environment (`rs-dev.richardkentgates.com`)

### Release process

1. Changes accumulate on `develop` and are verified on `rs-dev.richardkentgates.com`
2. When ready for pre-release QA, merge `develop` into `test` — the `test` branch auto-deploys to `rs-test.richardkentgates.com`
3. After QA passes, create a release PR from `test` (or `develop`) into `main`
4. Tag the merge commit on `main` with `v<version>` — this triggers `build-release.yml` which builds the production plugin ZIP, desktop binaries, and PWA snapshot, then deploys to `rs-app.richardkentgates.com`

### What we review

- [ ] Tests added / updated
- [ ] No new linting errors
- [ ] CHANGELOG updated
- [ ] No PII storage introduced
- [ ] All new DB queries use `$wpdb->prepare()`
- [ ] Output escaped at the point of output

---

## Reporting Bugs

Please [open an issue](https://github.com/richardkentgates/rich-statistics/issues) with:

- WordPress version
- PHP version
- Plugin version
- Steps to reproduce
- Expected vs. actual behaviour

For security vulnerabilities, **do not open a public issue** — see [SECURITY.md](SECURITY.md) instead.
