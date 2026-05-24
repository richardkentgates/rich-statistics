# Rich Statistics — Complete Workflow Documentation

## Overview

Rich Statistics uses a three-branch CI/CD pipeline with automated Freemius integration:

```
develop → test → main
   ↓        ↓      ↓
  dev     test   production
```

## Branch Structure

| Branch | Environment | Server | Workflow | Purpose |
|--------|-------------|--------|----------|---------|
| `develop` | Development | `dev.richstatistics.com` | `build-develop.yml` | Bleeding-edge development, auto-deploys on push |
| `test` | Beta/Staging | `test.richstatistics.com` | `build-test.yml` | Pre-release QA, Freemius beta uploads |
| `main` | Production | `app.richstatistics.com` | `build-release.yml` | Stable releases, Freemius public releases |

## Release Flow

### Step 1: Develop
```bash
# Work on develop branch
git checkout develop
# ... make changes, commit ...
git push origin develop
```

**Automated:**
- `build-develop.yml` triggers
- PHP lint, PHPCS, PHPUnit tests run
- Plugin ZIP created (version: `dev.<run_number>`)
- PWA deployed to `dev.richstatistics.com`
- Desktop builds pushed to dev server

### Step 2: Promote to Test
**GitHub Actions → Promote to Test → Run on `develop`**

**What happens:**
1. Creates PR `develop → test`
2. Merges PR (preserves history)
3. Triggers `build-test.yml` on test branch

**Automated:**
- `build-test.yml` runs
- Plugin ZIP created (version: `test.<run_number>`)
- PWA deployed to `test.richstatistics.com`
- **Freemius upload as `beta`** via `bin/deploy-freemius.php`
- Desktop builds pushed to test server

**Verify:**
- Check `test.richstatistics.com`
- Check Freemius dashboard for beta version
- Test functionality

### Step 3: Promote to Production
**GitHub Actions → Promote to Production → Run on `test` → Channel: `stable`**

**What happens:**
1. Creates PR `test → main`
2. Merges PR
3. Force-creates tag `v{version}` on main
4. Dispatches `Build Release` workflow

**Automated:**
- `build-release.yml` runs
- Plugin ZIP created
- **Freemius upload as `released`** (public to all license holders)
- GitHub Release created with ZIP + checksum
- PWA snapshot saved to `docs/app/v/{version}/{stable,beta}/`
- Desktop builds with version stamp
- PWA deployed to `app.richstatistics.com`

## Freemius Integration

### How It Works

The `bin/deploy-freemius.php` script uses the official Freemius PHP SDK:

```php
// 1. Check if version exists
$tags = $api->Api("plugins/{$plugin_id}/tags.json", 'GET');

// 2. If not exists, upload ZIP
$result = $api->Api("plugins/{$plugin_id}/tags.json", 'POST', 
    ['add_contributor' => false], ['file' => $file_name]);

// 3. Set release_mode
$api->Api("plugins/{$plugin_id}/tags/{$tag_id}.json", 'PUT',
    ['release_mode' => $release_mode]);
```

### Release Modes

| Mode | Description | Used By |
|------|-------------|---------|
| `beta` | Released to beta opt-in users | `build-test.yml` (test branch pushes) |
| `released` | Released to ALL license holders | `build-release.yml` (stable tags) |
| `pending` | Uploaded but not released | Not used in CI (manual only) |

### SDK Files

```
bin/
├── deploy-freemius.php          # Deploy script
└── freemius-php-api/
    └── freemius/
        ├── Freemius.php         # SDK main class
        ├── FreemiusBase.php     # SDK base class
        └── Exceptions/          # SDK exception classes
```

### Environment Variables Required

```yaml
DEV_ID: 25954                    # Freemius developer ID
PUBLIC_KEY: ${{ secrets.FREEMIUS_PUBLIC_KEY }}
SECRET_KEY: ${{ secrets.FREEMIUS_SECRET_KEY }}
PLUGIN_SLUG: rich-statistics
PLUGIN_ID: 25954
```

## Workflows

### build-develop.yml

**Trigger:** Push to `develop` or `workflow_dispatch`

**Jobs:**
1. `build-zip` — PHP checks, create ZIP artifact
2. `deploy-web` — Webhook deploy to dev server
3. `build-desktop` — Tauri builds for dev

### build-test.yml

**Trigger:** Push to `test` or `workflow_dispatch`

**Jobs:**
1. `build-zip` — PHP checks, create ZIP artifact
2. `deploy-web` — Webhook deploy to test server
3. `upload-freemius` — **SDK upload with `release_mode=beta`**
4. `build-desktop` — Tauri builds for test

### build-release.yml

**Trigger:** Tag push (`v*.*.*`) or `workflow_dispatch` from promote.yml

**Jobs:**
1. `build-zip` — PHP checks, create ZIP artifact
2. `upload-freemius` — **SDK upload with `release_mode=released` or `beta`**
3. `release` — GitHub Release, PWA snapshot
4. `build-desktop` — Tauri builds with version stamp
5. `ping-deploy` — Webhook deploy to production

### promote.yml (Promote to Production)

**Trigger:** `workflow_dispatch` on `test` branch

