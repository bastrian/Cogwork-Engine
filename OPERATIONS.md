# Cogwork Engine operations guide

## Deployment and upgrades

Cogwork Engine requires PHP 8.3 with cURL, JSON, mbstring, OpenSSL, PDO, and ZIP, plus either PDO SQLite or PDO MySQL. Keep `storage/` writable and keep `app/`, `storage/`, `tests/`, `vendor/`, and configuration files inaccessible over HTTP. The supplied Apache rules enforce this when `AllowOverride` is enabled.

For upgrades, replace application files but preserve the entire `storage/` directory. Database migrations are additive and run automatically on the next request. Create a Cogwork Engine backup before upgrading and test the upgrade on a copy when possible.

### HTTPS and reverse proxies

Account recovery, email verification, TOTP/recovery-code management, passkeys,
and reCAPTCHA are deliberately unavailable unless both the configured canonical
URL and the effective request are HTTPS. Direct TLS is detected automatically.
When TLS terminates at a reverse proxy, add only that proxy's exact IP address or
CIDR network under **Administration → System → Security and public URL**. Cogwork
Engine ignores `X-Forwarded-Proto` from every other client. A wrong trusted-proxy
entry can make secure cookies unavailable; remove it from the database or use a
host-level database backup if the UI is unreachable.

The bundled Caddy profile forwards HTTPS to the loopback-bound application. For
another proxy, preserve the original host and send `X-Forwarded-Proto: https`.
Never expose the application port publicly as a way around TLS.

### Manual upgrade and rollback

1. In **Administration → System**, check for updates and download the redacted
   upgrade-readiness report.
2. Finish or cancel active jobs, pause cron, and download a fresh Cogwork Engine
   backup. Also take a database/filesystem backup; the logical export excludes
   accounts and credentials.
3. Download the shared-hosting ZIP and `.zip.sha256` from the linked GitHub
   release. Verify SHA-256 locally before extracting. Cogwork Engine never
   downloads, executes, or installs application updates itself.
4. Preserve `storage/` and protected configuration, replace application files,
   then load the System page once. Migrations are idempotent and additive.
5. Run diagnostics, a catalog refresh, validation, and a dry-run build.

For rollback, stop cron, restore the prior application files and the matching
database/filesystem backup together, then run diagnostics. Do not restore old
files over a database changed by a future irreversible migration. Current
Phase 11–13 migrations only add tables, columns, and indexes and are rollback
safe when the previous application ignores them.

Before release, complete the physical Safari and Windows Hello checks in
[`MANUAL_VERIFICATION.md`](MANUAL_VERIFICATION.md). Linux browser automation
uses a virtual WebAuthn authenticator and cannot substitute for platform
Windows Hello or Safari behavior.

### Update-check privacy

When enabled, the server requests only the public
`bastrian/Cogwork-Engine` GitHub Releases API with a Cogwork Engine user agent.
No GitHub token, account identifier, pack metadata, or user data is sent. ETag
and Last-Modified validators and the last valid result are cached. The Stable
channel ignores prereleases; Include prereleases evaluates both. Failures leave
the last valid result visible as stale. Release notes are rendered as plain text,
and only trusted GitHub links and expected ZIP/checksum assets are shown.
Loading Administration never initiates a GitHub request. Automatic checks run
only from an authenticated cron invocation after the configured interval; the
administrator-only **Check now** action is separately rate-limited.

### Modrinth connectivity and proxies

The Modrinth master feature switch stops live API, status, catalog, icon, import,
update, migration-scan, and download work. Existing packs, local files, cached
metadata, validation, backups, and builds remain available where their required
files are already present. Disabling connectivity does not weaken HTTPS host
allowlists, redirect limits, download-size limits, or hash verification.

The Modrinth proxy and general GitHub proxy are configured independently.
Supported proxy types are HTTP, HTTPS, and SOCKS5, with bounded connection
timeouts and protected optional credentials. The Modrinth proxy-bypass list can
contain only exact destinations already compiled into Cogwork Engine's outbound
allowlist; arbitrary hosts, suffix patterns, URLs, and IP ranges are rejected.
Use **Test Modrinth connection and proxy** after every change. Diagnostics report
only the failure stage and never return credentials or private proxy responses.

### Mail, recovery, and strong authentication

Prefer authenticated SMTP with STARTTLS/TLS and a short timeout. SMTP, proxy,
and reCAPTCHA secrets live in protected configuration and are excluded from
logical backups, configuration exports, diagnostics, and audit records. PHP
`mail()` is an explicit fallback, not an implicit guarantee of delivery.
New or changed account addresses remain unverified until the single-use HTTPS
verification link is used. Unverified addresses cannot authorize password
recovery or email compatibility codes.

TOTP secrets are encrypted at rest. Recovery codes and reset/session/email codes
are stored only as hashes and shown only when appropriate. Print recovery codes
and keep them outside the server. Passkeys require the exact canonical HTTPS
origin. Windows Hello and FIDO2 availability depends on the browser and device.
If all factors are lost, an administrator must follow the audited account
recovery process; never transmit a password or factor secret by email.
Network identifiers used for abuse prevention and reset-request correlation are
coarsened and keyed per installation; raw client addresses are not retained in
these authentication records.

Recovery order:

1. Use a saved, single-use recovery code when a TOTP device, Windows Hello,
   roaming security key, or phone passkey is unavailable.
2. Use **Forgot password** only for password loss when the account address is
   verified and outbound mail works. Password recovery never bypasses a required
   second factor.
