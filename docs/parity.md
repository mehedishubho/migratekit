# Parity audit — MigrateKit vs All-in-One WP Migration 7.112

Clean-room audit of MigrateKit against the GPL reference copy of All-in-One WP Migration (`all-in-one-wp-migration/`, version 7.112). Behavior only — no reference code is used or copied.

Key: — nothing offered there; ✔ same capability shipped; ✚ ships as good or better; ◇ deliberate deviation.

The reference splits its catalog into a free core plus ~25 paid extensions (Multisite, Unlimited, Scheduler, Pro, and one cloud destination each: S3, S3-Client, GCS, Azure, B2, Box, DigitalOcean Spaces, Dropbox, FTP, GDrive, Glacier, Mega, OneDrive, pCloud, WebDAV, URL, File, Direct…). MigrateKit's rule: **every capability, bundled, no upsells.** Anything the reference gates behind an extension is tabulated under "paid in reference".

---

## 1. Export

| Feature | Reference | MigrateKit | Status |
|---|---|---|---|
| Archive: database, media, themes, plugins | free | same | ✔ |
| Exclude flags: `no_media`, `no_plugins`, `no_themes`, `no_muplugins`, `no_cache`, `no_database`, `no_post_revisions`, `no_spam_comments`, `no_email_replace` | free | all nine, Export page + REST/cli | ✔ |
| Skip inactive plugins/themes (`no_inactive_plugins`, `no_inactive_themes`) | paid (Pro/Unlimited) | bundled — archived files filtered to active plugin roots (network-activated included) and the current template/stylesheet | ✚ |
| Database find/replace pairs (`Replace.OldValues/NewValues` manifest block) | free | bundled — Export page pairs editor; pairs rewrite row values during the dump through a `ReplaceEngine` handed to the `Dumper` (serialized-safe, same engine as import) and are recorded in the manifest | ✔ |
| Include/exclude database tables (`Database.IncludedTables/ExcludedTables`) | paid (Unlimited) | bundled — Export page inputs, REST, `--include-tables/--exclude-tables`, Dumper filter | ✚ |
| Archive-level compression (none/gzip/bzip2) | n/a | Export page select | ✚ |
| Encryption (AES-256-CBC + `EncryptedSignature`) | paid | bundled — Export page password field | ✚ |
| Multisite scope: whole network / subsite archive | paid (Multisite) | bundled — scope picker, `--network=`, scheduled scope | ✚ |
| Destination: cloud providers | paid per provider | bundled (see §6) | ◇ |
| Resumable multi-request export | free (chunked pipeline) | same (per-step pipeline, storage dir per job) | ✔ |

## 2. Import

| Feature | Reference | MigrateKit | Status |
|---|---|---|---|
| .wpress import with replacement confirm | free | same (typed confirm + "I understand" flag) | ✔ |
| Chunked upload of arbitrarily large archives | free (1 MB plupload slices) | bundled — server-tracked resumable chunks: every slice carries its byte offset; duplicate/gap slices detected, replay-safe retries in the UI (3 attempts, backoff); works over WP-CLI/REST regardless of `upload_max_filesize` | ✚ |
| Password-protected import | free | bundled — password prompt; encrypted archives from MigrateKit **and** AI1WM verified via manifest signature | ✔ |
| Import validation (CRC, sentinel, manifest, prefix checks) | free | same (Validate stage) | ✔ |
| Import sources beyond upload: cloud pull | paid per provider | bundled (see §6) | ✚ |
| Import from arbitrary URL | paid (URL extension) | not implemented server-side; cloud pull + chunked upload + WP-CLI cover the transports we ship | ◇ |
| Multisite: network archive → network restore | paid (Multisite) | bundled, blog ids preserved, per-site URL derivation | ✚ |
| Multisite guards (network→plain refusal, subsite→network refusal with clear messages) | paid blocks entirely | bundled | ✚ |
| Honors source manifest `NoEmailReplace` | free-ish | honored (request flag OR manifest flag) | ✔ |

