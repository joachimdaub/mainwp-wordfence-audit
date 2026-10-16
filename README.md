# MainWP Wordfence Audit — MVP 0.1.0

Install the ZIP as a normal WordPress plugin **only on the MainWP dashboard**. Open Tools → MainWP Security Audit. Obtain a Wordfence Intelligence v3 API key, save it, then refresh the scanner feed. Sync MainWP child sites separately first.

Read-only; no changes to child sites. Admin-only capability `manage_options`, POST nonces, escaped output, fixed HTTPS Wordfence endpoint, request throttling. No third-party libraries. Does not disclose credentials in UI.

Limitations: MainWP site inventory compatibility needs testing against your installed MainWP version; blank inventory can mean a compatibility issue. Only recognizes unambiguous plugin directory slugs and theme slugs. Does not support complex/nonnumeric version formats; these should be investigated, not treated as safe. No WordPress Core matching, no scheduled refresh, no notifications. MainWP data can be stale. v0.1.0 is an untested MVP, not a security-audited production release.

Wordfence reference: https://www.wordfence.com/help/wordfence-intelligence/v3-accessing-and-consuming-the-vulnerability-data-feed/
