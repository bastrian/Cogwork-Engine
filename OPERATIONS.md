# Cogwork Engine operations guide

## Deployment and upgrades

Cogwork Engine requires PHP 8.3 with cURL, JSON, mbstring, OpenSSL, PDO, and ZIP, plus either PDO SQLite or PDO MySQL. Keep `storage/` writable and keep `app/`, `storage/`, `tests/`, `vendor/`, and configuration files inaccessible over HTTP. The supplied Apache rules enforce this when `AllowOverride` is enabled.

For upgrades, replace application files but preserve the entire `storage/` directory. Database migrations are additive and run automatically on the next request. Create a Cogwork Engine backup before upgrading and test the upgrade on a copy when possible.

### Locale maintenance

English and German translations are stored in `lang/en_US.php` and
`lang/de_DE.php`. Both files are denied direct web access. Keep keys and named
placeholders identical between locales and run PHPUnit after every translation
change. If a locale is removed, leave `en_US` available as the fallback and
change affected account preferences to a supported locale. User-entered pack
names, summaries, filenames, configuration, and logs are never translated.

Docker test deployment:

```bash
MODRIGHT_BIND=127.0.0.1 MODRIGHT_PORT=8080 docker compose up -d web
docker compose --profile test run --rm test
```

## Routine operation

- Refresh the offline catalog after importing a pack and periodically thereafter. Failed projects keep their previous cached metadata.
- Synchronize files before validation or server builds. Sync verifies sizes and hashes and repairs remote files, but never replaces a missing or changed private/local JAR.
- Run validation before releases. Builds also run validation automatically and stop on errors.
- Use the cron endpoint to continue queued jobs without an open browser. Prefer an `Authorization: Bearer` header and treat the token as a password.
- Cancelled and failed jobs can be retried from their job page. Retry begins a fresh job with the original payload.

## Account and permission recovery

Administrators create, disable, re-enable, promote, demote, and reset accounts
from **Users**. Before disabling or removing responsibility from a pack owner,
transfer each owned pack to an enabled account. Ownership grants every pack
capability; shared permissions do not imply ownership. Only owners and
administrators may transfer ownership.

Role, enabled-state, and password changes increment the account session version,
invalidating older sessions on their next request. Permission and ownership
changes include the acting user in the audit log. If the final administrator is
unavailable, recover the database from a protected host-level backup rather than
editing authorization rows through the web.

The logical Cogwork Engine export intentionally excludes credentials. Preserve the
configured SQLite database or a MySQL dump for complete account recovery.

## Tutorial and help maintenance

Tutorial state is stored per user as not started, in progress, skipped, or
completed with a current step. Users can restart it from Help. Help content is
local and usable without Modrinth. Keep English and German topic text aligned,
verify internal anchors, and update the displayed documentation version when
workflows change materially.

## Build profiles

- **Standard** uses the chosen `.mrpack`, server ZIP, or combined target.
- **Client** builds a client `.mrpack` without server-only files.
- **Dedicated server** builds a server ZIP using the saved server configuration.
- **Lightweight client** includes only files required on the client.
- **Development** includes required and optional client-compatible files.
- **Optional-mod bundle** includes entries marked optional on either environment.

Every successful normal build stores an immutable manifest containing the exact pack index and file hashes, profile, target, server options, validation counts, catalog timestamps, and package checksums. “Rebuild” uses that manifest and does not alter the current pack.

## Server configuration

Server configuration is stored per pack. `{MIN}` and `{MAX}` in start commands are replaced with the configured memory values. The server ZIP includes loader guidance, the required Java release, `server.properties`, scripts, selected override layers, and exact-path exclusions.

## Migration launch-test procedure

1. Run the migration scan and review every blocked, unknown, replacement, and dependency entry.
2. Give essential mods the correct priority and choose the safest acceptable Minecraft/loader target.
3. Create the migration copy; never repurpose the source pack as the test target.
4. Synchronize target files and resolve validation errors before building.
5. Review loader-specific configuration, scripts, loader APIs, and generated Java guidance.
6. Build both the intended client profile and a dedicated-server test package.
7. Start with a copied world, inspect the complete startup log, join once, and verify dimensions, registries, recipes, quests, and scripted content.
8. Keep the original pack and world backup until the migrated server has been exercised and accepted.

Cogwork Engine writes `eula=false` unless the administrator explicitly records acceptance for that pack. Review the current Minecraft EULA yourself before enabling it.

## Backup and recovery

Settings → Backup and migration exports:

- pack indexes and synchronized files;
- common, client, and server overrides;
- generated packages and checksums;
- server options and build manifests;
- cached Modrinth metadata and icons.

It excludes administrator accounts and password hashes, application keys, cron tokens, sessions, transient jobs, audit logs, and database credentials.

Restore validates the ZIP structure, entry count, expanded size, metadata format, pack indexes, and paths before importing records. By default it refuses internal pack-ID collisions. Enable overwrite only when intentionally restoring the same installation. Keep an independent filesystem/database backup before an overwrite restore.

For SQLite disaster recovery, preserve `storage/config.php`, the configured SQLite database, and the entire `storage/` directory. For MySQL, preserve `storage/config.php`, a database dump, and `storage/`. The logical Cogwork Engine backup is portable between SQLite and MySQL.

## Security checklist

- Terminate HTTPS before exposing Cogwork Engine.
- Verify protected directories return 403 or 404.
- Use a unique administrator password of at least 12 characters.
- Restrict access by VPN, firewall, or HTTP authentication when practical.
- Keep PHP and the host OS patched.
- Do not install private JARs you do not trust or redistribute files without permission.
- Review custom start commands before running generated server packs.
- Rotate the cron token if it is exposed.
- Test restores periodically.

Outbound API/download hosts are allowlisted and HTTPS-only. Redirects, archive traversal, oversized downloads, invalid hashes, duplicate paths, cross-layer override conflicts, and invalid JAR containers are checked. The application sends CSP, frame, referrer, and MIME-sniffing protections and uses CSRF tokens for state-changing requests.

## Accessibility and responsive behavior

The interface includes keyboard-visible focus, a skip link, labeled controls, reduced-motion support, responsive single-column forms, horizontally scrollable tables, and paginated/filterable mod lists. Icons used alongside project names are decorative; project names remain available as text.

## Troubleshooting

- **Job appears idle:** keep the job page open, retry it, or invoke cron. Check the displayed current item and recent activity.
- **Catalog refresh failures:** cached metadata remains usable. Retry after Modrinth recovers.
- **Build blocked:** open the validation report and resolve every error. Warnings do not block builds.
- **Server build reports missing files:** run Sync files and restore any missing local JARs manually.
- **Upload rejected:** check PHP `upload_max_filesize` and `post_max_size`, archive limits, extension, and file validity.
- **Restore collision:** restore into an empty installation or consciously enable overwrite after making another backup.
