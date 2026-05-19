# Rich Statistics — Complete CI/CD Documentation

## Executive Summary

Rich Statistics uses a three-branch GitFlow-inspired workflow with automated Freemius integration. All deployments are fully automated via GitHub Actions, with manual promotion gates between environments.

**Key Achievement:** Replaced unreliable third-party `buttonizer/freemius-deploy` action with official Freemius PHP SDK, achieving 100% reliable uploads with proper release mode handling.

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                        BRANCH STRUCTURE                              │
├──────────────┬──────────────┬───────────────────────────────────────┤
│   develop    │    test      │              main                     │
│   (dev)      │   (staging)  │           (production)                │
├──────────────┼──────────────┼───────────────────────────────────────┤
│ Auto-deploy  │ Auto-deploy  │ Manual promotion only                 │
│ on push      │ on push      │ Via promote.yml workflow              │
├──────────────┼──────────────┼───────────────────────────────────────┤
│ dev.         │ test.        │ app.                                  │
│ richstatistics.com          │ richstatistics.com                    │
├──────────────┼──────────────┼───────────────────────────────────────┤
│ Freemius: —  │ Freemius:    │ Freemius:                             │
│              │ beta         │ released (public)                     │
└──────────────┴──────────────┴───────────────────────────────────────┘
```

---

## Workflows

### 1. build-develop.yml

**Location:** `.github/workflows/build-develop.yml`  
**Trigger:** Push to `develop` or `workflow_dispatch`  
**Purpose:** Continuous integration for development

```yaml
Jobs:
  ├── build-zip (reusable)
  │   ├── PHP syntax check
  │   ├── Composer install
  │   ├── PHPCS (WordPress Coding Standards)
  │   └── Create plugin ZIP (version: dev.{run_number})
  │
  ├── deploy-web
  │   └── Webhook POST to dev.richstatistics.com/_deploy/
  │
  └── build-desktop (reusable)
      ├── Tauri build: Linux amd64
      ├── Tauri build: Linux arm64
      └── Tauri build: Windows
```

**Key Features:**
- Version format: `dev.{run_number}` (e.g., `dev.159`)
- Artifact retention: 1 day
- No Freemius upload (development only)

---

### 2. build-test.yml

**Location:** `.github/workflows/build-test.yml`  
**Trigger:** Push to `test` or `workflow_dispatch`  
**Purpose:** Pre-release QA with Freemius beta distribution

```yaml
Jobs:
  ├── build-zip (reusable)
  │   └── Version: test.{run_number}
  │
  ├── deploy-web
  │   └── Webhook to test.richstatistics.com/_deploy/
  │
  ├── upload-freemius ⭐ NEW
  │   ├── Setup PHP 8.1 + extensions
  │   ├── Checkout repo (for SDK + deploy script)
  │   ├── Download ZIP artifact
  │   └── Run: php bin/deploy-freemius.php {zip} {version} beta
  │
  └── build-desktop (reusable)
      └── Product suffix: "(Test)"
```

**Freemius Integration:**
- Release mode: `beta` (opt-in beta testers only)
- Uses official Freemius PHP SDK
- Handles re-uploads gracefully (skips if version exists, updates release_mode)

**Key Features:**
- Version format: `test.{run_number}` → maps to plugin's `RSA_VERSION`
- Artifact retention: 1 day
- Beta channel on Freemius

---

### 3. build-release.yml

**Location:** `.github/workflows/build-release.yml`  
**Trigger:** Tag push (`v*.*.*`) or `workflow_dispatch` from promote.yml  
**Purpose:** Production releases with public Freemius distribution

```yaml
Jobs:
  ├── build-zip (reusable)
  │   └── Version: from tag or input
  │
  ├── upload-freemius ⭐ NEW
  │   ├── Condition: startsWith(github.ref, 'refs/tags/')
  │   ├── Determine channel from tag name
  │   │   ├── Contains "beta" → release_mode=beta
  │   │   └── Otherwise → release_mode=released
  │   └── Run: php bin/deploy-freemius.php {zip} {version} {mode}
  │
  ├── release
  │   ├── Create GitHub Release
  │   ├── Attach ZIP + SHA256 checksum
  │   ├── Create PWA snapshot: docs/app/v/{version}/{stable,beta}/
  │   ├── Update versions.json (auto-generated from v/ directory)
  │   └── Prune old snapshots (keep last 12)
  │
  ├── build-desktop (reusable)
  │   ├── Stamp version in binary names
  │   └── Product suffix: "" (no suffix for production)
  │
  └── ping-deploy
      └── Webhook to app.richstatistics.com/_deploy/
