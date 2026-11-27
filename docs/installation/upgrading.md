# Update an existing installation

Updating replaces application code and installs the libraries in `composer.lock`. It must keep your own `.env`, application key, SQLite database, and `storage/`. Saved dashboard identity, tiles, themes, quips, map settings, and their revision live in SQLite, not in the downloaded source.

This guide is for your own host. It does not use the maintainer's deployment tooling. The commands below use a Unix shell (Linux/macOS) with PHP, Composer 2, Git (for the Git path), and the `sqlite3` command-line tool. Use your host's actual paths and the same PHP version/user as the web application. Shared hosting without shell access needs its control-panel equivalents or help from the hosting provider; do not assume uploading a ZIP alone completes an update. Native Windows upgrade commands are not covered by this shell procedure.

**Stop at the first error.** Read the changes since your installed commit, check [requirements](requirements.md), and take a restorable backup before changing code. `main` is the public update channel; record the exact commit you install. A successful Git pull by itself does not install dependencies or run migrations.

## 1. Identify and protect your state

From the folder containing `artisan`:

```bash
git remote get-url origin
git status --short
git rev-parse HEAD
php artisan tinker --execute='dump(DB::connection()->getDatabaseName());'
php artisan migrate:status
```

For a Git install, `origin` should be `https://github.com/thenumber3/midway-weather.git` (or its SSH equivalent). Stop if tracked files have local edits or the remote points elsewhere; back up and review those edits rather than resetting them. For a ZIP install, skip the Git commands and record the source version if known. The database command reports the **effective** database path, including cached configuration; do not assume the default file is in use. Do not share `.env` or private paths in support requests.

Record your dashboard title, subtitle, theme, enabled tiles, and the configuration revision shown in `/api/display/weather` under `configuration.revision`. Check Admin → Data sources and System & diagnostics for a baseline.

Keep the existing `APP_KEY`, Ambient keys, admin email/password hash, and database path. Compare a new `.env.example` with your file and add only settings you choose to adopt. Never replace `.env` with the example, run `composer setup`, or regenerate the application key during an upgrade. Do not run `migrate:fresh`, `migrate:refresh`, database seeders, or `weather:demo` against live data. Do not use `git clean -fdx`; ignored files include your database and environment.

