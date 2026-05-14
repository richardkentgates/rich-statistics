# Security Policy

## Supported Versions

| Version | Security Fixes |
|---|---|
| 2.x (current) | ✅ Active |
| 1.x | ❌ End of Life |

---

## Reporting a Vulnerability

**Please do not report security vulnerabilities in public GitHub issues.**

To report a vulnerability:

1. Email **security@richardkentgates.com** with the subject: `[Rich Statistics] Security Vulnerability`
2. Include:
   - Plugin version affected
   - WordPress / PHP version
   - Detailed description of the vulnerability
   - Steps to reproduce (proof-of-concept if possible)
   - Impact assessment (what an attacker could achieve)

You will receive acknowledgement within **72 hours** and a status update within **7 days**.

---

## Disclosure Policy

- We follow **responsible disclosure**: we ask that you give us a reasonable window (up to 90 days) to release a fix before public disclosure.
- We will credit you in the release notes unless you prefer to remain anonymous.

---

## Security Design Principles

Rich Statistics is built with the following security properties:

| Property | Implementation |
|---|---|
| No PII at rest | IP addresses hashed (SHA-256) for rate limiting, stored in transient (60s TTL), never in database; referrers stored as domain only; email-shaped query params stripped; raw User-Agent parsed then discarded |
| No third-party requests | Chart.js bundled locally; no CDN or external analytics calls |
| Nonce verification | All AJAX handlers and forms verify `wp_nonce` |
| Capability checks | All admin actions require `rsa_manage_statistics` capability |
| Prepared statements | All SQL uses `$wpdb->prepare()` |
| Rate limiting | 60 events/session/minute via WP transients |
| Output escaping | All output escaped at point of rendering |
| REST auth | WP Application Passwords (WP 5.6+ core, no custom token system) |
| Injection prevention | All input sanitised before use; HTML output uses `esc_*` functions |