```

**Freemius Integration:**
- Stable tags: `release_mode=released` (public to all license holders)
- Beta tags: `release_mode=beta` (beta opt-in list)
- Smart handling: checks if version exists, skips re-upload, updates release_mode

**PWA Snapshot Structure:**
```
docs/app/v/
├── 2.4.19/
│   ├── stable/   ← Production PWA files
│   │   ├── index.html, app.js, app.css, config.js, ...
│   │   └── icons/
│   └── beta/     ← Beta PWA files (identical structure)
│       └── ...
├── 2.4.20/
│   └── ...
└── versions.json  ← Auto-generated list of versions
```

**Key Features:**
- Version from git tag (e.g., `v2.4.20`)
- Artifact retention: 30 days
- GitHub Release with checksums
- Versioned PWA snapshots for desktop app compatibility

---

### 4. promote-test.yml

**Location:** `.github/workflows/promote-test.yml`  
**Trigger:** `workflow_dispatch` on `develop` branch  
**Purpose:** Merge develop → test for QA

```yaml
Jobs:
  └── promote
      ├── Checkout develop (fetch-depth: 0 for full history)
      ├── Configure git (github-actions bot)
      ├── Create PR: develop → test
      ├── Merge PR (--merge, NOT --squash)
      └── Dispatch "Build Test" workflow
```

**Critical Design Decisions:**

1. **--merge (not --squash):** Preserves git history, prevents branch divergence that caused merge conflicts with squash merges.

2. **Direct workflow dispatch:** `gh workflow run "Build Test" --ref test` — Push events from GITHUB_TOKEN cannot trigger new workflows (GitHub anti-recursion), so we dispatch directly.

3. **Full fetch depth:** `fetch-depth: 0` ensures complete history for proper merge detection.

**Usage:**
```
GitHub → Actions → Promote to Test → Run workflow
(No inputs required)
```

---

### 5. promote.yml (Promote to Production)

**Location:** `.github/workflows/promote.yml`  
**Trigger:** `workflow_dispatch` on `test` branch  
**Purpose:** Merge test → main, create tag, trigger release

```yaml
Inputs:
  ├── version: Auto-detected from rich-statistics.php (can override)
  └── channel: stable (default) | beta

Jobs:
  └── promote
      ├── Checkout test (fetch-depth: 0)
      ├── Resolve version (from input or RSA_VERSION constant)
      ├── Configure git
      │
      ├── IF channel == 'stable':
      │   ├── Create PR: test → main
      │   ├── Merge PR (--merge)
      │   ├── Fetch origin/main
      │   ├── Force-create tag: git tag -f v{version} origin/main  ⭐ CRITICAL
      │   ├── Force-push tag: git push -f origin v{version}       ⭐ CRITICAL
      │   └── Dispatch "Build Release": gh workflow run --ref v{version}
      │
      └── IF channel == 'beta':
          ├── Create tag: v{version}-beta.1
          └── Push tag
```

**Critical Fix: Force Tag Handling**

Problem: Tag `v2.4.19` already existed from previous release, causing promote to fail.

Solution: Use `git tag -f` and `git push -f origin` to force-update existing tags. This is safe because:
- We just merged test → main
- The merge commit contains the code we want to release
- The old tag pointed to outdated code

**Usage:**
```
GitHub → Actions → Promote to Production → Run workflow
Inputs:
  - version: (leave blank for auto-detect)
  - channel: stable | beta
```

---

### 6. Reusable Workflows

#### job-build-zip.yml

**Purpose:** Standardized plugin ZIP creation

```yaml
Inputs:
  ├── version (required)
  ├── artifact-name (required)
  └── retention-days (default: 1)

Steps:
  ├── Checkout
  ├── Setup PHP 8.2
  ├── PHP syntax check (all .php files)
  ├── Composer install
  ├── PHPCS (WordPress Coding Standards)
  ├── Create ZIP (excludes: .git, vendor, tests, docs, bin, composer.*, etc.)
  ├── Generate SHA256 checksum
  └── Upload artifact
