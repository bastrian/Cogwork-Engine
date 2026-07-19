# Cogwork Engine

*Build, maintain, and migrate modpacks.*

Cogwork Engine is a PHP 8.3+ manager for creating, importing, updating, and
packaging Modrinth modpacks on restricted shared hosting. It uses short,
resumable HTTP job steps and does not require a daemon, shell access, Node.js,
or Composer on the server.

## Requirements

- Apache 2.4 with `.htaccess` enabled
- PHP 8.3 or newer
- PHP extensions: cURL, JSON, PDO, ZIP, OpenSSL, and mbstring
- Either PDO SQLite or PDO MySQL
- A writable `storage/` directory
- Enough disk space for uploaded packs, downloaded mods, backups, and packages

The web installer reports missing extensions and write-permission problems.
Upload and POST limits are controlled by the hosting provider; large `.mrpack`
uploads may require increasing `upload_max_filesize` and `post_max_size` in the
hosting panel.

## Deployment

1. Upload the contents of this directory to its own directory below
   `public_html` (for example, `public_html/cogwork-engine`).
2. Confirm that Apache honors `.htaccess`. Requests for
   `/cogwork-engine/storage/`, `/cogwork-engine/app/`, and
   `/cogwork-engine/vendor/` must return
   403 or 404 before installation.
3. Make `storage/` writable by PHP. Prefer `0770`; use the hosting provider's
   recommended permission if PHP runs under a different account.
4. Open `/cogwork-engine/` in a browser and complete the installer.
5. Delete or disable the installation if the lock file cannot be created. The
   installer locks automatically by creating `storage/installed.lock`.

SQLite is the recommended default. For MySQL, create an empty database and user
in the hosting panel before running the installer. Database credentials and the
cron token are stored in `storage/config.php`, which must never be publicly
accessible.

Composer is only needed for development tests. The production runtime has no
third-party package dependency, so uploading `vendor/` is optional unless a
prebuilt distribution includes it.

## Operation

### Languages

Cogwork Engine includes English (`en_US`) and German (`de_DE`). Select a language on
the login page or from the language control in the authenticated header. The
choice is saved for the administrator and remembered before login in a secure,
HTTP-only cookie. English is the fallback when a translation is missing.

Translations live in the protected `lang/` directory and return keyed PHP
arrays. To add a locale such as `en_GB`, copy `en_US.php`, translate values
without changing keys or `{placeholders}`, add the locale to
`Translator::SUPPORTED`, and run the translation test suite. Translation text
is plain text; HTML belongs in templates and is escaped at its output context.

### Accounts, ownership, and sharing

The installer-created administrator is migrated automatically into the general
user model and remains the owner of every existing pack. Administrators manage
all users and packs. Ordinary users see only packs they own or that were shared
with them. Pack owners can grant Viewer, Contributor, Maintainer, or custom
permissions for viewing, metadata, mods, synchronization, updates, migration,
builds, server settings, sharing, and deletion. Authorization is enforced on
routes and downloads; hidden controls are only a usability aid.

New and migrated packs start private to their creator. Ownership transfer and
permission changes are audited. Disabling an account or changing its role or
password invalidates its existing session version.

### Tutorial and help

First login opens a localized, skippable tutorial. Progress is stored per user
and can be resumed or restarted from Help. The top-right Help link opens a local,
searchable, offline help center and selects a topic relevant to the current
screen. English and German guides cover packs, mods, background jobs, server
packs, migration, sharing, backups, and common terminology.

- **Create** starts an empty modpack with Minecraft and loader dependencies.
- **Import** stages a local `.mrpack` or Modrinth version for review before it
  creates a pack.
- **Add mods** searches the offline/live catalog, supports bulk selection and
  exact versions, detects duplicates, and accepts hash-tracked private JARs.
- **Sync files** downloads or repairs every indexed mod one file per HTTP step.
- **Check updates** discovers compatible versions and displays a review.
- **Apply updates** backs up the index, streams and verifies each file, and
  persists progress after each mod.
- **Build** supports standard, client, dedicated-server, lightweight,
  development, and optional-mod profiles. Every normal build records an
  immutable reproducibility manifest; dry runs do not mutate data.

Pack imports and builds preserve common `overrides/`, `server-overrides/`, and
`client-overrides/` layers. Server ZIPs apply common overrides followed by
server overrides, exclude client overrides and server-unsupported mods, and can
optionally include mods marked optional. Synchronize files before a server build
so every selected JAR can be verified with SHA-1 and SHA-512.

### Migration workbench

Open a pack and choose **Migration planner** to compare newer Minecraft releases
across Forge, NeoForge, Fabric, and Quilt. The resumable scan checks each
Modrinth project for a published target version, keeps service failures and local
JARs in an explicit “unknown” state, reports blockers and target dependencies,
and ranks the safest combinations. Mark important mods as essential, normal, or
optional to adjust the recommendations.

Same-project cross-loader builds are detected automatically. Administrators can
also maintain curated replacement mappings with confidence and evidence notes.
Different-project replacements are suggestions and require explicit approval.

Applying a target always creates a new pack, copies override layers and server
settings, updates the Minecraft, loader, loader version, and Java guidance, and
stores a migration manifest. The source pack is fingerprinted and is rejected if
it changed after analysis. Run **Sync files**, then **Validate**, and create a
client or server test build before using the migrated pack.

Modrinth compatibility is publisher-declared metadata, not proof that a mod
combination, configuration, script, or existing world works at runtime. Keep a
world backup and perform a real launch test, especially when changing loaders.

Keep the job page open to process jobs in the browser. Refreshing or closing the
page is safe; opening it again resumes the job.

### Optional cron

The generated token is in `storage/config.php`. Configure the hosting cron tool
to request:

```text
https://example.com/cogwork-engine/index.php?route=cron&token=GENERATED_TOKEN
```

Each invocation works for at most about 20 seconds and processes at most ten
steps. Prefer an `Authorization: Bearer GENERATED_TOKEN` header if the hosting
cron tool supports custom headers. Rotate the token by replacing it with 64
random hexadecimal characters in the protected config file.

## Security and limits

Outbound downloads are restricted to HTTPS requests to `api.modrinth.com` and
`cdn.modrinth.com`, without redirects. Archive entries are checked for path
traversal, file-count limits, and uncompressed-size limits. Downloads are
written to temporary files, bounded in size, hash-checked, and atomically moved.

Cogwork Engine supports administrators, ordinary users, pack ownership, and
granular sharing permissions. Do not expose it until HTTPS is enabled,
`.htaccess` protection has been verified, and strong account passwords have
been configured.

## Development

```bash
composer install
composer test

# Or without local PHP/Composer:
docker compose --profile test run --rm test
```

Tests use temporary files and do not require access to the live Modrinth API.
See [OPERATIONS.md](OPERATIONS.md) for upgrades, cron, server configuration,
profiles, backup/restore, security, recovery, and troubleshooting.

## License

Cogwork Engine is licensed under the
[GNU Affero General Public License v3.0](LICENSE). If you modify the application
and make that modified version available to users over a network, the license
requires you to offer those users the corresponding source code.