3. Ask another administrator to send an account reset link or use the strongly
   confirmed **Reset all authentication factors** action. Factor reset revokes
   every account session and is prominently audited.
4. Repair SMTP/PHP `mail()` or verify the address through normal account
   controls when email is inaccessible; do not substitute an emailed password.
5. For the final administrator with no usable factor, use protected host and
   database recovery or restore a tested server backup. Never copy factor
   secrets, reset/session tokens, or reusable credentials into a recovery file.

Emergency controls: set `COGWORK_MAINTENANCE_DISABLE=1` temporarily, or edit the
protected `storage/config.php` return value and add
`'emergency' => ['disable_maintenance' => true]`, to bypass a bad maintenance
configuration. Correct the database-backed setting, then remove the environment
variable or the entire `emergency` entry and retest. Do not leave the override
enabled: it suppresses maintenance enforcement for every visitor. Host-level
database/configuration recovery is required if the final administrator loses
every factor.

### Maintenance, retention, and diagnostics

Feature controls are grouped and searchable under **Administration → System →
Features**. Changes take effect immediately and require no process restart.
Dependencies are validated atomically before saving, and every changed flag
requires a reason and creates an audit entry containing its previous and new
state. Core authentication, administrator recovery, migrations, security
logging, and the feature-control screen are not optional flags.

Disabling Modrinth connectivity is refused while a dependent job is queued or
running; finish or cancel those jobs first. Once disabled, no new dependent job
can be created and no existing dependent step advances. Local packs, cached
metadata, local JARs, validation, backups, and builds whose required files are
already synchronized remain usable. Re-enabling the feature resumes eligible
queued work. Feature states are included in redacted diagnostics and
non-secret configuration exports.

Maintenance mode returns HTTP 503 to ordinary users and can publish a scheduled
English/German message and `Retry-After`. Enabled administrators retain access.
The processing policy independently controls new-job creation, subsequent
bounded steps of queued/running browser jobs, cron, outbound mail, and the
public status endpoint. A step already executing is allowed to finish; Cogwork
Engine does not terminate a PHP request midway through file or database work.

Retention cleanup is manual and bounded. It removes only eligible completed
jobs, archived notifications, old security/abuse records, and expired auth
artifacts. It never removes Minecraft worlds or external server data. Review the
dry-run counts and keep independent package, application, database, and world
retention policies.

Downloaded diagnostics and upgrade reports intentionally exclude credentials,
tokens, DSNs, private paths, personal data, and remote response bodies. Review a
report before attaching it to a support request.

Scheduled announcements are reconciled when application pages are served.
Their first activation and expiry transition are each recorded once in the
audit history, in addition to creation, editing, audience changes, archival,
and deletion. English title and message are required and are the fallback when
a German field is empty. Content is rendered as escaped plain text with safe
line breaks; links are restricted to local application paths or credential-free
absolute HTTPS URLs. Critical and maintenance notices cannot be dismissed.

Configuration exports are portable behavior profiles, not complete deployment
backups. They omit canonical URLs, trusted proxies, logout targets, mail-server
identity, proxy endpoints and bypass entries, CAPTCHA site identity, all
credentials, personal data, and private paths. Merge changes only provided
portable values. Replace resets other portable values to defaults but preserves
the destination installation's local-only settings. Every import is previewed,
explicitly confirmed, backed up internally, and audited.

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

Run browser tests with `docker compose --profile e2e run --rm e2e-chromium`
and `docker compose --profile e2e run --rm e2e-firefox`. Each runner clears
only its own explicitly mounted E2E volume before the run. Never use `docker
compose down --volumes` on an installed instance:
Compose also selects services without a profile, and that command deletes the
production `modright_data` volume. Stop or remove named test services directly
when troubleshooting them.

Optional Caddy HTTPS proxy:

```bash
COGWORK_DOMAIN=modpacks.example.com \
MODRIGHT_BIND=127.0.0.1 MODRIGHT_PORT=8095 \
docker compose --profile proxy up -d proxy
```

The domain must resolve to the server and inbound TCP ports 80 and 443 must be
available. Caddy obtains and renews the certificate automatically. Keep the
diagnostic application port bound to loopback so visitors cannot bypass HTTPS.

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
completed with a current step. The first-login tour runs on the real application
pages: each step identifies and highlights its target while leaving the control
usable. Interactive links advance to the next page; Previous, Next, Skip, and
Finish persist progress through the normal CSRF-protected endpoint. Missing or
unauthorized targets fall back to a usable explanatory dialog. Users can restart
the tour from Help. Help content is local and usable without Modrinth. Keep the
step routes, CSS selectors, English and German text aligned with interface
changes, verify internal anchors, and update the displayed documentation version
when workflows change materially.

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

Administration → System → Data & backups exports:

- pack indexes and synchronized files;
- common, client, and server overrides;
- generated packages and checksums;
- server options and build manifests;
- cached Modrinth metadata and icons.

It excludes administrator accounts and password hashes, application keys, cron tokens, sessions, transient jobs, audit logs, and database credentials.

The separate non-secret configuration export carries portable feature,
notification-default, maintenance, retention, and update policies. Account email
state, abuse counters, security events, reset/verification records, sessions,
TOTP material, recovery-code hashes, passkeys, and CAPTCHA/proxy/SMTP secrets are
intentionally excluded from both portable exports. Preserve and restore those
installation-local records only as part of a protected, matching database and
filesystem disaster-recovery backup; never merge authentication tables between
installations.

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