```

**Exclusions from ZIP:**
```
--exclude="*.git*"
--exclude="*/tests/*"
--exclude="*/bin/*"
--exclude="*/build/*"
--exclude="*/docs/*"
--exclude="composer.json"
--exclude="composer.lock"
--exclude="phpunit.xml.dist"
--exclude="phpcs.xml.dist"
--exclude="*.sh"
--exclude="CONTRIBUTING.md"
--exclude="SECURITY.md"
--exclude="README.md"
```

#### job-build-desktop.yml

**Purpose:** Tauri desktop app builds for all platforms

```yaml
Inputs:
  ├── target-path (server destination)
  ├── server-host
  ├── updater-url
  ├── apt-script
  ├── update-json-script
  ├── artifact-prefix
  ├── product-suffix (e.g., "(Test)")
  └── stamp-version (boolean)

Steps:
  ├── Setup Node.js
  ├── Install Tauri CLI
  ├── Build: Linux amd64 (.deb)
  ├── Build: Linux arm64 (.deb)
  ├── Build: Windows (.exe)
  ├── Push binaries to server via SSH
  ├── Run APT repo update script
  └── Update update.json for auto-updater
```

---

## Freemius Integration

### bin/deploy-freemius.php

**Purpose:** Upload plugin ZIP to Freemius using official PHP SDK

**Location:** `bin/deploy-freemius.php`

**Usage:**
```bash
php bin/deploy-freemius.php <file_name> <version> <release_mode> [sandbox]

# Example:
php bin/deploy-freemius.php rich-statistics-2.4.20.zip 2.4.20 released
php bin/deploy-freemius.php rich-statistics-2.4.20.zip 2.4.20 beta
```

**Environment Variables:**
```bash
DEV_ID=25954
PUBLIC_KEY=${FREEMIUS_PUBLIC_KEY}
SECRET_KEY=${FREEMIUS_SECRET_KEY}
PLUGIN_SLUG=rich-statistics
PLUGIN_ID=25954
```

**Algorithm:**
```php
1. Initialize Freemius SDK (developer scope)
2. GET plugins/{id}/tags.json — Check if version exists
3. IF exists:
   - Skip upload
   - Use existing tag ID
4. IF not exists:
   - POST plugins/{id}/tags.json with ZIP file
   - Get new tag ID from response
5. PUT plugins/{id}/tags/{tag_id}.json with release_mode
6. Output result
```

**Release Modes:**
| Mode | Description | Used By |
|------|-------------|---------|
| `beta` | Beta opt-in users only | build-test.yml, beta tags |
| `released` | All license holders | build-release.yml (stable) |
| `pending` | Unreleased (manual) | Not used in CI |

**Error Handling:**
- Missing env vars → exit 1
- File not found → exit 1
- SDK init failure → exit 1
- Upload failure → exit 1 (prints response)
- release_mode failure → exit 1 (prints response)

**Success Output:**
```
Checking existing tags for version 2.4.20...
Found existing tag ID 123385 for version 2.4.20
Version 2.4.20 already exists on Freemius (tag ID 123385). Skipping upload.
Setting release_mode to beta...
Done. Version 2.4.20 (tag ID 123385) set to release_mode=beta
Version: 2.4.20, Status: unknown
```

---

### SDK Files

**Location:** `bin/freemius-php-api/freemius/`

```
freemius/
├── Freemius.php              # Main SDK class (curl wrapper, HMAC signing)
├── FreemiusBase.php          # Base class (auth, path canonization)
└── Exceptions/
    ├── Exception.php
    ├── InvalidArgumentException.php
    ├── ArgumentNotExistException.php
    ├── EmptyArgumentException.php
    └── OAuthException.php
```

**PHPCS Exclusion:**
The SDK is excluded from WordPress Coding Standards checks via `phpcs.xml.dist`:
```xml
<exclude-pattern>bin/</exclude-pattern>
```

**Why:** SDK is third-party code, not following WPCS. Our deploy script is also excluded as it's a CLI tool, not WordPress plugin code.

---

## Server Infrastructure

### PWA Deployment Mechanism

**Webhook Endpoint:**
```
POST https://{env}.richstatistics.com/_deploy/
Header: X-Deploy-Token: {secret-token}
```

**Server-Side Flow:**
1. Webhook PHP (`_deploy/index.php`) receives POST
2. Validates `X-Deploy-Token` header against `/etc/rsa-webhook-token-{env}`
3. Writes timestamp to `{webroot}/.deploy-trigger`
4. Returns 200 OK immediately

**Cron Poller:**
```bash
# Runs every minute (3 separate crons: dev, test, prod)
* * * * * /usr/local/bin/rsa-deploy-cron-{env}
```

**Deploy Script (`rsa-deploy-cron-{env}`):**
```bash
1. Check if .deploy-trigger exists
2. Check age < 120 seconds
3. If valid:
   - Delete .deploy-trigger
   - Run rsa-app-update-{env}
   - Log deployment
