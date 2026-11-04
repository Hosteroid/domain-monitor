# Changelog

All notable changes to Domain Monitor will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.6] - 2026-11-04

### Upgrade Notes
- **Run the updater** - After updating the files, open `/install/update` to apply migrations 029–033
- **Reminders arrive on the right day** - Domain expiry reminders go out one day later than in 1.1.5: "expires in 7 days" is now sent 7 days before, not 8. A domain's days left is one higher everywhere in the app, for the same reason
- **Expired domains stop alerting daily** - An expired domain alerts once, on its expiration date (or on the first check in the week after it, when no check ran that day), and then on the Reminders After Expiry days (Settings → Monitoring, default 7, 14, 30). Leave that setting empty for no reminders after expiry
- **More domains get reminders** - Domains with an expiration date set by hand (.nl, .de, .eu and others), and domains whose WHOIS lookup fails, now get expiry reminders from the saved date
- **SSL "Expiring Soon" at 14 days** - It now starts at the largest SSL reminder day, which upgraded installs set to 14,7,3,1 (Settings → Monitoring → SSL Certificates), instead of a fixed 30 days. Certificates that were Expiring Soon under the old 30 days go back to Valid without a "Recovered" alert
- **Renewed alerts are on** - The new "Renewed" status alert is switched on for existing installs; turn it off under Settings → Monitoring → Status Change Notifications
- **Manual refreshes send alerts** - Refreshing WHOIS, DNS or SSL by hand now sends the same alerts as the cron jobs, so a change you find yourself still reaches the group
- **Wildcard DNS zones are tidied** - The first DNS refresh of a zone with a wildcard drops the subdomains that only answered through it and lists the wildcard as `*`, which is reported once as an added record
- **Cert Spotter is on** - DNS Deep Scans also ask SSLMate's Cert Spotter, so domain names go to SSLMate as they already go to crt.sh. Turn it off, or add an API key for more lookups an hour, under Settings → Monitoring → DNS Discovery

