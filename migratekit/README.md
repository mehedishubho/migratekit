# MigrateKit

One-click WordPress **migration, backup and restore** — database, media, themes and plugins packaged into a single archive.

Every feature that competing plugins sell as separate paid extensions is included here for free. No extension system, no license keys, no upsells.

- **Plugin URI:** https://getMigrateKit.com
- **Author:** MEHEDI HASSAN SHUBHO — https://wpmhs.com
- **License:** GPL-3.0-or-later

## Status

Core engine, every pipeline, the cloud storage layer and multisite support are implemented and tested.

| Component | State |
|---|---|
| `.wpress` archive writer/reader (4377-byte records, gzip/bzip2, AES-256-CBC, CRC32 EOF sentinel) | Done — byte-identical to reference archives for plain and gzip; encrypted archives cross-read both ways |
| Database layer (serialized-safe replacement, SQL splitter, literal rewriter, portability normalizer, dumper, importer, MySQLi driver) | Done |
| Export pipeline (prepare → manifest → enumerate → archive content/media/plugins/themes → DB dump → finalize) | Done, resumable |
| Import pipeline (validate → CRC → password → extract → DB import with URL/path replacement) | Done |
| Backups repository, capabilities, secret key, job status | Done |
| WordPress admin (Vue 3 + Vite: Export/Import/Backups with live progress) | Done |
| WordPress wiring (menu, asset loading, AJAX job endpoints, backup download) | Done |
| REST API (`migratekit/v1`: async jobs, logs, backups, one-click restore) | Done |
| WP-CLI (`export`, `backup`, `import`, `restore`, `backups`, `label`, `files`, `reset`; `cloud list/test/files/push/pull`) | Done |
| Scheduled backups (hourly → monthly), retention by count/age, email notifications, network scope, Schedules UI | Done |
| Reset Hub (site reset: content tables, transients, optional media files/users/plugin tables; safety backup; typed confirmation) | Done |
| Cloud storage (S3-compatible + Dropbox + Google Drive: push/pull, settings UI, WP-CLI, scheduled cloud copies) | Done |
| Multisite (whole-network and per-site export/import, native and bundled) | Done |
| Parity audit vs All-in-One WP Migration 7.112 | Done — see `docs/parity.md` |

### Reset Hub notes

- The safety backup is a database-only archive; enable *remove media files* and the backup includes media too.
- Reset keeps users, theme/plugin settings and WordPress configuration; opt-in flags remove non-admin users, uploads files or third-party tables sharing the table prefix.
- Delete fallback (DELETE + AUTO_INCREMENT reset) covers foreign keys and truncation errors; users are never touched on multisite networks.

### Cloud storage notes

- Supported backends: any S3-compatible storage (AWS S3, MinIO, Backblaze B2 via S3 API, Wasabi, …), Dropbox and Google Drive.
- Push and pull are long-running cloud jobs with live progress, like export/import. Push publishes a local archive to the provider; pull reassembles a remote archive into a local `.wpress` file in the backups directory.
- Provider settings live in one option (`migratekit_cloud`) and are masked in every REST/API surface — a `********` value or an empty secret keeps the stored secret on save, so OAuth tokens always survive settings edits.
- S3 talks SigV4 with unsigned payloads and always-multipart uploads, so plain-HTTP endpoints (MinIO on LAN) work out of the box.
- Dropbox/Google Drive use OAuth2 with admin-post callbacks; the app must whitelisted the site's callback URL, which the Cloud page shows.
- Known limitation: if the site crashes mid-chunk on Dropbox/Drive, the provider-side upload cursor desyncs from the job state (S3 self-heals by listing parts). Restarting the push job (new session, overwrite mode) recovers either way.
- Scheduled backups can name a cloud provider; each successful run publishes the new archive there automatically and appends the result to the schedule status and notification email.

### Cloud tooling

- `php tests/run-cloud.php` — 99-check offline suite: SigV4 derivation invariants, request shaping with a fake transport, provider semantics, push/pull pipelines.
- `tools/minio-integration.php` — real S3 round trip (multipart upload, listing, ranged download, delete) against any S3 endpoint; driven by `S3_ENDPOINT`, `S3_ACCESS_KEY`, `S3_SECRET_KEY`, `S3_BUCKET`, `S3_REGION` env vars.
- `tools/wp-cloud-smoke.php` — run inside WordPress via `tools/wp-cloud-test.sh`; exercises the cloud REST routes, settings masking and a live push/pull round trip when `MK_CLOUD_*` env vars point at reachable storage.

### Multisite notes

Multisite support is native and bundled — no extension needed (the reference plugin gates multisite migration behind a paid extension and its free version blocks networks entirely).