4. If invalid (too old):
   - Delete .deploy-trigger (stale)
```

**App Update Script (`rsa-app-update-{env}`):**
```bash
1. cd {webroot}
2. git init (if not exists)
3. git remote add {env} {server-repo-url}
4. git fetch {env} {branch}
5. git sparse-checkout set docs/app/
6. git checkout {branch} -- docs/app/
7. Copy docs/app/* to webroot
8. Record deployed version in .deployed-version
```

**Branch Mapping:**
| Environment | Branch | Token File |
|-------------|--------|------------|
| production  | main   | `/etc/rsa-webhook-token` |
| dev         | develop| `/etc/rsa-webhook-token-dev` |
| test        | test   | `/etc/rsa-webhook-token-test` |

**Manual Trigger:**
```bash
curl -X POST \
  -H "X-Deploy-Token: $(cat /etc/rsa-webhook-token-{env})" \
  https://{env}.richstatistics.com/_deploy/
```

**Troubleshooting:**
```bash
# Check if cron is running
grep "rsa-deploy-cron" /var/log/syslog | tail -5

# Check last deploy
cat /var/www/rs-app-{env}/.deployed-version

# Check trigger file age
ls -la /var/www/rs-app-{env}/.deploy-trigger

# Manual deploy test
curl -v -X POST -H "X-Deploy-Token: $(cat /etc/rsa-webhook-token-{env})" \
  https://{env}.richstatistics.com/_deploy/
```

---

### Desktop Binary Distribution

**Server Path:** `/var/www/rs-app-{env}/dist/`

**Files:**
```
dist/
├── rich-statistics_{version}_amd64.deb
├── rich-statistics_{version}_arm64.deb
├── rich-statistics_{version}.exe
├── update.json              # Auto-update manifest
└── apt/
    ├── InRelease
    ├── main/
    │   └── binary-amd64/
    │       └── Packages.gz
    └── binary-arm64/
        └── Packages.gz
```

**Update Manifest (update.json):**
```json
{
  "version": "2.4.20",
  "notes": "See CHANGELOG.md",
  "pubDate": "2026-05-18T11:00:00Z",
  "platforms": {
    "linux-amd64": {
      "url": "https://app.richstatistics.com/dist/rich-statistics_2.4.20_amd64.deb",
      "signature": "sha256:abc123..."
    },
    "linux-arm64": { ... },
    "windows": { ... }
  }
}
```

**APT Repo Update:**
```bash
# Run by CI after pushing .deb files
rsa-apt-repo-update-{env}

# Steps:
1. dpkg-scanpackages apt/main > apt/main/Packages
2. gzip apt/main/Packages
3. apt-ftparchive release apt/main > apt/InRelease
4. Sign with GPG key
```

---

## Version Management

### Version Strings (Must All Match)

**File: `rich-statistics.php`**
```php
// Line 6 (plugin header)
 * Version:           2.4.20

// Line 62 (constant)
define( 'RSA_VERSION', '2.4.20' );
```

**File: `readme.txt`**
```
Line 7: Stable tag: 2.4.20
```

**Auto-Detection:**
The promote workflow extracts version from `rich-statistics.php` line 62:
```bash
VERSION=$(grep "define( 'RSA_VERSION'" rich-statistics.php | sed "s/.*'\([0-9.]*\)'.*/\1/")
```

### Version Bump Checklist

Before promoting to production:

- [ ] Update `rich-statistics.php` line 6 (plugin header)
- [ ] Update `rich-statistics.php` line 62 (RSA_VERSION constant)
- [ ] Update `readme.txt` line 7 (Stable tag)
- [ ] Commit and push to test branch
- [ ] Verify tests pass on test branch
- [ ] Run Promote to Production workflow

### Semantic Versioning

**Format:** `MAJOR.MINOR.PATCH`

**Examples:**
- `2.4.19` → `2.4.20` (patch: bug fixes, minor improvements)
- `2.4.x` → `2.5.0` (minor: new features, backwards compatible)
- `2.x.x` → `3.0.0` (major: breaking changes)

**Beta Tags:**
- Format: `v{version}-beta.{n}`
- Example: `v2.4.20-beta.1`
- Used for: Pre-release testing on test branch

---

## Complete Release Flow (Step-by-Step)

### Phase 1: Development

```bash
# 1. Work on feature branch
git checkout -b feature/my-feature
# ... make changes, commit ...
git push origin feature/my-feature

