# MigrateKit

One-click WordPress **migration, backup and restore** — database, media, themes and plugins packaged into a single archive.

Every feature that competing plugins sell as separate paid extensions is included here for free. No extension system, no license keys, no upsells.

* **Author:** MEHEDI HASSAN SHUBHO — https://wpmhs.com
* **License:** GPL-3.0-or-later
* **Plugin folder:** [`migratekit/`](migratekit/) — plugin entry file, sources, tests and docs
* **Developer documentation:** [`migratekit/README.md`](migratekit/README.md)
* **WordPress.org readme:** [`migratekit/readme.txt`](migratekit/readme.txt)
* **Parity audit (vs All-in-One WP Migration 7.112):** [`migratekit/docs/parity.md`](migratekit/docs/parity.md)

## Highlights

| Area | What's inside |
|---|---|
| Migration | whole-site export/import, resumable chunked uploads that bypass host limits, serialized-safe URL and table-prefix replacement |
| Backups | labels, bulk delete, archive browser with per-file download, in-place restore with live progress and an AES-protected flow |
| Cloud | any S3-compatible bucket (AWS, MinIO, Backblaze B2, Wasabi, DigitalOcean Spaces), Dropbox and Google Drive — push, pull and scheduled copies |
| Automation | hourly-to-monthly scheduled backups with retention rules, email notifications and network scope; full WP-CLI and REST control |
| Extras | Reset Hub with safety backup, native multisite support, maintenance screen while a restore replaces the site |

## Status

Complete and audited: **487 offline checks across 9 suites** green, plus a live WordPress smoke that exports with the full option set, inspects the archive, then imports it back and verifies the outcome.

```bash
# run the whole offline test matrix (any PHP 7.4+ works without docker):
docker run --rm -v "$PWD/migratekit:/work" -w /work php:8.2-cli sh tests/run-all.sh
```

## Install

Copy the `migratekit/` folder to `wp-content/plugins/` (the compiled front-end in `migratekit/build/` is required at runtime — if you clone this repo instead of copying the working tree, run `npm run build` inside `migratekit/` first), then activate from the dashboard and open the **MigrateKit** menu.
