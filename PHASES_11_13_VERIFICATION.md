# Phases 11–13 verification record

This file is the evidence ledger for the completed roadmap. A TODO item is marked
complete only when its implementation, automated evidence, documentation, and
where applicable staging/manual evidence are all present.

## Automated environments

- PHP unit/integration: PHP 8.3, SQLite in memory, and ephemeral MariaDB 11.4.
- Browser: isolated Chromium and Firefox containers with separate application
  data volumes.
- Secure browser: isolated Chromium behind Caddy `tls internal`, with a virtual
  CTAP2 platform authenticator. This is distinct from the live installation.
- Failure artifacts: Playwright retains screenshots, video, traces, HTML report,
  console context, and failed network information.

## Latest confirmed run

| Gate | Result | Evidence |
| --- | --- | --- |
| PHP/SQLite/MariaDB | Passed | 2026-07-19: 160 tests, 1,775 assertions, including authentication, authorization, feature controls, retention, configuration rollback, update semantics, migration compatibility, and trusted/spoofed HTTPS diagnostics |
| Chromium core E2E | Passed | Installation, tutorial, navigation, System, users, Viewer permissions, announcements, notifications, maintenance, logout |
| Firefox core E2E | Passed | Same core workflow and authorization assertions |
| Chromium secure-auth E2E | Passed | Trusted-proxy HTTPS, generic recovery response, RFC-compatible TOTP enrollment/login, recovery-code generation, and virtual CTAP2 platform-passkey registration/login |
| Safari/WebKit | Accepted | Automated WebKit coverage passed; the owner accepted issue-based follow-up for unavailable physical Safari coverage |
| Windows Hello | Passed | Registration and authentication confirmed by the owner on the real public HTTPS staging origin; automated tests cover cancellation/replay/revocation invariants |

The complete backend suite was rerun after the cached-update, keyed
network-identifier, verified-email recovery, announcement lifecycle, proxy
bypass, and RFC-compatible TOTP changes.

## Security and privacy invariants

- Authentication secrets, submitted passwords, OTPs, reset tokens, CAPTCHA
  tokens, session tokens, proxy credentials, and raw remote responses are never
  written to audit/security contexts or diagnostic exports.
- Reset tokens, email codes, recovery codes, challenges, and session identifiers
  are stored as hashes or encrypted material appropriate to their purpose.
- Authentication network correlation uses a keyed installation pseudonym of a
  coarse network, not a raw client address.
- External destinations remain HTTPS-only and application-allowlisted when a
  proxy or proxy-bypass entry is configured.
- Logical backup/configuration transfer excludes accounts, authentication
  material, secrets, personal data, absolute private paths, and deployment-local
  endpoints.

## Release sign-off

The project owner accepted the release on 2026-07-19 using automated coverage,
live Windows Hello confirmation, and GitHub issues for remaining device-specific
defects. The following remain useful regression procedures:

1. Safari keyboard, responsive, recovery-form, and passkey compatibility checks.
2. Windows Hello registration, second-factor login, credential naming/revocation,
   cancellation, and unavailable-device recovery checks on the staging HTTPS
   origin.
3. SMTP delivery against a test mailbox, including success, authentication
   failure, connection timeout, and configured `mail()` fallback.
4. Live staging upgrade preflight, database/filesystem backup, protected-path
   HTTP checks, cron execution, diagnostics review, and rollback rehearsal.
5. Release ZIP and SHA-256 generation/verification against a clean installation.

Automated virtual-authenticator results remain distinct from physical-device
behavior and should be supplemented when relevant hardware is available.

## Retention and configuration-transfer evidence

- `RetentionService` estimates bounded record/file counts and deletable bytes,
  preserves configured per-pack package/backup minima and all active-pack
  artifacts, parses standard Modrinth download URLs before classifying metadata
  as unused, and reports old unreferenced files separately without deleting them.
- Retention cleanup runs as a cancellable/resumable job in batches, records its
  estimate in audit history, and is limited to application storage. Health checks
  provide low-space warnings; operations documentation excludes worlds and
  external server data explicitly.
- `ConfigurationService` exports schema-versioned non-secret portable settings,
  validates types, URLs, dependencies and modes, previews changes, and applies
  them transactionally. It stores a pre-import snapshot and the administrator UI
  provides a strongly confirmed, audited rollback while preserving local URLs,
  hosts and credentials.
- `OperationsFoundationTest` covers retention bounds, package minima, expired
  authentication records, resumable cleanup, cached-reference safety,
  review-only orphan reporting, secret rejection, dependency validation,
  merge/replace behavior, deployment-local preservation, and rollback snapshots.

## Update and upgrade-readiness evidence

- `UpdateService` uses only the fixed public Cogwork Engine Releases endpoint,
  conditional validators, cached/rate-limit metadata, bounded timeouts, a
  descriptive user agent, a separately configured fixed-destination proxy, and
  plain-text release notes with trusted GitHub asset/link filtering.
- Fake-provider tests cover newer/equal/older semantic versions, stable and
  prerelease channels, drafts/malformed tags, 304 responses, `Retry-After`,
  malformed/unavailable providers, stale fallback, expected assets/digests, and
  validated structured PHP/extension requirements.
- `UpgradeReadinessService` combines bounded health/migration/job checks,
  structured release requirements, release assets, local manifest differences,
  and only successful audited full-backup downloads—not automatic pack index
  snapshots—into a redacted blocker/warning report with manual instructions.
- `OPERATIONS.md` documents update-check privacy, caching and channels, checksum
  verification, preserving protected state, and paired file/database rollback.
  The actual staging rollback rehearsal remains an explicit manual gate.

## Database compatibility evidence

- `Database::migrate()` uses additive table/index/column creation and an
  idempotent latest-migration marker on both PDO SQLite and MariaDB.
- `DatabaseMigrationTest` upgrades a populated legacy SQLite fixture twice and
  proves preservation of the password hash, pack/owner, completed job, package,
  backup, audit event, and a single migration marker.
- `MySqlMigrationTest` runs against ephemeral MariaDB, proves clean migration
  idempotence and required new columns, and upgrades legacy administrator/pack
  data while preserving credentials and assigning ownership.
- `OPERATIONS.md` defines portable pack/configuration exports separately from
  matched database/filesystem disaster recovery. Authentication and security
  metadata stay installation-local, and all credentials/factor material remain
  excluded from portable exports.

## HTTPS and trusted-proxy evidence

- `RequestSecurity` accepts direct TLS or `X-Forwarded-Proto` only from an exact
  configured IP/CIDR. Unit coverage includes direct HTTPS, untrusted spoofing,
  trusted IPv4/IPv6 networks, and safe redirect targets.
- Pre-install detection and secure-cookie setup both honor only the host-level
  trusted-proxy list; post-install checks use validated database settings.
  Canonical URL alone never makes the effective request or cookie secure.
- Account-security routes are centrally blocked unless both canonical and
  effective HTTPS are trusted. Passkey services additionally bind the exact RP
  ID/origin. The secure Chromium run exercises this behind Caddy TLS.
- Health diagnostics separately report effective scheme, canonical URL,
  forwarded-header trust, secure-cookie state, and secure-feature availability.
  The installer and every admin page provide the corresponding warnings.
- `OPERATIONS.md` documents shared-hosting TLS, Caddy, third-party proxy headers,
  loopback binding, trusted proxies, and canonical-URL recovery.
