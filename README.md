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
| WordPress admin (Vue 3 + Vite: Dashboard, Export/Import/Backups, Cloud, Schedules, Reset — live progress that outlives the page it started on, cron-health warnings, one-click starter schedule) | Done |
| WordPress wiring (menu, asset loading, AJAX job endpoints, backup download) | Done |
| REST API (`migratekit/v1`: async jobs, logs, backups, one-click restore) | Done |
| WP-CLI (`export`, `backup`, `import`, `restore`, `backups`, `label`, `files`, `reset`; `cloud list/test/files/push/pull`) | Done |
| Scheduled backups (hourly → monthly), retention by count/age, email notifications, network scope, Schedules UI — cron runs plus an in-browser Run now with live progress | Done |
| Reset Hub (site reset: content tables, transients, optional media files/users/plugin tables; safety backup; typed confirmation) | Done |
| Cloud storage (S3-compatible + Dropbox + Google Drive: push/pull/restore, settings UI, WP-CLI, scheduled cloud copies) | Done |
| Multisite (whole-network and per-site export/import, native and bundled) | Done |
| Parity audit vs All-in-One WP Migration 7.112 | Done — see `docs/parity.md` |

### Reset Hub notes

- The safety backup is a database-only archive; enable *remove media files* and the backup includes media too.
- Reset keeps users, theme/plugin settings and WordPress configuration; opt-in flags remove non-admin users, uploads files or third-party tables sharing the table prefix.
- Delete fallback (DELETE + AUTO_INCREMENT reset) covers foreign keys and truncation errors; users are never touched on multisite networks.

### Cloud storage notes

- Supported backends: any S3-compatible storage (AWS S3, MinIO, Backblaze B2 via S3 API, Wasabi, …), Dropbox and Google Drive.
- Push and pull are long-running cloud jobs with live progress, like export/import. Push publishes a local archive to the provider; pull reassembles a remote archive into a local `.wpress` file in the backups directory.
- Every backup the storage lists has a **Restore** action next to Pull here: the same pull, followed by a restore that starts by itself once the archive has arrived. Stopping it during the fetch restores nothing.
- Provider settings live in one option (`migratekit_cloud`) and are masked in every REST/API surface — a `********` value or an empty secret keeps the stored secret on save, so OAuth tokens always survive settings edits.
- S3 talks SigV4 with unsigned payloads and always-multipart uploads, so plain-HTTP endpoints (MinIO on LAN) work out of the box.
- Dropbox/Google Drive use OAuth2 with admin-post callbacks; the app must whitelisted the site's callback URL, which the Cloud page shows.
- **Easy connect paths (AI1WM-style, no user app setup):**
  - Dropbox: the distribution can embed a public App key (`MIGRATEKIT_DROPBOX_APP_ID`); "Connect Dropbox" then opens the consent page with PKCE, and the user only pastes the displayed authorisation code. Token exchanges and refreshes are keyless (app key + verifier). A "Use my own Dropbox app" panel retains the full client-id/secret flow.
  - Google Drive: the recommended mode is a **service account** — paste the JSON key, share a Drive folder with the service-account address, done. Tokens are minted on demand with RS256-signed JWTs; the classic OAuth client flow stays available as a toggle.
- Known limitation: if the site crashes mid-chunk on Dropbox/Drive, the provider-side upload cursor desyncs from the job state (S3 self-heals by listing parts). Restarting the push job (new session, overwrite mode) recovers either way.
- Scheduled backups can name a cloud provider; each successful run publishes the new archive there automatically and appends the result to the schedule status and notification email.

### Jobs and the admin pages