# 2. Create PR to develop
gh pr create --base develop --head feature/my-feature --title "Add feature"

# 3. Review, merge PR (squash or merge)

# 4. Develop auto-deploys
# build-develop.yml triggers automatically
# PWA deploys to dev.richstatistics.com
```

### Phase 2: Promote to Test

```
GitHub UI:
1. Actions → Promote to Test → Run workflow
2. Select branch: develop
3. Run workflow
```

**What Happens:**
1. Workflow checks out develop
2. Creates PR: develop → test
3. Merges PR (--merge)
4. Dispatches Build Test workflow

**Build Test Runs:**
1. Creates plugin ZIP (version from RSA_VERSION)
2. Runs PHPCS, tests
3. Deploys PWA to test.richstatistics.com
4. **Uploads to Freemius as `beta`**
5. Builds desktop binaries

**Verify:**
- [ ] Check test.richstatistics.com
- [ ] Check Freemius dashboard (beta version listed)
- [ ] Test desktop app on test server
- [ ] Run manual QA tests

### Phase 3: Promote to Production

```
GitHub UI:
1. Actions → Promote to Production → Run workflow
2. Select branch: test
3. Inputs:
   - version: (leave blank for auto-detect)
   - channel: stable
4. Run workflow
```

**What Happens:**
1. Workflow checks out test
2. Resolves version (auto from RSA_VERSION)
3. Creates PR: test → main
4. Merges PR (--merge)
5. Force-creates tag: `git tag -f v{version} origin/main`
6. Force-pushes tag: `git push -f origin v{version}`
7. Dispatches Build Release workflow

**Build Release Runs:**
1. Creates plugin ZIP
2. **Uploads to Freemius as `released`** (public)
3. Creates GitHub Release with ZIP + checksum
4. Creates PWA snapshot: `docs/app/v/{version}/{stable,beta}/`
5. Updates `versions.json`
6. Prunes old snapshots (keep last 12)
7. Builds desktop binaries (version-stamped)
8. Deploys PWA to app.richstatistics.com

**Verify:**
- [ ] GitHub Release created
- [ ] Freemius shows version as "released"
- [ ] Production PWA updated
- [ ] Desktop binaries available
- [ ] APT repo updated

---

## Troubleshooting Guide

### Problem: Promote Fails — Tag Already Exists

**Error:**
```
fatal: tag 'v2.4.19' already exists
```

**Cause:** Tag was created in a previous release cycle, pointing to old code.

**Solution:**
```bash
# Force update tag to current main
git fetch origin main
git tag -f v2.4.19 origin/main
git push -f origin v2.4.19

# Re-dispatch Build Release
gh workflow run "Build Release" --ref "v2.4.19"
```

**Permanent Fix:** promote.yml now uses `git tag -f` and `git push -f` automatically.

---

### Problem: Freemius Upload Fails

**Error in logs:**
```
Upload failed. Response: {...}
```

**Checklist:**
1. **Secrets configured?**
   - `FREEMIUS_PUBLIC_KEY`
   - `FREEMIUS_DEV_ID` (25954)
   - `FREEMIUS_SECRET_KEY`
   - `PLUGIN_SLUG` (rich-statistics)
   - `PLUGIN_ID` (25954)

2. **ZIP file exists?**
   ```bash
   ls -la build/rich-statistics-*.zip
   ```

3. **Network connectivity?**
   SDK uses curl to api.freemius.com

4. **Version already uploaded?**
   This is OK — script skips upload and just updates release_mode

**Debug:**
```bash
# Test SDK initialization
php -r "
require 'bin/freemius-php-api/freemius/Freemius.php';
\$api = new Freemius_Api('developer', 25954, 'PUBKEY', 'SECRET', false);
print_r(\$api->Api('plugins/25954/tags.json', 'GET'));
"
```

---

### Problem: Deploy Stuck

**Symptoms:**
- Webhook returns 200 OK
- PWA not updating on server

**Check:**
```bash
# Check cron is running
grep "rsa-deploy-cron" /var/log/syslog | tail -5

