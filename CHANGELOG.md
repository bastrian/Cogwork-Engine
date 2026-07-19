# Changelog

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

[0.1.0]: https://github.com/bastrian/Cogwork-Engine/releases/tag/v0.1.0