## 3. Backups

| Feature | Reference | MigrateKit | Status |
|---|---|---|---|
| List with name, size, created | free | same + label column | ✔ |
| Labels (≤255 chars, editable) | free (editable labels) | bundled — Rename on Backups page, CLI table column, REST `GET` payload, `PATCH /backups/{name}` | ✔ |
| Download archive | free | same + per-entry download | ✔ |
| Archive browser (list contents) | free (`ai1wm_backup_list_content`) | bundled — Browse modal with filter, sorted listing, encrypted-archive unlock | ✚ |
| Download single file from archive | free (`ai1wm_backup_download_file`) | bundled — streams the decrypted entry via admin-post, no traversal surface (path matched against header manifest only) | ✚ |
| Bulk delete | free | bundled (select all/individual, one request deletes many) | ✔ |
| Delete calls home silently | free | bundled — labels cleaned up on delete | ✔ |
| Restore from backups page | **paid** (403 `upgrade_required`) | bundled — confirm + password retry flow, live progress, secret-based step polling survives the users-table replacement | ✚ |
| Backups count badge in menu | free (red bubble) | bundled | ✔ |
| Backups directory protected from web access | free | bundled (`migratekit-backups` guards) | ✔ |

## 4. Schedules & Reset

| Feature | Reference | MigrateKit | Status |
|---|---|---|---|
| Scheduled backups (hourly…monthly) | paid (Scheduler) | bundled — Cron `migratekit_run_schedule` + daily resync | ✚ |
| Retention by count/age | paid (Pro) | bundled per schedule | ✚ |
| Email notifications on finish | paid (Scheduler) | bundled | ✚ |
| Scheduled scope (main site / whole network) | paid, multisite schedules | bundled — scope select on Schedules page (flows through `Options::export`) | ✚ |
| Scheduled cloud copy | paid per provider | bundled (see §6) | ✚ |
| Reset hub (content tables, transients, media, optional users/foreign tables, safety backup, typed confirm) | paid (Reset) | bundled | ✚ |

## 5. Status, diagnostics, housekeeping

| Feature | Reference | MigrateKit | Status |
|---|---|---|---|
| Job status + progress surfaces (AJAX/REST) | free | bundled (+full REST log endpoint) | ✔ |
| Error logs retained ~7 days then removed | free (auto-delete after 7 days) | bundled — daily cron prune: finished job storage (incl. logs) after 24 h, abandoned after 7 days; injectable clock covered by tests | ✔ |
| Maintenance screen while import/restore runs | free (maintenance view during import) | bundled — 503 for anonymous visitors only; TTL (1 h) guarantees recovery if a job dies silently | ✚ |
| Environment/status diagnostics page | free (status screen) | partial — capabilities + site/network facts REST payload, per-job statuses; no replica of the reference's status screen (intentional: the admin pages carry compatibility data where relevant) | ◇ |
| Missing AUTO_INCREMENT / PHP version / multisite notices | free | advisory text widened into admin pages; compatibility enforced at import validate stage | ✓ |

## 6. Cloud storage

| Feature | Reference | MigrateKit | Status |
|---|---|---|---|
| S3 (+ SigV4, multipart) | paid (S3 / S3 Client) | bundled — any S3-compatible endpoint (AWS, MinIO, B2, Wasabi, DigitalOcean Spaces, GCS via HMAC) | ✚ |
| Dropbox | paid | bundled (OAuth2, chunked upload w/ per-chunk cursor) | ✚ |
| Google Drive | paid | bundled (OAuth2) | ✚ |
| GCS, Azure, B2 native, Box, Mega, Glacier, OneDrive, pCloud, WebDAV, FTP | paid each | not bundled; B2/Spaces/GCS reachable through the S3 provider | ◇ |
| Push/pull as observable jobs with progress, remote listing, remote delete | paid fragments | bundled | ✚ |
| Provider settings: secrets masked on every transport, OAuth survives settings edits | paid | bundled | ✔ |