- **Whole-network export** (`--network=whole`, or the Export page's *Entire network* scope picker in the network admin): one archive for every site on the network. The manifest gains two blocks —
  `Network: {Mode: subdirectory|subdomain, MainBlogId}` and a `Sites` array with, per site: `BlogId, IsMain, SiteURL, HomeURL, UploadsURL, UploadsPath, Prefix, Domain, Path`.
- **Subsite export** (`--network=subsite`, or *This site only* while working on one site of a network): stays a plain single-site archive (no `Network`/`Sites` keys) whose `Database.Prefix` is the numbered source prefix (e.g. `wp_2_`); media root at the subsite's own uploads directory.
- **Database placeholders**: multisite dumps use `SERVMASK_PREFIX_{blog_id}_posts` for subsite tables and `SERVMASK_PREFIX_posts` for base tables, so import can map every table unambiguously (longest placeholder first). Subsite archives imported into a standalone site fold the numbered prefix into the destination prefix.
- **Import semantics**:
  | Archive | Target | Behaviour |
  |---|---|---|
  | whole network | multisite network | full restore; blog IDs preserved; every site's URLs derived and rewritten (posts, options, GUIDs, usermeta, `wp_blogs.domain`) |
  | whole network | standalone site | refused — "can only be imported into a multisite network" |
  | single site | standalone site | full restore; subsite media land in the uploads root; shared user tables imported |
  | single site | multisite network | refused — "restore it into a standalone WordPress site" (avoids replacing the network's user tables) |
- **URL derivation**: subdirectory mode derives each subsite as `target main URL + source path`; subdomain mode relabels the source subsite's subdomain labels onto the target host. Subsite uploads become `target_uploads_url + sites/{id}/` (subdirectory) or the central uploads path under the derived host (subdomain). A scheme-less `old-host → new-host` pair is also emitted because `wp_blogs.domain` stores bare hostnames.
- **Known limitations**: domain-mapped subsites (source host not a subdomain of the source main host) get no derived pair and keep their source URL until a post-import search/replace; whole-network exports archive media from the central uploads directory, so files in a subsite's custom `upload_path` outside `wp-content` are only captured by a per-site export of that subsite.

### Multisite tooling

- `php tests/run-multisite.php` — 48-check offline suite: dump scoping + placeholder mapping, manifest `Network`/`Sites` blocks, options normalization, import table mappings and URL replacement (including encoded variants), and both import guards.
- `tools/ms-inspect.php <archive.wpress>` — prints an archive's manifest; handy through `wp eval-file` since the MariaDB smoke containers have no unzip/python.
- `tools/wp-ms-test.sh <network-source|network-target|plain>` — real WordPress multisite smoke (docker compose with a source network, a target network and a standalone site): provisions and seeds a network, exports whole-network and subsite archives, then verifies both import paths and both refusal guards. One WP-CLI quirk to remember: `wp eval-file` scripts cannot use `declare(strict_types=1)` — strict-typed features belong in `src/`.

### Parity audit

`docs/parity.md` carries the full feature matrix against the GPL reference (free tier + every paid extension), the edge cases each suite verifies, and the deliberate non-goals (upsell surfaces, per-provider extras beyond the three bundled clouds, the URL-import extension).

Live smoke: `tools/wp-parity-test.sh` (drive through `tools/wp-parity-test.sh` in a compose stack with the plugin mounted at `/smoke`) runs a full-flag export (compression, encryption, inactive-plugin filter, find/replace pair, table exclusion), inspects the archive via `tools/wp-parity-inspect.php`, then imports it back and verifies the replaced values, the untouched excluded table and the maintenance flag.

### Test suites

Run with any PHP 7.4+ binary (no Composer required):

```bash
php tests/run.php           # archive engine
php tests/run-database.php  # database layer
php tests/run-pipeline.php  # export pipeline
php tests/run-import.php    # import pipeline
php tests/run-storage.php   # backups + job ids
php tests/run-reset.php     # reset pipeline
php tests/run-cloud.php     # cloud providers + push/pull
php tests/run-multisite.php # multisite scope/mapping/guards
php tests/run-parity.php    # parity audit (filters, chunks, browser, labels)
```

`tools/interop-check.php` drives the reference plugin's own compressor/extractor to verify byte compatibility in both directions.

`tools/mysql-integration.php <host> <user> <pass>` runs a real MariaDB dump/import round-trip (prefix change, URL replacement, serialized data, blobs, NULLs, exclusions).

`tools/wp-smoke.php`, `tools/wp-import-smoke.php`, `tools/wp-rest-smoke.php`, `tools/wp-schedule-smoke.php`, `tools/wp-reset-smoke.php` and `tools/wp-cloud-smoke.php` are run inside a real WordPress with `wp eval-file` to exercise the job runner end to end (`tools/wp-*-test.sh` drive each one).

## Development

Requirements: PHP 7.4+, Composer, Node 18+.

```bash
composer install
npm ci
npm run build
composer test
```

## Archive format

MigrateKit reads and writes the `.wpress` container format: 4377-byte records, 512 KB chunked compression (gzip/bzip2), optional AES-256-CBC encryption, and a CRC32-verified end-of-file sentinel. Archives are interoperable in both directions with All-in-One WP Migration exports.