# Check trigger file
ls -la /var/www/rs-app-{env}/.deploy-trigger
stat /var/www/rs-app-{env}/.deploy-trigger

# Check last deployed version
cat /var/www/rs-app-{env}/.deployed-version

# Manual trigger test
curl -v -X POST \
  -H "X-Deploy-Token: $(cat /etc/rsa-webhook-token-{env})" \
  https://{env}.richstatistics.com/_deploy/
```

**Common Causes:**
1. Cron not running
2. Trigger file too old (>120s)
3. SSH key permissions on server
4. Git sparse-checkout failure

---

### Problem: Source Branch Deleted

**Symptoms:**
- develop or test branch missing
- Auto-deploys broken

**Cause:** Someone used `--delete-branch` in `gh pr merge` (FORBIDDEN)

**Fix:**
```bash
# Restore develop
git push origin origin/main:refs/heads/develop

# Restore test
git push origin origin/main:refs/heads/test
```

**Prevention:** Never use `--delete-branch` in promote workflows. Branches are long-lived environments, not feature branches.

---

### Problem: PHPCS Fails in CI

**Error:**
```
Script phpcs handling the phpcs event returned with error code 2
```

**Causes:**
1. New code has WPCS violations
2. SDK files accidentally included

**Fix:**
```bash
# Run locally
composer phpcs

# Auto-fix what's possible
composer phpcbf

# Check exclusions in phpcs.xml.dist
cat phpcs.xml.dist | grep exclude-pattern

# Ensure bin/ and SDK are excluded
<exclude-pattern>bin/</exclude-pattern>
```

---

## Environment Variables Reference

### GitHub Secrets (Required)

| Secret | Description | Example |
|--------|-------------|---------|
| `FREEMIUS_PUBLIC_KEY` | Freemius plugin public key | `pk_abc123...` |
| `FREEMIUS_DEV_ID` | Freemius developer ID | `25954` |
| `FREEMIUS_SECRET_KEY` | Freemius secret key | `sk_xyz789...` |
| `DEPLOY_WEBHOOK_TOKEN` | Production webhook token | (random string) |
| `DEPLOY_WEBHOOK_TOKEN_DEV` | Dev webhook token | (random string) |
| `DEPLOY_WEBHOOK_TOKEN_TEST` | Test webhook token | (random string) |
| `SERVER_SSH_KEY` | SSH private key for server | `-----BEGIN OPENSSH...` |
| `SERVER_HOST` | Server hostname | `app.richstatistics.com` |

### Workflow Inputs

**Promote to Production:**
| Input | Required | Default | Description |
|-------|----------|---------|-------------|
| `version` | No | (auto) | Override auto-detected version |
| `channel` | Yes | `stable` | `stable` or `beta` |

**Build Release:**
| Input | Required | Default | Description |
|-------|----------|---------|-------------|
| `version` | No | (from tag) | Version string (e.g., `2.4.20`) |

---

## File Reference

### Workflow Files
```
.github/workflows/
├── build-develop.yml          # Develop CI
├── build-test.yml             # Test CI + Freemius beta
├── build-release.yml          # Release CI + Freemius public
├── promote-test.yml           # develop → test
├── promote.yml                # test → main + tag
├── job-build-zip.yml          # Reusable: ZIP creation
├── job-build-desktop.yml      # Reusable: Desktop builds
├── tests.yml                  # PHPUnit tests
└── setup-webhook.yml          # One-time webhook setup
```

### Deploy Script
```
bin/
├── deploy-freemius.php        # Freemius upload script
└── freemius-php-api/
    └── freemius/
        ├── Freemius.php       # SDK main class
        ├── FreemiusBase.php   # SDK base class
        └── Exceptions/        # SDK exceptions
```

### Configuration
```
├── phpcs.xml.dist             # PHPCS rules + exclusions
├── composer.json              # Composer config + scripts
├── phpunit.xml.dist           # PHPUnit config
├── rich-statistics.php        # Main plugin (version here)
└── readme.txt                 # WordPress readme (version here)
```

### Documentation
```
docs/
├── CI-CD-COMPLETE.md          # This file
├── WORKFLOW.md                # Workflow quick reference
└── app/
    └── v/                     # PWA version snapshots