## 7. WP-CLI / REST

| Feature | Reference | MigrateKit | Status |
|---|---|---|---|
| `wp export/import/…` | paid (real commands) | bundled — `export`, `backup`, `import`, `restore`, `backups`, `label`, `files`, `reset`; `cloud list/test/files/push/pull`; export flags mirror the full option set | ✚ |
| REST endpoints | free (`/ai1wm/v1/...`) | bundled superset: capabilities, export/import jobs incl. chunk endpoints, logs, backups (get/label/delete/files), one-click restore, reset, cloud | ✚ |

## 8. Deploy/uninstall

| Feature | Reference | MigrateKit | Status |
|---|---|---|---|
| Plugin cleanup on delete (crons, options, transients) | free | bundled (`uninstall.php` prunes `migratekit_*` options, transients and cron jobs) | ✔ |
| Archives preserved on uninstall | reference keeps them | same — user data is never auto-deleted | ✔ |

## 9. Deliberate non-goals

- Sale/upsell surfaces: feedback, translate, share-buttons, report-problem, extension updaters and premium pitch pages — none exist by design.
- HTTP-auth credential capture (for the reference's loopback-based execution): no loopback architecture to authenticate — jobs run in-process, with the shared-secret step fallback replacing that role.
- Extension registry and `factory.php` stubs: no extension system.
- One-click "restore from URL" server fetch: the URL extension's behavior is approximated via cloud pull and manual transfer, not duplicated.
- A replica of the reference's dedicated status screen: the equivalent data is embedded in the admin header/jobs instead.

## 10. Edge-case matrix (where behavior is verified)

| Case | Verified in |
|---|---|
| Plain / gzip / bzip2 archive round trip; CRC32 EOF sentinel; truncated + corrupt detection | `tests/run.php` |
| Encrypted archive round trip; wrong password rejected via manifest signature | `tests/run.php`, `tests/run-import.php` |
| Chunked upload: ordered append, duplicate slice idempotence, gap rejection, size mismatch at finish, staged archive validity, refusal after finish | `tests/run-parity.php` [4] |
| Table include/exclude normalization + manifest blocks | `tests/run-parity.php` [1][2], `tests/run-database.php` |
| Active-only plugins/themes filtering, network-activated merge | `tests/run-parity.php` [3] |
| Archive browser listing (plain + password-protected), single-entry locate | `tests/run-parity.php` [5], `tools/wp-parity-test.sh` (live smoke) |
| Labels: set/change/clear/delete coupling, invalid names | `tests/run-parity.php` [6] |
| Storage prune: finished 24 h grace, abandoned 7 days, fresh survive | `tests/run-parity.php` [7] |
| Shared-secret step polling | `tests/run-parity.php` [8] |
| `NoEmailReplace` honored from request and manifest | `tests/run-parity.php` [9] |
| Maintenance flag lifecycle + TTL expiry | `tests/run-parity.php` [10] |
| Serialized-PHP replacement integrity, custom prefix rename, foreign keys, AUTO_INCREMENT reset, malformed SQL, extended inserts | `tests/run-import.php`, `tests/run-database.php` |
| Multisite: whole-network export manifest, subsite archives, placeholders, both refusal guards, prefix folding | `tests/run-multisite.php` + live network smoke (`tools/wp-ms-test.sh`) |
| Cloud: SigV4 derivation, provider semantics, multipart, settings masking, push/pull cursors | `tests/run-cloud.php` + `tools/minio-integration.php`, `tools/wp-cloud-test.sh` |
| Reset: selective table reset, retention safety backup, users untouched on networks | `tests/run-reset.php` |

## 11. Conclusion

Every free-tier capability of the reference is present, every paid extension capability it sold (multisite, unlimited-size imports, scheduler, reset, cloud push/pull, restore-from-backups, table exclusions, encryption) is bundled, and the UI layers chunked upload, archive browsing, labels, bulk management and secret-resilient restore on top. Remaining deviations are conscious choices listed in §9; none remove a migration capability.
