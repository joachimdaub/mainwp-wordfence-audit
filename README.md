# MainWP Wordfence Audit

Experimental, read-only vulnerability scanner for a MainWP dashboard using the Wordfence Intelligence v3 scanner feed.

> **Development status:** v0.4.3 is not yet production-ready or security-audited. Use a staging MainWP dashboard for initial tests. Do not publish the repository as a stable release yet.

## Requirements

- WordPress with MainWP Dashboard
- PHP 7.4 or later
- A Wordfence Intelligence v3 API key
- Enough free disk space for the full scanner feed and a writable WordPress uploads directory

## Installation and use

1. Install the plugin on the **MainWP dashboard only**, not on managed child sites.
2. Sync all managed sites in MainWP so their plugin/theme inventory is current.
3. In **Tools → MainWP Security Audit**, save your Wordfence v3 API key.
4. Refresh the vulnerability feed (respect the API's rate limits).
5. Inspect confirmed matches and entries requiring manual review.

This plugin never installs, updates, or modifies software on managed sites. It reads only the MainWP inventory currently available to the logged-in administrator.

## How the feed works

The scanner feed is downloaded directly into a temporary file via the WordPress HTTP API (not into a PHP string). A bounded-memory parser processes one top-level vulnerability record at a time and keeps only records matching installed plugin or theme slugs.

The resulting small index is written to `wp-content/uploads/mainwp-wordfence-audit/mainwp-wordfence-audit-index.json` and replaces the previous index only after successful processing. The temporary full feed is deleted. Previous successfully cached results are retained after a failed refresh.

**Important:** The index does not include API credentials, but the cache directory lives beneath uploads. Apache access rules and an `index.php` file are created as a best-effort measure; server administrators must ensure this cache is not served publicly, particularly with Nginx.

## API rate limiting

A minimum 45-minute cooldown is enforced on this installation, including failed requests. HTTP 429 enforces at least 60 minutes (or longer if indicated by `Retry-After`). The cooldown is not coordinated with other installations that share the same Wordfence API key. Avoid requesting another feed until an existing externally imposed rate limit has expired.

## Security and limitations

- Requires the WordPress `manage_options` capability for the admin page and submissions.
- Uses POST nonces, output escaping, a fixed HTTPS API endpoint, and no external PHP libraries.
- Does not match WordPress Core vulnerabilities.
- Unknown/unusual version formats need manual review.
- MainWP inventory completeness and schema compatibility still require integration testing.
- Only known vulnerabilities present in Wordfence data can be detected; a clean result does not prove a site is secure.
- No cron, notifications, or historical reporting.
- Feed download and validation have **not yet been tested against a real large Wordfence response**; test carefully before relying on scan results.

## Documentation

Wordfence Intelligence API: https://www.wordfence.com/help/wordfence-intelligence/v3-accessing-and-consuming-the-vulnerability-data-feed/

## Dashboard status

The admin page shows whether an API key is configured (without displaying it) and the next locally permitted request time using the WordPress site's configured timezone. A configured key is **not** necessarily valid. A blank results table before the first successful feed retrieval is not a clean bill of health.

When a previous plugin version returned only `api_error`, the original error details cannot be reconstructed. The dashboard explains this rather than reporting a misleading empty error; wait until the existing cooldown expires before requesting another feed.

## Finding management (v0.3.0)

- **Ignore** hides a specific site's individual vulnerability from the active results. Ignored findings remain accessible in an **Ignored findings** section and may be restored with **Unignore**. Ignored findings are not fixed vulnerabilities.
- **Open MainWP** takes the administrator to MainWP's management area to review updates. It does not install or activate software.
- **Reload MainWP inventory (no Wordfence API)** evaluates the latest locally saved MainWP site inventory and cached Wordfence index again. It does **not** ask child sites to synchronise. To fetch fresh versions from child sites, first run MainWP's regular sync.
- **Severity** displays a Wordfence severity or CVSS score when it is present in the cached feed. Earlier cache indexes only stored the title, so their severity will remain **Unknown** until the next permitted feed refresh. Do not refresh early just to populate severity.

Ignored findings are stored in a WordPress option and persist across feed reloads, including when their old finding no longer appears in current results.

**Pending for public release:** add automated PHP lint/integration tests, refine MainWP deep-links, confirm actual Wordfence CVSS schema on a fresh feed, and secure the JSON cache against direct access on Nginx.

## Finding filters (v0.4.3)

Use the dashboard's **Website** and **Severity** dropdowns to narrow active and currently ignored findings. **Sort** orders severity from highest to lowest by default (or the reverse). Unknown severity is kept visible in the default view and can be filtered explicitly. The controls only reload the admin page; they never request the Wordfence feed or update a managed site. **Reset** returns to all websites and severities.

The summary counters above the filters show the unfiltered current inventory. The separate **Visible findings** count reflects the filtered active results. Archived ignored findings are listed separately so they can always be restored.

## Dashboard layout (v0.4.3)

The **Overview** tab displays compact counters for confirmed vulnerabilities, uncertain findings, synced inventory items, and ignored entries. The **Data sources** panel separates actions clearly:

- **Recheck MainWP inventory**: re-evaluates locally stored MainWP software versions against the cached Wordfence index; does not contact managed sites or Wordfence.
- **Refresh Wordfence feed**: downloads new Wordfence vulnerability data, subject to the existing cooldown.

The **Settings** tab contains API key configuration. A configured key is not necessarily valid; the existing key is never displayed and a blank replacement field preserves it. Status times follow the WordPress site timezone.

The interface still needs visual verification on a real MainWP installation, including narrow desktop/mobile widths. No automatic site update operation has been added.

## Severity enrichment (v0.4.3)

Wordfence's **scanner feed does not include CVSS ratings**. After the scanner feed has been downloaded, the Overview offers **Load severity ratings**, which independently downloads the Wordfence **production feed** and joins detailed CVSS data to cached scanner findings by vulnerability UUID. Both endpoints share the same 45-minute cooldown and HTTP 429 handling. This second request must never be made during the cooldown.

Scanner-only findings remain available even if they have no production details. Such entries display **Not rated** instead of suggesting that they have a confirmed low severity. Existing detection matches are not modified by enrichment. Refreshing the scanner feed will currently replace enriched ratings; use the production action again after the next eligible cooldown if needed.

**Important:** This is experimental. Confirm the real production feed response and PHP memory footprint on a test dashboard before treating these ratings as verified. Do not use the separate production endpoint simply to work around API limits.

## Automatic daily refresh (v0.4.3)

The plugin registers two **WP-Cron** events after activation:

- `mwa_daily_scanner_refresh`: daily scanner feed refresh, first eligible approximately five minutes after the schedule is registered.
- `mwa_daily_severity_refresh`: daily production feed enrichment, first eligible approximately 65 minutes after schedule registration.

Both tasks share the same 45-minute API cooldown (and longer HTTP 429 cooldown). A cron run during the cooldown exits without contacting Wordfence. Neither task automatically synchronizes child sites; continue using MainWP's own sync mechanism. The Overview shows the next scanner execution and the last outcome of both cron tasks. The previous successful cache is retained if either refresh fails.

WP-Cron is traffic-driven and its scheduled events may run late on quiet dashboards. For reliable execution configure a system cron to run WordPress cron, for example:

```sh
*/5 * * * * /usr/bin/php /path/to/wordpress/wp-cron.php >/dev/null 2>&1
```

Confirm the PHP executable and WordPress path with your host. If using a system scheduler, disable duplicate visitor-triggered execution with `define('DISABLE_WP_CRON', true);` in `wp-config.php`. Do **not** add a direct custom URL for feed refreshes or bypass the rate limit.

**Testing pending:** PHP lint, job scheduling on activation/deactivation, error paths, and actual WP-Cron execution need verification on a staging MainWP installation before production reliance.

## Email alerts for new findings (v0.4.3)

A daily WP-Cron job checks the cached Wordfence findings against the currently stored MainWP inventory and sends a single summary email to the WordPress administrator address (`Settings → General`) **only when new, confirmed and non-ignored vulnerabilities appear**.

- The **first successful notification check establishes a baseline** and sends no email for existing findings.
- Each finding is identified by site, software type, slug, and Wordfence vulnerability ID. A version change that remains affected is not treated as a new vulnerability.
- Ignored findings are excluded.
- Once successfully mailed, findings are recorded to avoid repeated alerts.
- Failed email delivery does not acknowledge new findings; a future check can retry.
- Missing inventory or missing cached feed prevents a comparison and never resets the baseline.
- The notification job itself never calls the Wordfence API; it uses cached data.
- MainWP child sites are not automatically synced. Outdated inventory can delay or miss findings.
- Delivery depends on working WordPress `wp_mail()` configuration.

The Overview displays the administrator email address and last notification-check result. Email delivery and WP-Cron behavior require integration tests before production reliance.

## Notification settings (v0.4.3)

In **Settings → Email notifications**, administrators can enable or disable alerts and optionally set one custom recipient address. Notifications are enabled by default for existing installations, preserving previous behavior. An empty custom address falls back to the WordPress administrator email. Input is validated before saving, and the address is escaped when displayed.

When disabled, the notification cron skips email checks and preserves the known-finding baseline. Re-enabling does not automatically send past already acknowledged findings. The notifications still cover only newly detected, confirmed, non-ignored vulnerabilities.

## GitHub releases and WordPress updates (v0.4.3)

The update checker discovers **published, non-prerelease GitHub releases** from the public repository, not arbitrary commits on `main`. No API token is required. While this repository is private, GitHub's public release API cannot return release metadata and the checker offers no updates. Once public, WordPress can offer a normal plugin update when a newer tagged release has the asset `mainwp-wordfence-audit.zip`.

A GitHub Actions workflow in `.github/workflows/release.yml` checks the PHP syntax, verifies that the plugin header version matches the release tag, packages the plugin under a stable `mainwp-wordfence-audit/` folder, and attaches a ZIP to the GitHub release.

### Release procedure

1. Update the plugin version and relevant documentation on `main`.
2. Verify PHP lint, security checks, and installation/updater tests on a disposable WordPress instance.
3. Create a Git tag such as `v0.4.3` pointing to the tested commit and publish a GitHub release with the same tag.
4. Confirm that GitHub Actions attached `mainwp-wordfence-audit.zip` to the release.
5. Check **Dashboard → Updates** or **Plugins** in WordPress to verify the update is offered. WordPress may cache release metadata for up to 12 hours, and automatic update checks follow WordPress scheduling.

**Do not publish a GitHub release yet**: the scanner, cron and notification workflows still need end-to-end tests, and the repository is private. For now, the update checker will not distribute changes. No personal access token is stored.

## Optional dashboard indicators (v0.4.7)

The Settings tab contains **Dashboard visibility → Enable security indicators** (enabled by default). When enabled, the plugin adds a discreet count badge to its **Tools → MainWP Security Audit** submenu and a summary widget on the standard WordPress dashboard of the MainWP installation. It does not add banners, modals, or toolbar alerts, and does not modify managed child sites.

Only confirmed, non-ignored findings are counted. The dashboard widget displays the number of affected websites and individual findings and links to the audit screen. Counts are cached for five minutes, and the cache is invalidated when the plugin refreshes the feed or changes ignored findings. An unavailable feed does not show a misleading zero-risk count.

**Note:** This first widget uses the normal WordPress dashboard hook, not a native MainWP overview widget. A dedicated MainWP widget can be considered after verifying the extension hooks on the installed MainWP version. PHP lint and visual integration checks are still required before release.

## Configurable menu location (v0.4.7)

In **Settings → Dashboard visibility**, choose **Tools submenu** (default) or **Separate top-level menu**. A top-level entry uses the WordPress shield icon and retains the existing counter badge when security indicators are enabled. Links, form redirects, the email review link and filter reset follow the selected location. Dashboard indicators remain independently toggleable. No managed child sites are changed.

The feature still needs a live WordPress navigation and PHP syntax test before release.

## Single-click vulnerability and severity refresh (v0.4.7)

The Overview provides **Refresh vulnerabilities and severity**. This schedules a WordPress background workflow; it deliberately does **not** make both Wordfence API calls in the same HTTP request:

1. Retrieve and index the comprehensive **Scanner Feed** when the shared API cooldown permits.
2. If successful, schedule a **Production Feed** enrichment at least one hour later (or after a longer Wordfence cooldown).
3. Keep the Scanner findings intact and attach CVSS ratings to matching vulnerability UUIDs. A severity-only failure does not erase the scanner results.

Wordfence documents that Production records are fully analyzed, while the Scanner Feed can also contain early, not-yet-fully-analyzed findings. Using Production alone could miss newly disclosed vulnerabilities. Both feeds retain their separate daily cron schedules for now; the shared API cooldown protects against back-to-back requests. The combined operation's status and next step are shown on the dashboard.

This requires working WP-Cron or a system cron triggering WordPress. It is an asynchronous process, **not an immediate one-request refresh**. Live end-to-end testing and PHP lint remain mandatory before public release.

## Preserving severity across scanner refreshes (v0.5.0)

A successful Scanner Feed refresh retains cached CVSS scores for matching vulnerability UUIDs. New or unmatched scanner records remain unrated until Production Feed enrichment succeeds. This avoids losing previously downloaded ratings during the enforced API cooldown. The stored **Severity data** timestamp still indicates the last successful Production Feed enrichment, not the time the preserved scores were reused.

## Automated checks (v0.5.0)

GitHub Actions runs PHP syntax checks and the PHPUnit unit-test suite for PHP 7.4, 8.2 and 8.4 on each push to `main`, pull requests, and manual workflow runs. Development dependencies are defined in `composer.json`; production installation does not require Composer.

Run locally with:

```sh
composer install
composer test
```

The initial suite covers version-boundary logic, multiple affected version ranges, malformed versions, CVSS severity thresholds and stable finding identities. These tests do **not** yet replace WordPress/MainWP integration tests for cron, remote APIs, ignored findings and email delivery.

The public GitHub updater has been disabled in the plugin bootstrap until we are ready to publish. Release packaging is retained for later testing. Do not consider the project production-ready until GitHub CI and end-to-end tests pass.

## Feed parser tests

The isolated PHPUnit suite now includes synthetic Wordfence Scanner/Production-shaped JSON fixtures for the streaming parser. Tests verify matching installed software by type and case-insensitive slug, excluding unrelated software, preserving matched CVSS values, handling escaped JSON, and rejecting malformed or incomplete feed files. These tests use local temporary files and make **no Wordfence API requests**.

GitHub Actions results must be reviewed before treating the suite as passing. The asynchronous cron orchestration and real MainWP data integration remain to be tested.

## Combined refresh scheduling tests (v0.5.1)

The test suite now covers the combined workflow's scheduling calculations: a short initial Scanner delay, respect for an existing API cooldown, a minimum one-hour gap before Production severity enrichment, and an extended cooldown when necessary. The code also records a failed severity rescheduling attempt instead of silently leaving the refresh waiting.

These are isolated **scheduling unit tests**, not end-to-end tests of WP-Cron, the Wordfence API, or network failures. A green GitHub Actions run and real WordPress integration checks are still required.

## Cron execution tests (v0.5.2)

Cron execution is now isolated behind a callback runner for unit testing. Tests cover normal completion, expected RuntimeException statuses, unexpected exceptions with persisted diagnostics, and the stored outcome. Missing API configuration now records `no_key` instead of silently skipping cron execution.

The tests are isolated and do not execute WordPress cron or contact Wordfence. Real-site verification remains necessary.

## Combined refresh outcome tests (v0.5.3)

Added isolated PHPUnit coverage for the Scanner-to-Severity transition, successful Severity completion, missing results, API throttling and unexpected errors. The combined workflow now uses a shared outcome classifier, ensuring that only expected success statuses move it forward. These tests do not yet execute real WordPress scheduled events or network requests; integration validation remains pending.

## Combined WP-Cron event tests

The isolated test environment now records simulated `wp_schedule_single_event()` calls. Integration-style tests exercise the actual `combined_severity()` and `combined_scanner()` entry points without external traffic: active cooldown rescheduling, scheduling failure reporting, and missing API key handling. No Wordfence API calls are made in these scenarios. Full WordPress and remote-response integration tests remain pending.

## Mocked Wordfence HTTP tests (v0.5.4)

Both Wordfence feeds now share a single streamed-download helper. PHPUnit uses a local mock of WordPress HTTP functions to verify HTTP 200, HTTP 429 with `Retry-After`, HTTP 500, transport errors and production-endpoint throttling. Failed downloads must remove their temporary files and record an error; the tests make no live API requests.

Malformed JSON rejection is covered by the separate streaming parser tests. An end-to-end test that verifies the persistent vulnerability index remains unchanged after feed errors, and a successful CVSS enrichment test, are still pending. Review GitHub Actions results before treating this version as validated.

## Cache integrity and CVSS enrichment tests (v0.5.5)

The new `CacheAndSeverityTest` suite verifies that an invalid streaming feed is rejected without modifying an existing fixture cache, and that severity enrichment updates only matching vulnerability IDs with numeric CVSS scores while preserving other record fields and unmatched ratings. CVSS merging is isolated in `apply_severity()` for deterministic unit tests.

Cache preservation is currently tested as a parser-plus-existing-file regression fixture, **not** as a complete HTTP-to-WordPress-cache integration scenario. Integration tests for filesystem failures and the full refresh transaction remain open before public beta.

## Private cache directory (v0.5.6)

The vulnerability index now defaults to `../mainwp-wordfence-audit-private/` relative to the WordPress application directory (`ABSPATH`), **outside** that application root. This prevents direct download via ordinary WordPress URLs on both Apache and Nginx. Existing valid JSON indexes are migrated on first access and the legacy uploads file is removed after a private target file exists. The installer must have permission to create/write the parent directory; otherwise feed refresh fails safely instead of writing an exposed cache.

For hosts where the default parent is not writable, configure a private directory outside the WordPress application root in `wp-config.php` before activating this version:

```php
define('MWA_PRIVATE_CACHE_DIR', '/absolute/private/path/mainwp-wordfence-audit');
```

**Deployment note:** A directory outside `ABSPATH` is not guaranteed to be outside every custom web-server document root. Verify the chosen path cannot be served over HTTP, especially when WordPress is installed in a subdirectory or the web server exposes the parent directory. Do not deploy this storage migration to production until directory access and permissions have been verified.

PHPUnit now checks the default path selection. Live filesystem migration, symlink and unusual hosting configurations still need integration tests and review before public beta.

## Verified cache migration (v0.5.7)

Legacy cache migration now writes a temporary file in the private destination directory, verifies a complete write, atomically renames it, and checks the resulting SHA-256 content hash before deleting the old uploads cache. An invalid existing destination or invalid legacy JSON prevents deletion of the source. Unit tests cover successful migration, malformed legacy data, an invalid existing destination, and a valid pre-existing destination.

**Before production deployment:** verify that the private directory is outside the *actual web-server document root*, is writable by PHP, and that the old cache does not remain publicly accessible following a failed migration. Custom hosts, symlink behavior and filesystem permission failures still need live integration review. The private path alone cannot prove web-server isolation.

## Admin authorization and API key regression checks (v0.5.8)

The new `AdminSecurityTest` validates accepted API-key characters, the maximum key length and rejection of unexpected types, whitespace and header-injection characters. It also guards every registered `admin_post_mwa_*` entry point against accidental removal of its capability check and action-specific WordPress nonce check. These checks are static unit regressions and do not replace a real WordPress capability/CSRF integration test. The plugin continues to store its Wordfence API key as a non-autoloaded WordPress option; privileged database access can reveal it, so production database access controls remain important.
