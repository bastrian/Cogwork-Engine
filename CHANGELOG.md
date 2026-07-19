# Changelog

## [0.2.0] - 2026-07-19

- Unified account navigation and administrator Packs, Users, System, and Audit views.
- Required email identity, password recovery and verification, persistent abuse
  throttling, session management, TOTP, recovery codes, email codes, and passkeys.
- Optional score-based reCAPTCHA, protected SMTP/proxy secrets, and HTTPS-origin
  enforcement with explicitly trusted reverse proxies.
- Feature controls, maintenance scheduling and job pausing, announcements,
  notifications, pack activity, retention cleanup, redacted diagnostics,
  configuration transfer, GitHub update checks, and upgrade-readiness reports.
- Unified Administration → System panels, allowlist-restricted Modrinth proxy
  bypass controls, and durable announcement activation/expiry audit events.
- Additive SQLite/MySQL migration tracking and expanded security/operations tests.
- RFC 6238-compatible authenticator codes, user-named passkeys, coarse session
  network/device metadata, persistent CAPTCHA failure throttling, UTC-normalized
  announcement schedules, stronger deleted-user anonymization, and one shared
  logout redirect policy.
- Separate success/failure operation history in System health and portable,
  non-personal notification defaults in configuration transfer.
- Reorganized system administration into focused Features, Security,
  Integrations, Maintenance, Data, and Updates sections.
- Added password re-confirmation for sensitive account changes, factor-aware
  MFA selection, clearer recovery links, and structured passkey API errors.
- Improved large portable-backup performance, compact pack action menus,
  installer password confirmation, and clearer update status wording.

All notable changes to Cogwork Engine are documented here. The project follows
[Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-07-19

Initial public preview.

### Highlights

- Create and import Modrinth modpacks with compatible Minecraft and loader
  version selection.
- Search and cache Modrinth projects, synchronize verified files, review
  updates, and run resumable background jobs.
- Build reproducible client profiles and configurable dedicated-server packs.
- Compare Minecraft and loader migration targets, blockers, dependencies, and
  curated replacements without modifying the source pack.
- Manage administrators, users, pack ownership, and granular sharing rights.
- Export and validate logical backups across SQLite and MySQL installations.
- Use the localized English or German interface, tutorial, and help center.
- Monitor Modrinth availability and retain useful offline fallbacks.

### Platform

- PHP 8.3 or newer on Apache shared hosting.
- SQLite by default, with optional MySQL support.
- Automated tests on PHP 8.3 and PHP 8.4.

[0.2.0]: https://github.com/bastrian/Cogwork-Engine/releases/tag/v0.2.0
[0.1.0]: https://github.com/bastrian/Cogwork-Engine/releases/tag/v0.1.0