**Existing Stadia users:** newer code defaults to OpenStreetMap unless you set `WEATHER_BASEMAP_PROVIDER=stadia`. To retain your Stadia cartography/domain authentication, add that setting to your own `.env` before updating. See [map configuration](../configuration.md#maps-and-radar). Do not replace your existing credentials.

## 2. Pause writes and back up

Disable this application's scheduler cron/control-panel job or stop its `schedule:work` process, and wait for running collection/forecast/cleanup jobs to finish. Stop any other app workers. Enter maintenance mode and keep administrators out until the update finishes:

```bash
php artisan down
```

Maintenance mode alone does **not** stop the scheduler. For a local installation, stop the `artisan serve` process too. Back up outside both the application folder and the web root, with access restricted to your account. Substitute the real paths below. The destination must be new; `mkdir` should fail if it already exists.

```bash
umask 077
WEATHER_DB='/absolute/path/to/your/database.sqlite'
WEATHER_BACKUP='/private/backups/weather-before-update-YYYYMMDD-HHMMSS'
mkdir "$WEATHER_BACKUP"
cp -p .env "$WEATHER_BACKUP/environment"
cp -RLp storage "$WEATHER_BACKUP/storage"
sqlite3 "$WEATHER_DB" ".backup '$WEATHER_BACKUP/database.sqlite'"
sqlite3 "$WEATHER_BACKUP/database.sqlite" 'PRAGMA integrity_check;'
```

The last command must return **`ok`**. This uses SQLite's backup mechanism, which includes committed WAL data. Do not copy only a live `.sqlite` file and omit its `-wal`/`-shm` sidecars. If `sqlite3` is unavailable, obtain a verified SQLite-aware backup through your host before continuing. Protect backups: they contain credentials, station data and administrator configuration. Preserve any separately provisioned environment/admin sidecar too; the standard local setup uses `.env`, while managed installations may supply these outside it. Copying `storage/` preserves file sessions and other runtime files, not just logs.

Keep a copy of the old source and its `composer.lock`. For a clean Git installation:

```bash
git rev-parse HEAD > "$WEATHER_BACKUP/previous-commit.txt"
mkdir "$WEATHER_BACKUP/source"
git archive HEAD | tar -x -C "$WEATHER_BACKUP/source"
```

For ZIP installs, copy the existing application folder to private backup storage instead. Retain any custom files, access rules, symlink targets and hosting configuration. Check that backup copies are readable and test recovery in an isolated folder/database when possible.

## 3. Replace code, preserve state

### Git installation (same folder)

```bash
git fetch origin main
git log --oneline HEAD..origin/main
git merge --ff-only origin/main
git rev-parse HEAD
```

Review the fetched changes before the merge. `--ff-only` refuses divergent local history. If it fails, stop and resolve the local changes separately; do not force-reset the installation. `.env`, runtime SQLite files and storage contents are untracked/ignored and are not replaced by this clean fast-forward. Confirm your paths still point to the same state before running any migration.

### ZIP / upload installation

Download the current public source from [GitHub](https://github.com/thenumber3/midway-weather), record its commit, and extract it to a **separate staging folder**, not on top of the running installation. Have the hosting provider replace application source while retaining the existing `.env`, `storage/`, and actual SQLite database (including sidecars). Never enable an upload tool's “delete everything not in the archive” option against the live folder.

For a new release directory, reuse the existing persistent environment, storage and database through your hosting system. Ensure `DB_DATABASE` resolves to the **same existing database**; a relative/default path can silently select a new empty file in the new folder. Do not copy a filled database into `public/`. Maintain a document root pointing only to `public/`. The archive has no installed `vendor/`; run the following commands on the server, or ask your host to run them with its compatible PHP/Composer runtime. Source-only upload is insufficient.

## 4. Install, migrate and clear old caches

With writes still paused, from the updated application folder:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
php artisan optimize:clear
php artisan migrate --force
php artisan config:cache
php artisan view:cache
php artisan migrate:status
```

Use **`composer install`**, not `composer update`: the committed lockfile selects the tested dependencies. Confirm all migrations completed. Saved settings remain in the existing database; a compatibility migration may add missing settings and advance their revision. It must not overwrite a personalized identity. When upgrading from before dashboard identity existed, the migration preserves the previous heading/subtitle; edit them later in Admin → Display settings if desired.

If any step fails, leave maintenance mode active and the scheduler stopped. Fix the reported problem or use the recovery procedure below. Do not keep retrying with database reset commands.

## 5. Reopen and verify

When upgrading to Atmosphere 2.0, reopen any already-running dashboard or installed PWA while online to load the new stylesheet and renderer. Existing layout and seasonal choices carry forward. Atmospheric intensity defaults to **Immersive** and can be adjusted in [Admin → Themes](../admin.md#atmosphere-and-seasonal-themes).

```bash
php artisan up
php artisan weather:status
php artisan schedule:list
```

Restart/reload PHP workers through the hosting provider if required for its OPcache/release-directory configuration. For local use, restart `artisan serve`. Open your actual URL and check:

- Dashboard title/subtitle, theme, tile layout, map provider and radar match your choices. Sign into administration and check saved settings and previews.
- `/api/display/weather` still has your observations and configuration. Check the revision against your baseline (allow only an explained migration increment).
- `/api/v1/health` reports observation **and forecast** source status; `/up` alone only checks application boot. Old data should remain visible even before a new collection.

Re-enable **one** scheduler job, then confirm observations advance after a few minutes. Check forecasts separately; their normal refresh is hourly. If needed, run `php artisan weather:collect` and `php artisan weather:forecast` once, then check `weather:status` and source health again. Use [operations troubleshooting](../operations.md#troubleshooting) for failures. Refresh/reopen an installed PWA to pick up new assets; do not clear its server database.

Keep the backup until you have verified both the application and subsequent scheduled collection.

## Recovery

Keep the scheduler stopped and take the application out of service before recovery. Restore the previous source and matching lockfile, reinstall its locked dependencies, retain the original environment/application key, and clear/rebuild its caches. A code rollback does **not** undo migrations. Avoid `migrate:rollback` as a generic recovery command: schema changes can remove data and may not be backward compatible.

If a database restore is necessary, stop **all** processes accessing SQLite (including PHP workers), preserve the failed database and all sidecars in private storage, and restore the verified backup to the configured database path with the original ownership/permissions. Do not leave old WAL/SHM files beside a restored database. Restore matching environment/storage state as appropriate. Restoring a backup loses writes made after that backup; decide this explicitly before restoring. Recheck integrity, migrations, dashboard/admin and collection before reopening. Ask the hosting provider for help if it controls these processes.

## Validation boundaries

The maintainer rehearses updates on disposable local installations, including state preservation and WAL-aware backup. This is not a test of every shared-host control panel, filesystem permission model, PHP-FPM configuration or Windows upgrade path. Keep your own backups and use your host's release procedure where it supplies one.
