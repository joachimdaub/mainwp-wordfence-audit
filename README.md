# MainWP Wordfence Audit

**Current release candidate: v0.6.0-rc2. Not independently security-audited.**

Read-only WordPress plugin for the **MainWP Dashboard**. It compares locally synced MainWP plugin/theme versions with Wordfence Intelligence v3 vulnerability feeds and displays confirmed affected-version matches, uncertain matches and individually ignored findings. It does **not** update managed sites or perform a remote MainWP inventory sync.

## Requirements

- WordPress with an installed, working MainWP Dashboard and a current local MainWP software inventory
- PHP 7.4 or later and a functioning WordPress database connection
- Wordfence Intelligence v3 API key and outbound HTTPS access to Wordfence endpoints
- Writable WordPress/PHP temporary directory and sufficient disk space for a full feed download
- Functional WP-Cron triggering (normal site traffic or a configured system cron) for background updates

## Installation

1. Download the packaged plugin ZIP from a GitHub release. Do not install the Git repository directly on managed child sites.
2. In the **MainWP Dashboard WordPress installation**, choose **Plugins → Add New → Upload Plugin** and upload the ZIP.
3. Activate **MainWP Wordfence Audit**. Its dedicated database cache table is created automatically.
4. Sync child sites through MainWP as usual; the plugin only reads the resulting local inventory.
5. Open **Tools → MainWP Security Audit**, then **Settings**, and enter your own Wordfence Intelligence v3 API key.
6. Review the audit page after a successful scanner refresh and severity enrichment. The initial comparison establishes the email baseline without sending alerts for all existing findings.

New installations do **not** need an `MWA_PRIVATE_CACHE_DIR` setting or a manually created permanent cache directory. When available, a nonempty valid older JSON index can be imported automatically without deleting its source.

## How it works

The scanner and production severity feeds are downloaded to temporary files through the WordPress HTTP API, parsed incrementally and filtered to MainWP inventory slugs. The matched index is persisted in a dedicated `{prefix}mwa_vulnerability_cache` database table using an atomic upsert; rejected feeds cannot replace the last successfully stored index.

The scanner runs daily by default and severity enrichment runs on a separate daily schedule. An optional **Automatic feed refresh (preview)** setting schedules the scanner every three hours and severity every six hours. A shared minimum 45-minute local API cooldown applies, including to manual/combined requests. WordPress Cron may run late or be blocked by external constraints; scheduled times are not service-level guarantees.

**Email notifications** compare newly confirmed, non-ignored affected-version findings with an existing baseline. The comparison runs daily by default. The first successful comparison creates a baseline without sending an initial batch email. Failed delivery does not consume new findings. Email test delivery is separate from vulnerability data.

The Data sources panel shows compact scanner/severity/next-run timestamps and collapsible diagnostics. Missing, stale or unmatched feed data must never be interpreted as evidence that installed software is safe.

## Security, privacy and limitations

- Administrator access requires `manage_options`; state-changing admin actions use nonces and capability checks.
- The API key is stored as a WordPress option; it is not printed in full in the UI.
- No changes are made to managed child sites. The plugin reads MainWP's local inventory.
- It covers known plugin/theme vulnerabilities present in the available Wordfence data; **WordPress core vulnerabilities are not currently matched**.
- Nonstandard versions and incomplete ranges may require manual review.
- Feed freshness, inventory synchronization, site connectivity, WP-Cron reliability and API access all affect monitoring quality.
- Ignoring a finding does not fix a vulnerability.
- This pre-release has automated PHP and WordPress integration tests but has not had an independent security assessment.

## Development and test commands

`composer install` and `composer test` run the isolated PHP test suite. GitHub Actions also runs WordPress integration smoke tests with local simulated HTTP responses. No real Wordfence request is needed for those tests. For releases, verify both CI workflows on the tagged commit and test the installation/upgrade path.

## License

GPL-2.0-or-later. See `LICENSE`.

## Development history

Historical development notes and superseded implementation details are archived in [docs/development-history.md](docs/development-history.md). For release-specific changes, see the [release notes](RELEASE_NOTES_0.6.0-rc2.md).
