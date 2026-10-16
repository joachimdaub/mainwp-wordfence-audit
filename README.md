# MainWP Wordfence Audit — MVP 0.1.2

Install the ZIP as a normal WordPress plugin **only on the MainWP dashboard**. Open Tools → MainWP Security Audit. Obtain a Wordfence Intelligence v3 API key, save it, then refresh the scanner feed. Sync MainWP child sites separately first.

Read-only; no changes to child sites. Admin-only capability `manage_options`, POST nonces, escaped output, fixed HTTPS Wordfence endpoint, request throttling. No third-party libraries. Does not disclose credentials in UI.

Limitations: MainWP site inventory compatibility needs testing against your installed MainWP version; blank inventory can mean a compatibility issue. Only recognizes unambiguous plugin directory slugs and theme slugs. Does not support complex/nonnumeric version formats; these should be investigated, not treated as safe. No WordPress Core matching, no scheduled refresh, no notifications. MainWP data can be stale. v0.1.2 is an untested MVP, not a security-audited production release.

Wordfence reference: https://www.wordfence.com/help/wordfence-intelligence/v3-accessing-and-consuming-the-vulnerability-data-feed/

## API rate limits and current limitations

The free Wordfence Intelligence API key is generally limited to one request per 30 minutes. This release enforces a 45-minute minimum between manual attempts, including failures, and at least 60 minutes after HTTP 429 (or longer when Retry-After requests it). It displays a diagnostic HTTP status without revealing the API key.

**Known blocker:** The current feed download is capped at 35 MB, while the scanner feed may be substantially larger. A valid API response may therefore be rejected as truncated JSON. Do not repeatedly retry if this happens; the next version needs streaming storage and processing. Previous successful data is preserved on errors.

The cooldown is scoped to this WordPress installation, not the entire Wordfence account/key across multiple installations.
