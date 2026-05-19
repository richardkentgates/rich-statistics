# Rich Statistics Documentation Index

## 📚 Complete Documentation Suite

This directory contains comprehensive documentation for the Rich Statistics project.

---

## 🚀 Getting Started

### New Contributors
Start here: [`CI-CD-COMPLETE.md`](./CI-CD-COMPLETE.md) — Complete CI/CD reference with step-by-step workflows.

### Quick Reference
- **Workflow Diagram:** [`WORKFLOW.md`](./WORKFLOW.md) — Visual workflow reference
- **Agent Instructions:** [`../AGENTS.md`](../AGENTS.md) — Development guidelines

---

## 📖 Documentation Files

| Document | Size | Purpose | Audience |
|----------|------|---------|----------|
| [`CI-CD-COMPLETE.md`](./CI-CD-COMPLETE.md) | 28 KB | **Complete CI/CD reference** — workflows, Freemius, servers, troubleshooting | All developers |
| [`WORKFLOW.md`](./WORKFLOW.md) | 9.3 KB | Workflow quick reference — release flow, version management | Release managers |
| [`app-server-architecture.md`](./app-server-architecture.md) | 15 KB | Server infrastructure — webhooks, cron deploys, APT repo | DevOps |
| [`ai-architecture-wiki.md`](./ai-architecture-wiki.md) | 5.2 KB | AI tool architecture — structured data, no LLM calls | AI developers |
| [`../AGENTS.md`](../AGENTS.md) | — | Agent instructions — coding standards, common tasks | AI agents |
| [`../ROADMAP.md`](../ROADMAP.md) | — | Audit findings, infrastructure plan, version compatibility | Architects |
| [`../TODO.md`](../TODO.md) | — | Remaining work, technical debt | Project managers |

---

## 🔥 What's Working (May 2026)

### ✅ Freemius Integration
- **Official PHP SDK** replacing unreliable `buttonizer/freemius-deploy`
- **Smart upload logic** — checks if version exists, skips re-upload, updates release_mode
- **Release modes:**
  - `beta` — Test branch builds (opt-in beta testers)
  - `released` — Stable tags (public to all license holders)
- **Deploy script:** `bin/deploy-freemius.php` (fully documented in CI-CD-COMPLETE.md)

### ✅ CI/CD Pipeline
- **Three-branch workflow:** develop → test → main
- **Automated promotions:** PR-based with force-tag handling
- **Zero buttonizer references** — fully migrated to SDK
- **PHPCS exclusions** for SDK and CLI scripts

### ✅ Server Infrastructure
- **Webhook-based deploys** — POST to `/{env}/_deploy/` triggers cron poller
- **Sparse-checkout updates** — only `docs/app/` synced from git
- **Versioned snapshots** — `docs/app/v/{version}/{stable,beta}/`
- **Desktop binaries** — Linux (.deb) + Windows (.exe) with APT repo

### ✅ Documentation
- **28 KB CI-CD guide** — Every workflow, every step, every troubleshooting scenario
- **Workflow quick reference** — Release flow, version management, commands
- **Server architecture** — Webhook mechanism, cron pollers, APT repo
- **Agent instructions** — Updated with complete Freemius deploy flow

---

## 📋 Quick Links

### Workflows
- [Build Develop](../.github/workflows/build-develop.yml) — Development CI
- [Build Test](../.github/workflows/build-test.yml) — Test CI + Freemius beta
- [Build Release](../.github/workflows/build-release.yml) — Production releases
- [Promote to Test](../.github/workflows/promote-test.yml) — develop → test
- [Promote to Production](../.github/workflows/promote.yml) — test → main + tag

### Scripts
- [`bin/deploy-freemius.php`](../bin/deploy-freemius.php) — Freemius upload
- [`bin/freemius-php-api/`](../bin/freemius-php-api/) — Official PHP SDK

### Configuration
- [`phpcs.xml.dist`](../phpcs.xml.dist) — PHPCS rules + exclusions
- [`composer.json`](../composer.json) — Composer config + test scripts
- [`rich-statistics.php`](../rich-statistics.php) — Main plugin (version here)

---

## 🎯 Current Status

| Component | Version | Status |
|-----------|---------|--------|
| **Plugin** | 2.4.20 | ✅ On develop (ready for test) |
| **Freemius (beta)** | 2.4.20 | ✅ Uploaded as beta (tag ID 123385) |
| **Freemius (released)** | 2.4.19 | ✅ Public release (tag ID 123214) |
| **Dev Server** | — | ✅ dev.richstatistics.com operational |
| **Test Server** | — | ✅ test.richstatistics.com operational |
| **Production** | — | ✅ app.richstatistics.com operational |
| **Documentation** | — | ✅ Complete (this directory) |

---

## 📞 Support

### Troubleshooting
See [`CI-CD-COMPLETE.md`](./CI-CD-COMPLETE.md) § Troubleshooting Guide for:
- Tag already exists errors
- Freemius upload failures
- Deploy stuck issues
- PHPCS failures
- Source branch recovery

### Monitoring
- **GitHub Actions:** https://github.com/richardkentgates/rich-statistics/actions
- **Freemius Dashboard:** https://freemius.com (login required)
- **Server Logs:** `/var/log/syslog` (cron deploys)

### Contact
- **Documentation Issues:** Create issue with `documentation` label
- **CI/CD Issues:** Create issue with `ci-cd` label
- **Freemius Issues:** Create issue with `freemius` label

---

## 📝 Recent Updates (May 18, 2026)

- ✅ Created `CI-CD-COMPLETE.md` (28 KB comprehensive reference)
- ✅ Updated `WORKFLOW.md` with complete release flow
- ✅ Updated `AGENTS.md` with Freemius deploy documentation
- ✅ Migrated from `buttonizer/freemius-deploy` to official PHP SDK
- ✅ Fixed `promote.yml` to handle existing tags (force-update)
- ✅ Documented all working workflows and server infrastructure
- ✅ Version 2.4.20 successfully tested on Freemius beta
- ✅ Version 2.4.19 successfully released to Freemius public

---

**Last Updated:** May 18, 2026  
**Maintained By:** Development Team  
**Review Cycle:** Every release
