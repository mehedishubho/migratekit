=== MigrateKit ===
Contributors: mehedishubho
Tags: migrate, migration, backup, restore, export, import, multisite, clone
Requires at least: 5.6
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 0.0.13
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

One-click WordPress migration, backup and restore. Everything included, free, forever — no extensions.

== Description ==

MigrateKit packages your entire WordPress site — database, media, themes, plugins and configuration — into one archive you can download, store in the cloud, or restore with one click. No extension plug-ins, no license keys, no paid gates.

* **Site migration** — export a complete site and import it anywhere. URL and database replacement is serialized-safe, custom table prefixes are handled, and big archives stream in through resumable chunked uploads that work around host upload limits.
* **Backups** — every export lands in a protected backups directory. Label, rename, browse inside an archive, download a single file, or bulk-delete from the Backups page. Restore any backup in place with live progress.
* **Cloud storage** — publish to, and restore from, any S3-compatible bucket (AWS, MinIO, Backblaze B2, Wasabi, DigitalOcean Spaces, …), Dropbox or Google Drive. Secrets are stored safely masked, so OAuth tokens survive settings edits.
* **Scheduled backups** — hourly to monthly runs with retention by count or age, email notifications, optional cloud copies and network-wide scope on multisite.
* **Reset hub** — wipe content tables, transients, optional media files, non-admin users or third-party tables, behind a typed confirmation and an automatic safety backup.
* **Multisite, included** — export the whole network or a single subsite, import either one back (networks stay networks, subsites land on standalone sites), with per-site URL derivation.
* **Password-protected archives** — AES-256-CBC archive encryption on every export; imports verify the signature before touching the database.
* **WP-CLI and REST** — scriptable export, import, restore, backups, labels, archive listing, full cloud control and a complete REST API for automation.
* **Maintenance screen** — while an import or restore runs, visitors see a brief maintenance page (with a self-expiring guard) instead of a half-replaced site.

== Installation ==

1. From your dashboard go to Plugins, then **Add New**, then **Upload Plugin**, and choose the MigrateKit ZIP file. (Or unzip the folder into `wp-content/plugins/migratekit`.)
2. Activate the plugin.
3. Open the **MigrateKit** menu in the admin sidebar.
4. To migrate or back up: go to **Export**, choose your options (compression, password, exclusion filters, find/replace pairs), and start the export. The finished archive is kept in the Backups page, and you can download it from there.
5. To move the site: install MigrateKit on the destination, go to **Import**, upload the archive, confirm the warning, and follow the progress.
6. For cloud storage, open the **Cloud** tab, pick a provider and either enter S3-style credentials or connect the Dropbox / Google Drive app.

== Frequently Asked Questions ==

= Is there an archive size limit? =

Nothing plugin-side. Uploads are chunked and resume if a connection drops, so host upload limits are not a blocker. What remains is your host's disk space and execution time.

= Where are my backups stored? =

In `wp-content/migratekit-backups`, protected from the web with `.htaccess`, `web.config` and `robots.txt` guards. Backups are never deleted by the plugin; deleting is something you do from the Backups page.

= Can I import an archive made by a different .wpress tool? =

Yes. Plain (uncompressed) and gzip-compressed `.wpress` archives cross-read both ways. Password-protected archives work too, using their password.

= Does it work on multisite? =

Yes, natively: export the whole network or a single subsite, then import back into a network or a standalone site. The plugin refuses the two combinations that would damage a network.

= Does it change my live data during export? =

No. Exports read from the database and files; find/replace pairs are applied to the generated dump, not to your working site.

= What does the host need? =

PHP 7.4 or newer (8.2+ recommended) with the standard `openssl`, `zlib` and `bzip2` extensions, and MySQL/MariaDB.

= Can I use WP-CLI? =

Yes: `wp migratekit export|backup|import|restore|backups|label|files|reset` plus `cloud list|test|files|push|pull`, including the full export option set as command flags.

== Changelog ==

= 0.0.13 =
* Large sites no longer stall: file listing, the database dump, packing and the final checksum all run in small resumable slices instead of one long request.
* A backup interrupted by a server limit or a closed tab can be resumed where it stopped — the archive is repaired to its last safe point, never corrupted.
* Database dumps page by primary key, so tables with millions of rows stay fast and memory stays flat.
* A database error now fails the backup with a clear message instead of silently leaving a table out of the dump.
* Pre-flight disk space check with a clear message when the server cannot hold the backup.
* Folders of other backup plugins (All-in-One WP Migration, UpdraftPlus, Duplicator, BackWPup, …) are excluded from backups instead of being swept in.
* Scheduled backups run across many short cron requests, so hosts with short time limits can complete them.
* Transient failures retry automatically with growing pauses, and the backup log no longer grows with every file.
* Fixed the site-info REST endpoint failing on every install.

= 0.0.12 =
* Safety-first dashboard: the age of your last backup is the headline number, and "Back up now" is the single primary action.
* Running backups can be stopped mid-run, resume automatically after a page refresh, and offer one-click retry when they fail — with a guard against closing the tab mid-backup.
* Stat tiles refresh after every run (no more "0 backups" next to a success message) and failed loads get a red state with a Try again link.
* Clearer dashboard copy: the migration button names its destination ("Export this site"), and the Reset link states its consequence before you click it.
* Accessibility: keyboard focus indicators on every control, screen-reader progress announcements at stage milestones instead of every tick, and active-state colors that meet WCAG AA contrast in both light and dark schemes.
* New `migratekit_job_cancel` AJAX endpoint backs the Stop control (REST already supported cancellation).

= 0.0.11 =
* Responsive admin layout — the app now fills wide screens and adapts to tablets and phones instead of hugging a fixed width.
* Live progress for exports, imports, restores and cloud transfers: the percentage climbs continuously instead of sitting at 0% until the end.
* Reliable backup downloads: output buffering or compression enabled by other plugins can no longer corrupt or block the downloaded archive.
* Exports and imports survive transient request failures — a stalled step retries instead of aborting the whole job.
* Fixed the Dropbox tab showing no configuration options on fresh installs — the own-app key/secret form now appears immediately, with a link to the Dropbox app console.

= 0.0.1 =
* Initial public release.
* One-click export/import with serialized-safe replacement and resumable chunked uploads.
* Backups page with labels, archive browser, single-file download and bulk delete.
* Cloud storage for S3-compatible buckets, Dropbox and Google Drive, with scheduled cloud copies. Dropbox connects in one click (paste the shown authorisation code; no app setup), Google Drive accepts a service-account JSON key the same way, and S3 settings gained guided console links.
* Scheduled backups with retention rules, email notifications and network scope — an armed Next-run indicator with cron-health warnings, plus an in-browser Run now with live progress.
* Dashboard landing page with a one-click backup, a guided migration path and a one-click daily starter schedule.
* Reset Hub with safety backup and typed confirmation.
* Native multisite support (whole network or per-site).
* Password-protected (AES-256-CBC) archives.
* WP-CLI commands and a REST API covering every operation.
* Maintenance screen during imports and restores.
* Explanatory admin notice when the compiled front-end is missing (for example after installing from a source archive instead of the release ZIP).

== Upgrade Notice ==

= 0.0.12 =
Stop/resume for running backups, one-click retry after failures, and a safety-first dashboard. Update recommended.

= 0.0.11 =
Responsive layout for all screens, live progress percentages and reliable backup downloads. Update recommended.

= 0.0.1 =
First public release.
