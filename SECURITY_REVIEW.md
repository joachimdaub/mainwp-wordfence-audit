# Security review — pre-release v0.6.0

Date: 2026-10-29
Scope: Static review of the plugin entrypoint at the current pre-release state; no live penetration testing, no Wordfence traffic and no child-site changes. This is not a third-party audit.

## Confirmed protections

- Settings, refresh and ignore endpoints check `manage_options` and POST nonces (e.g. save_refresh_schedule, start_combined, ignore_action, save_notifications, save_key).
- Read-only dashboard and widget access are permission-gated.
- Externally supplied advisory titles are escaped with `esc_html` after truncation; inventory names and filter options are escaped.
- Vulnerability data is stored as JSON in a dedicated WordPress database table, not served as public JSON by current installations. Database values use prepared SQL arguments.
- Wordfence endpoints are constants, HTTPS URLs, redirects disabled; the configured key is sent only through the Authorization header.
- Temporary feed downloads are parsed incrementally; malformed JSON and feed-to-inventory zero-match rejection protect the last valid index.
- Failed notification delivery does not advance the known-finding baseline.

## Release blockers / follow-up work

### SR-01 — Full-feed download quotas (medium; partially mitigated, still open)

`download_feed()` uses streamed WordPress HTTP transfers and a 180-second timeout but has no explicit response-size cap. `stream_index()` bounds individual JSON records to 8 MiB, but this cap runs **after** the full response is downloaded. Repeated large/error responses could exhaust shared-hosting temporary disk space. A 256 MiB post-download size check now rejects oversized HTTP-200 feeds before parsing and deletes their temporary file. **This does not stop the HTTP transfer before 256 MiB is written**, so the disk-exhaustion risk is only partially mitigated. Add transfer-time enforcement or an equivalent bounded transport design, plus free-space checks. Add tests for oversized and truncated responses, including HTTP 200.

### SR-02 — Legacy uploads JSON exposure (medium; review before public beta)

`import_legacy_cache()` can read an older index from `wp-content/uploads/mainwp-wordfence-audit/`. The new database backend does not create this file, but older deployments might still have a publicly reachable copy on Nginx or nonstandard servers. Preserve the historical migration file until verified, then provide administrators a non-destructive warning/remediation path to identify and secure/remove it. Do not automatically delete unknown user data.

### SR-03 — Memory and data-shape checks in streaming import (low/medium)

`stream_index()` limits one JSON value to 8 MiB, but the aggregate matched index and full JSON payload still scale with inventory size. Inputs like a non-array `software` field should be rejected clearly rather than relying on runtime warnings. Add malformed-schema/large-matched-index regression tests and a reasonable index-size policy.

### SR-04 — Scheduled status reliability (low/medium)

A local API cooldown and two recurring schedules may lead to a throttled run without an automatically scheduled retry, particularly when manual/combined updates overlap scheduled events. Prevent healthy-looking status from hiding stale security data; consider retry/reschedule on throttle within the rate limit and clearer last-success/last-attempt indicators.

### SR-05 — Supply-chain and distribution review (release blocker)

Confirm license ownership and compatibility of included files, validate the generated ZIP on a clean site and an upgrade fixture, and ensure the tagged version exactly matches the plugin header and readme stable tag. The repository is currently private; do not publish/release automatically. Include a full copy of the chosen license in the eventual distribution, not only a link to a license on the internet.

## Lower-priority cleanup

- Old path-management helpers (`storage_dir()`, `migrate_legacy_cache()`, `cache_path_diagnostics()`) remain in the main plugin file although no longer used by normal refresh operations. Maintain migration compatibility but remove unreachable paths after tests.
- Historical Cron error options can persist after successful runs; mark them historical or clear on successful later runs.
- Explicitly document that empty/old/unavailable feed != vulnerability-free site.
- Test with real MainWP schema changes, PHP 7.4, and site admin accounts with different capabilities.

## Release gate

Do not label this review complete for beta until SR-01, SR-02 and SR-05 are addressed, both GitHub CI suites pass for the release commit, and clean-install/upgrade smoke tests pass. No production installation was modified during this review.