**Inputs:**
- `version` — Auto-detected from `rich-statistics.php` (can override)
- `channel` — `stable` or `beta`

**Steps for stable:**
1. Checkout test branch
2. Create PR `test → main`
3. Merge PR (`--merge` to preserve history)
4. Fetch main
5. **Force-create tag** (`git tag -f`)
6. **Force-push tag** (`git push -f`)
7. Dispatch `Build Release` workflow

**Why force?** If the tag already exists from a previous release at the same version, the force update ensures the tag points to the current merge commit with the latest code.

## Server Infrastructure

### PWA Deployment

Each environment has a webhook endpoint:

```
POST https://{env}.richstatistics.com/_deploy/
Header: X-Deploy-Token: {token}
```

**Server-side:**
1. Webhook writes timestamp to `{env}/.deploy-trigger`
2. Cron runs every minute, checks trigger age (< 120s)
3. If valid, runs `rsa-app-update-{env}` script
4. Script sparse-clones `docs/app/` from matching branch
5. Records deployed version in `.deployed-version`

### Desktop Binaries

Pushed to server `dist/` directory:
- `rich-statistics_{version}_amd64.deb` (Linux)
- `rich-statistics_{version}_arm64.deb` (Linux ARM)
- `rich-statistics_{version}.exe` (Windows)

APT repo updated via `rsa-apt-repo-update-{env}` script.

## Version Management

### Where Version Is Defined

1. `rich-statistics.php` line 6: `* Version:           2.4.26`
2. `rich-statistics.php` line 62: `define( 'RSA_VERSION', '2.4.26' );`
3. `readme.txt` line 7: `Stable tag: 2.4.26`

**All three must match.** The promote workflow auto-detects from line 62.

### Version Bump Checklist

Before promoting to production:
- [ ] Update all three version strings
- [ ] Commit and push to test branch
- [ ] Verify tests pass
- [ ] Run Promote to Production

## Troubleshooting

### Tag Already Exists

If promote fails with `fatal: tag 'vX.Y.Z' already exists`:

```bash
# Force update tag to current main
git fetch origin main
git tag -f vX.Y.Z origin/main
git push -f origin vX.Y.Z

# Re-dispatch Build Release
gh workflow run "Build Release" --ref "vX.Y.Z"
```

### Freemius Upload Fails

Check:
1. Secrets are set: `FREEMIUS_PUBLIC_KEY`, `FREEMIUS_DEV_ID`, `SECRET_KEY`
2. Plugin ID and Developer ID are correct (25954)
3. ZIP file path is correct
4. Network connectivity (SDK uses curl)

Check logs for:
```
Checking existing tags for version X.Y.Z...
Found existing tag ID {id} / Uploading {file}...
Setting release_mode to {mode}...
Done. Version X.Y.Z set to release_mode={mode}
```

### Deploy Stuck

Manually trigger webhook:
```bash
curl -X POST -H "X-Deploy-Token: $(cat /etc/rsa-webhook-token-{env})" \
  https://{env}.richstatistics.com/_deploy/
```

Check cron:
```bash
grep "rsa-deploy-cron" /var/log/syslog | tail -5
```

### Source Branch Deleted

If `--delete-branch` was used in promote (DON'T), restore branch:
```bash
git push origin origin/main:refs/heads/develop
git push origin origin/main:refs/heads/test
```

## CI Pipeline Rules

### CRITICAL

1. **NEVER use `--delete-branch`** in `gh pr merge` — destroys source branches
2. **GITHUB_TOKEN push events cannot trigger workflows** — must use `gh workflow run`
3. **Force tags in promote.yml** — handles re-releases at same version
4. **Version must match** — `RSA_VERSION` constant, plugin header, readme.txt

### Testing

```bash
# All tests
php vendor/bin/phpunit --no-coverage

# Unit tests only
php vendor/bin/phpunit --no-coverage tests/unit/

# Integration tests
php vendor/bin/phpunit --no-coverage tests/integration/

# PHPCS
composer phpcs
composer phpcbf  # auto-fix
```

## File Structure

```
.github/workflows/
├── build-develop.yml      # Develop branch CI
├── build-test.yml         # Test branch CI (Freemius beta)
├── build-release.yml      # Tagged releases (Freemius released)
├── promote.yml            # Promote to Production
├── promote-test.yml       # Promote to Test
├── job-build-zip.yml      # Reusable: ZIP creation
└── job-build-desktop.yml  # Reusable: Desktop builds

bin/
├── deploy-freemius.php    # Freemius upload script
└── freemius-php-api/      # Freemius PHP SDK

rich-statistics.php        # Main plugin file (version here)
readme.txt                 # WordPress.org readme (version here)
```

## Recent Changes (May 2026)

- ✅ Replaced `buttonizer/freemius-deploy` with official Freemius PHP SDK
- ✅ Added `bin/deploy-freemius.php` for direct SDK integration
- ✅ Fixed `promote.yml` to handle existing tags (`git tag -f`)
- ✅ Stable releases now upload with `release_mode=released` (not pending)
- ✅ Beta releases upload with `release_mode=beta`
- ✅ All workflows exclude SDK from PHPCS (`phpcs.xml.dist`)
- ✅ Documented complete workflow in AGENTS.md and WORKFLOW.md