- Every job the admin pages start — backups, restores, imports, pushes, pulls — is written down in the `migratekit_active_jobs` option, and the server keeps its progress. A page that loads asks which jobs are still open and carries on the ones that were merely waiting for a page, so the row with its progress bar is back after a reload, whichever MigrateKit page is opened.
- Moving between MigrateKit pages does not interrupt a job. A reload, or a move away, while a job runs gets the browser's own "leave site?" dialog.
- A job only advances while a page sends its step requests, so work with no page open waits on the server and is listed under **Unfinished tasks** with Resume. A job not touched for 12 hours (30 minutes for a restore) is listed but not continued unasked; a job whose step failed is listed with its reason and runs again only on Resume.
- The one thing a reloaded page cannot continue is an upload, because the rest of the file lives in the browser. The row says so, and choosing the same file on the Import page sends only the rest.
- A restore started from the cloud is a pull whose last step makes the restore a job in its own right, under an id reserved beforehand so that a request cut in between cannot make two. The database stage of an import replaces the options table, taking the job's own record and the registry with it: the runner writes both back afterwards and flushes the object cache.
- An encrypted archive is found to be encrypted only once its restore is under way. The row then asks for the password, drops the attempt made without it and starts the restore again with the password, in the same row; a wrong password is asked again, and Resume on such a row reads "Enter password".
- `migratekit_step_seconds` filters how long one step request may work (8 seconds by default). The smoke tests lower it so that a small site's jobs are cut across requests the way a large site's are.

### Cloud tooling

- `php tests/run-cloud.php` — 223-check offline suite: SigV4 derivation invariants, request shaping with a fake transport (including the S3 connection test's write check and the guidance added to a 403), provider semantics (including the PKCE paste-code connection, service-account JWT minting and upload sessions the storage has forgotten), push/pull pipelines, and transfers a reload cut off (lost sessions, stale offsets, overlong files).
- `tools/minio-integration.php` — real S3 round trip (connection test, an abandoned upload discarded, multipart upload, listing, ranged download, delete) against any S3 endpoint; driven by `S3_ENDPOINT`, `S3_ACCESS_KEY`, `S3_SECRET_KEY`, `S3_BUCKET`, `S3_REGION` env vars.
- `tools/wp-cloud-smoke.php` — run inside WordPress via `tools/wp-cloud-test.sh`; exercises the cloud REST routes, settings masking and a live push/pull round trip when `MK_CLOUD_*` env vars point at reachable storage.
- `tools/wp-reload-smoke.php` — run inside WordPress with `wp eval-file` (its cloud part needs the same `MK_CLOUD_*` env vars); calls the AJAX handlers the way a reloaded page does, from the open-jobs listing alone: a backup, a waiting upload, a failed step, a pull that restores in one go and is cut across requests, the registry surviving the database import, and a restore started again with its password.

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
php tests/run.php            # archive engine
php tests/run-database.php   # database layer
php tests/run-pipeline.php   # export pipeline
php tests/run-import.php     # import pipeline
php tests/run-storage.php    # backups + job ids
php tests/run-reset.php      # reset pipeline
php tests/run-download.php   # single-file downloads from a backup
php tests/run-cloud.php      # cloud providers + push/pull
php tests/run-multisite.php  # multisite scope/mapping/guards
php tests/run-parity.php     # parity audit (filters, chunks, browser, labels, jobs outliving their page)
```

`sh tests/run-all.sh` runs them one after another and prints each summary line. On a Windows PHP that has no `OPENSSL_CONF` of its own, set it first (`OPENSSL_CONF=<php folder>/extras/ssl/openssl.cnf`); otherwise the cloud suite stops where it generates a key.

`tools/interop-check.php` drives the reference plugin's own compressor/extractor to verify byte compatibility in both directions.

`tools/mysql-integration.php <host> <user> <pass>` runs a real MariaDB dump/import round-trip (prefix change, URL replacement, serialized data, blobs, NULLs, exclusions). It also pages a 20,000-row table and tables with composite, text, decimal and binary keys, and checks that a dump written one page per request is byte-identical to one written in a single pass.

`tools/wp-smoke.php`, `tools/wp-import-smoke.php`, `tools/wp-rest-smoke.php`, `tools/wp-schedule-smoke.php`, `tools/wp-reset-smoke.php` and `tools/wp-cloud-smoke.php` are run inside a real WordPress with `wp eval-file` to exercise the job runner end to end (`tools/wp-*-test.sh` drive each one). `tools/wp-long-smoke.php` does the same for a scheduled backup too big for one request: 60,000 files and a 500 MB incompressible file, with the cron requests that carry it along driven by hand. `tools/wp-reload-smoke.php` (under *Cloud tooling* above) does it for a job outliving the page that started it.

When the test install mounts the repository as the plugin (`tools/docker/smoke-compose.yml`), mount an empty volume over `wp-content/plugins/migratekit/storage` as well. Activating the plugin in the test install runs its storage cleanup, which deletes every job folder older than a week that the test install knows nothing about — including the real ones in the repository's `storage/`.

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
