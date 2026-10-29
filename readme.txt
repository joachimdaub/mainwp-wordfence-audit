=== MainWP Wordfence Audit ===
Contributors: joachimdaub
Tags: mainwp, vulnerabilities, security, monitoring, wordfence
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.5.21
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Read-only vulnerability monitoring for MainWP-managed WordPress sites using Wordfence Intelligence v3.

== Description ==

MainWP Wordfence Audit compares the locally synchronized MainWP plugin and theme inventory with the Wordfence Intelligence v3 vulnerability feeds. It reports confirmed version matches, findings requiring review, and individually ignored findings. It does not modify or update connected websites.

The matched feed index is stored in a dedicated WordPress database table. The plugin offers automatic scanner and CVSS refresh, local diagnostics and optional email alerts for newly discovered confirmed findings.

A Wordfence Intelligence v3 API key, MainWP Dashboard and a working WP-Cron are required. A Wordfence account and terms may apply. No Wordfence credentials are included.

== Installation ==

1. Install and activate on the MainWP Dashboard site only.
2. Sync managed sites in MainWP.
3. Open Tools > MainWP Security Audit > Settings and configure the API key.
4. Run or await scanner and severity refreshes, then inspect findings.

== Frequently Asked Questions ==

= Does this plugin fix vulnerabilities? =
No. It is read-only and does not perform updates on any child site.

= Does zero findings mean a site is safe? =
No. Inventory quality, feed availability, matching limitations, and feed freshness matter.

= Does it support WordPress Core vulnerabilities? =
No. The current matching scope is plugins and themes.

= Where are vulnerability results stored? =
In a dedicated database table in the dashboard site's WordPress database. Feed downloads use temporary files.

== Changelog ==

= 0.5.21 =
* Pre-release candidate with database-backed index, scheduled refreshes, notification support and compact diagnostics.

== Upgrade Notice ==

= 0.5.21 =
Pre-release only. Back up your site before installing or upgrading. Existing ignored findings and known-notification keys are preserved by the current migration implementation.