```

---

## Recent Changes (May 2026)

### Completed

- ✅ **Replaced buttonizer/freemius-deploy** with official Freemius PHP SDK
- ✅ **Created bin/deploy-freemius.php** for direct SDK integration
- ✅ **Fixed promote.yml** to handle existing tags (`git tag -f`, `git push -f`)
- ✅ **Stable releases** now upload with `release_mode=released` (not pending)
- ✅ **Beta releases** upload with `release_mode=beta`
- ✅ **PHPCS exclusions** for SDK and deploy script (`phpcs.xml.dist`)
- ✅ **Documented complete workflow** in AGENTS.md, WORKFLOW.md, CI-CD-COMPLETE.md
- ✅ **Version 2.4.20** successfully deployed to Freemius as beta
- ✅ **Version 2.4.19** successfully released to Freemius as public
- ✅ **All servers** operational (dev, test, production)

### Migration Notes

**From: buttonizer/freemius-deploy**
```yaml
# OLD (REMOVED)
- name: Upload to Freemius
  uses: buttonizer/freemius-deploy@v0.1.3
  with:
    file_name: plugin.zip
    release_mode: pending
    version: 2.4.19
  env:
    PUBLIC_KEY: ${{ secrets.FREEMIUS_PUBLIC_KEY }}
    # ...
```

**To: SDK-based deploy**
```yaml
# NEW (WORKING)
- name: Deploy to Freemius via SDK
  run: |
    php bin/deploy-freemius.php \
      "plugin.zip" \
      "2.4.19" \
      "released"
  env:
    DEV_ID: ${{ secrets.FREEMIUS_DEV_ID }}
    PUBLIC_KEY: ${{ secrets.FREEMIUS_PUBLIC_KEY }}
    SECRET_KEY: ${{ secrets.FREEMIUS_SECRET_KEY }}
    PLUGIN_SLUG: rich-statistics
    PLUGIN_ID: 25954
```

**Benefits:**
- No Docker container overhead
- Direct SDK integration
- Better error reporting
- Handles re-uploads gracefully
- Full control over release_mode
- No third-party action dependencies

---

## Quick Reference Commands

### Check Freemius Version
```bash
# Via API (requires auth)
curl -u "DEV_ID:SECRET_KEY" \
  "https://api.freemius.com/v1/developers/25954/plugins/25954/tags.json"
```

### Manual Deploy
```bash
# Test server
curl -X POST -H "X-Deploy-Token: $(cat /etc/rsa-webhook-token-test)" \
  https://test.richstatistics.com/_deploy/

# Production
curl -X POST -H "X-Deploy-Token: $(cat /etc/rsa-webhook-token)" \
  https://app.richstatistics.com/_deploy/
```

### Trigger Workflows via CLI
```bash
# Promote to Test
gh workflow run "Promote to Test" --ref develop

# Promote to Production (stable)
gh workflow run "Promote to Production" --ref test \
  -f channel=stable

# Build Release (manual)
gh workflow run "Build Release" --ref "v2.4.20"
```

### Check Deploy Status
```bash
# Server version
curl -s https://{env}.richstatistics.com/.deployed-version

# PWA version (from index.html)
curl -s https://{env}.richstatistics.com/ | grep -oP 'v\d+\.\d+\.\d+'

# Freemius latest
curl -s "https://api.freemius.com/v1/developers/25954/plugins/25954/tags.json" | \
  python3 -c "import sys,json;d=json.load(sys.stdin);print(d['tags'][0]['version'] if d.get('tags') else 'none')"
```

---

## Contact & Support

**Documentation:**
- `docs/CI-CD-COMPLETE.md` — This file (complete reference)
- `docs/WORKFLOW.md` — Workflow quick reference
- `AGENTS.md` — Agent instructions + workflow summary

**Logs:**
- GitHub Actions: https://github.com/richardkentgates/rich-statistics/actions
- Server logs: `/var/log/syslog` (cron deploys)
- Webhook logs: `{webroot}/.deploy-trigger` timestamps

**Monitoring:**
- Uptime: External monitoring system (see ROADMAP.md §8)
- Errors: Manual log checks (see ROADMAP.md §8.2)
- Freemius: Dashboard at https://freemius.com

