# v0.6.0-rc2 — Release candidate

**Status:** Prepared on the private repository's main branch; do not mark publicly released until the release workflows and installation checks pass.

This release candidate retains all v0.6.0-rc1 functionality and includes the complete GNU GPL version 2 license text, licensed as GPL-2.0-or-later. No intended runtime behavior changes from rc1.

## Included capabilities

- Read-only MainWP plugin/theme vulnerability matching with Wordfence Intelligence v3 scanner feed
- Database-backed cache, CVSS enrichment, ignored findings, and email alerts for newly confirmed findings
- Daily scheduled checks with opt-in accelerated scanner (3h) and severity (6h) intervals
- Administrator diagnostics, legacy cache warnings and feed-download limits
- GitHub Actions PHP tests, WordPress integration tests and release-ZIP installation checks

## Validation before publication

- [ ] PHP checks and WordPress integration workflows pass on the exact rc2 commit
- [ ] Tag `v0.6.0-rc2` points to that verified commit
- [ ] Release packaging workflow passes and attaches `mainwp-wordfence-audit.zip`
- [ ] Install/upgrade ZIP smoke-tested in an actual MainWP hosting environment
- [ ] Keep repository private until public launch has explicit approval

## Upgrade

Install the release ZIP in the MainWP Dashboard WordPress installation. No additional migration is expected from rc1. Preserve a backup before testing. The plugin does not update child sites.

See `README.md` for installation instructions and limitations. This is a release candidate, not an independent security certification.
