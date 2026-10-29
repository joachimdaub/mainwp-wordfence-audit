# v0.6.0-rc1 — Release candidate (draft)

**Status:** Pre-release; not yet published. The repository remains private.

MainWP Wordfence Audit is a read-only vulnerability monitoring plugin for a WordPress installation hosting MainWP Dashboard. It compares locally synchronized plugin and theme versions with Wordfence Intelligence v3 scanner data and enriches matches with production CVSS severity data.

## Highlights

- Database-backed vulnerability index with atomic replacement and optional migration from legacy JSON data.
- Confirmed affected-version matches, review-required matches, ignored findings and filtered administrator views.
- Scheduled scanner and severity refresh with a shared Wordfence API cooldown and optional accelerated 3-/6-hour cadence.
- Email notifications for newly detected, confirmed and non-ignored findings; initial baseline creation prevents historical alert floods.
- Collapsible data source diagnostics with update timestamps and warnings for legacy uploads cache files.
- Streaming feed ingestion with a 256 MiB response-size cap. Failed imports preserve the last saved index.
- No remote changes to any MainWP-managed site.

## Requirements

- WordPress and MainWP Dashboard on the same installation.
- PHP 7.4+, Wordfence Intelligence v3 API key, outbound HTTPS and functional WP-Cron.
- A current synced MainWP plugin/theme inventory.

## Installation

Install the **release ZIP**, not a source-code archive, under Plugins → Add New → Upload Plugin on the MainWP Dashboard site. Activate and configure your own API key under Tools → MainWP Security Audit → Settings. Review Data sources before interpreting a zero-finding result.

## Known limitations

- Covers WordPress plugins and themes in supported Wordfence feed data; does not check WordPress core.
- Feed freshness and WP-Cron execution are not guaranteed.
- The first notification check establishes a baseline and does not email previously known findings.
- A local warning about legacy uploaded JSON is not proof of public HTTP exposure; operators should check and secure old files.
- Automated tests do not replace independent security review or testing with a real installation.

## Release gate

- [x] GPL-2.0-or-later license confirmed.
- [x] WordPress plugin metadata aligned to 0.6.0-rc1.
- [x] Automated PHP checks and WordPress integration successful for commit 7ce9a33.
- [x] CI installs the built plugin ZIP on disposable WordPress.
- [ ] Confirm checks for the final release-note commit.
- [ ] Verify the exact tagged commit and ZIP contents after packaging.
- [ ] Confirm ownership/permissions and whether a private prerelease or public beta is intended.
- [ ] Verify legacy uploads cache exposure and any outstanding hosting-specific concerns before public launch.

**No GitHub tag or release has been created by this document.**
