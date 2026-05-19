# Version Bump Checklist

When bumping the version, update **all** of these files:

## Plugin (PHP)

| File | Location | Example |
|------|----------|---------|
| `rich-statistics.php` | Header `Version:` | `2.4.21` |
| `rich-statistics.php` | `RSA_VERSION` constant | `'2.4.21'` |
| `rich-statistics.php` | `RSA_APP_VERSION` constant | `'2.4.21'` |
| `readme.txt` | `Stable tag:` | `2.4.21` |
| `readme.txt` | `== Changelog ==` section | Add `= 2.4.21 =` entry |

## Tests

| File | Location | Example |
|------|----------|---------|
| `tests/bootstrap.php` (line ~66) | `RSA_VERSION` (integration) | `'2.4.21'` |
| `tests/bootstrap.php` (line ~155) | `RSA_VERSION` (unit) | `'2.4.21'` |

## PWA

| File | Location | Example |
|------|----------|---------|
| `docs/app/sw.js` | Cache name | `'rsa-2-4-21'` |

## Desktop (Tauri)

| File | Location | Example |
|------|----------|---------|
| `src-tauri/Cargo.toml` | `version` | `"2.4.21"` |
| `src-tauri/tauri.conf.json` | `version` | `"2.4.21"` |

## Quick grep to verify

```bash
# Find all remaining references to old version
grep -rn "2\.4\.20" --include="*.php" --include="*.js" --include="*.json" --include="*.toml" --include="*.txt" .
```

## CI handles automatically

These are updated by `build-release.yml` on tag push — **do not edit manually**:

- `docs/app/versions.json`
- `docs/app/versions-beta.json`
- `docs/app/v/{version}/` (PWA snapshot directory)
