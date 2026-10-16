# MainWP Wordfence Audit

Experimental, read-only vulnerability scanner for a MainWP dashboard using the Wordfence Intelligence v3 scanner feed.

> **Development status:** v0.3.1 is not yet production-ready or security-audited. Use a staging MainWP dashboard for initial tests. Do not publish the repository as a stable release yet.

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

## Finding filters (v0.3.1)

Use the dashboard's **Website** and **Severity** dropdowns to narrow active and currently ignored findings. **Sort** orders severity from highest to lowest by default (or the reverse). Unknown severity is kept visible in the default view and can be filtered explicitly. The controls only reload the admin page; they never request the Wordfence feed or update a managed site. **Reset** returns to all websites and severities.

The summary counters above the filters show the unfiltered current inventory. The separate **Visible findings** count reflects the filtered active results. Archived ignored findings are listed separately so they can always be restored.
