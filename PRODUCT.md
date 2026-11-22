# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Two audiences, confirmed equally primary:

- **DIY site owners** — non-experts managing their own WordPress site who need to move, back up, or restore it without losing data. They rely on guided flows, plain-language warnings, and safe defaults.
- **Freelancers and agencies** — professionals moving many client sites. They value speed, precision, WP-CLI and REST automation, and full control over export/import options.

## Product Purpose

MigrateKit is a free WordPress plugin for one-click site migration, backup, and restore. It packages the entire site — database, media, themes, plugins, configuration — into a single password-protectable archive that can be downloaded, stored in the cloud, or restored with one click. Success means a best-in-class reputation: the known free alternative to paid migration plugins, recognized for craft and reliability.

## Positioning

Every feature that competing plugins (notably All-in-One WP Migration) sell as separate paid extensions is included here for free — no extension system, no license keys, no upsells, no size gates. Archives use the interoperable `.wpress` format and cross-read with other tools, so there is no vendor lock-in.

## Operating Context

- Runs inside the WordPress admin (wp-admin) as a plugin; PHP 7.4+ (8.2+ recommended) with `openssl`, `zlib`, `bzip2`; MySQL/MariaDB.
- Host environments with aggressive upload limits are the norm — hence resumable chunked uploads and streaming jobs.
- Multisite networks (whole-network and per-subsite workflows) are a first-class context, including the network admin.
- Automation contexts: WP-CLI (`wp migratekit …`) and REST API (`migratekit/v1`) cover every operation.
- Cloud storage context: S3-compatible buckets, Dropbox, Google Drive — including LAN endpoints like MinIO.
- Reference competitor (All-in-One WP Migration 7.112) is vendored in-repo for parity auditing.

## Capabilities and Constraints

- Export/import pipelines are resumable and retry transient failures; progress reports live percentages.
- Backups live in a protected `wp-content/migratekit-backups` directory; the plugin never deletes them automatically.
- Scheduled backups: hourly to monthly, retention by count or age, email notifications, optional cloud copies, network-wide scope.
- Reset Hub wipes content behind a typed confirmation plus an automatic safety backup.
- Multisite import guards refuse the two archive/target combinations that would damage a network.
- Archives: AES-256-CBC optional encryption; plain and gzip `.wpress` cross-read with other tools.
- Known limitation: a mid-chunk crash on Dropbox/Drive pushes desyncs the provider-side cursor (S3 self-heals); restarting the push job recovers.
- Maintenance screen with self-expiring guard shields visitors during imports/restores.
- Permanent product commitment: **free, forever — no paid tier, no extension marketplace, no license keys, ever** (user-confirmed).

## Brand Commitments

- Name: **MigrateKit** (binding). Plugin URI getMigrateKit.com; author MEHEDI HASSAN SHUBHO (wpmhs.com).
- License: GPL-3.0-or-later.
- Voice in readme: direct, plain-language, anti-upsell ("Everything included, free, forever — no extensions.").
- No logo or visual identity assets exist yet; the admin menu currently uses the `dashicons-migrate` placeholder.

## Evidence on Hand

- `docs/parity.md` — parity audit vs All-in-One WP Migration 7.112.
- `readme.txt` / `README.md` — full feature and changelog record (WordPress.org-style).
- Offline test suites (`tests/run-cloud.php` 148 checks, `tests/run-multisite.php` 48 checks) and real-environment smoke tooling (`tools/`).
- Absences future work must not fabricate: no testimonials, no user/download counts, no case studies, no press, no logo or brand imagery.

## Product Principles

1. **Everything included, free forever.** Feature access is never a pricing or licensing lever; no artificial size or capability gates.
2. **Safety before action.** Destructive operations sit behind typed confirmations and automatic safety backups; imports verify before touching the database.
3. **Both audiences on every capability.** Each operation exposes a guided UI path for novices and a precise, scriptable path (CLI/REST) for professionals.
4. **No lock-in.** The interoperable `.wpress` format cross-reads with other tools in both directions.
5. **Resilient by default.** Jobs are resumable, chunked, live-progressed, and survive transient host failures — assume hostile hosting environments.
