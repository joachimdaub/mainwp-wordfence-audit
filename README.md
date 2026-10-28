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

## Escaping external advisory titles (v0.5.9)

Wordfence advisory titles displayed in active or ignored findings are now consistently escaped as HTML text and truncated to 300 characters. Unexpected non-scalar values are not rendered. PHPUnit covers HTML-injection strings, oversized titles, and invalid value types. The current review focused on advisory title output; the full plugin security audit, permission integration tests, and production deployment verification remain open.

## Real WordPress smoke testing

GitHub Actions now runs a separate **WordPress integration smoke test** on pushes to `main`, pull requests and manual dispatch. It installs a disposable WordPress instance with MySQL, activates the plugin through WP-CLI and checks registered admin-post handlers, all three recurring cron hooks, role capabilities for an administrator versus a subscriber, and clearing recurring cron events during deactivation. No Wordfence API credentials are provided, and no customer sites are contacted.

This is intentionally a **WordPress-only smoke test**, not yet a MainWP integration or live Wordfence refresh test. MainWP inventory access, filesystem migration on real hosting, email delivery and cron execution with remote responses remain open. Review the new GitHub Actions workflow status before claiming the smoke test passes.

## MainWP inventory regression tests (v0.5.10)

MainWP plugin and theme inventory entries are now normalized through a dedicated validator. PHPUnit covers directory-based plugin slugs, theme slugs, invalid field types, path-like invalid slugs, unsupported software types, and the rule that a display name is not used to guess a slug. The actual WordPress smoke test also asserts that the plugin handles a missing MainWP Dashboard without a fatal error. These do not yet test MainWP's real database shape; that requires a separate MainWP-enabled integration environment.

## MainWP database-adapter tests

`MainWPInventoryAdapterTest` exercises the inventory-reading method with a simulated `MainWP_DB` implementation. It covers multiple websites, JSON and serialized inventories, website-option fallback, malformed rows, and background execution without a configured inventory user. This is an interface-level integration test, **not** a test against a real MainWP Dashboard database. A separate MainWP-enabled staging installation is still required before the beta release.

## Scanner HTTP-to-cache WordPress integration check

The WordPress integration workflow now runs `tests/integration/scanner-refresh.php` with the WordPress HTTP transport intercepted by a local fixture. It tests a successful scanner refresh, malformed JSON rejection, HTTP 500 handling, persistence of the previous cache, and the last-success timestamp. This runs against a real disposable WordPress installation with no MainWP inventory and **does not contact Wordfence**. Matching vulnerabilities for actual MainWP sites and production CVSS enrichment remain separate integration tasks.

## Production CVSS HTTP-to-cache integration test

The WordPress integration workflow now runs `tests/integration/severity-refresh.php` after the scanner test. It seeds a private scanner cache with matching and nonmatching findings, intercepts WordPress HTTP calls to the Production endpoint, and validates ID-based CVSS enrichment, preservation of scanner titles and unrelated scores, the enriched count, and the success timestamp. Malformed JSON and HTTP 500 fixtures must leave the previous cache and success timestamp intact. **The feed is simulated**; no external API request or live MainWP connection is made.

## Notification cron integration (WordPress fixture)

The WordPress workflow now runs `tests/integration/notification-cron.php` with a disposable MainWP database fixture and an intercepted `pre_wp_mail` transport. It asserts that initial findings establish a baseline without sending an email; one new confirmed vulnerability triggers exactly one email; subsequent runs do not duplicate it; and disabling notifications prevents mail while preserving the baseline. No outbound mail or MainWP child-site connections occur. Mail transport failure, ignored findings, and actual scheduled WP-Cron invocation remain to be tested.

### Notification failure and ignored-finding regressions

The WordPress notification integration fixture now also tests failed `pre_wp_mail` delivery, preserving the known-finding baseline so a subsequent successful attempt can retry without duplication. It verifies that ignored vulnerability IDs do not enter the confirmed-notification baseline or trigger an email, and dispatches the registered WordPress cron action with `do_action`. This validates hook wiring within a WordPress request, **not** the separate HTTP spawning or timing behavior of the WP-Cron scheduler.

## Cache filesystem regression coverage (v0.5.11)

Legacy cache migration now explicitly refuses symbolic links at both the old index and destination index, and private cache directory setup rejects a symlink at its selected directory path. Regression tests cover both symlink conditions as well as a nonexistent, non-writable destination parent: the existing legacy cache must remain intact.