### Added
- **Watchlist Notification Groups** - A group's purpose is "My domains" or "Watchlist" (domains you want to acquire). A watchlist skips the reminders before expiry and the alert on the expiry date, gets a lifecycle update on the reminder days after expiry, and is told when a domain approaches deletion (redemption period), is pending delete, becomes available or is renewed, worded for buying it and never as an urgent alert (normal Pushover priority). Carried in group export and import (#95)
- **Reminders After Expiry** - Days after a domain expired to send a reminder, in Settings → Monitoring → Notification Schedule (default 7, 14, 30). None go out once WHOIS reports the domain in redemption, pending delete or available, which its status alerts announce
- **Domain Renewal Alerts** - A "Domain Renewed" alert when a WHOIS check finds the expiration date at least a month later for the same registration, also after a manual or bulk refresh; sent in-app and to the group's channels with the new and previous expiration dates. Switched on under Settings → Monitoring → Status Change Notifications and follows each group's status alert switch. A domain the registry renewed on its own at expiry is flagged, since the registrar can still delete it (#74)
- **SSL Certificates on the Dashboard** - Widget with the five certificates closest to expiry (days left, issuer, link to the domain's SSL tab) and counts of valid, expiring, expired and invalid certificates (#77)
- **SSL Expiry Reminders** - Reminder days for SSL certificates in Settings → Monitoring → SSL Certificates (default 14, 7, 3, 1); each reminder day is sent once per certificate, also when a check failed in between, and starts over after a renewal (#72)
- **Alert Types per Notification Group** - Turn domain expiration, status change, DNS change or SSL alerts on or off for each group; carried in group export and import (#88)
- **Second Certificate Transparency Source** - DNS deep scans ask Cert Spotter as well as crt.sh and merge the results, so new certificates are found while crt.sh is behind. Sources and an optional, encrypted Cert Spotter API key in Settings → Monitoring → DNS Discovery; a rate-limited source is skipped for the rest of the run (#100)
- **Bulk SSL Monitoring** - Enable or disable SSL monitoring for the selected domains from the Domains list
- **SSL Monitoring for New Domains** - Setting to turn SSL monitoring on for domains that are added, bulk added or imported (off by default)
- **SSL Status on the Domains List** - Lock next to each domain the SSL cron checks, coloured by its worst certificate, with a summary on hover and a link to the SSL tab
- **Readable SSL Errors** - A failed check names the cause (hostname mismatch, self-signed, incomplete chain, untrusted authority, refused or timed out); the raw error is under "Technical details" on the SSL tab
- **DNS Record Management** - Add, delete and bulk delete records, import BIND zone files, Quick Scan and background Deep Scan; manual and imported records are kept by refreshes and marked with a source badge
- **MX and TXT for Subdomains** - Subdomains are queried for MX and TXT too; MX, TXT and CAA tables show the host
- **WHOIS Failure Reasons** - When a WHOIS lookup fails, the server, port and error are shown when adding or refreshing a domain, in bulk add and bulk refresh, on the WHOIS Lookup page and in the cron log; a registry refusing this server's queries no longer saves an empty record (#90, #46)
- **Notification Icons for SSL and DNS** - SSL alerts show a lock coloured by outcome and DNS changes their own icon; new "Monitoring" group in the notifications type filter, and an SSL option in a domain's Notifications tab
- **Styled Tooltips** - Themed tooltips replace the browser's native ones, with keyboard focus and dark mode support

### Changed
- **Expired Domain Alerts** - An expired domain alerts once, on its expiration date, and then on the reminder days after expiry. Before, the alert started the day before the expiration date and repeated every day (#95). When no check ran on the day, the first check in the week after sends it; the status change to Expired and the expiry-day alert count as one alert, so a manual refresh and the cron don't both send it
- **Wildcard DNS** - Zones with a wildcard record are detected on every refresh and scan: subdomains that only answer through the wildcard are no longer listed or kept (a wildcard zone could collect hundreds), the wildcard is listed as host `*`, and the DNS tab shows a Wildcard DNS badge. Subdomains with records of their own, and records you add or import, stay; dropping wildcard-only hosts is not reported as a DNS change, while a subdomain that keeps records of its own still reports what it lost (#76)
- **SSL "Expiring Soon"** - Starts at the largest SSL reminder day (14 by default) instead of a fixed 30 days, and the status and days left are worked out live instead of from the last check
- **Alert Titles and Colours** - Domain status and expiry alerts get a proper title in email, Pushover, Discord, Slack and Mattermost (a status change email was titled "Domain Expiration Alert"), a readable status, and a bar colour that matches it; SSL alerts show hostname, days left and valid-until date
- **Renewed Expired Domains** - An expired domain that is renewed gets "Domain Renewed" instead of "Domain Registered"; a domain that was dropped and registered again (new creation date) still gets "Domain Registered", also when a failed lookup came in between
- **First DNS Scan** - A domain's first DNS check saves a baseline instead of alerting that every record was added
- **Cron Domain Selection** - DNS and SSL crons skip domains that are available, expired, in redemption or pending delete; domains whose WHOIS lookup failed are still checked
- **Faster SSL Failures** - A connection that is refused or times out is no longer tried a second time without verification
- **DNS Lookups** - Names are queried as absolute names so the resolver's search domains can't fake answers; the DNS_ALL fallback only runs when the typed queries found nothing; underscore hosts are queried for CNAME, TXT and SRV only (#98)
- **IP Details** - PTR answers and ip-api.com results are cached for the run, batches hold at most 100 IPs, the CLI waits out ip-api.com's rate limit and retries a refused batch once, and stored ASN/geo details are kept when a lookup returns nothing (#98, #107)
- **SOA Records** - Imported SOA records store the primary nameserver as their value, a name keeps a single SOA, and the SOA card shows its source and a delete button (#97, #99)
- **Dropdowns** - Select menus get a themed list in Chrome and Edge 135+; other browsers keep their native menus
- **Bulk Action Bars** - Redesigned on Domains, Tags, Notification Groups, Users, Error Logs and TLD Registry
- **Transfers** - Changing a domain's owner unlinks groups and tags that belong to other users, with audit logging
- **Root Domain Validation** - Subdomains are rejected when adding or importing domains; .com.br, .co.uk, .gob.mx, .me.uk and other second-level ccTLD domains are accepted (#91)
- **Mattermost** - Rich attachments only for expiration, status and SSL alerts; DNS changes stay plain text

### Fixed
- **Expiry Reminders a Day Early** - Days left were counted in whole days from the current time, which came out one short after midnight: reminders went out a day early ("expires in 7 days" eight days before, "expires TOMORROW" two days before) and every days-left figure was one low. Days left are now calendar days everywhere: alerts, the Domains list, domain pages, the dashboard and search
- **Reminders for Expiration Dates Set by Hand** - A domain whose WHOIS has no expiration date (.nl, .de, .eu and others) got no expiry reminders even with an expiration date set on the domain; that date is now used for reminders, as the edit form already said
- **Reminders When WHOIS Fails** - A domain whose lookup failed, or whose rate-limited retries gave up, got no expiry reminder that day; the reminder due now goes out from the saved expiration date, with a line saying WHOIS couldn't be checked
- **Updater with open_basedir** - The backup before an update stopped with "is_executable(): open_basedir restriction in effect" when mysqldump was outside the allowed paths. A mysqldump found on the PATH is still used, otherwise the built-in database backup (#73)
- **Notifications Tab Filter** - Expiry reminders in a domain's Notifications tab are listed under Expiration instead of Other
- **Alerts After a Manual Refresh** - Refreshing WHOIS, DNS or SSL by hand (single, bulk, Refresh All, adding an SSL host) sends the alerts the crons would have sent, by the same rules: status changes, renewals, DNS changes, SSL status changes and SSL expiry reminders. A change found by hand used to be saved without an alert, so the cron never saw it. Domains with monitoring paused, or DNS or SSL monitoring off, send none. The domain cron's retry queue for rate-limited lookups sends status alerts too
- **SSL Reminders After a Manual Check** - Checking a renewed certificate by hand kept the old certificate's reminder state, so the new certificate's first reminders were skipped
- **Retry Summary in the Cron Log** - After WHOIS retries the domain cron reported "still failed" when every retry had worked, and "All retries completed successfully" when some had failed; it now names the domains that still failed
- **Manual and Imported DNS Records** - Records you add or import that the lookups don't return the same way (a relative MX target, a TXT split into strings, a delegated subdomain's NS) were reported as removed on every DNS check; only records the lookups found are reported as removed now. A manual record with priority 0 is saved as 0
- **Domain Cron and Deleted Domains** - Deleting a domain while the domain cron ran could stop the run, so the remaining domains weren't checked that day; the cron now skips it and carries on
- **Status Alerts After a Failed Lookup** - A domain in redemption, pending delete or available is saved as Error when its lookup fails; the next lookup that worked announced its status again. Status changes are now judged against the last successful lookup
- **Login with a Custom Database Port** - With `DB_PORT` other than 3306, database sessions were never enabled and every login ended with "Your session was terminated remotely"
- **DNS Crons on Windows** - Stopped failing on `DNS_CAA`, which Windows builds of PHP don't define
- **.com.br and Other Non-UTF-8 WHOIS Responses** - Converted from Latin-1, and a stray byte can no longer fail the save; the parent TLD's RDAP server is used when a second-level suffix has none (#91)
- **"Remember me" with 2FA** - The choice is kept through the two-factor step
- **.sk Expiration Dates** - "Valid until" dates are recognised (#105)
- **DNS Cron Example** - The cron line shown for the DNS check was missing a `*` (#79)
- **Expired Certificate Days** - The number of days since a certificate expired was one too high
- **Notifications Page in Dark Mode** - Icon backgrounds use the dark palette
- **Webhook Payload Docs** - The README example now shows the event name the app sends (`domain_monitor_alert`)

### Security
- **Sessions** - The session ID is regenerated on login and after 2FA; session cookies are Secure, HttpOnly and SameSite=Lax
- **Installer** - CSRF checks on install and update; admin credentials are quoted before going into the SQL
- **Templates** - `safe_url` and `safe_mailto` Twig filters, escaped tag names in JavaScript, `rel="noopener noreferrer"` on external links
- **Access Checks** - Referrers are validated to prevent open redirects; isolation mode is enforced when finding, updating, deleting and grouping domains; channels are checked to belong to the group before they are deleted or toggled; error log sorting only accepts known columns; the debug WHOIS route requires an admin

### Technical
- **Services** - `App\Services\CtLogs` (`CtLogSourceInterface`, `CrtshSource`, `CertSpotterSource`); `DnsService::fetchCtLogSubdomains()` and `mergeSubdomainLists()`; `check_dns.php --ctlog <source> <domain>` subprocess (`--crtsh` still works); `WhoisService::detectRenewal()` and `getLastKnownStatus()`; `NotificationService::alertDomainStatusChange()`, `alertDomainRenewal()`, `alertDnsChanges()` and `alertSslCheck()` (alert rules moved out of the crons so manual refreshes use them too), `sendDomainRenewalAlert()` and `notifyDomainRenewed()`; `SslService::checkEndpoint()` and `resolveReminder()`; `NotificationService::alertDomainExpiry()` and `getExpiryAlertType()`; wildcard probing in `DnsService` refreshes and scans
- **Helpers** - `SslHelper` (display formatting, stats, per-domain summary); notification icons and colours live in `LayoutHelper` only; `CronHelper` for cron scripts
- **Models** - `Model::hasColumn()` (code that writes a column a pending migration adds leaves it out until the update has run), `SslCertificate::getAllMonitored()` and `getStatusByDomainIds()`, `NotificationGroup::allowsAlert()`, `Notification::wasCreatedRecently()` and `wasCreatedForDomainSince()`, `NotificationLog::wasSentSince()`, `Domain::DNS_SSL_CHECK_STATUSES`, `NotificationGroup::isWatchlist()`, `DnsRecord::hasWildcard()`, Setting getters for SSL reminder days, new-domain SSL, certificate log sources, the Cert Spotter key and the reminder days after expiry
- **Routes** - `POST /domains/bulk-toggle-ssl`, DNS record add/delete/bulk delete, zone import and discovery
- **Assets** - `public/assets/tooltip.css` and `tooltip.js`
- **Database** - `dns_records.source`; `ssl_certificates.last_reminder_days` and `last_reminder_valid_to`; `notification_groups.notify_expiration`, `notify_status`, `notify_dns`, `notify_ssl`, `purpose`; settings `ssl_notification_days`, `post_expiry_notification_days`, `ssl_monitoring_new_domains`, `ct_log_sources`, `certspotter_api_key`; `renewed` in `notification_status_triggers`; notification type `domain_renewed`

### Migrations
- `029_add_dns_record_source.sql` - DNS record source (discovered, manual, imported)
- `030_add_ssl_reminders_and_group_alert_types.sql` - SSL reminder days, new-domain SSL setting, last reminder per certificate, alert type switches on notification groups
- `031_add_ct_log_sources.sql` - Certificate transparency sources, Cert Spotter API key, app version 1.1.6
- `032_add_renewal_notifications.sql` - Turns on the "Renewed" status change notification
- `033_add_watchlist_groups.sql` - Notification group purpose (my domains or watchlist) and reminder days after expiry

### Contributors
- **@mews-se** - DNS lookup efficiency (#98), imported SOA fixes (#99), ip-api.com rate limiting (#107), and the Cert Spotter proposal (#100)
- **@dodog** - .sk expiration date report (#104) and fix (#105)
- **@delsassergh** - DNS cron example fix (#79)
- **@artyomtsybulkin** - Second-level ccTLD suggestion (#91)
- **@celebrion** - Separate SSL alert days (#72) and renewal notifications (#74) requests
- **@stevelacey** - Granular notification controls (#88) and watchlist lifecycle notifications (#95) requests
- **@Mellowlynx** - Wildcard DNS request (#76)
- **@Margedion** - SSL certificates on the dashboard request (#77)
- **@manalshaikh** - open_basedir updater report (#73)
- **@insxa** - .es WHOIS connection report (#46)
- **@WTMike24** - .hr WHOIS report (#90)
- **@evandroks** - .com.br domain report (#91)
- **@N-Storm** - Stale SOA records with imported zones report (#97)

---

## [1.1.5] - 2026-03-08

### Added
- **Twig Templating** - All PHP views migrated to Twig; `twig/twig` added as dependency
- **Twig-Only Rendering** - Removed legacy PHP view fallbacks; ErrorHandler renders via Twig with safe escaped HTML fallback on failure
- **DNS Monitoring** - Track DNS record changes with DnsService (lookup, crt.sh discovery, Cloudflare detection, IP enrichment), DnsRecord model for snapshots and diffs, per-domain `dns_monitoring_enabled` toggle, manual and scheduled refresh via `cron/check_dns.php`
- **SSL Certificate Monitoring** - Track TLS certificates with SslService and SslCertificate model; validity, expiry, issuer, SAN list; per-domain `ssl_monitoring_enabled`; add/refresh/delete endpoints for root and custom hostnames/ports; `cron/check_ssl.php` for scheduled checks
- **Domain View Tabs** - New tabbed domain view: Overview, DNS, Billing, Notifications, SSL, WHOIS
- **Domain View Template Setting** - Choose `detailed` (tabbed) or `legacy` view per installation
- **Cron Staleness Warnings** - Settings shows warnings when domain/DNS/SSL cron runs are overdue
- **Timezone on Installer Routes** - App timezone now applied even on `/install` and `/install/update` when installed, so upgrade notifications use correct timezone

### Changed
- **2FA Flows** - Twig templates for setup, verify, backup-codes; TwoFactorService silences deprecated QR code warnings
- **Settings Page** - Timezone lists, notification preset selection, cron path display, cached update state, rollback availability
- **Avatar and 2FA Data in Controllers** - ProfileController and UserController pass avatar and two-factor info to Twig views
- **EmailHelper** - Safer subject handling
- **TldRegistry** - Search improvements
- **Domain Sorting** - Uses effective status (e.g. `expiring_soon`) for ordering
- **Discord Channel** - Null-safe field handling

### Technical
- **Core** - `Core\TwigService` for Twig rendering; Controller and Router always use Twig
- **Models** - `DnsRecord`, `SslCertificate`
- **Services** - `DnsService` (DNS lookup, crt.sh, Cloudflare detection), `SslService` (certificate fetch and parsing)
- **DomainController** - `performWhoisRefresh`/`performDnsRefresh`, `refreshWhois`, `refreshDns`, `refreshAll`; SSL endpoints: `addSslHost`, `refreshAllSsl`, `bulkRefreshSsl`, `bulkDeleteSsl`, `refreshSsl`, `deleteSsl`
- **NotificationService** - Notifications when DNS or SSL monitoring is toggled
- **domains table** - `dns_last_checked`, `dns_monitoring_enabled`, `crtsh_last_fetched`, `ssl_last_checked`, `ssl_monitoring_enabled`
- **settings** - `domain_view_template`, `dns_check_interval_hours`, `last_dns_check_run`, `ssl_check_interval_hours`, `last_ssl_check_run`

### Migrations
- `027_add_dns_monitoring.sql` - dns_records table, domain columns, DNS cron settings
- `028_add_ssl_monitoring.sql` - ssl_certificates table, domain columns, SSL cron settings, app version 1.1.5

---

## [1.1.4] - 2026-03-02

### Added
- **CSV/JSON Import & Export for TLD Registry** - Export all TLDs with WHOIS servers, RDAP servers, registry URLs, and active status; import from CSV/JSON with create-or-update logic and duplicate detection
- **Manual TLD Creation** - Create button with popup modal to add custom TLD entries (supports multi-level TLDs like .co.uk, .co.za, .com.au)
- **IANA Dropdown Menu** - Consolidated "Import TLDs from IANA", "Check for Updates", and "IANA Import Logs" into a single indigo dropdown, reducing button clutter and separating IANA sync from file import/export
- **TldRegistry::findByTld()** - Lookup TLDs regardless of active status (used by import deduplication and create duplicate check)
- **TldRegistry::getAll()** - Retrieve all TLDs ordered by name for export

### Changed
- **Standardized Import Logging** - Added consistent `Logger('import')` calls across all four import functions (Tags, Domains, Notification Groups, TLD Registry) with start, file info, parse count, validation warnings, and completion stats
- **Standardized Export Logging** - TLD Registry export now uses local `Logger('export')` instances matching Tags, Domains, and Notification Groups pattern
- **TLD Registry Action Bar Redesigned** - Six separate buttons consolidated into four: IANA dropdown (indigo), Export dropdown (emerald), Import button, Create TLD button

### Technical
- **Drag-and-Drop File Upload for TLD Import** - Same dropzone pattern as Tags and Groups with file preview, remove, and submit spinner
- **TLD Validation** - Regex supports multi-level TLDs (`^\.[a-z0-9\-]+(\.[a-z0-9\-]+)*$`), auto-lowercasing, dot-prefix normalization
- **Import Create-or-Update** - File import creates new TLDs or updates existing ones; RDAP servers parsed from JSON arrays or comma/semicolon-separated strings
- **Routes** - Added `GET /tld-registry/export`, `POST /tld-registry/import`, `POST /tld-registry/create` before `{id}` catch-all

### Migrations
- `026_update_app_version_v1.1.4.sql` - Updates app version to 1.1.4

---

## [1.1.3] - 2026-02-11

### Added
- **CSV/JSON Import & Export for Domains** - Export all domains with tags, groups, and notes; import from file with WHOIS auto-lookup, group matching by name, and duplicate skip
- **CSV/JSON Import & Export for Tags** - Export/import user tags with human-readable color names and descriptions
- **CSV/JSON Import & Export for Notification Groups** - Export groups with channels (sensitive data masked); import with auto-disable for masked credentials
- **In-App Update System** - Check, download, and apply updates directly from Settings (GitHub Releases & hotfix tracking)
  - Two update channels: Stable (releases only) and Latest (releases + hotfixes)
  - Full file and database backup before every update, with one-click rollback
  - Automatic `composer install` when dependencies change (detects cPanel/shared hosting limitations)
  - Commit SHA integrity verification on downloaded archives
  - Update badge in top navigation bar (admin-only, configurable)
  - Cron-based background update checks with admin notifications
- **Update Available Notifications** - In-app alerts for admins when a new release or hotfix is detected
- **Tag Transfer** - Admin-only transfer of individual or bulk-selected tags to another user
- **Domain Bulk Transfer** - Admin-only bulk transfer of selected domains to another user
- **Drag-and-Drop File Upload** - File import zones on Domains (bulk-add), Tags, and Groups pages with format hints and size limits

### Changed
- **Bulk Action Bars Redesigned** - Consistent inline toolbar across Domains, Tags, Groups, Users, Errors, and TLD Registry
- **Notification Click Routing** - `update_available` notifications redirect to Settings → Updates tab
- **Domains Per-Page Preference** - Remembered via cookie (persists for 1 year)
- **Installer Route Protection** - Requires admin auth for post-install routes; blocks re-installation
- **Settings Page** - New Updates tab with status card, preferences, rollback, and release notes viewer (Markdown rendered via marked.js + DOMPurify)
- **Button Color Consistency** - TLD Registry and transfer modals use `bg-primary` branding instead of mixed indigo/green
- **ErrorHandler Hardened** - Recursion guard, `JSON_PARTIAL_OUTPUT_ON_ERROR` for stack traces, `\Throwable` catch, graceful fallback to `error_log()`

### Fixed
- **Tag Delete XSS** - Fixed escaping of tag names containing quotes in delete confirmation
- **Bulk Actions Bar Toggle Bug** - Removed flex class toggling that caused display issues

### Security
- **Sensitive Data Masking in Exports** - API tokens show `****` + last 4 chars; webhook URLs show scheme + host only; masked channels imported as disabled
- **Installer Access Control** - Post-install pages (update, migration runner) require admin authentication
- **Import Validation** - File size limits (5 MB domains, 2 MB groups, 1 MB tags), extension whitelist (`.csv`, `.json`), CSRF on all import forms

### Technical
- **UpdateController** - New admin-only controller with check, apply, rollback, and preference endpoints
- **UpdateService** - GitHub API integration with release/commit tracking, file + DB backup, staged extraction, and rollback
- **LayoutHelper::getUpdateBadgeInfo()** - Cached badge state for top-nav without API calls on page load
- **ViewHelper::getMaxUploadSize()** - Returns effective PHP upload limit as human-readable string
- **NotificationGroup::findByName()** - Lookup groups by name with optional user scope
- **Setting::getUpdateSettings()** - Returns all update-related settings in one call
- **In-memory CSV building** - Uses `php://temp` streams to avoid output buffer conflicts

### Migrations
- `025_add_update_system_v1.1.3.sql` - Adds `update_channel` and `update_badge_enabled` settings, updates app version to 1.1.3

---

## [1.1.2] - 2026-02-09

### Added
- **Google Chat Webhook Support** - Selectable payload formats: Generic (n8n/Zapier/Make), Google Chat (rich card), and Simple Text
- **Domain Status Change Notifications** - Configurable alerts for domain lifecycle events: available, registered, expired, redemption_period, pending_delete
- **Failed Login Notifications** - In-app alerts for failed login attempts with geolocation, device info, and reason
- **Domain Expiration Bell Notifications** - In-app notifications for expiring domains, respects user isolation mode
- **Admin User Profile Page** (`/users/{id}`) - Detailed view with Overview, Domains, Tags, and Notification Groups tabs
- **Dashboard Insights Widgets** - Registrar distribution, tag usage, and notification coverage for logged-in users
- **Quick Actions Dropdown** - Top-nav `+` button with Add Domain, Create Group, Create Tag, and WHOIS Lookup
- **WHOIS Rate Limit Handling** - Exponential backoff with retry logic, grouped by TLD to avoid repeated throttling
- **Admin TLD Registry Editing** - Edit WHOIS and RDAP servers directly from the TLD registry UI
- **Redemption Period & Pending Delete Detection** - New domain statuses parsed from EPP status codes (`redemptionPeriod`, `pendingDelete`)
- **Configurable Status Triggers** - Settings UI to choose which domain status changes trigger notifications
- **Sidebar Branding** - SVG logo with clickable "Domain Monitor" title and "Track your domains" subtitle
- **404 Error Logging** - Router logs 404 errors with request method, IP, user-agent, and referer details
- **Copy Error Report** - Clipboard copy with toast feedback in admin error detail view

### Changed
- **Dashboard Redesigned** - Compact admin system status bar, balanced widget grid, removed Quick Actions widget
- **Mobile UI Overhauled** - Sidebar overlay with swipe-to-close, body scroll lock, responsive layout tweaks
- **Error Log Deduplication Improved** - Matches on type + file + line + message; resolution operates on all matching errors
- **Webhook Logging Enhanced** - Masked URLs, response body truncation, payload previews, structured error handling
- **Notification Dropdown Enriched** - Country flags, device icons for login alerts, clickable domain links
- **User Create Form Redesigned** - Centered card layout, responsive grid, password show/hide toggles, live validation
- **WHOIS Date Parsing** - Added DD/MM/YYYY format support for European registries (.pt, .es, .fr)
- **Domain Status ENUM Expanded** - Added `redemption_period` and `pending_delete` values
- **Status Detection Improved** - Better handling for .nl and .eu domains missing expiration dates
- **Login Success Messages** - Now include the user's full name
- **Centralized Logging** - Logger service replaces all remaining `error_log()` calls

### Fixed
- **Notification Group Delete** - Changed from GET to POST with CSRF token (was vulnerable to CSRF)
- **Bulk Domain Create** - Wrapped in try/catch to handle duplicate domain conflicts gracefully
- **User Edit Form Action** - Fixed route mismatch (`/users/update` → `/users/{id}/update`)
- **Tag Isolation Access** - Enforced permission checks in TagController for isolated mode
- **RDAP Server Route** - Fixed route name mismatch between definition and controller method
- **Top-Nav Dropdowns** - Fixed broken dropdown toggle logic after Quick Actions addition
- **PHP 8.x Compatibility** - Fixed null parameter warnings in date functions
- **Sidebar Quick Stats** - Fixed variable collision when viewing user profiles

### Security
- **CSRF Protection** - Added to profile delete, notification delete/clear-all, user delete, user toggle-status
- **POST Method Enforced** - All destructive actions changed from GET to POST (profile, notifications, users, groups)
- **Failed Login Alerts** - Target user notified with IP address and user-agent details
- **Tag Access Control** - Isolated mode users blocked from viewing other users' tags via direct URL

### Migrations
- `024_add_status_notifications_v1.1.2.sql` - Expands domain status ENUM, adds notification status triggers setting, updates app version

---

## [1.1.1] - 2025-11-18

### Added
- **Pushover Notification Channel** - Send domain expiration alerts via Pushover (iOS, Android, Desktop)
  - Priority-based notifications (Emergency, High, Normal, Low) based on days until expiration
  - Emergency alerts (expired or expiring in ≤1 day) with auto-retry every 5 minutes for 1 hour
  - 23 custom notification sounds to choose from
  - Device targeting - send to specific devices or all devices
  - Rich notifications with title, message, and clickable URL to domain details
  - Optional custom sound and device configuration
  - Database migration `022_add_pushover_channel_type.sql` to add Pushover support

### Fixed
- **Security: PHP 8.x URI Injection Vulnerability** - Fixed deprecated `strpos()` null parameter warning
  - Added early request validation in `public/index.php` to block malformed URIs
  - Enhanced `core/Auth.php` to handle null values from `parse_url()` gracefully
  - Malformed requests are now logged and return 400 Bad Request
  - Prevents attackers from causing PHP warnings via malformed URI probes
- **PHP 8.x Compatibility: strtotime() Null Parameter** - Fixed deprecated warnings for null expiration dates
  - Added null checks before calling `strtotime()` in all domain view templates
  - Displays "Unknown" for domains without expiration dates (e.g., .nl domains)
  - Updated 9 view files: groups/edit, domains/index, domains/view, domains/edit, dashboard/index, tags/view, search/results
  - Also fixed `NotificationService::formatExpirationMessage()` to handle null dates
- **Domain Status Detection for .nl Domains** - Fixed incorrect "available" status for registered .nl domains
  - `.nl` WHOIS/RDAP doesn't always provide expiration dates or explicit status flags
  - Improved `WhoisService::getDomainStatus()` to detect registered domains via nameservers and valid registrar
  - Cron job now preserves existing expiration dates when WHOIS doesn't return one
  - Prevents false positives for domain availability
- **Domain Status Detection for .eu Domains** - Fixed incorrect status and registrar parsing for .eu domains
  - Added specific `.eu` registrar format parsing (`Name: Registrar Name`)
  - Fixed RDAP vCard parsing to strip "Name:" prefix from registrar field  
  - Fixed WHOIS parsing to handle "Name: Company" format in registrar sections
  - Enhanced status detection logic to recognize registered domains without explicit status flags
  - Consistent behavior between manual refresh and automated cron checks
- **Logging Consistency** - Replaced all remaining `error_log()` calls with custom Logger service
  - Updated `WhoisService.php`, `NotificationService.php`, `AuthController.php`, `UserController.php`
  - Centralized structured logging throughout the application
  - Better debugging and audit trail capabilities

### Changed
- **Status Detection** - Unified `DomainHelper::determineStatus()` to use `WhoisService::getDomainStatus()` for consistency
- **Documentation** - Updated README.md to reflect all available notification channels including Pushover

## [1.1.0] - 2025-10-09

### Added
- **User Notifications System** - In-app notification center with filtering and pagination
- **Welcome Notifications** - Automatically sent to new users on registration or fresh install
- **System Upgrade Notifications** - Admins notified when system is upgraded with migration details
- **Notification Types**:
  - System: Welcome, Upgrade notifications
  - Domain: Expiring, Expired, Updated
  - Security: New login detection
  - WHOIS: Lookup failures
- **Notification Features**:
  - Unread notification count in top navigation
  - Dropdown preview of recent notifications
  - Full notification page with filtering (status, type, date range)
  - Pagination and sorting
  - Mark as read / Mark all as read
  - Delete individual / Clear all notifications
- **Database-Backed Sessions** - Full session management stored in database
- **Active Session Management** - View, monitor, and control all logged-in devices
- **Geolocation Tracking** - IP-based location detection (country, city, region, ISP)
- **Session Details Display**:
  - Country flags with flag-icons library
  - City and country name
  - ISP/Network provider
  - Device type detection (Desktop/Mobile/Tablet)
  - Browser detection (Chrome/Firefox/Safari/Edge/Opera)
  - Session age and last activity timestamps
  - Remember me indicator (cookie badge)
- **Remote Session Control**:
  - Terminate individual sessions with delete button
  - Logout all other sessions with one click
  - Immediate logout validation (deleted sessions can't access anything)
- **Enhanced Profile Page**:
  - Sidebar navigation layout
  - Four sections: Profile Information, Security, Active Sessions, Danger Zone
  - URL hash navigation (#profile, #security, #sessions, #danger)
  - Clean design matching application theme
- **Remember Token Security**:
  - Remember tokens linked to specific sessions
  - Deleting session also invalidates remember token
  - Prevents auto-login after remote logout
- **Session Validator Middleware** - Validates sessions on every request
- **Auto-Detected Cron Paths** - Settings page shows actual installation paths (thanks @jadeops)
- **Automatic Session Cleanup** - Multiple cleanup triggers (no cron job needed)
- User registration with email verification
- Password reset via email
- Remember me functionality (30-day cookies)
- User profile management
- Change password
- Email verification with token expiry (24h)
- Password reset tokens (1h expiry)
- Registration enable/disable toggle
- User CRUD management (admin-only)
- Role-based access control (admin/user)
- Centralized app version in database
- Web-based installer (replaces CLI migrate.php)
- Web-based updater for new migrations
- Auto-detection of installation status
- Migration tracking system
- Consolidated database schema for v1.1.0 fresh installs
- Smart migration system (consolidated for new, incremental for upgrades)
- **Two-Factor Authentication (2FA) System**:
  - TOTP (Time-based One-Time Password) implementation
  - Email backup codes for 2FA recovery
  - 2FA verification attempts tracking with rate limiting
  - 2FA policy settings (optional/required/disabled)
  - Complete 2FA setup, verification, and management flow
  - Backup codes generation and verification system
- **CAPTCHA Security System**:
  - Support for reCAPTCHA v2, reCAPTCHA v3, and Cloudflare Turnstile
  - Configurable CAPTCHA settings in admin panel
  - Score-based verification for reCAPTCHA v3
  - Integration with login and registration forms
  - CAPTCHA provider selection and configuration
- **Domain Tags System**:
  - Domain tagging for organization and categorization
  - Comma-separated tags field in domains table
  - Tag-based domain filtering and organization
  - Indexed tag searches for performance
- **Advanced Error Logging System**:
  - Database-backed error logging and tracking
  - Error deduplication and occurrence counting
  - Request context capture (method, URI, data)
  - User context (IP, user agent, session data)
  - System context (PHP version, memory usage)
  - Error resolution tracking and management
  - Admin error log interface for debugging
- **Enhanced Logger Service**:
  - Structured logging with context arrays
  - Multiple log levels (debug, info, warning, error, critical)
  - Date-based log file rotation
  - Context-aware logging throughout the application
  - JSON-formatted log entries with timestamps
- **User Avatar System**:
  - Avatar upload and deletion functionality
  - Gravatar integration with fallback to user initials
  - Dynamic web root detection for file uploads
  - Avatar display in profile, navigation, and user listings
  - File validation and security measures
- **WHOIS Parsing Improvements**:
  - Enhanced WHOIS data parsing and processing
  - Better referral server handling and following
  - Improved domain availability detection
  - Status parsing cleanup and consistency
  - WHOIS server display improvements

### Changed
- Profile page completely redesigned with sidebar layout
- Session system migrated from file-based to database-backed
- Top navigation dropdown links updated with hash navigation
- Settings → System tab now shows auto-detected cron paths
- Help & Support menu links to GitHub repository
- Auth views refactored with base layout
- System section (Settings/Users) restricted to admins
- TLD Registry read-only for regular users
- Sidebar shows role-based links
- Profile integrated with dashboard layout
- Installation now via web UI instead of CLI
- Auto-redirect to installer on first run
- Domain management enhanced with tagging system
- Error handling improved with comprehensive logging
- WHOIS parsing enhanced with better data extraction
- User interface updated with avatar display throughout

### Security
- **Database Session Storage** - True session control with remote termination
- **Session Validation** - Every request validates session exists in database
- **Geolocation Logging** - Track suspicious login locations
- **Remember Token Linking** - Tokens tied to sessions, deleted together
- **Immediate Logout** - Deleted sessions invalidated within seconds
- Bcrypt password hashing
- Secure 32-byte tokens
- Time-limited tokens
- One-time use reset tokens
- HttpOnly secure cookies
- Email enumeration protection
- Session-based verification resend
- Admin-only route protection
- **Two-Factor Authentication** - TOTP and email backup codes for enhanced security
- **CAPTCHA Protection** - Anti-bot protection for login and registration
- **Advanced Error Logging** - Comprehensive error tracking and debugging
- **File Upload Security** - Avatar upload validation and secure file handling

### Technical
- **MVC Architecture Refactoring** - Complete separation of concerns
  - `LayoutHelper` - Global layout data (notifications, stats, settings)
  - `DomainHelper` - Domain formatting and business logic
  - `SessionHelper` - Session display formatting
  - `NotificationService` - Notification creation and management
  - All business logic removed from views (~265 lines cleaned)
- Database session handler implementing SessionHandlerInterface
- IP geolocation via ip-api.com (free, 45 req/min)
- Session validator middleware for real-time validation
- Automatic session cleanup (no cron needed for sessions)
- Flag-icons library integration for country flags
- User-agent parsing for device and browser detection
- Remember token cascade deletion on session termination
- Notification system with 7 notification types
- Welcome notifications on user creation and fresh install
- Upgrade notifications for admins with version tracking
- **TwoFactorService** - Complete 2FA implementation with TOTP and backup codes
- **CaptchaService** - Multi-provider CAPTCHA verification system
- **ErrorHandler** - Centralized error handling with database logging
- **Logger** - Enhanced logging service with structured context
- **AvatarHelper** - User avatar management with Gravatar integration
- **Tag Model** - Domain tagging system with user isolation
- **ErrorLog Model** - Error tracking and deduplication system

### Contributors
- Special thanks to @jadeops for auto-detected cron path improvement & XSS protection enhancement (PR #1)

## [1.0.0] - 2024-10-08

### Added
- Initial release of Domain Monitor
- Modern PHP 8.1+ MVC architecture
- Domain management system with CRUD operations
- Automatic WHOIS lookup for domain information
- Multi-channel notification system:
  - Email notifications via PHPMailer
  - Telegram bot integration
  - Discord webhook support
  - Slack webhook support
- Notification groups feature
- Assign domains to notification groups
- Dashboard with real-time statistics
- Domain status tracking (active, expiring_soon, expired, error)
- Notification logging system
- Customizable notification intervals
- Cron job for automated domain checks
- Test notification script
- Responsive, modern UI design
- Database migration system
- Comprehensive documentation
- Installation guide
- Basic login/logout authentication
- Security features (prepared statements, session management)
- **TLD Registry System with IANA integration**
  - Import and manage TLD data (RDAP servers, WHOIS servers, registry URLs)
  - Progressive import workflow with real-time progress tracking
  - Support for 1,400+ TLDs with automatic updates
  - Import logs and history tracking
- Advanced domain verification using TLD registry data
- RDAP protocol support for modern domain queries
- Automatic WHOIS server discovery per TLD
- Monitoring status change notifications (activated/deactivated alerts)
- Notification group assignment change alerts
- Enhanced domain detail view with channel status indicators
- Comprehensive notification threshold configuration
- Debug logging for notification thresholds

### Changed
- Unified design system across all views
  - Consistent header styles (bordered instead of gradients)
  - Standardized button sizes and padding
  - Consistent form input styling
  - Unified empty state designs
  - Removed emojis from UI elements
- Improved navigation flow (edit page returns to detail view)
- Enhanced cron job logging with threshold display
- Streamlined installation process
  - Encryption key auto-generation during migration
  - No separate script needed for encryption key setup

### Fixed
- Notification channel type display error in domain view
- Navigation redirect after domain update
- Cancel button redirect in domain edit page
- Design inconsistencies in notification group views

### Security
- Random secure password generation on installation
- One-time password display during migration
- Removed hardcoded default credentials
- 16-character cryptographically secure admin passwords

### Features
- ✅ Add, edit, delete, and view domains
- ✅ Automatic expiration date detection via WHOIS
- ✅ Support for multiple notification channels per group
- ✅ Flexible notification scheduling (60, 30, 21, 14, 7, 5, 3, 2, 1 days before)
- ✅ Email notifications with HTML templates
- ✅ Rich Discord embeds with color coding
- ✅ Telegram messages with formatting
- ✅ Slack blocks for structured messages
- ✅ Notification deduplication (prevent spam)
- ✅ Manual domain refresh
- ✅ Active/inactive domain toggle
- ✅ Comprehensive logging
- ✅ Statistics dashboard
- ✅ Recent notifications view
- ✅ Domain details with WHOIS data
- ✅ Nameserver display
- ✅ Notification history per domain

### Technical
- PHP 8.1+ with modern features (match expressions, typed properties)
- MySQL/MariaDB database
- PSR-4 autoloading
- Environment-based configuration
- MVC pattern implementation
- Service layer architecture
- Repository pattern for data access
- Interface-based notification channels
- JSON configuration storage
- Prepared statements for SQL injection prevention
- CSRF token support ready
- Responsive CSS with CSS variables
- No JavaScript framework dependencies (vanilla JS where needed)

### Documentation
- README.md with comprehensive guide
- Inline code documentation
- Configuration examples
- Troubleshooting guide

---

## Roadmap - Future Enhancements

- [x] User authentication system (completed - v1.1.0)
- [x] Session management with geolocation (completed - v1.1.0)
- [x] TLD Registry System (completed - v1.0.0)
- [x] Remote session termination (completed - v1.1.0)
- [x] In-app user notifications (completed - v1.1.0)
- [ ] Multi-user support with advanced permissions and roles
- [ ] API for external integrations
- [x] Domain grouping/tagging (completed - v1.1.0)
- [ ] Custom notification templates
- [ ] SMS notifications (Twilio)
- [x] Google Chat notifications (completed - v1.1.2)
- [ ] WhatsApp notifications
- [x] Export functionality (CSV, JSON) (completed - v1.1.3, TLD Registry - v1.1.4)
- [x] Import domains from CSV/JSON (completed - v1.1.3, TLD Registry - v1.1.4)
- [ ] Domain transfer tracking
- [x] DNS record monitoring (completed - v1.1.5)
- [x] SSL certificate monitoring (completed - v1.1.5)
- [ ] Downtime monitoring
- [x] 2FA for login (completed - v1.1.0)
- [ ] Mobile app
- [ ] Docker support
- [ ] Redis caching
- [ ] Rate limiting
- [ ] Webhook support for third-party integrations
- [ ] Dark mode UI toggle
- [ ] Multi-language support
- [x] Advanced filtering and search (completed - v1.1.0)
- [x] Bulk operations (completed - v1.1.0)
- [ ] Scheduled reports
- [ ] Integration with domain registrars

---

## Version History

### 1.1.6 (2026-11-04)
- **Renewal Alerts** - "Domain Renewed" when the expiration date moves later (#74)
- **Watchlists** - Notification groups for domains you want to acquire, reminders after expiry instead of a daily "expired" alert (#95)
- **Wildcard DNS** - Subdomains that only answer through a wildcard are no longer listed (#76)
- **SSL on the Dashboard** - Five certificates closest to expiry, with status counts (#77)
- **SSL Expiry Reminders** - Configurable reminder days, default 14, 7, 3, 1 (#72)
- **Alert Types per Group** - Expiration, status, DNS and SSL alerts switch on or off per notification group (#88)
- **Cert Spotter** - DNS deep scans ask Cert Spotter as well as crt.sh and merge the results (#100)
- **SSL Tools** - Bulk enable/disable, new-domain default, lock on the Domains list, readable errors
- **DNS Records** - Add, delete, import zone files, background deep scans; quieter first scan
- **WHOIS** - Failure reasons shown, second-level ccTLDs such as .com.br accepted (#90, #46, #91)
- **Alerts** - Proper titles, readable statuses and colours in every channel
- **UI** - Themed tooltips and dropdowns, redesigned bulk action bars
- **Security** - Session fixation, installer CSRF, open redirect and isolation fixes
- **Fixes** - Expiry reminders a day early, reminders for expiration dates set by hand, updater with open_basedir (#73), alerts after a manual refresh, SSL reminders after a manual check, login with a custom `DB_PORT`, DNS crons on Windows, "remember me" with 2FA, .sk dates
- Migrations: `029_add_dns_record_source.sql`, `030_add_ssl_reminders_and_group_alert_types.sql`, `031_add_ct_log_sources.sql`, `032_add_renewal_notifications.sql`, `033_add_watchlist_groups.sql`

### 1.1.5 (2026-03-08)
- **Twig Templating** - All PHP views migrated to Twig; Twig-only rendering with safe error fallback
- **DNS Monitoring** - DnsService, DnsRecord model, crt.sh discovery, Cloudflare detection, per-domain toggle, `cron/check_dns.php`
- **SSL Certificate Monitoring** - SslService, SslCertificate model, add/refresh/delete endpoints, `cron/check_ssl.php`
- **Domain View Tabs** - Overview, DNS, Billing, Notifications, SSL, WHOIS; `domain_view_template` setting (detailed/legacy)
- **Cron Staleness Warnings** - Settings shows overdue warnings for domain/DNS/SSL cron runs
- **Timezone on Installer** - App timezone applied on `/install` and `/install/update` when installed
- **2FA/Settings** - Twig templates for 2FA, timezone lists, notification presets, cron path in Settings
- Migrations: `027_add_dns_monitoring.sql`, `028_add_ssl_monitoring.sql`

### 1.1.4 (2026-03-02)
- **TLD Registry Import & Export** - CSV/JSON export/import for TLD entries with WHOIS, RDAP, registry URL data
- **Manual TLD Creation** - Modal form to add custom TLDs with multi-level support (.co.uk, .co.za, .com.au)
- **IANA Dropdown** - Consolidated IANA operations (Import TLDs, Check Updates, Import Logs) into a single dropdown
- **Standardized Import/Export Logging** - Consistent `Logger` usage across Tags, Domains, Notification Groups, and TLD Registry
- **TLD Registry Action Bar Redesigned** - Cleaner layout: IANA (indigo), Export (emerald), Import, Create TLD
- Migration: `026_update_app_version_v1.1.4.sql`

### 1.1.3 (2026-02-11)
- **CSV/JSON Import & Export** - Domains, Tags, and Notification Groups with drag-and-drop file upload
- **Sensitive Data Masking** - API tokens and webhook URLs masked in group exports; masked channels imported as disabled
- **In-App Update System** - Check, apply, and rollback updates from Settings (GitHub Releases + hotfix tracking)
- **Update Channels** - Stable (releases only) or Latest (releases + hotfixes) with configurable badge
- **File & Database Backup** - Automatic backup before every update, one-click rollback
- **Update Notifications** - In-app alerts for admins when new releases or hotfixes are detected
- **Tag Transfer** - Admin-only individual and bulk transfer of tags between users
- **Domain Bulk Transfer** - Admin-only bulk transfer of domains to another user
- **Bulk Action Bars Redesigned** - Consistent inline toolbar styling across all list pages
- **Installer Hardened** - Admin auth required post-install; re-installation blocked
- **ErrorHandler Improvements** - Recursion guard, graceful fallback logging, `\Throwable` catch
- Migration: `025_add_update_system_v1.1.3.sql`

### 1.1.2 (2026-02-09)
- **Google Chat Webhook Support** - Selectable payload formats (Generic, Google Chat, Simple Text)
- **Domain Status Change Notifications** - Configurable alerts for available, registered, expired, redemption_period, pending_delete
- **Failed Login Notifications** - In-app alerts with geolocation, device info, and failure reason
- **Domain Expiration Bell Notifications** - In-app alerts respecting user isolation mode
- **Admin User Profile Page** - `/users/{id}` with Overview, Domains, Tags, Notification Groups tabs
- **Dashboard Insights** - Registrar distribution, tag usage, notification coverage widgets
- **Quick Actions Dropdown** - Top-nav shortcut for Add Domain, Create Group, Create Tag, WHOIS Lookup
- **WHOIS Rate Limit Handling** - Exponential backoff with TLD-grouped retry logic
- **Admin TLD Registry Editing** - Edit WHOIS/RDAP servers from UI
- **Redemption Period & Pending Delete** - New domain lifecycle statuses from EPP codes
- **Sidebar Branding** - Logo, title, and subtitle in sidebar navigation
- **Mobile UI Overhaul** - Sidebar overlay, swipe-to-close, responsive layout improvements
- **CSRF Protection** - POST method enforced on all destructive actions
- **Error Log Deduplication** - Improved matching on type + file + line + message
- **WHOIS Date Parsing** - DD/MM/YYYY format support for European registries
- **404 Error Logging** - Router logs with full request context
- Migration: `024_add_status_notifications_v1.1.2.sql`

### 1.1.0 (2025-10-09)
- **User Notifications System** - In-app notification center with 7 notification types, filtering, pagination
- **Advanced Session Management** - Database-backed sessions with geolocation (country, city, ISP)
- **Remote Session Control** - Terminate any device instantly with immediate logout validation
- **Enhanced Profile Page** - Sidebar navigation with 4 tabs, hash-based routing (#profile, #security, #sessions)
- **Two-Factor Authentication** - Complete TOTP implementation with email backup codes and rate limiting
- **CAPTCHA Security System** - Support for reCAPTCHA v2/v3 and Cloudflare Turnstile with admin configuration
- **Domain Tags System** - Organize domains with custom tags for better categorization and filtering
- **Advanced Error Logging** - Database-backed error tracking with deduplication, context capture, and admin interface
- **User Avatar System** - Avatar upload with Gravatar integration and fallback to user initials
- **Enhanced Logger Service** - Structured logging with context arrays and multiple log levels
- **WHOIS Parsing Improvements** - Enhanced domain data parsing, referral handling, and availability detection
- **MVC Architecture Refactoring** - 3 new Helpers (Layout, Domain, Session), ~265 lines cleaned from views
- **Geolocation Tracking** - IP-based location detection using ip-api.com, country flags with flag-icons
- **Device Detection** - Browser & device type parsing (Chrome/Firefox/Safari, Desktop/Mobile/Tablet)
- **Auto-Detected Cron Paths** - Settings show actual installation paths (thanks @jadeops)
- **Welcome Notifications** - Sent to new users on registration or fresh install
- **Upgrade Notifications** - Admins notified on system updates with version & migration count
- **Web-Based Installer** - Replaces CLI, auto-generates encryption key, one-time password display
- **Web-Based Updater** - `/install/update` for running new migrations with smart detection
- **User Registration** - Full signup flow with email verification, password reset, resend verification
- **User Management** - CRUD for users with filtering, sorting, pagination (admin-only)
- **Remember Me** - 30-day secure tokens linked to sessions, cascade deletion on logout
- **Session Validator** - Middleware validates sessions on every request for instant remote logout
- **Consistent UI/UX** - Unified filtering, sorting, pagination across Domains, Users, Notifications, TLD Registry
- **Smart Migrations** - Consolidated schema for fresh installs, incremental for upgrades
- **XSS Protection** - htmlspecialchars() applied across all user-facing data (thanks @jadeops)

### 1.0.0 (2024-10-08)
- Initial public release
- Created by [Hosteroid](https://www.hosteroid.uk) - Premium Hosting Solutions

---

## 🙏 Special Thanks

### Contributors
- **@jadeops** - Auto-detected cron path improvement & XSS protection enhancement (PR #1)

