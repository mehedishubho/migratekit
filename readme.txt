=== MigrateKit ===
Contributors: mehedishubho
Tags: migrate, migration, backup, restore, export, import, multisite, clone
Requires at least: 5.6
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 1.0.0
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

= 1.0.0 =
* Initial public release.
* One-click export/import with serialized-safe replacement and resumable chunked uploads.
* Backups page with labels, archive browser, single-file download and bulk delete.
* Cloud storage for S3-compatible buckets, Dropbox and Google Drive, with scheduled cloud copies.
* Scheduled backups with retention rules and email notifications.
* Reset Hub with safety backup and typed confirmation.
* Native multisite support (whole network or per-site).
* Password-protected (AES-256-CBC) archives.
* WP-CLI commands and a REST API covering every operation.

== Upgrade Notice ==

= 1.0.0 =
First public release.