These are filesystem-level regression tests, not proof that a hosting-specific path is inaccessible through HTTP. Before deploying, verify the configured cache directory is outside the server's actual public document root, is writable by PHP, and is not exposed through an alias or other web-server mapping. Testing symlinked ancestor directories and alternative webroots remains part of the hosting review.

## Runtime fix: temporary feed files (v0.5.12)

Both feed downloads previously called `wp_tempnam()`, which is not a WordPress Core function. In production cron execution this caused a fatal `undefined function` error before scanner or severity downloads could start. Both endpoints now use PHP `tempnam()` inside the directory returned by WordPress `get_temp_dir()`. The WordPress integration test verifies that the helper creates a real temporary file. Deploy to a staging environment first and verify scheduled refresh status and private cache write permissions before using on a production dashboard.

## Local cache-to-inventory diagnostics (v0.5.14)

The dashboard's Data sources section displays counts for unique MainWP inventory slugs, cached software slugs, cached vulnerability records, overlapping slugs, and overlapping vulnerability records. It flags a completely empty cache or a nonempty cache with no inventory overlap. The diagnostics only inspect inventory and the existing local cache while rendering; they do not contact Wordfence, trigger a MainWP remote sync, or replace cache content. These counts distinguish an empty matching index from version-range filtering; they do not prove coverage or vulnerability-free sites.

## Scanner matching diagnostics (v0.5.15)

On each successfully parsed scanner feed, the plugin persists aggregate diagnostic counts for inventory items and unique slugs, feed vulnerability entries, software records, valid type/slug pairs, matched records, and matched software slugs. These counts appear under **Data sources** as **Last scanner matching attempt** and remain available even if a zero-match attempt is rejected. No raw feed rows, API keys, or site identifiers are stored in this diagnostic option. The existing cache is preserved when zero matches are found. This adds no additional API request; diagnostics are collected on the next normal scanner fetch. Malformed feed downloads are not treated as successfully parsed and therefore do not replace the previous diagnostic counts.

## Read-only cache path diagnostics (v0.5.18)

The administrator-only Data sources panel now reports the configured cache path, resolved WordPress root, resolved cache parent/directory, existence, PHP writability, symlink status, and a read-only path validation result. This inspection does not invoke storage_dir(), create directories, migrate files, or make external HTTP requests. Existing last feed errors remain visible until a subsequent feed operation updates or clears them. Confirm that the resolved directory is outside every public webroot or web-server alias before running the scanner.

## Database-backed vulnerability cache (v0.5.19)

The matching vulnerability index is now stored in the dedicated `{prefix}mwa_vulnerability_cache` WordPress table. WordPress creates the table automatically on the next request. Scanner and Production severity imports update one cache row with a single atomic SQL UPSERT after validation; a rejected feed does not replace the prior data. No persistent private cache directory or `MWA_PRIVATE_CACHE_DIR` configuration is required for new installations. Temporary feed downloads still use WordPress's temporary directory and are deleted after parsing.

If the new database row is absent, the plugin attempts to import a nonempty valid JSON cache from the old configured private directory or legacy uploads location without deleting the original file. Existing ignored-finding options and mail baselines are untouched. Do not interpret an empty cache as no known vulnerabilities. A previously overwritten empty JSON cache cannot reconstruct lost matches.

The old path diagnostics are no longer displayed; database diagnostics and the remaining legacy helper code may be removed after the migration compatibility window. A successful staging run and CI confirmation are required before treating this as beta-ready.

## Optional accelerated recurring feed checks (v0.5.20)

Default behavior remains unchanged: scanner and production-severity feeds run once per day; the separate email notification job remains daily. Administrators can opt in under **Settings → Automatic feed refresh (preview)** to run the scanner every three hours and production severity every six hours. The first scheduled runs are staggered (initially 5 and 65 minutes from rescheduling), retaining the shared minimum 45-minute API cooldown. Existing recurring events are replaced only when their interval differs, and a second settings save/normal page load does not reset their schedules.

Switching back to daily refresh is supported without resetting stored vulnerability data, ignore choices, last-known email findings, or notification cron. Saving the setting does not make an API request. Normal WP-Cron timing depends on site traffic or an external system cron, and combined/manual requests may consume the shared cooldown; occasional scheduled checks may therefore be skipped or throttled. This is a preview setting for controlled evaluation, not a guarantee of precisely timed polling.
