# Local read-only findings export v1

Available in the Phase 1b export feature. This additive API does not change the
existing audit screens, feeds, matching, ignored-finding keys or notifications.

```php
$result = mwa_export_findings_v1();
// Equivalent hook, with exactly the same capability checks:
$result = apply_filters('mwa_readonly_export_v1', null);
if (is_wp_error($result)) { /* unavailable/forbidden: retain previous evidence */ }
```

Call inside the authenticated WordPress installation hosting MainWP Dashboard.
`manage_options` is mandatory, including via the hook. No background-user fallback
or unauthenticated HTTP/REST endpoint exists. The caller cannot select another
user or arbitrary database fields. MainWP's public methods enumerate only the
current user's sites. No API key is needed to **read** an existing local cache.

The export performs no HTTP/feed refresh, database migration, legacy cache import,
option writes, scanner scheduling or child-site operations. It reads only the
owning plugin's existing scanner cache. Missing/corrupt/uninitialized cache is
reported as missing, never repaired as a side effect of export. API keys, raw
errors, private keys, HTTP credentials, inventory objects and feed payloads are
not returned. Tests record SQL operations and HTTP requests around the call.

## Shape

```json
{
  "schema_version": 1,
  "generated_at": "2026-11-05T12:00:00Z",
  "feed": {
    "status": "fresh",
    "scanner_at": "2026-11-05T10:00:00Z",
    "severity_at": "2026-11-05T11:00:00Z",
    "scanner_max_age": 172800,
    "severity_max_age": 259200
  },
  "assessment_scope": ["plugins", "themes"],
  "sites": [{
    "mainwp_site_id": 9,
    "status": "ready",
    "inventory_at": "2026-11-05T09:00:00Z",
    "observed_at": "2026-11-05T09:00:00Z",
    "complete": true,
    "findings": [{
      "finding_id": "64 lowercase hex SHA-256 characters",
      "advisory_id": "upstream-advisory-id",
      "component": {"type": "plugin", "slug": "example-plugin", "version": "1.5"},
      "severity": "critical",
      "affected_version_match": "affected",
      "status": "open",
      "title": "Advisory title as text",
      "observed_at": "2026-11-05T09:00:00Z"
    }]
  }]
}
```

- Timestamps are UTC seconds, nullable when no valid observation exists.
  `generated_at` is only serialization time, **not** proof of a new scan.
- Feed status: `fresh`, `stale`, `missing`, `error`, `invalid_time`. Scanner maximum
  age is **48h**, severity maximum age **72h** (independent daily cadences);
  inventories expire after **24h**. Future skew above five minutes is invalid.
  The last successful times are retained during failure. Raw error text is omitted.
- Per-site status: `ready`, `incomplete`, `error`. A malformed/missing site does
  not abort other sites. Complete means **only the declared plugin/theme scope**:
  fresh successful feeds, recent synced inventory, both inventory collections
  present/parseable, no sync errors, no unnormalizable component, unknown severity
  or uncertain affected-version comparison. Empty inventories are valid only when
  explicitly present; missing inventories are incomplete.
- Severity: `critical`, `high`, `medium`, `low`, `info`, `unknown`, derived from
  the existing audit severity function. Existing `range_status()` supplies
  `affected`, `unaffected`, `unknown`; no second matcher is introduced.
- Status is `open` or `ignored`, using existing audit ignore decisions. An
  `unaffected` record is explicit evidence that a particular installed component
  version no longer matches an advisory; consumers may resolve only with fresh,
  complete scope. `unknown` must not resolve a previously confirmed finding.
- Finding ID is `sha256(type + '|' + lowercase(slug) + '|' + advisory_id)`.
  Namespace it by MainWP site ID in the consumer. URL and installed version are
  excluded, so corrected versions/URL changes keep the same finding identity.
  Audit's existing URL-based ignore keys are intentionally unchanged internally.
- Findings enumerate all cache advisories for installed components, including
  unaffected/uncertain comparisons. **Absence is not resolution**: removed
  components/advisories, missing sites/export or stale feeds must retain previous
  findings unless a separate authoritative lifecycle contract supplies evidence.
- WordPress core, external malware scanning and arbitrary PHP packages are outside
  scope. A consumer must not label the whole project safe because this scope is
  complete. No guarantee is made about issues absent from the local feed.

Integration contract tests run in the existing WordPress CI job. Security Hub's
companion Phase 1b PR additionally exercises this API with the actual MainWP
Dashboard in isolated Docker, local inventory and simulated upstream HTTP feeds.
These are not production scans. Coordinate installation with that companion PR;
older audit versions simply leave the Hub's local audit source unavailable.
